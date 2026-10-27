<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingSetting;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\SupplierPaymentAuditLog;
use App\Models\SupplierPaymentTarget;
use App\Models\User;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentService;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Whitelisted read projections; every mutation still passes through the A3 writer. */
final class AccountingSupplierPaymentWorkspace
{
    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingSupplierPaymentService $payments, private readonly AccountingBusinessDate $dates) {}

    /** @return Builder<Company> */
    public function companies(User $actor): Builder
    {
        abort_unless($actor->exists && User::query()->whereKey($actor->getKey())->exists() && $actor->can('erp.supplier-payments.view'), 403);
        $query = Company::query();
        if (! $actor->hasRole('super-admin')) {
            $id = $actor->employee()->value('company_id');
            abort_if($id === null, 403);
            $query->whereKey($id);
        }

        return $query;
    }

    /** @return array<string, mixed> */
    public function context(User $actor, ?int $id): array
    {
        $companies = $this->companies($actor)->orderBy('name')->orderBy('id')->get(['id', 'name', 'code', 'currency_code', 'status', 'timezone']);
        if ($id !== null) {
            $this->access->authorize($actor, $id, 'erp.supplier-payments.view');
        }
        if ($id === null && $companies->count() === 1) {
            $id = (int) $companies->firstOrFail()->getKey();
        }
        $company = $id === null ? null : $companies->firstWhere('id', $id);
        $setting = $company === null ? null : AccountingSetting::query()->where('company_id', $company->getKey())->first();
        $writable = $company !== null && $company->getAttribute('status') === 'active' && ($setting === null || $setting->getAttribute('cutover_locked_at') === null);
        $issues = [];
        if ($company !== null && ! $writable) {
            $issues[] = 'This company is inactive or locked for cutover. Payments are read only.';
        }
        if ($company !== null && ($setting === null || $setting->getAttribute('state') !== 'enabled')) {
            $issues[] = 'Accounting is not enabled. Drafts and approval can be prepared; new cash posting remains blocked.';
        }

        return ['companies' => $companies->map(static fn (Company $row): array => $row->only(['id', 'name', 'code', 'currency_code', 'status']))->all(), 'company' => $company?->only(['id', 'name', 'code', 'currency_code', 'status']), 'writable' => $writable, 'postingEnabled' => $writable && $setting !== null && $setting->getAttribute('state') === 'enabled', 'canCreate' => $writable && $actor->can('erp.supplier-payments.create'), 'today' => $company === null ? null : $this->dates->today($company), 'issues' => $issues];
    }

    /** Company and optional supplier have already been authorized by this method.
     * @return array<string, mixed>
     */
    public function options(User $actor, int $companyId, ?int $supplierId = null, ?SupplierPayment $payment = null): array
    {
        $this->access->authorize($actor, $companyId, 'erp.supplier-payments.view');
        $company = Company::query()->findOrFail($companyId);
        if ($supplierId !== null) {
            Supplier::query()->where('company_id', $companyId)->where('currency_code', $company->getAttribute('currency_code'))->findOrFail($supplierId);
        }
        if ($payment !== null) {
            abort_unless((int) $payment->getAttribute('company_id') === $companyId && (int) $payment->getAttribute('supplier_id') === $supplierId, 404);
        }
        $suppliers = Supplier::query()->where('company_id', $companyId)->where('currency_code', $company->getAttribute('currency_code'))->orderBy('name')->get(['id', 'code', 'name', 'status'])->map(static fn (Supplier $row): array => ['id' => (int) $row->getKey(), ...$row->only(['code', 'name', 'status'])])->all();
        $cash = AccountingCashAccount::query()->where('company_id', $companyId)->where('currency_code', $company->getAttribute('currency_code'))->orderBy('name')->get(['id', 'name', 'role', 'kind', 'status'])->filter(static fn (AccountingCashAccount $row): bool => $row->getAttribute('status') === 'active' || ($payment !== null && (int) $payment->getAttribute('cash_account_id') === (int) $row->getKey()))->map(static fn (AccountingCashAccount $row): array => ['id' => (int) $row->getKey(), ...$row->only(['name', 'role', 'kind']), 'selectable' => $row->getAttribute('status') === 'active'])->values()->all();
        $bills = [];
        if ($supplierId !== null) {
            // Advisory balances only. Posting re-verifies the complete recognized native history under locks.
            $rows = DB::table('supplier_payables as p')->join('supplier_bills as b', function ($join): void {
                $join->on('b.id', '=', 'p.supplier_bill_id')->on('b.company_id', '=', 'p.company_id');
            })->where('p.company_id', $companyId)->where('p.supplier_id', $supplierId)->where('p.currency_code', $company->getAttribute('currency_code'))->where('b.supplier_id', $supplierId)->where('b.currency_code', $company->getAttribute('currency_code'))->where('b.status', 'approved')->orderBy('b.id')->get(['b.id', 'b.number', 'p.amount', 'p.paid_amount', 'p.credited_amount', 'p.due_date']);
            foreach ($rows as $bill) {
                $bills[] = ['id' => (int) $bill->id, 'number' => (string) $bill->number, 'amount' => bcadd((string) $bill->amount, '0', 2), 'paid' => bcadd((string) $bill->paid_amount, '0', 2), 'credited' => bcadd((string) $bill->credited_amount, '0', 2), 'outstanding' => bcsub(bcsub((string) $bill->amount, (string) $bill->paid_amount, 2), (string) $bill->credited_amount, 2), 'due_date' => $bill->due_date];
            }
        }

        return ['suppliers' => $suppliers, 'cashAccounts' => $cash, 'bills' => $bills, 'selectedSupplierId' => $supplierId];
    }

    /** @return array<string, mixed> */
    public function summary(SupplierPayment $row): array
    {
        return ['id' => (int) $row->getKey(), 'company_id' => (int) $row->getAttribute('company_id'), 'supplier_id' => (int) $row->getAttribute('supplier_id'), 'cash_account_id' => (int) $row->getAttribute('cash_account_id'), 'revision' => (int) $row->getAttribute('revision'), 'kind' => $row->getAttribute('reverses_payment_id') === null ? 'payment' : 'reversal', 'reverses_payment_id' => $row->getAttribute('reverses_payment_id') === null ? null : (int) $row->getAttribute('reverses_payment_id'), ...$row->only(['number', 'status', 'currency_code', 'payment_method', 'amount', 'advance_amount']), 'document_date' => $row->getAttribute('document_date')->format('Y-m-d')];
    }

    /** @return array<string, mixed> */
    public function detail(User $actor, int $id, bool $editing = false): array
    {
        $row = $this->payments->find($actor, $id);
        $companyId = (int) $row->getAttribute('company_id');
        $context = $this->context($actor, $companyId);
        $status = (string) $row->getAttribute('status');
        $writable = (bool) $context['writable'];
        $targets = SupplierPaymentTarget::query()->where('company_id', $companyId)->where('supplier_payment_id', $id)->orderBy('supplier_bill_id')->get()->map(static fn (SupplierPaymentTarget $target): array => ['supplier_bill_id' => (int) $target->getAttribute('supplier_bill_id'), 'amount' => (string) $target->getAttribute('amount')])->all();
        $owner = in_array((int) $actor->getKey(), [(int) $row->getAttribute('created_by'), (int) $row->getAttribute('last_edited_by')], true);
        $capabilities = ['edit' => $writable && $status === 'draft' && $actor->can('erp.supplier-payments.edit'), 'submit' => $writable && $status === 'draft' && $actor->can('erp.supplier-payments.submit'), 'approve' => $writable && $status === 'submitted' && ! $owner && $actor->can('erp.supplier-payments.approve'), 'reject' => $writable && $status === 'submitted' && $actor->can('erp.supplier-payments.approve'), 'cancel' => $writable && in_array($status, ['draft', 'submitted', 'approved'], true) && $actor->can('erp.supplier-payments.cancel'), 'post' => $context['postingEnabled'] && $status === 'approved' && $actor->can('erp.supplier-payments.post') && ($targets === [] || $actor->can('erp.supplier-payments.allocate'))];
        if ($editing) {
            abort_unless($capabilities['edit'], 403);
        }
        $supplier = Supplier::query()->where('company_id', $companyId)->find((int) $row->getAttribute('supplier_id'));
        $cash = AccountingCashAccount::query()->where('company_id', $companyId)->find((int) $row->getAttribute('cash_account_id'));
        $history = SupplierPaymentAuditLog::query()->where('company_id', $companyId)->where('supplier_payment_id', $id)->orderByDesc('id')->limit(100)->get()->map(static fn (SupplierPaymentAuditLog $event): array => ['id' => (int) $event->getKey(), 'action' => (string) $event->getAttribute('action'), 'actor_id' => (int) $event->getAttribute('actor_id'), 'reason' => $event->getAttribute('reason'), 'created_at' => self::time($event->getAttribute('created_at'))])->all();
        $allocations = SupplierPaymentAllocation::query()->where('company_id', $companyId)->where('supplier_payment_id', $id)->orderBy('id')->get()->map(static fn (SupplierPaymentAllocation $allocation): array => ['id' => (int) $allocation->getKey(), 'supplier_bill_id' => (int) $allocation->getAttribute('supplier_bill_id'), 'amount' => (string) $allocation->getAttribute('amount'), 'posted_at' => self::time($allocation->getAttribute('posted_at'))])->all();
        $workflow = [];
        foreach (['created_by', 'last_edited_by', 'submitted_by', 'approved_by', 'posted_by', 'cancelled_by'] as $field) {
            $value = $row->getAttribute($field);
            $workflow[$field] = $value === null ? null : (int) $value;
        }
        foreach (['submitted_at', 'approved_at', 'posted_at', 'cancelled_at'] as $field) {
            $workflow[$field] = self::time($row->getAttribute($field));
        }
        $billNames = SupplierBill::query()->where('company_id', $companyId)->where('supplier_id', $row->getAttribute('supplier_id'))->whereIn('id', array_unique([...array_column($targets, 'supplier_bill_id'), ...array_column($allocations, 'supplier_bill_id')]))->pluck('number', 'id')->all();

        return [...$context, ...($editing ? $this->options($actor, $companyId, (int) $row->getAttribute('supplier_id'), $row) : []), 'payment' => [...$this->summary($row), ...$row->only(['payment_reference', 'notes', 'reason'])], 'supplier' => $supplier?->only(['id', 'code', 'name', 'status']), 'cashAccount' => $cash?->only(['id', 'name', 'role', 'kind', 'status']), 'targets' => $targets, 'allocations' => $allocations, 'billNames' => (object) $billNames, 'workflow' => $workflow, 'history' => $history, 'capabilities' => $capabilities, 'postingKey' => $capabilities['post'] ? (string) Str::uuid() : null, 'canViewBills' => $actor->can('erp.supplier-bills.view')];
    }

    private static function time(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : ($value === null ? null : (string) $value);
    }
}
