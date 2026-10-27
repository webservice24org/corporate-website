<?php

declare(strict_types=1);

namespace App\Services\ERP\Sales;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerCreditAllocation;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\ERP\Accounting\Source\Sales\AccountingSalesCorrectionPosting;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class CustomerCreditAllocationService
{
    public function __construct(private readonly SalesCompanyAccess $access, private readonly SalesOrderService $orders, private readonly CustomerCreditLedger $ledger, private readonly SalesInvoiceBalanceService $balances, private readonly SalesCreditMath $math, private readonly AccountingSalesCorrectionPosting $finance) {}

    /** @param array<string, mixed> $input */
    public function allocate(User $actor, array $input): CustomerCreditAllocation
    {
        $data = Validator::make($input, ['company_id' => ['required', 'integer', 'min:1'], 'source_sales_invoice_id' => ['required', 'integer', 'min:1'], 'sales_invoice_id' => ['required', 'integer', 'min:1', 'different:source_sales_invoice_id'], 'amount' => ['required', 'regex:/\A[0-9]{1,16}(?:\.[0-9]{1,2})?\z/'], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u'], 'idempotency_key' => ['required', 'regex:/\A[a-zA-Z0-9_-]{16,100}\z/']])->validate();

        return DB::transaction(function () use ($actor, $data): CustomerCreditAllocation {
            $company = $this->access->authorize($actor, (int) $data['company_id'], 'erp.customer-credits.allocate', true);
            $source = SalesInvoice::query()->where('company_id', $company->getKey())->lockForUpdate()->findOrFail((int) $data['source_sales_invoice_id']);
            $target = SalesInvoice::query()->where('company_id', $company->getKey())->lockForUpdate()->findOrFail((int) $data['sales_invoice_id']);
            if ((int) $source->getAttribute('customer_id') !== (int) $target->getAttribute('customer_id') || $source->getAttribute('currency_code') !== $target->getAttribute('currency_code')) {
                $this->math->fail('Credit transfers require the same customer and currency.');
            }
            $amount = $this->math->money((string) $data['amount'], true);
            $reason = trim((string) $data['reason']);
            $hash = hash('sha256', json_encode([(int) $source->getKey(), (int) $target->getKey(), $amount, $reason], JSON_THROW_ON_ERROR));
            $existing = CustomerCreditAllocation::query()->where('company_id', $company->getKey())->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->getAttribute('reverses_allocation_id') !== null || ! hash_equals((string) $existing->getAttribute('payload_hash'), $hash)) {
                    $this->math->fail('Credit operation key conflicts with another request.');
                } $this->finance->complete($actor, (int) $company->getKey(), 'customer_credit_allocation', (int) $existing->getKey(), 'created', false);

                return $existing;
            }
            $this->balances->snapshot($source);
            $this->balances->snapshot($target);
            $state = $this->ledger->customer((int) $company->getKey(), (int) $source->getAttribute('customer_id'));
            if (! isset($state[(int) $source->getKey()], $state[(int) $target->getKey()]) || bccomp($amount, $state[(int) $source->getKey()]['available'], 2) > 0 || bccomp($amount, $state[(int) $target->getKey()]['outstanding'], 2) > 0) {
                $this->math->fail('Credit transfer exceeds available source credit or target outstanding.');
            }
            $row = CustomerCreditAllocation::query()->create(['company_id' => $company->getKey(), 'source_sales_invoice_id' => $source->getKey(), 'sales_invoice_id' => $target->getKey(), 'amount' => $amount, 'reason' => $reason, 'posted_at' => now()->toDateTimeString(), 'posted_by' => $actor->getKey(), 'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $hash]);
            $this->ledger->customer((int) $company->getKey(), (int) $source->getAttribute('customer_id'));

            $this->finance->complete($actor, (int) $company->getKey(), 'customer_credit_allocation', (int) $row->getKey(), 'created', true);

            return $row;
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function reverse(User $actor, int $id, array $input): CustomerCreditAllocation
    {
        $data = Validator::make($input, ['reason' => ['required', 'string', 'max:2000', 'regex:/\S/u'], 'idempotency_key' => ['required', 'regex:/\A[a-zA-Z0-9_-]{16,100}\z/']])->validate();

        return DB::transaction(function () use ($actor, $id, $data): CustomerCreditAllocation {
            $found = CustomerCreditAllocation::query()->whereIn('company_id', $this->orders->companies($actor, 'erp.customer-credits.reverse')->select('id'))->findOrFail($id);
            $company = $this->access->authorize($actor, (int) $found->getAttribute('company_id'), 'erp.customer-credits.reverse', true);
            $row = CustomerCreditAllocation::query()->where('company_id', $company->getKey())->lockForUpdate()->findOrFail($id);
            if ($row->getAttribute('reverses_allocation_id') !== null) {
                $this->math->fail('Reverse the original allocation only.');
            }
            $hash = hash('sha256', json_encode(['reverse', $id, trim((string) $data['reason'])], JSON_THROW_ON_ERROR));
            $existing = CustomerCreditAllocation::query()->where('reverses_allocation_id', $id)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->getAttribute('idempotency_key') !== $data['idempotency_key'] || ! hash_equals((string) $existing->getAttribute('payload_hash'), $hash)) {
                    $this->math->fail('Allocation already has a different reversal.');
                } $this->finance->complete($actor, (int) $company->getKey(), 'customer_credit_allocation', (int) $existing->getKey(), $existing->getAttribute('reverses_allocation_id') === null ? 'created' : 'reversed', false);

                return $existing;
            }
            if (CustomerCreditAllocation::query()->where('company_id', $company->getKey())->where('idempotency_key', $data['idempotency_key'])->exists()) {
                $this->math->fail('Credit operation key already used.');
            }
            $source = SalesInvoice::query()->where('company_id', $company->getKey())->findOrFail($row->getAttribute('source_sales_invoice_id'));
            $this->balances->snapshot($source);
            $this->balances->snapshot(SalesInvoice::query()->where('company_id', $company->getKey())->findOrFail($row->getAttribute('sales_invoice_id')));
            $this->ledger->customer((int) $company->getKey(), (int) $source->getAttribute('customer_id'));
            $undo = CustomerCreditAllocation::query()->create([...$row->only(['company_id', 'source_sales_invoice_id', 'sales_invoice_id', 'amount']), 'reverses_allocation_id' => $id, 'reason' => trim((string) $data['reason']), 'posted_at' => now()->toDateTimeString(), 'posted_by' => $actor->getKey(), 'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $hash]);
            $this->ledger->customer((int) $company->getKey(), (int) $source->getAttribute('customer_id'));

            $this->finance->complete($actor, (int) $company->getKey(), 'customer_credit_allocation', (int) $undo->getKey(), 'reversed', true);

            return $undo;
        }, 3);
    }

    /** @return array<int, array<string, mixed>> */
    public function read(User $actor, int $companyId, int $customerId): array
    {
        return DB::transaction(function () use ($actor, $companyId, $customerId): array {
            $company = $this->access->authorize($actor, $companyId, 'erp.customer-receivables.view');
            Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
            Customer::query()->withoutGlobalScope(SoftDeletingScope::class)->where('company_id', $companyId)->findOrFail($customerId);
            foreach (SalesInvoice::query()->where('company_id', $companyId)->where('customer_id', $customerId)->where('status', 'issued')->get() as $invoice) {
                $this->balances->snapshot($invoice);
            }

            return $this->ledger->customer($companyId, $customerId);
        }, 3);
    }
}
