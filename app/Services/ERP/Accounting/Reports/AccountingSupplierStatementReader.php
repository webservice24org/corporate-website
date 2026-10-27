<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reports;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingAccountMapping;
use App\Models\Accounting\AccountingPurchaseEvent;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\AccountingSupplierCredit;
use App\Models\Accounting\JournalEntry;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Accounting\AccountingDecimal;
use App\Services\ERP\Accounting\Source\Purchase\AccountingPurchaseEvidence;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentEvidence;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentLedger;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentPosting;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Live-recognized supplier read models. Every balance is dated; unsupported source/opening history stays in review. */
final class AccountingSupplierStatementReader
{
    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingBusinessDate $dates, private readonly AccountingDecimal $decimal, private readonly AccountingSupplierStatementContract $contract, private readonly AccountingSupplierGlReconciliation $gl, private readonly AccountingPurchaseEvidence $purchase, private readonly AccountingSupplierPaymentEvidence $payments, private readonly AccountingSupplierPaymentPosting $posting, private readonly AccountingSupplierPaymentLedger $ledger) {}

    /** Internal company-wide source snapshot for dated AP aging; no statement/export authority implied.
     * @return array<string, mixed>
     */
    public function agingSource(User $actor, int $companyId): array
    {
        $this->access->authorize($actor, $companyId, 'erp.ap-aging.view');

        return DB::transaction(function () use ($actor, $companyId): array {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $companyId, 'erp.ap-aging.view');
            foreach (['supplier_bills', 'accounting_supplier_credits', 'supplier_payments', 'supplier_payment_allocations', 'accounting_purchase_events'] as $table) {
                if (DB::table($table)->where('company_id', $companyId)->count() > 10000) {
                    throw ValidationException::withMessages(['aging' => 'AP source history exceeds its interactive capacity; no partial totals are returned.']);
                }
            }

            return $this->collect($actor, $company, null);
        });
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function statement(User $actor, int $companyId, int $supplierId, array $input = []): array
    {
        return $this->statementProjection($actor, $companyId, $supplierId, $input, false);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function statementExport(User $actor, int $companyId, int $supplierId, array $input = []): array
    {
        return $this->statementProjection($actor, $companyId, $supplierId, $input, true);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function statementProjection(User $actor, int $companyId, int $supplierId, array $input, bool $allRows): array
    {
        $this->access->authorize($actor, $companyId, 'erp.supplier-statements.view');

        return DB::transaction(function () use ($actor, $companyId, $supplierId, $input, $allRows): array {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $companyId, 'erp.supplier-statements.view');
            if ($allRows) {
                $this->access->authorize($actor, $companyId, 'erp.supplier-statements.export');
            } $supplier = Supplier::query()->where('company_id', $companyId)->findOrFail($supplierId);
            $data = $this->filters($company, $input, true);
            $loaded = $this->collect($actor, $company, $supplierId);
            $projection = $this->contract->project($loaded['events'], $data['from'], $data['to'], $data['page'], $data['per_page'], $allRows);
            $bridge = $this->gl->read($company, $loaded['events'], $data['to'], $supplierId);
            $complete = $loaded['exceptions'] === [] && $bridge['exceptions'] === [];

            return [...$this->meta($company), ...$projection, 'supplier' => $supplier->only(['id', 'code', 'name', 'status']), 'complete' => $complete, 'balance_status' => $complete ? 'verified_live' : 'review', 'exceptions' => [...$loaded['exceptions'], ...$bridge['exceptions']], 'reconciliation' => [...$bridge, 'reconciled' => $complete && $bridge['reconciled']]];
        }, 3);
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function payables(User $actor, int $companyId, array $input = []): array
    {
        return $this->payableProjection($actor, $companyId, $input, false);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function payablesExport(User $actor, int $companyId, array $input = []): array
    {
        return $this->payableProjection($actor, $companyId, $input, true);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function payableProjection(User $actor, int $companyId, array $input, bool $allRows): array
    {
        $this->access->authorize($actor, $companyId, 'erp.supplier-statements.view');

        return DB::transaction(function () use ($actor, $companyId, $input, $allRows): array {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $companyId, 'erp.supplier-statements.view');
            if ($allRows) {
                $this->access->authorize($actor, $companyId, 'erp.supplier-statements.export');
            } $data = $this->filters($company, $input, false);
            $supplierId = $data['supplier_id'];
            if ($supplierId !== null) {
                Supplier::query()->where('company_id', $companyId)->findOrFail($supplierId);
            } $loaded = $this->collect($actor, $company, $supplierId);
            $result = $this->billPositions($loaded, $data['as_of']);
            $all = $result['rows'];
            $total = count($all);

            return [...$this->meta($company), 'as_of' => $data['as_of'], 'due_date_basis' => 'current_native_terms', 'rows' => $allRows ? $all : array_slice($all, ($data['page'] - 1) * $data['per_page'], $data['per_page']), 'totals' => $result['totals'], 'complete' => $loaded['exceptions'] === [], 'balance_status' => $loaded['exceptions'] === [] ? 'verified_live' : 'review', 'exceptions' => $loaded['exceptions'], 'pagination' => ['current_page' => $data['page'], 'per_page' => $data['per_page'], 'last_page' => max(1, (int) ceil($total / $data['per_page'])), 'total' => $total]];
        }, 3);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function reconciliationExport(User $actor, int $companyId, array $input = []): array
    {
        $this->access->authorize($actor, $companyId, 'erp.supplier-statements.export');
        $this->access->authorize($actor, $companyId, 'erp.accounting-reports.export');

        return DB::transaction(function () use ($actor, $companyId, $input): array {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $companyId, 'erp.supplier-statements.export');
            $this->access->authorize($actor, $companyId, 'erp.accounting-reports.export');

            return $this->reconcile($actor, $companyId, $input);
        }, 3);
    }

    /** Company-wide source-to-GL gate requires both statement and accounting-report authority.
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function reconcile(User $actor, int $companyId, array $input = []): array
    {
        $this->access->authorize($actor, $companyId, 'erp.supplier-statements.view');
        $this->access->authorize($actor, $companyId, 'erp.accounting-reports.view');

        return DB::transaction(function () use ($actor, $companyId, $input): array {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $companyId, 'erp.supplier-statements.view');
            $this->access->authorize($actor, $companyId, 'erp.accounting-reports.view');
            $data = $this->filters($company, $input, false);
            if ($data['supplier_id'] !== null) {
                throw ValidationException::withMessages(['supplier_id' => 'Company control reconciliation cannot be narrowed to one supplier.']);
            }
            $loaded = $this->collect($actor, $company, null);
            $source = $this->contract->position($loaded['events'], $data['as_of']);
            $bridge = $this->gl->read($company, $loaded['events'], $data['as_of']);
            $register = $this->billPositions($loaded, $data['as_of']);
            $exceptions = [...$loaded['exceptions'], ...$bridge['exceptions']];
            if ($source['ap'] !== $register['totals']['ap_signed'] || $source['ap'] !== $bridge['source']['ap'] || $source['advance'] !== $bridge['source']['advance']) {
                $exceptions[] = $this->issue('source_position_bridge_mismatch', null, null, null);
            }

            return [...$this->meta($company), 'as_of' => $data['as_of'], 'source_position' => $source, 'payable_totals' => $register['totals'], 'reconciliation' => [...$bridge, 'reconciled' => $exceptions === [] && $bridge['reconciled']], 'complete' => $exceptions === [], 'balance_status' => $exceptions === [] && $bridge['reconciled'] ? 'reconciled_live' : 'review', 'exceptions' => $exceptions];
        }, 3);
    }

    /** @return array<string, mixed> */
    private function collect(User $actor, Company $company, ?int $supplierId): array
    {
        $companyId = (int) $company->getKey();
        $events = [];
        $issues = [];
        $bills = [];
        $ownedSuppliers = Supplier::query()->where('company_id', $companyId)->get(['id', 'code', 'name', 'status'])->keyBy('id');
        $billQuery = SupplierBill::query()->where('company_id', $companyId)->where(fn ($q) => $q->where('status', 'approved')->orWhereNotNull('approved_at'));
        if ($supplierId !== null) {
            $billQuery->where('supplier_id', $supplierId);
        }
        foreach ($billQuery->orderBy('id')->get() as $bill) {
            $id = (int) $bill->getKey();
            $party = (int) $bill->getAttribute('supplier_id');
            $recognized = false;
            $date = $bill->getAttribute('invoice_date');
            $amount = null;
            try {
                $amount = $this->decimal->money((string) $bill->getAttribute('grand_total'));
            } catch (ValidationException) { /* Keep unsafe native values out of monetary totals. */
            }
            try {
                if (! isset($ownedSuppliers[$party]) || $bill->getAttribute('status') !== 'approved' || $bill->getAttribute('currency_code') !== $company->getAttribute('currency_code')) {
                    throw new \InvalidArgumentException('Unsupported source scope/state/currency.');
                }
                $payload = $this->purchase->recognized($companyId, 'supplier_bill', $id);
                $events[] = $this->event($actor, $company, 'supplier_bill', $id, $party, $id, null, 'bill_approved', (string) $bill->getAttribute('number'), $amount, $payload);
                $recognized = true;
            } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException) {
                $issues[] = $this->issue('bill_recognition_review', $party, 'supplier_bill', $id);
            }
            $bills[$id] = ['id' => $id, 'number' => (string) $bill->getAttribute('number'), 'supplier_id' => $party, 'supplier_name' => isset($ownedSuppliers[$party]) ? (string) $ownedSuppliers[$party]->getAttribute('name') : null, 'native_amount' => $bill->getAttribute('currency_code') === $company->getAttribute('currency_code') ? $amount : null, 'native_currency' => (string) $bill->getAttribute('currency_code'), 'invoice_date' => $date instanceof DateTimeInterface ? $date->format('Y-m-d') : null, 'due_date' => $bill->getAttribute('due_date') instanceof DateTimeInterface ? $bill->getAttribute('due_date')->format('Y-m-d') : null, 'recognized' => $recognized, 'source_url' => $actor->can('erp.supplier-bills.view') ? '/erp/purchase/bills/'.$id : null];
            if ($recognized) {
                try {
                    $this->ledger->bill($companyId, $party, (string) $company->getAttribute('currency_code'), $id, true);
                } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException) {
                    $issues[] = $this->issue('payable_summary_review', $party, 'supplier_bill', $id);
                }
            }
        }
        $creditQuery = AccountingSupplierCredit::query()->where('company_id', $companyId)->where(fn ($q) => $q->where('status', 'issued')->orWhereNotNull('issued_at'));
        if ($supplierId !== null) {
            $creditQuery->whereIn('supplier_bill_id', array_keys($bills));
        }
        foreach ($creditQuery->orderBy('id')->get() as $credit) {
            $id = (int) $credit->getKey();
            $billId = (int) $credit->getAttribute('supplier_bill_id');
            $party = isset($bills[$billId]) ? $bills[$billId]['supplier_id'] : null;
            try {
                if ($party === null || $credit->getAttribute('currency_code') !== $company->getAttribute('currency_code')) {
                    throw new \InvalidArgumentException('Unsupported credit dependency/currency.');
                } $payload = $this->purchase->recognized($companyId, 'supplier_credit', $id);
                $events[] = $this->event($actor, $company, 'supplier_credit', $id, $party, $billId, null, 'credit_issued', (string) $credit->getAttribute('number'), $credit->getAttribute('amount'), $payload);
            } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException) {
                $issues[] = $this->issue('credit_recognition_review', $party, 'supplier_credit', $id);
            }
        }
        $rootQuery = SupplierPayment::query()->where('company_id', $companyId)->whereNull('reverses_payment_id')->where(fn ($q) => $q->where('status', 'posted')->orWhereNotNull('posted_at'));
        if ($supplierId !== null) {
            $rootQuery->where('supplier_id', $supplierId);
        }
        foreach ($rootQuery->orderBy('id')->get() as $root) {
            $rootId = (int) $root->getKey();
            $party = (int) $root->getAttribute('supplier_id');
            $family = [];
            try {
                if (! isset($ownedSuppliers[$party]) || $root->getAttribute('currency_code') !== $company->getAttribute('currency_code')) {
                    throw new \InvalidArgumentException('Unsupported payment scope/currency.');
                } $this->posting->verify($companyId, $rootId);
                $payload = $this->payments->recognized($companyId, 'supplier_payment', $rootId);
                $family[] = $this->event($actor, $company, 'supplier_payment', $rootId, $party, null, $rootId, 'cash_paid', (string) $root->getAttribute('number'), $root->getAttribute('amount'), $payload);
                foreach (SupplierPaymentAllocation::query()->where('supplier_payment_id', $rootId)->orderBy('id')->get() as $row) {
                    $id = (int) $row->getKey();
                    $payload = $this->payments->recognized($companyId, 'supplier_payment_allocation', $id);
                    $family[] = $this->event($actor, $company, 'supplier_payment_allocation', $id, $party, (int) $row->getAttribute('supplier_bill_id'), $rootId, $row->getAttribute('reverses_allocation_id') === null ? 'allocated' : 'allocation_compensated', 'SPA-'.$id, $row->getAttribute('amount'), $payload);
                }
                foreach (SupplierPayment::query()->where('company_id', $companyId)->where('reverses_payment_id', $rootId)->get() as $cash) {
                    $id = (int) $cash->getKey();
                    $payload = $this->payments->recognized($companyId, 'supplier_payment', $id);
                    $family[] = $this->event($actor, $company, 'supplier_payment', $id, $party, null, $rootId, 'cash_reversed', (string) $cash->getAttribute('number'), $cash->getAttribute('amount'), $payload);
                }
                $events = [...$events, ...$family];
            } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException) {
                $issues[] = $this->issue('payment_family_review', $party, 'supplier_payment', $rootId);
            }
        }
        // Orphan admissions can remain after raw native-row damage; never silently count them as new source proof.
        $classes = ['supplier_bill' => SupplierBill::class, 'supplier_credit' => AccountingSupplierCredit::class, 'supplier_payment' => SupplierPayment::class, 'supplier_payment_allocation' => SupplierPaymentAllocation::class];
        foreach (AccountingPurchaseEvent::query()->where('company_id', $companyId)->whereIn('source_kind', array_keys($classes))->get() as $admission) {
            $payload = $admission->getAttribute('payload');
            $party = is_array($payload) && is_int($payload['projection']['supplier_id'] ?? null) ? $payload['projection']['supplier_id'] : null;
            if ($supplierId !== null && $party !== null && $party !== $supplierId) {
                continue;
            } $kind = (string) $admission->getAttribute('source_kind');
            $id = (int) $admission->getAttribute('source_id');
            $class = $classes[$kind];
            if (! $class::query()->where('company_id', $companyId)->whereKey($id)->exists()) {
                $issues[] = $this->issue('orphan_admission', $party, $kind, $id);
            }
        }

        return ['events' => $events, 'exceptions' => $issues, 'bills' => $bills];
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function event(User $actor, Company $company, string $kind, int $id, int $supplier, ?int $bill, ?int $payment, string $event, string $number, mixed $amount, array $payload): array
    {
        $value = $this->decimal->money($amount);
        $companyId = (int) $company->getKey();
        $effect = AccountingSourceEffect::query()->where('company_id', $companyId)->where('source_kind', $kind)->where('source_id', $id)->firstOrFail();
        $date = $effect->getAttribute('accounting_date');
        if (! $date instanceof DateTimeInterface || $date->format('Y-m-d') !== $payload['date'] || $payload['currency'] !== $company->getAttribute('currency_code')) {
            throw new \InvalidArgumentException('Report accounting date or currency differs from immutable admission.');
        }
        $journalId = $effect->getAttribute('journal_entry_id') === null ? null : (int) $effect->getAttribute('journal_entry_id');
        if ($journalId !== null) {
            $journal = JournalEntry::query()->where('company_id', $companyId)->findOrFail($journalId);
            if ($journal->getAttribute('status') !== 'posted' || $journal->getAttribute('accounting_date')->format('Y-m-d') !== $date->format('Y-m-d')) {
                throw new \InvalidArgumentException('Journal and source accounting dates differ.');
            }
        }
        $mapping = AccountingAccountMapping::query()->where('company_id', $companyId)->where('mapping_version_id', $effect->getAttribute('mapping_version_id'))->whereIn('role', ['trade_ap', 'supplier_advances'])->pluck('account_id', 'role')->all();
        $controls = [];
        $ap = '0.00';
        $advance = '0.00';
        foreach ($payload['lines'] as $line) {
            if (! in_array($line['role'], ['trade_ap', 'supplier_advances'], true)) {
                continue;
            } $role = $line['role'];
            $account = (int) ($mapping[$role] ?? 0);
            if ($journalId === null || ! Account::query()->where('company_id', $companyId)->whereKey($account)->exists() || ($line['party_type'] ?? null) !== 'supplier' || ($line['party_id'] ?? null) !== $supplier) {
                throw new \InvalidArgumentException('Report control role/party/account identity is missing.');
            } $debit = $this->decimal->money($line['debit']);
            $credit = $this->decimal->money($line['credit']);
            $controls[] = ['role' => $role, 'account_id' => $account, 'debit' => $debit, 'credit' => $credit, 'journal_id' => $journalId];
            if ($role === 'trade_ap') {
                $ap = bcadd($ap, bcsub($credit, $debit, 2), 2);
            } else {
                $advance = bcadd($advance, bcsub($debit, $credit, 2), 2);
            }
        }
        $expected = $this->contract->effect($event, $value);
        if ($ap !== $expected['ap'] || $advance !== $expected['advance']) {
            throw new \InvalidArgumentException('Source amount and control recipe differ.');
        }
        $sourceUrl = match ($kind) {
            'supplier_bill' => $actor->can('erp.supplier-bills.view') ? '/erp/purchase/bills/'.$id : null, 'supplier_payment' => $actor->can('erp.supplier-payments.view') ? '/erp/accounting/supplier-payments/'.$id : null, 'supplier_payment_allocation' => $actor->can('erp.supplier-payments.view') ? '/erp/accounting/supplier-payments/'.$payment : null, default => null
        };

        return ['company_id' => $companyId, 'supplier_id' => $supplier, 'currency' => (string) $company->getAttribute('currency_code'), 'source_kind' => $kind, 'source_id' => $id, 'bill_id' => $bill, 'payment_id' => $payment, 'date' => $date->format('Y-m-d'), 'sequence' => (int) $effect->getKey(), 'event' => $event, 'number' => $number, 'amount' => $value, 'source_url' => $sourceUrl, 'journal_url' => $journalId !== null && $actor->can('erp.journal-entries.view') ? '/erp/accounting/journals/'.$journalId : null, 'control_lines' => $controls];
    }

    /** @param array<string, mixed> $loaded
     * @return array<string, mixed>
     */
    private function billPositions(array $loaded, string $asOf): array
    {
        $rows = [];
        $totals = ['ap_signed' => '0.00', 'open_payables' => '0.00', 'ap_debit_balances' => '0.00'];
        foreach ($loaded['bills'] as $bill) {
            $events = array_values(array_filter($loaded['events'], static fn (array $row): bool => $row['bill_id'] === $bill['id'] && $row['date'] <= $asOf));
            $approved = array_values(array_filter($events, static fn (array $row): bool => $row['event'] === 'bill_approved'));
            if ($bill['recognized'] && $approved === []) {
                continue;
            } if (! $bill['recognized'] && ($bill['invoice_date'] === null || $bill['invoice_date'] > $asOf)) {
                continue;
            }
            $position = $this->contract->position($events, $asOf);
            $credited = '0.00';
            $allocated = '0.00';
            foreach ($events as $event) {
                if ($event['event'] === 'credit_issued') {
                    $credited = bcadd($credited, $event['amount'], 2);
                } elseif ($event['event'] === 'allocated') {
                    $allocated = bcadd($allocated, $event['amount'], 2);
                } elseif ($event['event'] === 'allocation_compensated') {
                    $allocated = bcsub($allocated, $event['amount'], 2);
                }
            }
            $outstanding = $bill['recognized'] ? $position['ap'] : null;
            if ($outstanding !== null) {
                $totals['ap_signed'] = bcadd($totals['ap_signed'], $outstanding, 2);
                if (bccomp($outstanding, '0', 2) >= 0) {
                    $totals['open_payables'] = bcadd($totals['open_payables'], $outstanding, 2);
                } else {
                    $totals['ap_debit_balances'] = bcsub($totals['ap_debit_balances'], $outstanding, 2);
                }
            }
            $rows[] = [...$bill, 'credited_as_of' => $bill['recognized'] ? $credited : null, 'allocated_as_of' => $bill['recognized'] ? $allocated : null, 'outstanding_as_of' => $outstanding, 'balance_kind' => $outstanding === null ? 'review' : (bccomp($outstanding, '0', 2) < 0 ? 'debit_balance' : (bccomp($outstanding, '0', 2) === 0 ? 'settled' : 'open_payable'))];
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /** @param array<string, mixed> $input
     * @return array{from: string, to: string, as_of: string, supplier_id: int|null, page: int, per_page: int}
     */
    private function filters(Company $company, array $input, bool $statement): array
    {
        $allowed = $statement ? ['from', 'to', 'page', 'per_page'] : ['as_of', 'supplier_id', 'page', 'per_page'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw ValidationException::withMessages(['supplier_statement' => 'Unsupported reporting filter.']);
        }
        $today = $this->dates->today($company);
        $data = Validator::make($input, ['from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01'], 'to' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today, ...(! empty($input['from']) ? ['after_or_equal:from'] : [])], 'as_of' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.$today], 'supplier_id' => ['nullable', 'integer', 'min:1'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']])->validate();

        return ['from' => $data['from'] ?? '1900-01-01', 'to' => $data['to'] ?? $today, 'as_of' => $data['as_of'] ?? $today, 'supplier_id' => isset($data['supplier_id']) ? (int) $data['supplier_id'] : null, 'page' => (int) ($data['page'] ?? 1), 'per_page' => (int) ($data['per_page'] ?? 25)];
    }

    /** @return array<string, mixed> */
    private function meta(Company $company): array
    {
        return ['company' => $company->only(['id', 'code', 'name']), 'currency' => (string) $company->getAttribute('currency_code'), 'date_basis' => 'immutable_accounting_date', 'coverage_basis' => 'verified_live_sources_only', 'opening_policy' => 'legacy_openings_require_dedicated_coverage_verification'];
    }

    /** @return array<string, mixed> */
    private function issue(string $code, ?int $supplier, ?string $kind, ?int $id): array
    {
        return ['code' => $code, 'supplier_id' => $supplier, 'source_kind' => $kind, 'source_id' => $id];
    }
}
