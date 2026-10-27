<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Close;

use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\AccountingPeriodCloseEvent;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccountingPeriodCloseEvidence
{
    public function __construct(private readonly AccountingCanonicalPayload $canonical) {}

    /** Caller retains company and period locks. @return list<AccountingPeriodCloseEvent> */
    public function read(AccountingPeriod $period): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Period evidence requires a company transaction.');
        }
        $rows = AccountingPeriodCloseEvent::query()->where('company_id', $period->getAttribute('company_id'))->where('period_id', $period->getKey())->orderBy('sequence')->limit(1001)->get();
        if ($rows->count() > 1000) {
            $this->fail('Period history exceeds the interactive verification limit.');
        }
        $previous = null;
        $sequence = 0;
        $result = [];
        foreach ($rows as $row) {
            $p = $row->getAttribute('payload');
            $sequence++;
            if (! is_array($p) || (int) $row->getAttribute('sequence') !== $sequence || $row->getAttribute('previous_hash') !== $previous || ! in_array($row->getAttribute('event_kind'), ['prepare', 'approve', 'hard_close'], true) || $this->canonical->hash($this->body($row)) !== $row->getAttribute('payload_hash')) {
                $this->fail('Period close history requires review. No history is rebuilt.');
            }
            if ($row->getAttribute('event_kind') === 'prepare') {
                if (($p['snapshot']['period_id'] ?? null) !== (int) $period->getKey() || ($p['snapshot']['company_id'] ?? null) !== (int) $period->getAttribute('company_id') || ($p['snapshot_hash'] ?? null) !== $this->canonical->hash($p['snapshot'])) {
                    $this->fail('Prepared checklist identity or snapshot changed.');
                }
            } elseif ($row->getAttribute('event_kind') === 'approve') {
                $prior = $result === [] ? null : $result[array_key_last($result)];
                if ($prior === null || $prior->getAttribute('event_kind') !== 'prepare' || ($p['preparation_id'] ?? null) !== (int) $prior->getKey() || ($p['preparation_hash'] ?? null) !== $prior->getAttribute('payload_hash') || (int) $row->getAttribute('actor_id') === (int) $prior->getAttribute('actor_id') || ($p['snapshot_hash'] ?? null) !== $prior->getAttribute('payload')['snapshot_hash']) {
                    $this->fail('Checklist approval must refer to the immediately preceding independent preparation.');
                }
            } else {
                $prior = $result === [] ? null : $result[array_key_last($result)];
                if ($prior === null || $prior->getAttribute('event_kind') !== 'approve' || ($p['approval_id'] ?? null) !== (int) $prior->getKey() || ($p['approval_hash'] ?? null) !== $prior->getAttribute('payload_hash')) {
                    $this->fail('Hard-close evidence must consume its recorded approval.');
                }
            }
            $previous = (string) $row->getAttribute('payload_hash');
            $result[] = $row;
        }
        $head = $result === [] ? null : $result[array_key_last($result)];
        if (($head === null ? null : (int) $head->getKey()) !== ($period->getAttribute('close_evidence_head_id') === null ? null : (int) $period->getAttribute('close_evidence_head_id')) || $previous !== $period->getAttribute('close_evidence_hash')) {
            $this->fail('Period close history head is missing, truncated or changed.');
        }
        // A state change must have its matching event; direct state edits cannot create close authority.
        if ($period->getAttribute('state') === 'hard_closed' && ($result === [] || $result[array_key_last($result)]->getAttribute('event_kind') !== 'hard_close' || $result[array_key_last($result)]->getAttribute('payload')['closed_revision'] !== (int) $period->getAttribute('revision'))) {
            $this->fail('Hard-closed period has no matching close evidence. Legacy closures require separate review.');
        }

        return $result;
    }

    /** @param array<string, mixed> $payload */
    public function append(User $actor, AccountingPeriod $period, string $kind, array $payload): AccountingPeriodCloseEvent
    {
        $history = $this->read($period);
        $last = $history === [] ? null : $history[array_key_last($history)];
        if (count($history) >= 1000) {
            $this->fail('Period history limit reached.');
        }
        $row = new AccountingPeriodCloseEvent(['company_id' => (int) $period->getAttribute('company_id'), 'period_id' => (int) $period->getKey(), 'sequence' => count($history) + 1, 'event_kind' => $kind, 'actor_id' => (int) $actor->getKey(), 'payload' => $payload, 'previous_hash' => $last?->getAttribute('payload_hash'), 'occurred_at' => now()->utc()->format('Y-m-d H:i:s')]);
        $row->setAttribute('payload_hash', $this->canonical->hash($this->body($row)));
        $row->save();
        $query = DB::table('accounting_periods')->where('id', $period->getKey())->where('company_id', $period->getAttribute('company_id'))->where('revision', $period->getAttribute('revision'));
        $prior = $period->getAttribute('close_evidence_hash');
        $prior === null ? $query->whereNull('close_evidence_hash') : $query->where('close_evidence_hash', $prior);
        if ($query->update(['close_evidence_head_id' => $row->getKey(), 'close_evidence_hash' => $row->getAttribute('payload_hash')]) !== 1) {
            $this->fail('Period evidence head changed.');
        }
        $period->setAttribute('close_evidence_head_id', $row->getKey());
        $period->setAttribute('close_evidence_hash', $row->getAttribute('payload_hash'));
        $period->syncOriginalAttributes(['close_evidence_head_id', 'close_evidence_hash']);

        return $row;
    }

    /** @return array<string, mixed> */
    private function body(AccountingPeriodCloseEvent $row): array
    {
        return ['contract' => 'period-close-v1', 'company_id' => (int) $row->getAttribute('company_id'), 'period_id' => (int) $row->getAttribute('period_id'), 'sequence' => (int) $row->getAttribute('sequence'), 'event_kind' => $row->getAttribute('event_kind'), 'actor_id' => (int) $row->getAttribute('actor_id'), 'payload' => $row->getAttribute('payload'), 'previous_hash' => $row->getAttribute('previous_hash'), 'occurred_at' => $row->getAttribute('occurred_at')->format('Y-m-d H:i:s')];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['period_close' => $message]);
    }
}
