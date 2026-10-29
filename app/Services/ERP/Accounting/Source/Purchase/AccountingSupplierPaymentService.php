<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Purchase;

use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingSetting;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\SupplierPaymentAuditLog;
use App\Models\SupplierPaymentTarget;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Accounting\AccountingDecimal;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Authorized native initial-payment writer; no disabled-accounting cash path and no manual-journal authority. */
final class AccountingSupplierPaymentService
{
    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly SupplierPaymentNumberService $numbers, private readonly AccountingSupplierPaymentContract $contract, private readonly AccountingSupplierPaymentIntent $intent, private readonly AccountingSupplierPaymentLedger $ledger, private readonly AccountingSupplierPaymentPosting $posting, private readonly AccountingDecimal $decimal, private readonly AccountingBusinessDate $dates) {}

    public function find(User $actor, int $id, string $permission = 'erp.supplier-payments.view'): SupplierPayment
    {
        if (! $actor->exists || ! $actor->can($permission)) {
            throw new AuthorizationException('Supplier payment permission is required.');
        }
        $query = SupplierPayment::query();
        if (! $actor->hasRole('super-admin')) {
            $query->whereIn('company_id', $actor->employee()->select('company_id'));
        }

        return $query->findOrFail($id);
    }

    /** @param array<string, mixed> $input */
    public function save(User $actor, array $input, ?int $id = null): SupplierPayment
    {
        $allowed = ['company_id', 'supplier_id', 'document_date', 'cash_account_id', 'payment_method', 'amount', 'advance_amount', 'payment_reference', 'notes', 'targets', 'revision'];
        $this->fields($input, $allowed);
        $data = Validator::make($input, ['company_id' => ['required', 'integer', 'min:1'], 'supplier_id' => ['required', 'integer', 'min:1'], 'document_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:9999-12-31'], 'cash_account_id' => ['required', 'integer', 'min:1'], 'payment_method' => ['required', 'in:cash,bank_transfer,cheque,other'], 'amount' => ['required'], 'advance_amount' => ['required'], 'payment_reference' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:4000'], 'targets' => ['present', 'array', 'max:100'], 'targets.*' => ['array:supplier_bill_id,amount'], 'targets.*.supplier_bill_id' => ['required', 'integer', 'min:1', 'distinct'], 'targets.*.amount' => ['required'], 'revision' => [$id === null ? 'nullable' : 'required', 'integer', 'min:1']])->validate();
        $targets = [];
        foreach ($data['targets'] as $target) {
            $targets[] = ['supplier_bill_id' => (int) $target['supplier_bill_id'], 'amount' => $target['amount']];
        } $split = $this->contract->initialSplit($data['amount'], $targets, $data['advance_amount']);

        return DB::transaction(function () use ($actor, $data, $split, $id): SupplierPayment {
            $permission = $id === null ? 'erp.supplier-payments.create' : 'erp.supplier-payments.edit';
            $company = $this->scope($actor, (int) $data['company_id'], $permission);
            $setting = $this->setting($company);
            $this->writable($setting);
            $row = $id === null ? null : $this->find($actor, $id, $permission);
            if ($row !== null) {
                $row = SupplierPayment::query()->where('company_id', $company->getKey())->lockForUpdate()->findOrFail((int) $row->getKey());
                $this->state($row, (int) $data['revision'], 'draft');
                if ((int) $row->getAttribute('supplier_id') !== (int) $data['supplier_id'] || $row->getAttribute('document_date')->format('Y-m-d') !== $data['document_date']) {
                    $this->fail('Numbered supplier/date identity cannot change.');
                }
            }
            if ($data['document_date'] > $this->dates->today($company)) {
                $this->fail('A payment cannot have a future company business date.');
            }
            $supplier = Supplier::query()->where('company_id', $company->getKey())->where('currency_code', $company->getAttribute('currency_code'))->lockForUpdate()->findOrFail((int) $data['supplier_id']);
            $this->supplierAdvance($supplier, $split['advance']);
            $this->cash($company, (int) $data['cash_account_id'], $data['payment_method']);
            $before = $row === null ? null : $this->auditSnapshot($row);
            $prepared = [];
            foreach ($split['targets'] as $target) {
                $balance = $this->ledger->bill((int) $company->getKey(), (int) $supplier->getKey(), (string) $company->getAttribute('currency_code'), $target['supplier_bill_id'], false);
                if (bccomp($target['amount'], $balance['outstanding'], 2) > 0) {
                    $this->fail('Initial target exceeds current outstanding AP.');
                } $prepared[] = [...$target, 'supplier_payable_id' => $balance['payable_id']];
            }
            if ($row !== null) {
                foreach (SupplierPaymentTarget::query()->where('supplier_payment_id', $row->getKey())->get() as $old) {
                    $old->delete();
                }
            }
            $values = ['amount' => $split['amount'], 'advance_amount' => $split['advance'], 'cash_account_id' => (int) $data['cash_account_id'], 'payment_method' => $data['payment_method'], 'payment_reference' => $data['payment_reference'] ?? null, 'notes' => $data['notes'] ?? null, 'supplier_snapshot' => $supplier->only(['id', 'code', 'name', 'currency_code']), 'issuer_snapshot' => $company->only(['id', 'name', 'currency_code']), 'updated_by' => $actor->getKey(), 'last_edited_by' => $actor->getKey()];
            if ($row === null) {
                $row = SupplierPayment::query()->create([...$values, 'company_id' => $company->getKey(), 'supplier_id' => $supplier->getKey(), 'currency_code' => $company->getAttribute('currency_code'), 'document_date' => $data['document_date'], 'number' => $this->numbers->next((int) $company->getKey(), $data['document_date']), 'created_by' => $actor->getKey()]);
            } else {
                $row->forceFill([...$values, 'revision' => (int) $row->getAttribute('revision') + 1])->save();
            }
            foreach ($prepared as $target) {
                SupplierPaymentTarget::query()->create(['company_id' => $company->getKey(), 'supplier_payment_id' => $row->getKey(), ...$target]);
            }
            $this->intent->hash($row);
            $this->audit($actor, $row, $id === null ? 'created' : 'edited', null, $before);

            return $row;
        }, 3);
    }

    public function submit(User $actor, int $id, int $revision): SupplierPayment
    {
        return DB::transaction(function () use ($actor, $id, $revision): SupplierPayment {
            $locked = $this->locked($actor, $id, 'erp.supplier-payments.submit');
            $row = $locked['payment'];
            $this->writable($locked['setting']);
            $this->state($row, $revision, 'draft');
            $before = $this->auditSnapshot($row);
            $this->review($locked['company'], $row);
            $hash = $this->intent->hash($row);
            $row->forceFill(['status' => 'submitted', 'revision' => $revision + 1, 'submitted_by' => $actor->getKey(), 'submitted_at' => CarbonImmutable::now('UTC'), 'submitted_payload_hash' => $hash, 'updated_by' => $actor->getKey()])->save();
            $this->audit($actor, $row, 'submitted', null, $before);

            return $row;
        }, 3);
    }

    public function approve(User $actor, int $id, int $revision): SupplierPayment
    {
        return DB::transaction(function () use ($actor, $id, $revision): SupplierPayment {
            $locked = $this->locked($actor, $id, 'erp.supplier-payments.approve');
            $row = $locked['payment'];
            $this->writable($locked['setting']);
            $this->state($row, $revision, 'submitted');
            $before = $this->auditSnapshot($row);
            if ((int) $row->getAttribute('created_by') === (int) $actor->getKey() || (int) $row->getAttribute('last_edited_by') === (int) $actor->getKey()) {
                throw new AuthorizationException('Payment approval must differ from maker and last editor.');
            } $this->review($locked['company'], $row);
            $hash = $this->intent->hash($row);
            if ($hash !== $row->getAttribute('submitted_payload_hash')) {
                $this->fail('Submitted payment intent changed; reject and review a new draft.');
            } $row->forceFill(['status' => 'approved', 'revision' => $revision + 1, 'approved_by' => $actor->getKey(), 'approved_at' => CarbonImmutable::now('UTC'), 'approved_payload_hash' => $hash, 'updated_by' => $actor->getKey()])->save();
            $this->audit($actor, $row, 'approved', null, $before);

            return $row;
        }, 3);
    }

    public function reject(User $actor, int $id, int $revision, string $reason): SupplierPayment
    {
        $this->reason($reason);

        return DB::transaction(function () use ($actor, $id, $revision, $reason): SupplierPayment {
            $locked = $this->locked($actor, $id, 'erp.supplier-payments.approve');
            $row = $locked['payment'];
            $this->writable($locked['setting']);
            $this->state($row, $revision, 'submitted');
            $before = $this->auditSnapshot($row);
            $row->forceFill(['status' => 'draft', 'revision' => $revision + 1, 'submitted_by' => null, 'submitted_at' => null, 'submitted_payload_hash' => null, 'approved_by' => null, 'approved_at' => null, 'approved_payload_hash' => null, 'updated_by' => $actor->getKey()])->save();
            $this->audit($actor, $row, 'rejected', $reason, $before);

            return $row;
        }, 3);
    }

    public function cancel(User $actor, int $id, int $revision, string $reason): SupplierPayment
    {
        $this->reason($reason);

        return DB::transaction(function () use ($actor, $id, $revision, $reason): SupplierPayment {
            $locked = $this->locked($actor, $id, 'erp.supplier-payments.cancel');
            $row = $locked['payment'];
            $this->writable($locked['setting']);
            if (! in_array($row->getAttribute('status'), ['draft', 'submitted', 'approved'], true)) {
                $this->fail('Posted/cancelled payments cannot be cancelled.');
            } $this->revision($row, $revision);
            $before = $this->auditSnapshot($row);
            $row->forceFill(['status' => 'cancelled', 'revision' => $revision + 1, 'reason' => trim($reason), 'cancelled_by' => $actor->getKey(), 'cancelled_at' => CarbonImmutable::now('UTC'), 'updated_by' => $actor->getKey()])->save();
            $this->audit($actor, $row, 'cancelled', $reason, $before);

            return $row;
        }, 3);
    }

    public function post(User $actor, int $id, int $revision, string $key): SupplierPayment
    {
        Validator::make(['revision' => $revision, 'key' => $key], ['revision' => ['required', 'integer', 'min:1'], 'key' => ['required', 'regex:/\A[A-Za-z0-9_-]{16,64}\z/']])->validate();

        return DB::transaction(function () use ($actor, $id, $revision, $key): SupplierPayment {
            $locked = $this->locked($actor, $id, 'erp.supplier-payments.post');
            $row = $locked['payment'];
            $company = $locked['company'];
            if ($row->getAttribute('status') === 'posted') {
                if ($row->getAttribute('idempotency_key') !== $key || $row->getAttribute('payload_hash') !== $this->intent->hash($row)) {
                    $this->fail('Posting retry differs from its original key or intent.');
                } $this->posting->replay($actor, $row);

                return $row;
            }
            $this->writable($locked['setting']);
            $this->state($row, $revision, 'approved');
            if ($locked['setting'] === null || $locked['setting']->getAttribute('state') !== 'enabled') {
                $this->fail('Supplier cash posting requires enabled accounting; there is no silent financial bypass.');
            }
            $hash = $this->intent->hash($row);
            if ($hash !== $row->getAttribute('approved_payload_hash') || $hash !== $row->getAttribute('submitted_payload_hash')) {
                $this->fail('Approved payment intent changed.');
            }
            if ($row->getAttribute('document_date')->format('Y-m-d') > $this->dates->today($company)) {
                $this->fail('Payment date is in the future.');
            }
            $supplier = Supplier::query()->where('company_id', $company->getKey())->where('currency_code', $row->getAttribute('currency_code'))->lockForUpdate()->findOrFail((int) $row->getAttribute('supplier_id'));
            $this->supplierAdvance($supplier, (string) $row->getAttribute('advance_amount'));
            $this->cash($company, (int) $row->getAttribute('cash_account_id'), (string) $row->getAttribute('payment_method'));
            $targets = $this->intent->targets($row);
            if ($targets !== []) {
                $this->access->authorize($actor, (int) $company->getKey(), 'erp.supplier-payments.allocate');
            } $balances = $this->targets($row, true);
            if (SupplierPaymentAllocation::query()->where('supplier_payment_id', $id)->exists()) {
                $this->fail('An unposted payment already has ledger history.');
            }
            if (SupplierPayment::query()->where('company_id', $company->getKey())->where('idempotency_key', $key)->exists()) {
                $this->fail('This company payment posting key is already in use.');
            }
            $before = $this->auditSnapshot($row);
            $instant = CarbonImmutable::now('UTC');
            $row->forceFill(['status' => 'posted', 'revision' => $revision + 1, 'posted_by' => $actor->getKey(), 'posted_at' => $instant, 'payload_hash' => $hash, 'idempotency_key' => $key, 'updated_by' => $actor->getKey()])->save();
            foreach ($targets as $index => $target) {
                SupplierPaymentAllocation::query()->create(['company_id' => $company->getKey(), 'supplier_payment_id' => $id, ...$target, 'posted_by' => $actor->getKey(), 'posted_at' => $instant, 'payload_hash' => $hash, 'idempotency_key' => $key.':post:'.$index, 'reason' => 'Approved initial bill allocation']);
                $balance = $balances[$target['supplier_bill_id']];
                DB::table('supplier_payables')->where('id', $target['supplier_payable_id'])->where('company_id', $company->getKey())->update(['paid_amount' => $this->decimal->total([$balance['paid'], $target['amount']])]);
            }
            $this->posting->record($actor, $row);
            $this->audit($actor, $row, 'posted', null, $before);

            return $row;
        }, 3);
    }

    /** @return array<int, array{payable_id: int, amount: string, paid: string, credited: string, outstanding: string}> */
    private function targets(SupplierPayment $row, bool $financial): array
    {
        $balances = [];
        foreach ($this->intent->targets($row) as $target) {
            $balance = $this->ledger->bill((int) $row->getAttribute('company_id'), (int) $row->getAttribute('supplier_id'), (string) $row->getAttribute('currency_code'), $target['supplier_bill_id'], $financial);
            if ($balance['payable_id'] !== $target['supplier_payable_id'] || bccomp($target['amount'], $balance['outstanding'], 2) > 0) {
                $this->fail('Approved target no longer matches available AP.');
            } $balances[$target['supplier_bill_id']] = $balance;
        }

        return $balances;
    }

    /** @return array{company: Company, setting: AccountingSetting|null, payment: SupplierPayment} */
    private function locked(User $actor, int $id, string $permission): array
    {
        $found = $this->find($actor, $id, $permission);
        $company = $this->scope($actor, (int) $found->getAttribute('company_id'), $permission);
        $setting = $this->setting($company);
        $payment = SupplierPayment::query()->where('company_id', $company->getKey())->lockForUpdate()->findOrFail($id);

        return compact('company', 'setting', 'payment');
    }

    private function scope(User $actor, int $company, string $permission): Company
    {
        $this->access->authorize($actor, $company, $permission);
        $row = Company::query()->lockForUpdate()->findOrFail($company);
        if ($row->getAttribute('status') !== 'active') {
            $this->fail('An active company is required.');
        }

        return $row;
    }

    private function setting(Company $company): ?AccountingSetting
    {
        return AccountingSetting::query()->where('company_id', $company->getKey())->lockForUpdate()->first();
    }

    private function writable(?AccountingSetting $setting): void
    {
        if ($setting !== null && $setting->getAttribute('cutover_locked_at') !== null) {
            $this->fail('Payments are locked for cutover.');
        }
    }

    private function cash(Company $company, int $id, string $method): void
    {
        $cash = AccountingCashAccount::query()->where('company_id', $company->getKey())->where('status', 'active')->where('currency_code', $company->getAttribute('currency_code'))->lockForUpdate()->find($id);
        if ($cash === null || $cash->getAttribute('kind') !== ($method === 'cash' ? 'cash' : 'bank')) {
            $this->fail('Choose an active owned payment cash/bank binding matching currency and method.');
        }
    }

    private function supplierAdvance(Supplier $supplier, string $advance): void
    {
        if ($supplier->getAttribute('status') !== 'active' && $advance !== '0.00') {
            $this->fail('An inactive supplier cannot receive a new unallocated advance.');
        }
    }

    private function state(SupplierPayment $row, int $revision, string $state): void
    {
        $this->revision($row, $revision);
        if ($row->getAttribute('status') !== $state || $row->getAttribute('reverses_payment_id') !== null) {
            $this->fail('Payment is not in the required original workflow state.');
        }
    }

    private function revision(SupplierPayment $row, int $revision): void
    {
        if ((int) $row->getAttribute('revision') !== $revision) {
            $this->fail('Payment revision changed; reload.');
        }
    }

    /** @param array<string, mixed> $input
     * @param list<string> $allowed */
    private function fields(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            $this->fail('Actors, approval, currency, state and finance are server controlled.');
        }
    }

    private function reason(string $reason): void
    {
        if (trim($reason) === '' || mb_strlen($reason) > 2000) {
            $this->fail('Provide a reason up to two thousand characters.');
        }
    }

    private function review(Company $company, SupplierPayment $row): void
    {
        if ($row->getAttribute('document_date')->format('Y-m-d') > $this->dates->today($company)) {
            $this->fail('Payment date is in the future.');
        }
        $supplier = Supplier::query()->where('company_id', $company->getKey())->where('currency_code', $row->getAttribute('currency_code'))->lockForUpdate()->findOrFail((int) $row->getAttribute('supplier_id'));
        $this->supplierAdvance($supplier, (string) $row->getAttribute('advance_amount'));
        $this->cash($company, (int) $row->getAttribute('cash_account_id'), (string) $row->getAttribute('payment_method'));
        $this->targets($row, false);
    }

    /** @return array<string, mixed> */
    private function auditSnapshot(SupplierPayment $row): array
    {
        return [...$row->only(['number', 'revision', 'status', 'amount', 'advance_amount', 'cash_account_id', 'payment_method', 'payment_reference', 'notes', 'supplier_snapshot', 'issuer_snapshot', 'last_edited_by', 'submitted_by', 'approved_by', 'posted_by', 'submitted_payload_hash', 'approved_payload_hash']), 'document_date' => $row->getAttribute('document_date')->format('Y-m-d'), 'intent_hash' => $this->intent->hash($row), 'targets' => $this->intent->targets($row)];
    }

    /** @param array<string, mixed>|null $before */
    private function audit(User $actor, SupplierPayment $row, string $action, ?string $reason, ?array $before = null): void
    {
        SupplierPaymentAuditLog::query()->create(['company_id' => $row->getAttribute('company_id'), 'supplier_payment_id' => $row->getKey(), 'actor_id' => $actor->getKey(), 'action' => $action, 'reason' => $reason === null ? null : trim($reason), 'old_values' => $before, 'new_values' => $this->auditSnapshot($row), 'created_at' => CarbonImmutable::now('UTC')]);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['supplier_payment' => $message]);
    }
}
