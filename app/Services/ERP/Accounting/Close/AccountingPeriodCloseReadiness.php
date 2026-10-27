<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Close;

use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\Books\AccountingCashBookReader;
use App\Services\ERP\Accounting\Reconciliation\AccountingBankFinalizationWorkflow;
use App\Services\ERP\Accounting\Reports\AccountingAgingReader;
use App\Services\ERP\Accounting\Reports\AccountingLedgerReader;
use App\Services\ERP\Accounting\Reports\AccountingTaxReader;
use App\Services\ERP\Accounting\Source\AccountingReconciliationCatalog;
use App\Services\ERP\Accounting\Source\AccountingSourceReconciliation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccountingPeriodCloseReadiness
{
    public const REQUIRED = ['erp.trial-balance.view', 'erp.ar-aging.view', 'erp.ap-aging.view', 'erp.tax-reconciliation.view', 'erp.cash-books.view', 'erp.accounting-reports.view', 'erp.accounting-mappings.view'];

    public function __construct(private readonly AccountingBusinessDate $dates, private readonly AccountingMappingService $mappings, private readonly AccountingLedgerReader $ledger, private readonly AccountingAgingReader $aging, private readonly AccountingTaxReader $tax, private readonly AccountingCashBookReader $books, private readonly AccountingSourceReconciliation $sources, private readonly AccountingReconciliationCatalog $catalog, private readonly AccountingBankFinalizationWorkflow $banks, private readonly AccountingPeriodCloseFingerprint $fingerprint, private readonly AccountingCanonicalPayload $canonical, private readonly AccountingPeriodCloseScan $scan) {}

    /** Caller owns company/year/period locks. No writes. @return array<string, mixed> */
    public function capture(User $actor, AccountingPeriod $period): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Close capture requires retained company and calendar locks.');
        }
        $id = (int) $period->getAttribute('company_id');
        $owner = Company::query()->findOrFail($id);
        $from = $period->getAttribute('starts_on')->format('Y-m-d');
        $to = $period->getAttribute('ends_on')->format('Y-m-d');
        $checks = [];
        $inventory = ['inventory' => null, 'dispatched_inventory' => null];
        $calendar = DB::table('accounting_periods')->where('company_id', $id)->whereDate('starts_on', '<=', $to)->whereDate('ends_on', '>=', $from)->count() === 1 && DB::table('fiscal_years')->where('company_id', $id)->whereDate('starts_on', '<=', $to)->whereDate('ends_on', '>=', $from)->count() === 1;
        $checks[] = $this->check('calendar_unambiguous', $calendar, 'Exactly one contained period and fiscal year must cover this close window.');
        $checks[] = $this->check('period_ended', $to < $this->dates->today($owner), 'Period must have ended before today in company time.');
        $setting = DB::table('accounting_company_settings')->where('company_id', $id)->first();
        $checks[] = $this->check('accounting_enabled', $setting !== null && $setting->state === 'enabled', 'Mandatory live accounting must be enabled; this checklist does not activate accounting or validate legacy cutover.');
        $earlier = DB::table('accounting_periods')->where('company_id', $id)->where('id', '<>', $period->getKey())->whereDate('ends_on', '<', $from)->where('state', '<>', 'hard_closed')->count();
        $checks[] = $this->check('earlier_periods_closed', $earlier === 0, 'Earlier configured periods must be hard closed.', $earlier);
        $pending = DB::table('journal_entries')->where('company_id', $id)->whereNotIn('status', ['posted', 'cancelled'])->where(function ($q) use ($to): void {
            $q->whereDate('accounting_date', '<=', $to)->orWhereNull('accounting_date');
        })->count();
        $checks[] = $this->check('pending_journals', $pending === 0, 'Resolve draft, submitted, approved or failed journals through cutoff before hard close.', $pending);
        $permissionReady = true;
        foreach (self::REQUIRED as $permission) {
            if (! $actor->can($permission)) {
                $permissionReady = false;
                $checks[] = $this->check('permission:'.$permission, false, 'Separate report view permission is required: '.$permission);
            }
        }
        if ($permissionReady) {
            $tasks = [
                'mapping' => function () use ($actor, $id, $to): bool {
                    return $this->mappings->readiness($actor, $id, $to)['status'] === 'ready';
                },
                'trial_balance' => function () use ($actor, $id, $from, $to, &$inventory): bool {
                    $report = $this->ledger->trial($actor, $id, ['from' => $from, 'to' => $to]);
                    $inventory = ['inventory' => '0.00', 'dispatched_inventory' => '0.00'];
                    foreach ($report['accounts'] as $account) {
                        $role = $account['control_role'];
                        if (! in_array($role, ['inventory', 'dispatched_inventory'], true)) {
                            continue;
                        }
                        $inventory[$role] = bcadd($inventory[$role], bcsub($account['closing_debit'], $account['closing_credit'], 2), 2);
                    }

                    return $report['balanced'] === true;
                },
                'trade_ar' => fn (): bool => $this->aging->report($actor, $id, 'ar', ['as_of' => $to])['complete'] === true,
                'trade_ap' => fn (): bool => $this->aging->report($actor, $id, 'ap', ['as_of' => $to])['complete'] === true,
                'tax_accounts' => fn (): bool => $this->tax->report($actor, $id, ['from' => $from, 'to' => $to])['complete'] === true,
                'source_posting' => fn (): bool => $this->allSources($actor, $id),
                'pending_native_documents' => fn (): bool => $this->nativePending($id, $to) === 0,
            ];
            foreach ($tasks as $code => $task) {
                try {
                    $checks[] = $this->check($code, $task(), 'Full '.$code.' verification must pass. Resolve exceptions in its native workspace; no overrides or repairs occur here.');
                } catch (ValidationException|ModelNotFoundException|\LogicException|\JsonException) {
                    $checks[] = $this->check($code, false, 'Evidence is missing, inconsistent, unsupported or outside the interactive limit. Open the source workspace.');
                }
            }
            $bindings = AccountingCashAccount::query()->where('company_id', $id)->orderBy('id')->limit(51)->get();
            $checks[] = $this->check('binding_limit', $bindings->count() <= 50, 'At most 50 cash/bank bindings can be verified by this interactive close contract.');
            if ($bindings->count() <= 50) {
                foreach ($bindings as $binding) {
                    $key = (int) $binding->getKey();
                    try {
                        $book = $this->books->book($actor, $id, $key, ['from' => '1900-01-01', 'to' => $to]);
                        $ok = $book['complete'] && $book['posted_totals_complete'];
                    } catch (ValidationException|ModelNotFoundException|\LogicException|\JsonException) {
                        $ok = false;
                    }
                    $checks[] = $this->check('cash_book:'.$key, $ok, 'Binding #'.$key.' posted GL and supported native evidence must verify through cutoff.');
                    if ($binding->getAttribute('kind') !== 'bank') {
                        continue;
                    }
                    $authorized = $actor->can('erp.bank-reconciliations.view') && $actor->can('erp.bank-statements.view');
                    $ok = false;
                    if ($authorized) {
                        $roots = DB::table('accounting_bank_reconciliations as r')->join('accounting_bank_statements as s', function ($q): void {
                            $q->on('s.id', '=', 'r.statement_id')->on('s.company_id', '=', 'r.company_id');
                        })->where('r.company_id', $id)->where('r.cash_account_id', $key)->whereDate('s.ends_on', $to)->limit(2)->get(['r.id']);
                        if ($roots->count() === 1) {
                            try {
                                $review = $this->banks->review($actor, (int) $roots->firstOrFail()->id, $id);
                                $ok = $review['state'] === 'finalized' && $review['technical_ready'];
                            } catch (ValidationException|ModelNotFoundException|\LogicException|\JsonException) {
                                $ok = false;
                            }
                        }
                    }
                    $checks[] = $this->check('bank_approval:'.$key, $ok, 'Bank #'.$key.' requires statement/reconciliation view and a verified independent finalization ending exactly at period cutoff. Explained timing items follow that approval; zero difference alone is insufficient.');
                }
            }
        }
        $checks[] = $this->check('inventory_gl_nonnegative', $inventory['inventory'] !== null && $inventory['dispatched_inventory'] !== null && bccomp($inventory['inventory'], '0', 2) >= 0 && bccomp($inventory['dispatched_inventory'], '0', 2) >= 0, 'Inventory control amounts must be available and nonnegative; otherwise review the trial balance and required adjustments.');
        try {
            $fingerprint = $this->fingerprint->capture($id);
        } catch (ValidationException|\JsonException) {
            $fingerprint = null;
            $checks[] = $this->check('snapshot_scope', false, 'Complete persisted evidence cannot be fingerprinted. No partial checklist can be approved.');
        }
        $ready = count(array_filter($checks, static fn (array $check): bool => ! $check['passed'])) === 0;
        $snapshot = ['contract' => 'period-close-v1', 'company_id' => $id, 'period_id' => (int) $period->getKey(), 'period_revision' => (int) $period->getAttribute('revision'), 'period_state' => (string) $period->getAttribute('state'), 'from' => $from, 'to' => $to, 'currency' => (string) $owner->getAttribute('currency_code'), 'checks' => $checks, 'inventory_gl' => $inventory, 'state_fingerprint' => $fingerprint, 'automated_ready' => $ready, 'manual_review_required' => ['inventory_valuation_and_dispatched_stock', 'required_adjustments_and_other_payables']];

        return ['snapshot' => $snapshot, 'snapshot_hash' => $this->canonical->hash($snapshot)];
    }

    private function allSources(User $actor, int $id): bool
    {
        return $this->scan->verified(fn (array $input): array => $this->sources->page($actor, $id, $input));
    }

    private function nativePending(int $company, string $to): int
    {
        $count = 0;
        foreach ($this->catalog->definitions() as $definition) {
            if ($definition['status'] === null) {
                continue;
            }
            $class = $definition['model'];
            $model = new $class;
            $query = DB::table($model->getTable())->where('company_id', $company)->whereNotIn('status', [$definition['status'], 'cancelled', 'rejected', 'voided', 'reversed']);
            $date = $definition['date'];
            if ($date !== null) {
                $query->where(function ($q) use ($date, $to): void {
                    $q->whereDate($date, '<=', $to)->orWhereNull($date);
                });
            }
            // Undated native drafts are conservative blockers rather than silently excluded.
            $count += $query->count();
        }
        $count += DB::table('supplier_payment_reversal_requests')->where('company_id', $company)->whereDate('document_date', '<=', $to)->whereNotIn('status', ['posted', 'cancelled', 'rejected'])->count();
        $count += DB::table('accounting_expenses as e')->where('e.company_id', $company)->whereNotIn('e.status', ['cancelled', 'rejected'])->whereDate('e.accounting_date', '<=', $to)->whereNotExists(function ($q) use ($to): void {
            $q->selectRaw('1')->from('accounting_expense_finance_plans as p')->whereColumn('p.company_id', 'e.company_id')->whereColumn('p.expense_id', 'e.id')->where('p.kind', 'recognition')->where('p.status', 'posted')->whereDate('p.document_date', '<=', $to);
        })->count();

        return $count;
    }

    /** @return array{code: string, passed: bool, message: string, count: int|null} */
    private function check(string $code, bool $passed, string $message, ?int $count = null): array
    {
        return compact('code', 'passed', 'message', 'count');
    }
}
