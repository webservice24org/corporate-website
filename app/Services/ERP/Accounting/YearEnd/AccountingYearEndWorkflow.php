<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\YearEnd;

use App\Models\Accounting\AccountingYearEndEvent;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingConfigurationGuard;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AccountingYearEndWorkflow
{
    public const VIEW = 'erp.year-end.view';

    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingConfigurationGuard $configuration, private readonly AccountingYearEndEvidence $evidence, private readonly AccountingYearEndReadiness $readiness, private readonly AccountingYearEndJournalWriter $writer, private readonly AccountingYearEndJournalEvidence $journals, private readonly AccountingCanonicalPayload $canonical) {}

    /** @return array<string, mixed> */
    public function page(User $actor, ?int $companyId, ?int $yearId): array
    {
        if (! $actor->can(self::VIEW)) {
            throw new AuthorizationException;
        }
        $query = Company::query();
        if (! $actor->hasRole('super-admin')) {
            $query->whereIn('id', $actor->employee()->select('company_id'));
        } $companies = [];
        foreach ($query->orderBy('code')->get(['id', 'code', 'name']) as $row) {
            $companies[] = ['id' => (int) $row->getKey(), 'code' => (string) $row->getAttribute('code'), 'name' => (string) $row->getAttribute('name')];
        }
        if ($companyId === null && ! $actor->hasRole('super-admin') && count($companies) === 1) {
            $companyId = $companies[0]['id'];
        }
        $owner = null;
        $years = [];
        $review = null;
        if ($companyId !== null) {
            $this->access->authorize($actor, $companyId, self::VIEW);
            $owner = Company::query()->findOrFail($companyId);
            foreach (FiscalYear::query()->where('company_id', $companyId)->orderByDesc('starts_on')->limit(501)->get() as $year) {
                $years[] = $this->identity($year);
            }
            if (count($years) > 500) {
                $this->fail('Calendar selection exceeds the interactive limit.');
            }
            if ($yearId !== null) {
                $review = DB::transaction(function () use ($actor, $companyId, $yearId): array {
                    return $this->review($actor, $this->locked($actor, $companyId, $yearId));
                });
            }
        } elseif ($yearId !== null) {
            $this->fail('Select a company before a fiscal year.');
        }

        return ['companies' => $companies, 'company' => $owner === null ? null : ['id' => (int) $owner->getKey(), 'code' => (string) $owner->getAttribute('code'), 'name' => (string) $owner->getAttribute('name')], 'years' => $years, 'review' => $review, 'requestKey' => (string) Str::uuid()];
    }

    /** @param array<string, mixed> $input */
    public function change(User $actor, int $company, int $yearId, string $mode, string $action, array $input): AccountingYearEndEvent
    {
        if (! in_array($mode, ['close', 'reopen'], true) || ! in_array($action, ['prepare', 'approve', 'post'], true)) {
            $this->fail('Unsupported year-end action.');
        }
        $permission = $action === 'post' ? 'erp.year-end.'.$mode : 'erp.year-end.'.$action;
        $rules = ['revision' => ['required', 'integer', 'min:1'], 'sequence' => ['required', 'integer', 'min:0'], 'key' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u']];
        if ($action === 'approve') {
            $rules += ['review_reference' => ['required', 'string', 'max:500', 'regex:/\S/u'], 'period_reviews_confirmed' => ['accepted']];
        }
        if (array_diff(array_keys($input), array_keys($rules)) !== []) {
            $this->fail('Journal lines, dates, target accounts, state and approval identities are server controlled.');
        }
        $data = Validator::make($input, $rules)->validate();
        $data['reason'] = trim($data['reason']);

        return DB::transaction(function () use ($actor, $company, $yearId, $mode, $action, $permission, $data): AccountingYearEndEvent {
            $year = $this->locked($actor, $company, $yearId);
            $this->access->authorize($actor, $company, $permission);
            $history = $this->evidence->read($year);
            $events = $history['events'];
            $key = hash('sha256', $data['key']);
            $request = $this->canonical->hash(['actor' => (int) $actor->getKey(), 'company' => $company, 'year' => $yearId, 'mode' => $mode, 'action' => $action, 'command' => $data]);
            $replay = AccountingYearEndEvent::query()->where('company_id', $company)->where('key_hash', $key)->first();
            if ($replay !== null) {
                if ($replay->getAttribute('request_hash') !== $request || (int) $replay->getAttribute('fiscal_year_id') !== $yearId) {
                    $this->fail('This request key already belongs to a different year-end command.');
                }
                $this->verifyOperation($replay);

                return $replay;
            }
            $this->configuration->lockForSetup(Company::query()->findOrFail($company));
            if ((int) $data['revision'] !== (int) $year->getAttribute('revision') || (int) $data['sequence'] !== count($events)) {
                $this->fail('Fiscal-year revision or evidence sequence changed. Reload.');
            }
            if (($mode === 'close' && $year->getAttribute('state') !== 'open') || ($mode === 'reopen' && $year->getAttribute('state') !== 'closed')) {
                $this->fail('The requested mode does not match the fiscal-year state.');
            }
            $last = $events === [] ? null : $events[array_key_last($events)];
            if ($action === 'prepare') {
                $capture = $this->readiness->capture($actor, $year, $mode);
                if (! $capture['snapshot']['ready']) {
                    $this->fail('Resolve every year-end blocker before preparing the plan.');
                }

                return $this->evidence->append($actor, $year, 'prepare', [...$capture, 'reason' => $data['reason']], $key, $request);
            }
            if ($action === 'approve') {
                if ($last === null || $last->getAttribute('event_kind') !== 'prepare') {
                    $this->fail('Prepare the current year-end plan before independent approval.');
                }
                if ((int) $last->getAttribute('actor_id') === (int) $actor->getKey() || ($mode === 'reopen' && (int) $year->getAttribute('closed_by') === (int) $actor->getKey())) {
                    throw new AuthorizationException('Approval must be independent of the preparer and, for reopening, the original closer.');
                }
                $capture = $this->readiness->capture($actor, $year, $mode);
                if (! $capture['snapshot']['ready'] || $capture['snapshot_hash'] !== $last->getAttribute('payload')['snapshot_hash']) {
                    $this->fail('Prepared evidence changed. Prepare the current plan again.');
                }

                return $this->evidence->append($actor, $year, 'approve', ['preparation_id' => (int) $last->getKey(), 'preparation_hash' => $last->getAttribute('payload_hash'), 'snapshot_hash' => $capture['snapshot_hash'], 'review_reference' => trim($data['review_reference']), 'period_reviews_confirmed' => true, 'reason' => $data['reason']], $key, $request);
            }
            if ($last === null || $last->getAttribute('event_kind') !== 'approve') {
                $this->fail('An independently approved current plan is required.');
            }
            if ($mode === 'reopen' && (int) $year->getAttribute('closed_by') === (int) $actor->getKey()) {
                throw new AuthorizationException('A different authorized user from the last closer must execute reopening.');
            }
            $prep = null;
            foreach ($events as $event) {
                if ((int) $event->getKey() === $last->getAttribute('payload')['preparation_id']) {
                    $prep = $event;
                }
            }
            if ($prep === null) {
                $this->fail('Approved preparation membership is missing.');
            }
            $capture = $this->readiness->capture($actor, $year, $mode);
            if (! $capture['snapshot']['ready'] || $prep->getAttribute('payload')['snapshot']['mode'] !== $mode || $capture['snapshot_hash'] !== $last->getAttribute('payload')['snapshot_hash'] || ($last->getAttribute('payload')['period_reviews_confirmed'] ?? null) !== true) {
                $this->fail('A blocker or changed evidence prevents this financial operation. Prepare and independently approve again.');
            }
            $journal = $this->writer->write($actor, $year, $prep, $last);
            $operation = $this->evidence->append($actor, $year, $mode, ['mode' => $mode, 'preparation_id' => (int) $prep->getKey(), 'approval_id' => (int) $last->getKey(), 'approval_hash' => $last->getAttribute('payload_hash'), 'journal_hash' => $journal?->getAttribute('payload_hash'), 'no_journal_reason' => $journal === null ? 'zero_nominal_balances' : null, 'year_revision' => (int) $year->getAttribute('revision') + 1, 'reason' => $data['reason']], $key, $request, $journal === null ? null : (int) $journal->getKey());
            $values = ['state' => $mode === 'close' ? 'closed' : 'open', 'revision' => (int) $year->getAttribute('revision') + 1];
            if ($mode === 'close') {
                $values += ['closed_by' => (int) $actor->getKey(), 'closed_at' => now()->utc()];
            } $year->fill($values)->save();
            $this->evidence->read($year);
            $this->verifyOperation($operation);

            return $operation;
        });
    }

    private function locked(User $actor, int $company, int $yearId): FiscalYear
    {
        $this->access->authorize($actor, $company, self::VIEW);
        Company::query()->whereKey($company)->lockForUpdate()->firstOrFail();

        return FiscalYear::query()->where('company_id', $company)->lockForUpdate()->findOrFail($yearId);
    }

    private function verifyOperation(AccountingYearEndEvent $event): void
    {
        $id = $event->getAttribute('journal_entry_id');
        if ($id !== null) {
            $journal = JournalEntry::query()->where('company_id', $event->getAttribute('company_id'))->find((int) $id);
            if ($journal === null) {
                $this->fail('Recorded year-end journal is missing. It is not recreated.');
            } $this->journals->verify($journal);
        }
    }

    /** @return array<string, mixed> */
    private function review(User $actor, FiscalYear $year): array
    {
        $history = $this->evidence->read($year);
        $events = $history['events'];
        $last = $events === [] ? null : $events[array_key_last($events)];
        $mode = $year->getAttribute('state') === 'closed' ? 'reopen' : 'close';
        $capture = $this->readiness->capture($actor, $year, $mode);
        $s = $capture['snapshot'];
        $fresh = $last !== null && in_array($last->getAttribute('event_kind'), ['prepare', 'approve'], true) && $last->getAttribute('payload')['snapshot_hash'] === $capture['snapshot_hash'];
        $kind = $last === null ? 'none' : $last->getAttribute('event_kind');
        $safe = [];
        $operations = [];
        $financial = $actor->can('erp.trial-balance.view') && $actor->can('erp.profit-loss.view');
        foreach (array_slice(array_reverse($events), 0, 50) as $event) {
            $safe[] = ['id' => (int) $event->getKey(), 'kind' => (string) $event->getAttribute('event_kind'), 'actor_id' => (int) $event->getAttribute('actor_id'), 'at' => $event->getAttribute('occurred_at')->format(DATE_ATOM)];
        }
        if ($financial) {
            foreach (array_reverse($events) as $event) {
                if (! in_array($event->getAttribute('event_kind'), ['close', 'reopen'], true)) {
                    continue;
                } if (count($operations) >= 10) {
                    break;
                } $this->verifyOperation($event);
                $prep = null;
                foreach ($events as $item) {
                    if ((int) $item->getKey() === $event->getAttribute('payload')['preparation_id']) {
                        $prep = $item;
                    }
                }
                if ($prep === null) {
                    $this->fail('Historical year-end preparation is missing.');
                } $snap = $prep->getAttribute('payload')['snapshot'];
                $operations[] = ['id' => (int) $event->getKey(), 'kind' => (string) $event->getAttribute('event_kind'), 'journal_id' => $event->getAttribute('journal_entry_id') === null ? null : (int) $event->getAttribute('journal_entry_id'), 'result' => $snap['plan']['result'], 'lines' => $snap['plan']['lines'], 'labels' => $snap['labels'], 'date' => $snap['date']];
            }
        }

        return ['approvalReference' => $last !== null && $financial && $kind === 'approve' ? (string) $last->getAttribute('payload')['review_reference'] : null, 'year' => $this->identity($year), 'mode' => $mode, 'activeFrom' => $s['active_from'], 'sequence' => count($events), 'checks' => $s['checks'], 'ready' => $s['ready'], 'fresh' => $fresh, 'latestKind' => $kind, 'currency' => $s['currency'], 'plan' => $financial ? $s['plan'] : null, 'labels' => $financial ? $s['labels'] : [], 'events' => $safe, 'operations' => $operations, 'canPrepare' => $s['ready'] && $actor->can('erp.year-end.prepare'), 'canApprove' => $last !== null && $s['ready'] && $kind === 'prepare' && $fresh && (int) $last->getAttribute('actor_id') !== (int) $actor->getKey() && ($mode !== 'reopen' || (int) $year->getAttribute('closed_by') !== (int) $actor->getKey()) && $actor->can('erp.year-end.approve'), 'canPost' => $s['ready'] && $kind === 'approve' && $fresh && ($mode !== 'reopen' || (int) $year->getAttribute('closed_by') !== (int) $actor->getKey()) && $actor->can('erp.year-end.'.$mode), 'historyTruncated' => count($events) > 50, 'operationsLimited' => count(array_filter($events, static fn (AccountingYearEndEvent $e): bool => in_array($e->getAttribute('event_kind'), ['close', 'reopen'], true))) > 10];
    }

    /** @return array<string, mixed> */
    private function identity(FiscalYear $y): array
    {
        return ['id' => (int) $y->getKey(), 'code' => (string) $y->getAttribute('code'), 'name' => (string) $y->getAttribute('name'), 'state' => (string) $y->getAttribute('state'), 'revision' => (int) $y->getAttribute('revision'), 'from' => $y->getAttribute('starts_on')->format('Y-m-d'), 'to' => $y->getAttribute('ends_on')->format('Y-m-d')];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['year_end' => $message]);
    }
}
