<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\YearEnd;

use App\Models\Accounting\AccountingYearEndEvent;
use App\Models\Accounting\FiscalYear;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccountingYearEndEvidence
{
    public function __construct(private readonly AccountingCanonicalPayload $canonical, private readonly AccountingYearEndContract $contract) {}

    /** Caller retains company/year locks. @return array{events: list<AccountingYearEndEvent>, active: AccountingYearEndEvent|null} */
    public function read(FiscalYear $year): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Year-end evidence requires a transaction.');
        }
        $events = AccountingYearEndEvent::query()->where('company_id', $year->getAttribute('company_id'))->where('fiscal_year_id', $year->getKey())->orderBy('sequence')->limit(1001)->get();
        if ($events->count() > 1000) {
            $this->fail('Year-end history exceeds the interactive verification limit.');
        }
        $history = [];
        $previous = null;
        $active = null;
        foreach ($events as $event) {
            $p = $event->getAttribute('payload');
            $kind = $event->getAttribute('event_kind');
            $last = $history === [] ? null : $history[array_key_last($history)];
            if (! is_array($p) || ! in_array($kind, ['prepare', 'approve', 'close', 'reopen'], true) || (int) $event->getAttribute('sequence') !== count($history) + 1 || $event->getAttribute('previous_hash') !== $previous || $event->getAttribute('payload_hash') !== $this->canonical->hash($this->body($event))) {
                $this->fail('Year-end history is missing, reordered or inconsistent. No repair is performed.');
            }
            if ($kind === 'prepare') {
                $s = $p['snapshot'] ?? null;
                if (! is_array($s) || ($s['company_id'] ?? null) !== (int) $year->getAttribute('company_id') || ($s['year_id'] ?? null) !== (int) $year->getKey() || ($p['snapshot_hash'] ?? null) !== $this->canonical->hash($s) || ! in_array($s['mode'] ?? '', ['close', 'reopen'], true) || ($s['mode'] === 'close' && $active !== null) || ($s['mode'] === 'reopen' && ($active === null || $s['original_close_id'] !== (int) $active->getKey()))) {
                    $this->fail('Prepared year-end scope or active closure changed.');
                }
                if ($s['mode'] === 'reopen') {
                    $original = null;
                    foreach ($history as $item) {
                        if ((int) $item->getKey() === $active->getAttribute('payload')['preparation_id']) {
                            $original = $item;
                        }
                    }
                    if ($original === null || $this->canonical->hash($s['plan']) !== $this->canonical->hash($this->contract->reverse($original->getAttribute('payload')['snapshot']['plan'])) || $this->canonical->hash($s['mapping']) !== $this->canonical->hash($original->getAttribute('payload')['snapshot']['mapping'])) {
                        $this->fail('A reopening plan must exactly compensate the recorded original, including its retained-earnings account.');
                    }
                }
            } elseif ($kind === 'approve') {
                if ($last === null || $last->getAttribute('event_kind') !== 'prepare' || ($p['preparation_id'] ?? null) !== (int) $last->getKey() || ($p['preparation_hash'] ?? null) !== $last->getAttribute('payload_hash') || (int) $event->getAttribute('actor_id') === (int) $last->getAttribute('actor_id') || $p['snapshot_hash'] !== $last->getAttribute('payload')['snapshot_hash'] || ($p['period_reviews_confirmed'] ?? null) !== true || trim((string) ($p['review_reference'] ?? '')) === '' || ($last->getAttribute('payload')['snapshot']['mode'] === 'reopen' && $active !== null && (int) $event->getAttribute('actor_id') === (int) $active->getAttribute('actor_id'))) {
                    $this->fail('A current independent year-end approval is required.');
                }
            } else {
                if ($last === null || $last->getAttribute('event_kind') !== 'approve' || ($p['approval_id'] ?? null) !== (int) $last->getKey() || ($p['approval_hash'] ?? null) !== $last->getAttribute('payload_hash') || ($p['mode'] ?? null) !== $kind) {
                    $this->fail('Year-end financial operation has no matching approval.');
                }
                $prep = null;
                foreach ($history as $item) {
                    if ((int) $item->getKey() === $last->getAttribute('payload')['preparation_id']) {
                        $prep = $item;
                    }
                }
                if ($prep === null || $prep->getAttribute('payload')['snapshot']['mode'] !== $kind || ($p['preparation_id'] ?? null) !== (int) $prep->getKey()) {
                    $this->fail('Year-end operation belongs to another prepared plan.');
                }
                $plan = $prep->getAttribute('payload')['snapshot']['plan'];
                $journal = $event->getAttribute('journal_entry_id');
                if (($plan['lines'] === []) !== ($journal === null) || ($journal === null && (($p['no_journal_reason'] ?? null) !== 'zero_nominal_balances' || ($p['journal_hash'] ?? null) !== null)) || ($journal !== null && (($p['no_journal_reason'] ?? null) !== null || ! is_string($p['journal_hash'] ?? null)))) {
                    $this->fail('Year-end journal membership and no-journal evidence disagree.');
                }
                if ($kind === 'close') {
                    if ($active !== null) {
                        $this->fail('An active year close cannot be duplicated.');
                    } $active = $event;
                } else {
                    if ($active === null || $prep->getAttribute('payload')['snapshot']['original_close_id'] !== (int) $active->getKey() || (int) $event->getAttribute('actor_id') === (int) $active->getAttribute('actor_id')) {
                        $this->fail('Year reopening must independently compensate its active close.');
                    } $active = null;
                }
            }
            if (! in_array($kind, ['close', 'reopen'], true) && $event->getAttribute('journal_entry_id') !== null) {
                $this->fail('Review events cannot own journals.');
            }
            $history[] = $event;
            $previous = (string) $event->getAttribute('payload_hash');
        }
        $head = $history === [] ? null : $history[array_key_last($history)];
        if (($head === null ? null : (int) $head->getKey()) !== ($year->getAttribute('year_end_head_id') === null ? null : (int) $year->getAttribute('year_end_head_id')) || $previous !== $year->getAttribute('year_end_hash')) {
            $this->fail('Year-end history head is missing or truncated.');
        }
        if (! in_array($year->getAttribute('state'), ['open', 'closed'], true) || ($year->getAttribute('state') === 'closed') !== ($active !== null) || ($active !== null && ($active->getAttribute('payload')['year_revision'] !== (int) $year->getAttribute('revision') || (int) $active->getAttribute('actor_id') !== (int) $year->getAttribute('closed_by')))) {
            $this->fail('Fiscal-year state and retained closure evidence disagree. Legacy year closures require review.');
        }

        return ['events' => $history, 'active' => $active];
    }

    /** @param array<string, mixed> $payload */
    public function append(User $actor, FiscalYear $year, string $kind, array $payload, string $keyHash, string $requestHash, ?int $journal = null): AccountingYearEndEvent
    {
        $read = $this->read($year);
        $history = $read['events'];
        if (count($history) >= 1000) {
            $this->fail('Year-end event limit reached.');
        } $last = $history === [] ? null : $history[array_key_last($history)];
        $event = new AccountingYearEndEvent(['company_id' => (int) $year->getAttribute('company_id'), 'fiscal_year_id' => (int) $year->getKey(), 'sequence' => count($history) + 1, 'event_kind' => $kind, 'actor_id' => (int) $actor->getKey(), 'journal_entry_id' => $journal, 'key_hash' => $keyHash, 'request_hash' => $requestHash, 'payload' => $payload, 'previous_hash' => $last?->getAttribute('payload_hash'), 'occurred_at' => now()->utc()->format('Y-m-d H:i:s')]);
        $event->setAttribute('payload_hash', $this->canonical->hash($this->body($event)));
        $event->save();
        $query = DB::table('fiscal_years')->where('id', $year->getKey())->where('company_id', $year->getAttribute('company_id'))->where('revision', $year->getAttribute('revision'));
        $old = $year->getAttribute('year_end_hash');
        $old === null ? $query->whereNull('year_end_hash') : $query->where('year_end_hash', $old);
        if ($query->update(['year_end_head_id' => $event->getKey(), 'year_end_hash' => $event->getAttribute('payload_hash')]) !== 1) {
            $this->fail('Fiscal-year evidence head changed.');
        }
        $year->setAttribute('year_end_head_id', $event->getKey());
        $year->setAttribute('year_end_hash', $event->getAttribute('payload_hash'));
        $year->syncOriginalAttributes(['year_end_head_id', 'year_end_hash']);

        return $event;
    }

    /** @return array<string, mixed> */
    private function body(AccountingYearEndEvent $e): array
    {
        return ['contract' => 'year-end-v1', 'company_id' => (int) $e->getAttribute('company_id'), 'year_id' => (int) $e->getAttribute('fiscal_year_id'), 'sequence' => (int) $e->getAttribute('sequence'), 'kind' => $e->getAttribute('event_kind'), 'actor_id' => (int) $e->getAttribute('actor_id'), 'journal_id' => $e->getAttribute('journal_entry_id') === null ? null : (int) $e->getAttribute('journal_entry_id'), 'key_hash' => $e->getAttribute('key_hash'), 'request_hash' => $e->getAttribute('request_hash'), 'payload' => $e->getAttribute('payload'), 'previous_hash' => $e->getAttribute('previous_hash'), 'at' => $e->getAttribute('occurred_at')->format('Y-m-d H:i:s')];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['year_end' => $message]);
    }
}
