<?php

declare(strict_types=1);

namespace App\Services\ERP\Sales;

use App\Models\Accounting\AccountingSetting;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\CustomerReceiptAllocation;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCashAccountService;
use App\Services\ERP\Accounting\Source\Sales\AccountingReceiptPosting;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CustomerReceiptService
{
    public const METHODS = ['cash', 'bank_transfer', 'cheque', 'card', 'mobile_money', 'other'];

    public function __construct(
        private readonly SalesCompanyAccess $access,
        private readonly SalesOrderService $orders,
        private readonly SalesDocumentGuard $guard,
        private readonly SalesDocumentNumberService $numbers,
        private readonly SalesAuditService $audit,
        private readonly SalesReceiptLedger $ledger,
        private readonly SalesInvoiceBalanceService $balances,
        private readonly CustomerCreditLedger $customerCredits,
        private readonly AccountingCashAccountService $cash,
        private readonly AccountingReceiptPosting $accounting,
    ) {}

    public function find(User $actor, int $id, string $permission = 'erp.customer-receipts.view'): CustomerReceipt
    {
        $receipt = CustomerReceipt::query()->whereIn('company_id', $this->orders->companies($actor, $permission)->select('id'))->findOrFail($id);
        $this->access->authorize($actor, (int) $receipt->getAttribute('company_id'), $permission);

        return $receipt;
    }

    /** @param array<string, mixed> $input */
    public function save(User $actor, array $input, ?int $id = null): CustomerReceipt
    {
        $data = Validator::make($input, ['company_id' => ['required', 'integer', 'min:1'], 'customer_id' => ['required', 'integer', 'min:1'], 'document_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'regex:/\A[0-9]{1,16}(?:\.[0-9]{1,2})?\z/'], 'payment_method' => ['required', 'in:'.implode(',', self::METHODS)], 'cash_account_id' => ['nullable', 'integer', 'min:1'], 'payment_reference' => ['nullable', 'string', 'max:150'], 'notes' => ['nullable', 'string', 'max:5000'], 'revision' => [$id === null ? 'nullable' : 'required', 'integer', 'min:1']])->validate();

        return DB::transaction(function () use ($actor, $data, $id): CustomerReceipt {
            $permission = 'erp.customer-receipts.'.($id === null ? 'create' : 'edit');
            $company = $this->access->authorize($actor, (int) $data['company_id'], $permission, true);
            $this->access->authorize($actor, (int) $company->getKey(), 'erp.sales-customers.select', true);
            $receipt = $id === null ? null : $this->find($actor, $id, $permission);
            if ($receipt !== null) {
                $receipt = CustomerReceipt::query()->where('company_id', $company->getKey())->lockForUpdate()->findOrFail($id);
                $this->draft($receipt);
                $this->guard->assertRevision($receipt, (int) $data['revision']);
                if ((int) $receipt->getAttribute('customer_id') !== (int) $data['customer_id'] || $receipt->getAttribute('document_date')->format('Y-m-d') !== $data['document_date']) {
                    $this->fail('source', 'Customer and numbered receipt date cannot change.');
                }
            }
            $customer = Customer::query()->withoutGlobalScope(SoftDeletingScope::class)->where('company_id', $company->getKey())->lockForUpdate()->findOrFail((int) $data['customer_id']);
            if ((! $customer->getAttribute('is_active') || $customer->getAttribute('deleted_at') !== null) && ! SalesInvoice::query()->where('company_id', $company->getKey())->where('customer_id', $customer->getKey())->where('currency_code', $company->getAttribute('currency_code'))->where('status', 'issued')->whereNotNull('issued_at')->exists()) {
                $this->fail('customer_id', 'Inactive customers can only be selected to settle existing issued invoices.');
            }
            if ($data['document_date'] > now((string) $company->getAttribute('timezone'))->toDateString()) {
                $this->fail('document_date', 'Receipt date cannot be in the future.');
            }
            $amount = $this->ledger->money((string) $data['amount'], true);
            $before = $receipt === null ? [] : ['revision' => $receipt->getAttribute('revision'), 'amount' => $receipt->getAttribute('amount')];
            $cashId = $this->cash->selection($company, array_key_exists('cash_account_id', $data) ? $data['cash_account_id'] : $receipt?->getAttribute('cash_account_id'), (string) $data['payment_method'], AccountingSetting::query()->where('company_id', $company->getKey())->where('state', 'enabled')->exists());
            $attributes = ['cash_account_id' => $cashId, 'amount' => $amount, 'payment_method' => $data['payment_method'], 'payment_reference' => $data['payment_reference'] ?? null, 'notes' => $data['notes'] ?? null, 'updated_by' => $actor->getKey()];
            if ($receipt === null) {
                $receipt = CustomerReceipt::query()->create([...$attributes, 'company_id' => $company->getKey(), 'customer_id' => $customer->getKey(), 'currency_code' => $company->getAttribute('currency_code'), 'number' => $this->numbers->next((int) $company->getKey(), 'receipt', (string) $data['document_date']),
                    'document_date' => $data['document_date'], 'status' => 'draft', 'revision' => 1, 'created_by' => $actor->getKey(), 'customer_snapshot' => $customer->only(['id', 'customer_code', 'customer_type', 'name', 'business_name', 'contact_person', 'phone', 'email']), 'terms_snapshot' => ['issuer' => $company->only(['name', 'address', 'phone', 'email'])]]);
            } else {
                $receipt->forceFill([...$attributes, 'revision' => (int) $data['revision'] + 1])->save();
            }
            $this->audit->record($actor, (int) $company->getKey(), 'receipt', (int) $receipt->getKey(), $id === null ? 'create' : 'edit', $before, ['amount' => $amount, 'revision' => $receipt->getAttribute('revision')]);

            return $receipt;
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function postReceipt(User $actor, int $id, array $input): CustomerReceipt
    {
        $data = $this->operation($input);

        return DB::transaction(function () use ($actor, $id, $data): CustomerReceipt {
            $receipt = $this->locked($actor, $id, 'erp.customer-receipts.post');
            $hash = $this->hash(['post', $this->header($receipt), $data['allocations'], $data['reverse_allocation_ids']]);
            if ($receipt->getAttribute('status') === 'posted') {
                if ($receipt->getAttribute('reverses_receipt_id') !== null || $receipt->getAttribute('posted_at') === null || $receipt->getAttribute('idempotency_key') !== $data['idempotency_key'] || ! hash_equals((string) $receipt->getAttribute('payload_hash'), $hash)) {
                    $this->fail('idempotency_key', 'Receipt posting retry does not match the original request.');
                }

                $this->accounting->post($actor, $receipt, $data, $hash, false);

                return $receipt;
            }
            $this->draft($receipt);
            $this->guard->assertRevision($receipt, (int) $data['revision']);
            if ($data['reverse_allocation_ids'] !== []) {
                $this->fail('allocations', 'A draft receipt has no allocations to reverse.');
            }
            if ($data['allocations'] !== []) {
                $this->access->authorize($actor, (int) $receipt->getAttribute('company_id'), 'erp.customer-receipts.allocate', true);
            }
            $company = $this->access->authorize($actor, (int) $receipt->getAttribute('company_id'), 'erp.customer-receipts.post', true);
            if ($receipt->getAttribute('document_date')->format('Y-m-d') > now((string) $company->getAttribute('timezone'))->toDateString() || $receipt->getAttribute('currency_code') !== $company->getAttribute('currency_code')) {
                $this->fail('source', 'Review receipt date and company currency before posting.');
            }
            $this->unusedReceiptKey($receipt, (string) $data['idempotency_key']);
            $customer = Customer::query()->withoutGlobalScope(SoftDeletingScope::class)->where('company_id', $company->getKey())->lockForUpdate()->findOrFail($receipt->getAttribute('customer_id'));
            $total = '0.00';
            foreach ($data['allocations'] as $row) {
                $total = $this->ledger->money(bcadd($total, $row['amount'], 2));
            }
            if ((! $customer->getAttribute('is_active') || $customer->getAttribute('deleted_at') !== null) && $total !== $receipt->getAttribute('amount')) {
                $this->fail('allocations', 'Receipts for inactive customers must fully settle matching issued invoices; new unapplied advances are not allowed.');
            }
            if (CustomerReceiptAllocation::query()->where('customer_receipt_id', $id)->exists()) {
                $this->fail('allocations', 'A receipt draft unexpectedly already has allocation history.');
            }
            $receipt->forceFill(['status' => 'posted', 'revision' => (int) $data['revision'] + 1, 'posted_at' => now()->toDateTimeString(), 'posted_by' => $actor->getKey(), 'updated_by' => $actor->getKey(), 'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $hash])->save();
            $this->apply($actor, $receipt, [], $data['allocations'], (string) $data['idempotency_key'].':post', $hash, 'Initial receipt allocation');
            $this->audit->record($actor, (int) $company->getKey(), 'receipt', $id, 'post', ['status' => 'draft', 'revision' => $data['revision']], ['status' => 'posted', 'revision' => $receipt->getAttribute('revision'), 'initial_allocations' => $data['allocations']]);

            $this->accounting->post($actor, $receipt, $data, $hash, true);

            return $receipt;
        }, 3);
    }

    /** Later allocation/reallocation, including compensations. @param array<string, mixed> $input */
    public function allocate(User $actor, int $id, array $input): CustomerReceipt
    {
        $data = $this->operation($input, true);

        return DB::transaction(function () use ($actor, $id, $data): CustomerReceipt {
            if ($data['allocations'] === [] && $data['reverse_allocation_ids'] === []) {
                $this->fail('allocations', 'Select allocations to reverse or new invoice amounts.');
            }
            $permission = $data['allocations'] === [] ? 'erp.customer-receipts.reverse' : 'erp.customer-receipts.allocate';
            $receipt = $this->locked($actor, $id, $permission);
            if ($data['reverse_allocation_ids'] !== []) {
                $this->access->authorize($actor, (int) $receipt->getAttribute('company_id'), 'erp.customer-receipts.reverse', true);
            }
            $hash = $this->hash(['change', $id, $data['reverse_allocation_ids'], $data['allocations'], $data['reason']]);
            $first = CustomerReceiptAllocation::query()->where('company_id', $receipt->getAttribute('company_id'))->where('idempotency_key', $data['idempotency_key'].':change:0')->lockForUpdate()->first();
            if ($first !== null) {
                if ((int) $first->getAttribute('customer_receipt_id') !== $id || ! hash_equals((string) $first->getAttribute('payload_hash'), $hash)) {
                    $this->fail('idempotency_key', 'Allocation operation key conflicts with another request.');
                }

                $this->accounting->change($actor, $receipt, $data, $hash, false);

                return $receipt;
            }
            $this->guard->assertRevision($receipt, (int) $data['revision']);
            $this->spendable($receipt);
            $this->apply($actor, $receipt, $data['reverse_allocation_ids'], $data['allocations'], (string) $data['idempotency_key'].':change', $hash, (string) $data['reason']);
            $receipt->forceFill(['revision' => (int) $data['revision'] + 1, 'updated_by' => $actor->getKey()])->save();
            $action = $data['allocations'] === [] ? 'reverse' : 'allocate';
            $this->audit->record($actor, (int) $receipt->getAttribute('company_id'), 'receipt', $id, $action, ['revision' => $data['revision']], ['kind' => 'allocation_change', 'revision' => $receipt->getAttribute('revision'), 'reversed_ids' => $data['reverse_allocation_ids'], 'allocations' => $data['allocations']], (string) $data['reason']);

            $this->accounting->change($actor, $receipt, $data, $hash, true);

            return $receipt;
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function reverseAllocation(User $actor, int $id, int $allocationId, array $input): CustomerReceipt
    {
        return $this->allocate($actor, $id, [...$input, 'allocations' => [], 'reverse_allocation_ids' => [$allocationId]]);
    }

    /** @param array<string, mixed> $input */
    public function reverseReceipt(User $actor, int $id, array $input): CustomerReceipt
    {
        $data = $this->operation($input, true);

        return DB::transaction(function () use ($actor, $id, $data): CustomerReceipt {
            $receipt = $this->locked($actor, $id, 'erp.customer-receipts.reverse');
            if ($data['allocations'] !== [] || $data['reverse_allocation_ids'] !== []) {
                $this->fail('source', 'Receipt reversal derives its allocations from the posted record.');
            }
            $hash = $this->hash(['reverse_receipt', $id, $this->header($receipt), $data['reason']]);
            $existing = CustomerReceipt::query()->where('reverses_receipt_id', $id)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->getAttribute('idempotency_key') !== $data['idempotency_key'] || ! hash_equals((string) $existing->getAttribute('payload_hash'), $hash)) {
                    $this->fail('idempotency_key', 'Receipt already has a different reversal.');
                }

                $this->accounting->reverse($actor, $receipt, $existing, $data, $hash, null, false);

                return $existing;
            }
            $this->guard->assertRevision($receipt, (int) $data['revision']);
            $state = $this->spendable($receipt);
            $this->unusedReceiptKey($receipt, (string) $data['idempotency_key']);
            $company = $this->access->authorize($actor, (int) $receipt->getAttribute('company_id'), 'erp.customer-receipts.reverse', true);
            // Compensate allocation rows while the original is still a valid posted source.
            $this->apply($actor, $receipt, $state['active_ids'], [], 'rr:'.$id.':'.substr(hash('sha256', (string) $data['idempotency_key']), 0, 32), $hash, (string) $data['reason']);
            $date = now((string) $company->getAttribute('timezone'))->toDateString();
            $reversal = CustomerReceipt::query()->create([
                ...$receipt->only(['company_id', 'customer_id', 'currency_code', 'customer_snapshot', 'billing_address_snapshot', 'shipping_address_snapshot', 'amount', 'payment_method', 'payment_reference', 'cash_account_id']),
                'terms_snapshot' => [...($receipt->getAttribute('terms_snapshot') ?? []), 'reversal_source' => ['id' => $id, 'number' => $receipt->getAttribute('number')]], 'notes' => null,
                'number' => $this->numbers->next((int) $company->getKey(), 'receipt', $date), 'document_date' => $date, 'status' => 'posted', 'revision' => 1, 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(), 'posted_by' => $actor->getKey(), 'posted_at' => now()->toDateTimeString(),
                'reverses_receipt_id' => $id, 'reversal_reason' => $data['reason'], 'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $hash,
            ]);
            $receipt->forceFill(['revision' => (int) $data['revision'] + 1, 'updated_by' => $actor->getKey()])->save();
            $this->ledger->state($receipt);
            foreach ([$receipt, $reversal] as $document) {
                $this->audit->record($actor, (int) $company->getKey(), 'receipt', (int) $document->getKey(), 'reverse', [], ['kind' => 'receipt_reversal', 'original_id' => $id, 'reversal_id' => $reversal->getKey(), 'reversal_number' => $reversal->getAttribute('number')], (string) $data['reason']);
            }

            $this->accounting->reverse($actor, $receipt, $reversal, $data, $hash, $state['active_ids'], true);

            return $reversal;
        }, 3);
    }

    public function cancel(User $actor, int $id, int $revision, string $reason): CustomerReceipt
    {
        return DB::transaction(function () use ($actor, $id, $revision, $reason): CustomerReceipt {
            $receipt = $this->locked($actor, $id, 'erp.customer-receipts.cancel');
            $this->draft($receipt);
            $this->guard->assertRevision($receipt, $revision);
            if (CustomerReceiptAllocation::query()->where('customer_receipt_id', $id)->exists()) {
                $this->fail('receipt', 'A draft unexpectedly has allocation history.');
            }
            $receipt->forceFill(['status' => 'cancelled', 'revision' => $revision + 1, 'updated_by' => $actor->getKey()])->save();
            $this->audit->record($actor, (int) $receipt->getAttribute('company_id'), 'receipt', $id, 'cancel', ['status' => 'draft'], ['status' => 'cancelled', 'revision' => $revision + 1], $reason);

            return $receipt;
        }, 3);
    }

    /** @return array<string, mixed> */
    public function snapshot(User $actor, int $id): array
    {
        return DB::transaction(function () use ($actor, $id): array {
            $receipt = $this->locked($actor, $id, 'erp.customer-receipts.view', false);
            $state = $this->ledger->state($receipt);
            $this->checkBalances($receipt, $state['active_ids']);

            return $state;
        }, 3);
    }

    /** @param list<int> $reverseIds
     * @param  list<array{sales_invoice_id: int, amount: string}>  $targets
     */
    private function apply(User $actor, CustomerReceipt $receipt, array $reverseIds, array $targets, string $key, string $hash, string $reason): void
    {
        $state = $this->spendable($receipt);
        $this->checkBalances($receipt, $state['active_ids']);
        $invoiceIds = array_column($targets, 'sales_invoice_id');
        $undos = [];
        foreach ($reverseIds as $id) {
            $row = CustomerReceiptAllocation::query()->where('company_id', $receipt->getAttribute('company_id'))->where('customer_receipt_id', $receipt->getKey())->whereNull('reverses_allocation_id')->lockForUpdate()->findOrFail($id);
            if (! in_array((int) $row->getKey(), $state['active_ids'], true)) {
                $this->fail('allocations', 'Only a currently active original allocation can be reversed.');
            }
            $undos[] = $row;
            $invoiceIds[] = (int) $row->getAttribute('sales_invoice_id');
        }
        $invoices = SalesInvoice::query()->where('company_id', $receipt->getAttribute('company_id'))->whereIn('id', $invoiceIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $index = 0;
        foreach ($undos as $row) {
            $this->allocationRow($actor, $receipt, (int) $row->getAttribute('sales_invoice_id'), (string) $row->getAttribute('amount'), (int) $row->getKey(), $key.':'.$index++, $hash, $reason);
        }
        $available = $this->ledger->state($receipt)['available'];
        foreach ($targets as $target) {
            $invoice = $invoices->get($target['sales_invoice_id']);
            abort_unless($invoice instanceof SalesInvoice, 404);
            if ((int) $invoice->getAttribute('customer_id') !== (int) $receipt->getAttribute('customer_id') || $invoice->getAttribute('currency_code') !== $receipt->getAttribute('currency_code') || $invoice->getAttribute('status') !== 'issued' || $invoice->getAttribute('issued_at') === null) {
                $this->fail('allocations', 'Allocate only to same-company/customer/currency issued invoices.');
            }
            $balance = $this->balances->snapshot($invoice);
            if (bccomp($target['amount'], $available, 2) > 0 || bccomp($target['amount'], (string) $balance['outstanding'], 2) > 0) {
                $this->fail('allocations', 'Allocation exceeds available receipt funds or invoice outstanding.');
            }
            $this->allocationRow($actor, $receipt, (int) $invoice->getKey(), $target['amount'], null, $key.':'.$index++, $hash, $reason);
            $available = bcsub($available, $target['amount'], 2);
        }
        $state = $this->ledger->state($receipt);
        $this->checkBalances($receipt, $state['active_ids']);
        $this->customerCredits->customer((int) $receipt->getAttribute('company_id'), (int) $receipt->getAttribute('customer_id'));
    }

    private function allocationRow(User $actor, CustomerReceipt $receipt, int $invoiceId, string $amount, ?int $reverses, string $key, string $hash, string $reason): void
    {
        CustomerReceiptAllocation::query()->create(['company_id' => $receipt->getAttribute('company_id'), 'customer_receipt_id' => $receipt->getKey(), 'sales_invoice_id' => $invoiceId, 'amount' => $amount, 'reverses_allocation_id' => $reverses, 'idempotency_key' => $key, 'payload_hash' => $hash, 'posted_by' => $actor->getKey(), 'posted_at' => now()->toDateTimeString(), 'reason' => $reason]);
    }

    /** @param list<int> $activeIds */
    private function checkBalances(CustomerReceipt $receipt, array $activeIds): void
    {
        $ids = CustomerReceiptAllocation::query()->whereIn('id', $activeIds)->pluck('sales_invoice_id');
        foreach (SalesInvoice::query()->where('company_id', $receipt->getAttribute('company_id'))->whereIn('id', $ids)->orderBy('id')->get() as $invoice) {
            $this->balances->snapshot($invoice);
        }
    }

    /** @return array<string, mixed> */
    private function spendable(CustomerReceipt $receipt): array
    {
        $state = $this->ledger->state($receipt);
        if ($receipt->getAttribute('status') !== 'posted' || $state['reversed'] || $state['is_reversal']) {
            $this->fail('status', 'Only an unreversed original posted receipt can fund allocations or be reversed.');
        }

        return $state;
    }

    private function locked(User $actor, int $id, string $permission, bool $writing = true): CustomerReceipt
    {
        $found = $this->find($actor, $id, $permission);
        $company = $this->access->authorize($actor, (int) $found->getAttribute('company_id'), $permission, $writing);
        if (! $writing) {
            Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
        }

        return CustomerReceipt::query()->where('company_id', $company->getKey())->lockForUpdate()->findOrFail($id);
    }

    private function unusedReceiptKey(CustomerReceipt $receipt, string $key): void
    {
        if (CustomerReceipt::query()->where('company_id', $receipt->getAttribute('company_id'))->where('idempotency_key', $key)->exists()) {
            $this->fail('idempotency_key', 'This receipt operation key is already in use.');
        }
    }

    private function draft(CustomerReceipt $receipt): void
    {
        if ($receipt->getAttribute('status') !== 'draft' || $receipt->getAttribute('posted_at') !== null || $receipt->getAttribute('idempotency_key') !== null || $receipt->getAttribute('reverses_receipt_id') !== null) {
            $this->fail('status', 'Only an unposted original receipt draft can be edited, posted or cancelled.');
        }
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function operation(array $input, bool $reason = false): array
    {
        $data = Validator::make($input, ['revision' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'regex:/\A[a-zA-Z0-9_-]{16,64}\z/'], 'reason' => [$reason ? 'required' : 'nullable', 'string', 'max:2000', 'regex:/\S/u'],
            'allocations' => ['nullable', 'array', 'max:100'], 'allocations.*.sales_invoice_id' => ['required', 'integer', 'min:1', 'distinct'], 'allocations.*.amount' => ['required', 'regex:/\A[0-9]{1,16}(?:\.[0-9]{1,2})?\z/'],
            'reverse_allocation_ids' => ['nullable', 'array', 'max:100'], 'reverse_allocation_ids.*' => ['required', 'integer', 'min:1', 'distinct']])->validate();
        $targets = [];
        foreach ($data['allocations'] ?? [] as $row) {
            $targets[] = ['sales_invoice_id' => (int) $row['sales_invoice_id'], 'amount' => $this->ledger->money((string) $row['amount'], true)];
        }
        usort($targets, static fn (array $a, array $b): int => $a['sales_invoice_id'] <=> $b['sales_invoice_id']);
        $ids = array_map('intval', $data['reverse_allocation_ids'] ?? []);
        sort($ids);

        return [...$data, 'allocations' => $targets, 'reverse_allocation_ids' => $ids, 'reason' => trim((string) ($data['reason'] ?? ''))];
    }

    /** @return array<string, mixed> */
    private function header(CustomerReceipt $receipt): array
    {
        return [...$receipt->only(['id', 'number', 'company_id', 'customer_id', 'currency_code', 'amount', 'payment_method', 'payment_reference', 'notes', 'customer_snapshot', 'billing_address_snapshot', 'shipping_address_snapshot', 'terms_snapshot']), 'document_date' => $receipt->getAttribute('document_date')->format('Y-m-d')];
    }

    private function hash(mixed $value): string
    {
        $canonical = function (mixed $entry) use (&$canonical): mixed {
            if (! is_array($entry)) {
                return $entry;
            }
            if (! array_is_list($entry)) {
                ksort($entry);
            }

            return array_map($canonical, $entry);
        };

        return hash('sha256', json_encode($canonical($value), JSON_THROW_ON_ERROR));
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
