<?php

declare(strict_types=1);

namespace App\Services\ERP\Sales;

use App\Models\CustomerCreditAllocation;
use App\Models\CustomerReceipt;
use App\Models\CustomerReceiptAllocation;
use App\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;

/** Internal customer-wide settlement reader. The caller authorizes and locks the company. */
final class CustomerCreditLedger
{
    public function __construct(private readonly SalesCreditMath $math, private readonly SalesCreditNoteLedger $notes, private readonly SalesReceiptLedger $receipts) {}

    /** @return array<int, array<string, mixed>> */
    public function customer(int $companyId, int $customerId): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Customer credit requires an authorized caller transaction and company lock.');
        }
        $invoices = SalesInvoice::query()->where('company_id', $companyId)->where('customer_id', $customerId)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $state = [];
        foreach ($invoices as $invoice) {
            if (! in_array($invoice->getAttribute('status'), ['draft', 'issued', 'cancelled'], true) || (($invoice->getAttribute('status') === 'issued') !== ($invoice->getAttribute('issued_at') !== null))) {
                $this->math->fail('Invoice state requires review before allocating credit.');
            }
            if ($invoice->getAttribute('status') !== 'issued') {
                continue;
            }
            $native = $this->notes->progress($invoice)['total'];
            $paid = '0.00';
            $receiptIds = CustomerReceiptAllocation::query()->where('sales_invoice_id', $invoice->getKey())->whereNull('reverses_allocation_id')->pluck('customer_receipt_id')->unique();
            foreach ($receiptIds as $receiptId) {
                $receipt = CustomerReceipt::query()->findOrFail($receiptId);
                if ((int) $receipt->getAttribute('company_id') !== $companyId || (int) $receipt->getAttribute('customer_id') !== $customerId || $receipt->getAttribute('currency_code') !== $invoice->getAttribute('currency_code')) {
                    $this->math->fail('Cash allocation ownership requires review.');
                }
                $rs = $this->receipts->state($receipt);
                foreach (CustomerReceiptAllocation::query()->whereIn('id', $rs['active_ids'])->where('sales_invoice_id', $invoice->getKey())->get() as $a) {
                    $paid = $this->math->money(bcadd($paid, (string) $a->getAttribute('amount'), 2));
                }
            }
            $gross = $this->math->money((string) $invoice->getAttribute('grand_total'));
            if (bccomp($paid, $gross, 2) > 0 || bccomp($native, $gross, 2) > 0) {
                $this->math->fail('Cash or native credit exceeds original invoice value.');
            }
            $remaining = bcsub(bcsub($gross, $paid, 2), $native, 2);
            $capacity = bccomp($remaining, '0', 2) < 0 ? bcsub('0', $remaining, 2) : '0.00';
            $state[(int) $invoice->getKey()] = ['currency' => (string) $invoice->getAttribute('currency_code'), 'gross' => $gross, 'paid' => $paid, 'native' => $native, 'incoming' => '0.00', 'outgoing' => '0.00', 'capacity' => $capacity, 'outstanding' => bccomp($remaining, '0', 2) > 0 ? $remaining : '0.00', 'available' => $capacity];
        }
        // Query both endpoints, so foreign-company/customer links cannot disappear from validation.
        $ids = $invoices->modelKeys();
        $rows = CustomerCreditAllocation::query()->where(fn ($q) => $q->whereIn('source_sales_invoice_id', $ids)->orWhereIn('sales_invoice_id', $ids))->orderBy('id')->lockForUpdate()->get();
        foreach ($rows as $row) {
            if ($row->getAttribute('reverses_allocation_id') !== null) {
                $original = CustomerCreditAllocation::query()->findOrFail($row->getAttribute('reverses_allocation_id'));
                if ($original->getAttribute('reverses_allocation_id') !== null) {
                    $this->math->fail('Credit allocation reversal chains are unsupported.');
                }
                $this->pair($original, $row);

                continue;
            }
            $amount = $this->math->money((string) $row->getAttribute('amount'), true);
            $source = (int) $row->getAttribute('source_sales_invoice_id');
            $target = (int) $row->getAttribute('sales_invoice_id');
            if ((int) $row->getAttribute('company_id') !== $companyId || $row->getAttribute('posted_at') === null || ! isset($state[$source], $state[$target]) || $source === $target || $state[$source]['currency'] !== $state[$target]['currency']) {
                $this->math->fail('Credit allocations require different issued invoices for the same company, customer and currency.');
            }
            $undo = CustomerCreditAllocation::query()->where('reverses_allocation_id', $row->getKey())->lockForUpdate()->first();
            if ($undo !== null) {
                $this->pair($row, $undo);

                continue;
            }
            $state[$source]['outgoing'] = $this->math->money(bcadd($state[$source]['outgoing'], $amount, 2));
            $state[$target]['incoming'] = $this->math->money(bcadd($state[$target]['incoming'], $amount, 2));
        }
        foreach ($state as &$s) {
            if (bccomp($s['incoming'], $s['outstanding'], 2) > 0 || bccomp($s['outgoing'], $s['capacity'], 2) > 0) {
                $this->math->fail('Credit allocation exceeds source customer credit or target outstanding. Reverse affected credit allocations before correcting cash or issuing overlapping credits.');
            }
            $s['outstanding'] = bcsub($s['outstanding'], $s['incoming'], 2);
            $s['available'] = bcsub($s['capacity'], $s['outgoing'], 2);
        }
        unset($s);

        return $state;
    }

    private function pair(CustomerCreditAllocation $original, CustomerCreditAllocation $undo): void
    {
        foreach (['company_id', 'source_sales_invoice_id', 'sales_invoice_id', 'amount'] as $field) {
            if ($original->getAttribute($field) !== $undo->getAttribute($field)) {
                $this->math->fail('Credit allocation compensation identity or amount differs.');
            }
        }
        if ($undo->getAttribute('posted_at') === null || (int) $undo->getAttribute('reverses_allocation_id') !== (int) $original->getKey() || CustomerCreditAllocation::query()->where('reverses_allocation_id', $undo->getKey())->exists()) {
            $this->math->fail('Credit allocation reversal proof requires review.');
        }
    }
}
