<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Close;

use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\FiscalYear;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingConfigurationGuard;
use App\Services\ERP\Accounting\AccountingDecimal;
use App\Services\ERP\Accounting\AccountingPeriodStateService;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class AccountingPeriodCloseWorkflow
{
    public const VIEW = 'erp.period-close.view';

    public const PREPARE = 'erp.period-close.prepare';

    public const APPROVE = 'erp.period-close.approve';

    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingConfigurationGuard $configuration, private readonly AccountingPeriodCloseReadiness $readiness, private readonly AccountingPeriodCloseEvidence $evidence, private readonly AccountingDecimal $decimal) {}

    /** @return array<string, mixed> */
    public function page(User $actor, ?int $companyId, ?int $periodId): array
    {
        if (! $actor->can(self::VIEW)) {
            throw new AuthorizationException;
        }
        $query = Company::query();
        if (! $actor->hasRole('super-admin')) {
            $query->whereIn('id', $actor->employee()->select('company_id'));
        }
        $companies = [];
        foreach ($query->orderBy('code')->get(['id', 'code', 'name']) as $row) {
            $companies[] = ['id' => (int) $row->getKey(), 'code' => (string) $row->getAttribute('code'), 'name' => (string) $row->getAttribute('name')];
        }
        if ($companyId === null && ! $actor->hasRole('super-admin') && count($companies) === 1) {
            $companyId = $companies[0]['id'];
        }
        $company = null;
        $periods = [];
        $review = null;
        if ($companyId !== null) {
            $this->access->authorize($actor, $companyId, self::VIEW);
            $company = Company::query()->findOrFail($companyId);
            foreach (AccountingPeriod::query()->where('company_id', $companyId)->orderByDesc('starts_on')->limit(501)->get() as $row) {
                $periods[] = $this->identity($row);
            }
            if (count($periods) > 500) {
                $this->fail('Use a smaller calendar workspace; at most 500 period selectors are supported.');
            }
            if ($periodId !== null) {
                $review = DB::transaction(function () use ($actor, $companyId, $periodId): array {
                    $period = $this->locked($actor, $companyId, $periodId);

                    return $this->review($actor, $period);
                });
            }
        } elseif ($periodId !== null) {
            $this->fail('Choose the company before a period.');
        }

        return ['companies' => $companies, 'company' => $company === null ? null : ['id' => (int) $company->getKey(), 'code' => (string) $company->getAttribute('code'), 'name' => (string) $company->getAttribute('name')], 'periods' => $periods, 'review' => $review];
    }

    /** @param array<string, mixed> $input */
    public function prepare(User $actor, int $company, int $periodId, array $input): void
    {
        $data = $this->command($input, false);
        DB::transaction(function () use ($actor, $company, $periodId, $data): void {
            $period = $this->locked($actor, $company, $periodId, self::PREPARE);
            $history = $this->evidence->read($period);
            $this->expected($period, count($history), $data);
            $capture = $this->readiness->capture($actor, $period);
            if (! $capture['snapshot']['automated_ready']) {
                $this->fail('Resolve every automated blocker before preparing a review.');
            }
            $this->evidence->append($actor, $period, 'prepare', [...$capture, 'reason' => trim($data['reason'])]);
        });
    }

    /** @param array<string, mixed> $input */
    public function approve(User $actor, int $company, int $periodId, array $input): void
    {
        $data = $this->command($input, true);
        DB::transaction(function () use ($actor, $company, $periodId, $data): void {
            $period = $this->locked($actor, $company, $periodId, self::APPROVE);
            $history = $this->evidence->read($period);
            $this->expected($period, count($history), $data);
            $last = $history === [] ? null : $history[array_key_last($history)];
            if ($last === null || $last->getAttribute('event_kind') !== 'prepare') {
                $this->fail('Prepare the current checklist before independent approval.');
            }
            if ((int) $last->getAttribute('actor_id') === (int) $actor->getKey()) {
                throw new AuthorizationException('The preparer cannot approve their own period checklist.');
            }
            $capture = $this->readiness->capture($actor, $period);
            $prepared = $last->getAttribute('payload');
            if (! $capture['snapshot']['automated_ready'] || $capture['snapshot_hash'] !== $prepared['snapshot_hash']) {
                $this->fail('The checklist or financial evidence changed. Prepare a fresh review.');
            }
            $inventory = $this->decimal->money($data['inventory_amount']);
            $dispatched = $this->decimal->money($data['dispatched_amount']);
            if (bccomp($inventory, $capture['snapshot']['inventory_gl']['inventory'], 2) !== 0 || bccomp($dispatched, $capture['snapshot']['inventory_gl']['dispatched_inventory'], 2) !== 0) {
                $this->fail('The reviewed inventory valuation and dispatched-stock values must reconcile to their separate GL controls. Post any required adjustment first.');
            }
            $this->evidence->append($actor, $period, 'approve', ['preparation_id' => (int) $last->getKey(), 'preparation_hash' => $last->getAttribute('payload_hash'), 'snapshot_hash' => $capture['snapshot_hash'], 'inventory_amount' => $inventory, 'dispatched_amount' => $dispatched, 'inventory_reference' => trim($data['inventory_reference']), 'adjustments_reference' => trim($data['adjustments_reference']), 'adjustments_complete' => true, 'reason' => trim($data['reason'])]);
        });
    }

    /** Uses the same shared hard-close gate as the original calendar endpoint. @param array<string, mixed> $input */
    public function close(User $actor, int $company, int $periodId, array $input): void
    {
        $data = $this->command($input, false);
        DB::transaction(function () use ($actor, $company, $periodId, $data): void {
            $period = $this->locked($actor, $company, $periodId);
            $this->access->authorize($actor, $company, 'erp.accounting-periods.close');
            $history = $this->evidence->read($period);
            $this->expected($period, count($history), $data);
            app(AccountingPeriodStateService::class)->close($actor, $company, (int) $period->getAttribute('fiscal_year_id'), $periodId, ['state' => 'hard_closed', 'revision' => $data['revision'], 'reason' => $data['reason']]);
        });
    }

    /** Caller owns transaction. New commands require an active company/open year; reads also show closed history. */
    public function locked(User $actor, int $company, int $periodId, string $permission = self::VIEW): AccountingPeriod
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Period close requires a transaction.');
        }
        $this->access->authorize($actor, $company, self::VIEW);
        $this->access->authorize($actor, $company, $permission);
        $owner = Company::query()->whereKey($company)->lockForUpdate()->firstOrFail();
        $candidate = AccountingPeriod::query()->where('company_id', $company)->findOrFail($periodId);
        $year = FiscalYear::query()->where('company_id', $company)->lockForUpdate()->findOrFail((int) $candidate->getAttribute('fiscal_year_id'));
        $period = AccountingPeriod::query()->where('company_id', $company)->where('fiscal_year_id', $year->getKey())->lockForUpdate()->findOrFail($periodId);
        if ($period->getAttribute('starts_on')->format('Y-m-d') < $year->getAttribute('starts_on')->format('Y-m-d') || $period->getAttribute('ends_on')->format('Y-m-d') > $year->getAttribute('ends_on')->format('Y-m-d')) {
            $this->fail('Period ownership or fiscal-year containment changed.');
        }
        if ($permission !== self::VIEW) {
            $this->configuration->lockForSetup($owner);
            if ($year->getAttribute('state') !== 'open' || ! in_array($period->getAttribute('state'), ['open', 'soft_closed'], true)) {
                $this->fail('Only an open fiscal year and open/soft-closed period can prepare or approve a close.');
            }
        }

        return $period;
    }

    /** @return array<string, mixed> */
    private function review(User $actor, AccountingPeriod $period): array
    {
        $history = $this->evidence->read($period);
        $last = $history === [] ? null : $history[array_key_last($history)];
        $capture = $this->readiness->capture($actor, $period);
        $snapshot = $capture['snapshot'];
        $kind = $last === null ? 'none' : (string) $last->getAttribute('event_kind');
        $fresh = false;
        if ($last !== null && in_array($kind, ['prepare', 'approve'], true)) {
            $fresh = $last->getAttribute('payload')['snapshot_hash'] === $capture['snapshot_hash'];
        }
        $year = FiscalYear::query()->where('company_id', $period->getAttribute('company_id'))->findOrFail((int) $period->getAttribute('fiscal_year_id'));
        $writable = $year->getAttribute('state') === 'open' && in_array($period->getAttribute('state'), ['open', 'soft_closed'], true);
        $events = [];
        foreach (array_slice(array_reverse($history), 0, 50) as $row) {
            $events[] = ['id' => (int) $row->getKey(), 'kind' => (string) $row->getAttribute('event_kind'), 'actor_id' => (int) $row->getAttribute('actor_id'), 'at' => $row->getAttribute('occurred_at')->format(DATE_ATOM)];
        }
        $approval = null;
        $mayReadApproval = true;
        foreach (AccountingPeriodCloseReadiness::REQUIRED as $permission) {
            if (! $actor->can($permission)) {
                $mayReadApproval = false;
            }
        }
        foreach (array_reverse($history) as $event) {
            if (! $mayReadApproval || $event->getAttribute('event_kind') !== 'approve') {
                continue;
            }
            $body = $event->getAttribute('payload');
            $approval = ['actorId' => (int) $event->getAttribute('actor_id'), 'at' => $event->getAttribute('occurred_at')->format(DATE_ATOM), 'inventoryAmount' => $body['inventory_amount'], 'dispatchedAmount' => $body['dispatched_amount'], 'inventoryReference' => $body['inventory_reference'], 'adjustmentsReference' => $body['adjustments_reference'], 'current' => $kind === 'approve' && $fresh];
            break;
        }

        return ['approvedReview' => $approval, 'period' => $this->identity($period), 'sequence' => count($history), 'checks' => $snapshot['checks'], 'inventoryGl' => $snapshot['inventory_gl'], 'currency' => $snapshot['currency'], 'automatedReady' => $snapshot['automated_ready'], 'latestKind' => $kind, 'fresh' => $fresh, 'events' => $events, 'historyTruncated' => count($history) > 50, 'canPrepare' => $writable && $snapshot['automated_ready'] && $actor->can(self::PREPARE), 'canApprove' => $writable && $snapshot['automated_ready'] && $kind === 'prepare' && $fresh && (int) $last->getAttribute('actor_id') !== (int) $actor->getKey() && $actor->can(self::APPROVE), 'canClose' => $writable && $snapshot['automated_ready'] && $kind === 'approve' && $fresh && $actor->can('erp.accounting-periods.close'), 'calendarUrl' => $actor->can('erp.fiscal-years.view') && $actor->can('erp.accounting-periods.view') ? '/erp/accounting/calendar/'.$period->getAttribute('fiscal_year_id') : null];
    }

    /** @return array<string, mixed> */
    private function identity(AccountingPeriod $p): array
    {
        return ['id' => (int) $p->getKey(), 'year_id' => (int) $p->getAttribute('fiscal_year_id'), 'code' => (string) $p->getAttribute('code'), 'state' => (string) $p->getAttribute('state'), 'revision' => (int) $p->getAttribute('revision'), 'from' => $p->getAttribute('starts_on')->format('Y-m-d'), 'to' => $p->getAttribute('ends_on')->format('Y-m-d')];
    }

    /** @param array<string, mixed> $data */
    private function expected(AccountingPeriod $p, int $sequence, array $data): void
    {
        if ((int) $data['revision'] !== (int) $p->getAttribute('revision') || (int) $data['sequence'] !== $sequence) {
            $this->fail('Period revision or checklist sequence changed. Reload before continuing.');
        }
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function command(array $input, bool $approval): array
    {
        $rules = ['revision' => ['required', 'integer', 'min:1'], 'sequence' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u']];
        if ($approval) {
            $rules += ['inventory_amount' => ['required', 'string', 'max:30'], 'dispatched_amount' => ['required', 'string', 'max:30'], 'inventory_reference' => ['required', 'string', 'max:500', 'regex:/\S/u'], 'adjustments_reference' => ['required', 'string', 'max:500', 'regex:/\S/u'], 'adjustments_complete' => ['accepted']];
        }
        if (array_diff(array_keys($input), array_keys($rules)) !== []) {
            $this->fail('Unsupported checklist command fields. Server snapshots and approval identity cannot be supplied.');
        }

        return Validator::make($input, $rules)->validate();
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['period_close' => $message]);
    }
}
