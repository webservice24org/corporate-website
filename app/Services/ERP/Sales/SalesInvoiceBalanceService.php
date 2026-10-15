<?php

declare(strict_types=1);

namespace App\Services\ERP\Sales;

use App\Models\Company;
use App\Models\SalesDelivery;
use App\Models\SalesDeliveryItem;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Internal reader. Caller has authorized the invoice/company; no settlement writes here. */
final class SalesInvoiceBalanceService
{
    public function __construct(private readonly SalesCreditExposureService $money, private readonly SalesQuotationMath $math, private readonly CustomerCreditLedger $customerCredits) {}

    /** @param array<array-key, int|string> $companyIds */
    public function assertStates(array $companyIds): void
    {
        $invalid = SalesInvoice::query()->whereIn('company_id', $companyIds)->where(function ($query): void {
            $query->whereNotIn('status', ['draft', 'issued', 'cancelled'])
                ->orWhere(fn ($q) => $q->where('status', 'issued')->whereNull('issued_at'))
                ->orWhere(fn ($q) => $q->where('status', '<>', 'issued')->whereNotNull('issued_at'));
        })->exists();
        if ($invalid) {
            $this->fail('Invoice issuance states require review before receivables can be read.');
        }
    }

    /** @return array<string, mixed> */
    public function snapshot(SalesInvoice $invoice): array
    {
        return DB::transaction(function () use ($invoice): array {
            $company = Company::query()->lockForUpdate()->findOrFail($invoice->getAttribute('company_id'));
            $invoice = SalesInvoice::query()->where('company_id', $company->getKey())->lockForUpdate()->findOrFail($invoice->getKey());
            if ($invoice->getAttribute('status') !== 'issued' || $invoice->getAttribute('issued_at') === null) {
                $this->fail('Only issued invoices create receivables.');
            }
            $order = SalesOrder::query()->where('company_id', $company->getKey())->findOrFail($invoice->getAttribute('sales_order_id'));
            if ($order->getAttribute('confirmed_at') === null || (int) $order->getAttribute('customer_id') !== (int) $invoice->getAttribute('customer_id') || $order->getAttribute('currency_code') !== $invoice->getAttribute('currency_code')) {
                $this->fail('Issued invoice party/order references require review.');
            }
            $items = SalesInvoiceItem::query()->where('sales_invoice_id', $invoice->getKey())->lockForUpdate()->get();
            if ($items->isEmpty()) {
                $this->fail('An issued invoice has no fulfillment lines.');
            }
            $totals = ['subtotal' => '0.00', 'discount_total' => '0.00', 'tax_total' => '0.00', 'grand_total' => '0.00'];
            foreach ($items as $item) {
                $di = SalesDeliveryItem::query()->findOrFail($item->getAttribute('sales_delivery_item_id'));
                $delivery = SalesDelivery::query()->where('company_id', $company->getKey())->where('sales_order_id', $order->getKey())->findOrFail($di->getAttribute('sales_delivery_id'));
                $line = SalesOrderItem::query()->where('sales_order_id', $order->getKey())->findOrFail($di->getAttribute('sales_order_item_id'));
                if ($delivery->getAttribute('status') !== 'posted' || $delivery->getAttribute('posted_at') === null || (int) $delivery->getAttribute('customer_id') !== (int) $invoice->getAttribute('customer_id') || $delivery->getAttribute('currency_code') !== $invoice->getAttribute('currency_code')
                    || bccomp((string) $item->getAttribute('quantity'), '0', 4) <= 0 || bccomp((string) $item->getAttribute('quantity'), (string) $di->getAttribute('invoiced_quantity'), 4) > 0 || bccomp((string) $item->getAttribute('quantity'), (string) $line->getAttribute('invoiced_quantity'), 4) > 0
                    || bccomp((string) $item->getAttribute('discount_amount'), (string) $item->getAttribute('subtotal'), 2) > 0 || bccomp(bcadd(bcsub((string) $item->getAttribute('subtotal'), (string) $item->getAttribute('discount_amount'), 2), (string) $item->getAttribute('tax_amount'), 2), (string) $item->getAttribute('line_total'), 2) !== 0) {
                    $this->fail('Issued line fulfillment/counter/amount references require review.');
                }
                foreach (['product_id', 'product_variant_id', 'unit_id'] as $field) {
                    if ($item->getAttribute($field) !== $di->getAttribute($field) || $di->getAttribute($field) !== $line->getAttribute($field)) {
                        $this->fail('Issued line product identities require review.');
                    }
                }
                foreach (['subtotal' => 'subtotal', 'discount_total' => 'discount_amount', 'tax_total' => 'tax_amount', 'grand_total' => 'line_total'] as $header => $field) {
                    $totals[$header] = bcadd($totals[$header], (string) $item->getAttribute($field), 2);
                }
            }
            foreach ($totals as $header => $amount) {
                if ($invoice->getAttribute($header) !== $amount) {
                    $this->fail('Issued invoice header and line amounts do not reconcile.');
                }
            }
            $gross = $this->money->money((string) $invoice->getAttribute('grand_total'));
            $paid = 0;
            $credits = 0;
            $allocations = DB::table('customer_receipt_allocations')->where('sales_invoice_id', $invoice->getKey())->whereNull('reverses_allocation_id')->orderBy('id')->lockForUpdate()->get();
            foreach ($allocations as $allocation) {
                if ((int) $allocation->company_id !== (int) $company->getKey() || $allocation->posted_at === null) {
                    $this->fail('Allocation ownership or posting timestamp requires review.');
                }
                $amount = $this->money->money((string) $allocation->amount);
                $reversal = DB::table('customer_receipt_allocations')->where('reverses_allocation_id', $allocation->id)->lockForUpdate()->first();
                if ($reversal !== null) {
                    if ((int) $reversal->company_id !== (int) $company->getKey() || (int) $reversal->sales_invoice_id !== (int) $invoice->getKey() || (int) $reversal->customer_receipt_id !== (int) $allocation->customer_receipt_id || $reversal->posted_at === null || $this->money->money((string) $reversal->amount) !== $amount) {
                        $this->fail('Allocation reversal sources require review.');
                    }

                    continue;
                }
                $receipt = DB::table('customer_receipts')->where('id', $allocation->customer_receipt_id)->lockForUpdate()->first();
                if ($receipt === null || (int) $receipt->company_id !== (int) $company->getKey() || (int) $receipt->customer_id !== (int) $invoice->getAttribute('customer_id') || $receipt->currency_code !== $invoice->getAttribute('currency_code') || $receipt->status !== 'posted' || $receipt->posted_at === null || $receipt->reverses_receipt_id !== null || DB::table('customer_receipts')->where('reverses_receipt_id', $receipt->id)->where('status', 'posted')->exists()) {
                    $this->fail('An active allocation requires a valid same-customer posted receipt.');
                }
                $receiptTotal = 0;
                $targets = DB::table('customer_receipt_allocations as a')->join('sales_invoices as i', 'i.id', '=', 'a.sales_invoice_id')->where('a.customer_receipt_id', $receipt->id)->whereNull('a.reverses_allocation_id')
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('customer_receipt_allocations as r')->whereColumn('r.reverses_allocation_id', 'a.id'))
                    ->lockForUpdate()->get(['a.amount', 'a.company_id', 'a.posted_at', 'i.company_id as invoice_company_id', 'i.customer_id', 'i.currency_code', 'i.status', 'i.issued_at']);
                foreach ($targets as $target) {
                    if ((int) $target->company_id !== (int) $company->getKey() || (int) $target->invoice_company_id !== (int) $company->getKey() || (int) $target->customer_id !== (int) $invoice->getAttribute('customer_id') || $target->currency_code !== $invoice->getAttribute('currency_code') || $target->status !== 'issued' || $target->issued_at === null || $target->posted_at === null) {
                        $this->fail('Receipt allocation targets require review.');
                    }
                    $receiptTotal = $this->plus($receiptTotal, $this->money->money((string) $target->amount));
                }
                if ($receiptTotal > $this->money->money((string) $receipt->amount)) {
                    $this->fail('Active allocations exceed the receipt amount.');
                }
                $paid = $this->plus($paid, $amount);
            }
            $notes = DB::table('sales_credit_notes')->where('sales_invoice_id', $invoice->getKey())->where('status', 'issued')->lockForUpdate()->get();
            foreach ($notes as $note) {
                if ((int) $note->company_id !== (int) $company->getKey() || (int) $note->customer_id !== (int) $invoice->getAttribute('customer_id') || $note->currency_code !== $invoice->getAttribute('currency_code') || $note->issued_at === null) {
                    $this->fail('Issued credit sources require review.');
                }
                $credits = $this->plus($credits, $this->money->money((string) $note->grand_total));
            }
            if ($paid > $gross || $credits > $gross) {
                $this->fail('Receipt or credit totals exceed the original invoice.');
            }
            $creditState = $this->customerCredits->customer((int) $company->getKey(), (int) $invoice->getAttribute('customer_id'))[(int) $invoice->getKey()];
            $nativeCredits = $credits;
            $credits = $this->plus($credits, $this->money->money((string) $creditState['incoming']));
            $outstanding = max(0, $gross - $paid - $credits);
            $today = now((string) $company->getAttribute('timezone'))->toDateString();

            return ['native_credits' => $this->math->format($nativeCredits), 'allocated_credit' => $creditState['incoming'], 'transferred_credit' => $creditState['outgoing'], 'available_customer_credit' => $creditState['available'], 'gross' => $this->math->format($gross), 'paid' => $this->math->format($paid), 'credits' => $this->math->format($credits), 'outstanding' => $this->math->format($outstanding),
                'payment_status' => $gross === 0 ? 'no_charge' : ($paid >= $gross ? 'paid' : ($paid > 0 ? 'partially_paid' : 'unpaid')),
                'credit_status' => $credits === 0 ? 'none' : ($credits >= $gross ? 'fully_credited' : 'partially_credited'),
                'excess_settlement' => $this->math->format(max(0, $paid + $credits - $gross)), 'overdue' => $outstanding > 0 && $invoice->getAttribute('due_date')->format('Y-m-d') < $today];
        }, 3);
    }

    private function plus(int $left, int $right): int
    {
        if ($right < 0 || $left >= 1000000000000000000 - $right) {
            $this->fail('Settlement totals exceed the supported range.');
        }

        return $left + $right;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['receivable' => $message]);
    }
}
