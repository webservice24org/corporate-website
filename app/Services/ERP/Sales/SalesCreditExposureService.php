<?php

declare(strict_types=1);

namespace App\Services\ERP\Sales;

use App\Models\Customer;
use App\Models\SalesDelivery;
use App\Models\SalesDeliveryItem;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Internal assessment. Caller holds the company, order and customer locks. */
final class SalesCreditExposureService
{
    private const MAX_TOTAL = 1000000000000000000;

    public function __construct(private readonly SalesQuotationMath $math, private readonly SalesReceiptLedger $receipts, private readonly CustomerCreditLedger $customerCredits, private readonly SalesReturnLedger $returns) {}

    /** @return array<string, mixed> */
    public function assess(Customer $customer, SalesOrder $candidate): array
    {
        $companyId = (int) $candidate->getAttribute('company_id');
        $customerId = (int) $customer->getKey();
        $currency = (string) $candidate->getAttribute('currency_code');
        if (DB::transactionLevel() < 1 || (int) $customer->getAttribute('company_id') !== $companyId || (int) $candidate->getAttribute('customer_id') !== $customerId) {
            throw new \LogicException('Credit assessment requires matching, locked Sales sources in a transaction.');
        }
        if (DB::table('sales_invoices')->where('company_id', $companyId)->where('customer_id', $customerId)->whereNotNull('issued_at')->where('status', '<>', 'issued')->exists()) {
            $this->review('An invoice issuance state requires review.');
        }
        if (DB::table('sales_invoices')->where('company_id', $companyId)->where('customer_id', $customerId)->where('status', 'issued')->whereNull('issued_at')->exists()) {
            $this->review('An issued invoice is missing its issuance timestamp.');
        }
        if (DB::table('sales_invoices')->where('company_id', $companyId)->where('customer_id', $customerId)->whereNotIn('status', ['draft', 'issued', 'cancelled'])->exists()) {
            $this->review('An unsupported invoice state requires review.');
        }
        $invoices = DB::table('sales_invoices')->where('company_id', $companyId)->where('customer_id', $customerId)
            ->whereNotNull('issued_at')->where('status', 'issued')->orderBy('id')->lockForUpdate()->get();
        $creditState = $this->customerCredits->customer($companyId, $customerId);
        $receivable = 0;
        $billedValues = [];
        $billedQuantities = [];
        $deliveryBilled = [];
        $deliveryLimits = [];
        $checkedReceipts = [];
        foreach ($invoices as $invoice) {
            $invoiceCurrency = (string) $invoice->currency_code;
            $gross = $this->money((string) $invoice->grand_total);
            $details = DB::table('sales_invoice_items as ii')
                ->join('sales_delivery_items as di', 'di.id', '=', 'ii.sales_delivery_item_id')
                ->join('sales_deliveries as d', 'd.id', '=', 'di.sales_delivery_id')
                ->join('sales_order_items as oi', 'oi.id', '=', 'di.sales_order_item_id')
                ->join('sales_orders as o', 'o.id', '=', 'oi.sales_order_id')
                ->where('ii.sales_invoice_id', $invoice->id)->lockForUpdate()->get([
                    'ii.line_total', 'ii.quantity', 'ii.product_id', 'ii.product_variant_id', 'ii.unit_id',
                    'di.id as delivery_item_id', 'di.quantity as delivery_quantity', 'di.invoiced_quantity as delivery_invoiced_quantity',
                    'oi.id as order_item_id', 'oi.product_id as source_product_id', 'oi.product_variant_id as source_variant_id', 'oi.unit_id as source_unit_id',
                    'di.product_id as delivery_product_id', 'di.product_variant_id as delivery_variant_id', 'di.unit_id as delivery_unit_id',
                    'o.id as order_id', 'o.company_id', 'o.customer_id', 'o.currency_code', 'o.confirmed_at',
                    'd.company_id as delivery_company_id', 'd.customer_id as delivery_customer_id', 'd.sales_order_id as delivery_order_id', 'd.status as delivery_status', 'd.posted_at',
                ]);
            if ($details->isEmpty() || $details->count() !== DB::table('sales_invoice_items')->where('sales_invoice_id', $invoice->id)->count()) {
                $this->review('An issued invoice has missing fulfillment source lines.');
            }
            $invoiceTotal = 0;
            foreach ($details as $detail) {
                if ((int) $detail->company_id !== $companyId || (int) $detail->customer_id !== $customerId || (string) $detail->currency_code !== $invoiceCurrency
                    || (int) $detail->order_id !== (int) $invoice->sales_order_id || $detail->confirmed_at === null
                    || (int) $detail->delivery_company_id !== $companyId || (int) $detail->delivery_customer_id !== $customerId || (int) $detail->delivery_order_id !== (int) $detail->order_id || $detail->delivery_status !== 'posted' || $detail->posted_at === null
                    || (int) $detail->product_id !== (int) $detail->source_product_id || (int) $detail->delivery_product_id !== (int) $detail->source_product_id
                    || (int) ($detail->product_variant_id ?? 0) !== (int) ($detail->source_variant_id ?? 0) || (int) ($detail->delivery_variant_id ?? 0) !== (int) ($detail->source_variant_id ?? 0)
                    || (int) $detail->unit_id !== (int) $detail->source_unit_id || (int) $detail->delivery_unit_id !== (int) $detail->source_unit_id) {
                    $this->review('Invoice fulfillment references require review.');
                }
                $itemId = (int) $detail->order_item_id;
                $lineAmount = $this->money((string) $detail->line_total);
                $lineQuantity = $this->math->scaled((string) $detail->quantity, 10000);
                if ($lineQuantity === 0) {
                    $this->review('Invoice source quantity must be positive.');
                }
                $invoiceTotal = $this->add($invoiceTotal, $lineAmount);
                $billedValues[$itemId] = $this->add($billedValues[$itemId] ?? 0, $lineAmount);
                $billedQuantities[$itemId] = ($billedQuantities[$itemId] ?? 0) + $lineQuantity;
                $deliveryId = (int) $detail->delivery_item_id;
                $deliveryBilled[$deliveryId] = ($deliveryBilled[$deliveryId] ?? 0) + $lineQuantity;
                $deliveryLimits[$deliveryId] = ['quantity' => $this->math->scaled((string) $detail->delivery_quantity, 10000), 'invoiced' => $this->math->scaled((string) $detail->delivery_invoiced_quantity, 10000)];
            }
            if ($invoiceTotal !== $gross) {
                $this->review('Invoice header and line values do not reconcile.');
            }

            $credits = 0;
            $notes = DB::table('sales_credit_notes')->where('sales_invoice_id', $invoice->id)->where('status', 'issued')->whereNotNull('issued_at')->lockForUpdate()->get();
            foreach ($notes as $note) {
                if ((int) $note->company_id !== $companyId || (int) $note->customer_id !== $customerId || (string) $note->currency_code !== $invoiceCurrency) {
                    $this->review('An invoice credit source has inconsistent ownership or currency.');
                }
                $credits = $this->add($credits, $this->money((string) $note->grand_total));
            }
            $paid = 0;
            $allocations = DB::table('customer_receipt_allocations')->where('sales_invoice_id', $invoice->id)->whereNull('reverses_allocation_id')->orderBy('id')->lockForUpdate()->get();
            foreach ($allocations as $allocation) {
                if ((int) $allocation->company_id !== $companyId) {
                    $this->review('An invoice allocation has inconsistent company ownership.');
                }
                $amount = $this->money((string) $allocation->amount);
                $reversal = DB::table('customer_receipt_allocations')->where('reverses_allocation_id', $allocation->id)->lockForUpdate()->first();
                if ($reversal !== null) {
                    if ((int) $reversal->company_id !== $companyId || (int) $reversal->sales_invoice_id !== (int) $invoice->id || (int) $reversal->customer_receipt_id !== (int) $allocation->customer_receipt_id || $this->money((string) $reversal->amount) !== $amount) {
                        $this->review('An allocation reversal requires review.');
                    }

                    continue;
                }
                $receipt = DB::table('customer_receipts')->where('id', $allocation->customer_receipt_id)->lockForUpdate()->first();
                if ($receipt === null || (int) $receipt->company_id !== $companyId || (int) $receipt->customer_id !== $customerId || (string) $receipt->currency_code !== $invoiceCurrency || $receipt->status !== 'posted' || $receipt->posted_at === null || $receipt->reverses_receipt_id !== null) {
                    $this->review('An active invoice allocation requires a valid posted receipt.');
                }
                if (DB::table('customer_receipts')->where('reverses_receipt_id', $receipt->id)->where('status', 'posted')->exists()) {
                    $this->review('A reversed receipt still has an active allocation.');
                }
                if (! isset($checkedReceipts[(int) $receipt->id])) {
                    $receiptTotal = 0;
                    $active = DB::table('customer_receipt_allocations as a')->join('sales_invoices as i', 'i.id', '=', 'a.sales_invoice_id')
                        ->where('a.customer_receipt_id', $receipt->id)->whereNull('a.reverses_allocation_id')
                        ->whereNotExists(function ($q): void {
                            $q->selectRaw('1')->from('customer_receipt_allocations as r')->whereColumn('r.reverses_allocation_id', 'a.id');
                        })->lockForUpdate()->get(['a.amount', 'a.company_id', 'i.company_id as invoice_company_id', 'i.customer_id', 'i.currency_code', 'i.status', 'i.issued_at']);
                    foreach ($active as $entry) {
                        if ((int) $entry->company_id !== $companyId || (int) $entry->invoice_company_id !== $companyId || (int) $entry->customer_id !== $customerId || (string) $entry->currency_code !== $invoiceCurrency || $entry->status !== 'issued' || $entry->issued_at === null) {
                            $this->review('Receipt allocation targets require review.');
                        }
                        $receiptTotal = $this->add($receiptTotal, $this->money((string) $entry->amount));
                    }
                    if ($receiptTotal > $this->money((string) $receipt->amount)) {
                        $this->review('Active allocations exceed the posted receipt amount.');
                    }
                    $checkedReceipts[(int) $receipt->id] = true;
                }
                $paid = $this->add($paid, $amount);
            }
            if ($credits > $gross || $paid > $gross) {
                $this->review('Invoice credit or settlement totals exceed the issued value.');
            }
            $incoming = $this->money((string) ($creditState[(int) $invoice->id]['incoming'] ?? '0.00'));
            $outstanding = max(0, $gross - $credits - $paid - $incoming);
            if ($outstanding > 0 && $invoiceCurrency !== $currency) {
                $this->review('Outstanding invoice currency requires review before new credit.');
            }
            $receivable = $this->add($receivable, $outstanding);
        }
        foreach ($deliveryBilled as $deliveryId => $quantity) {
            if ($quantity > $deliveryLimits[$deliveryId]['quantity'] || $quantity !== $deliveryLimits[$deliveryId]['invoiced']) {
                $this->review('Delivery invoiced counters do not match issued source lines.');
            }
        }
        if (DB::table('sales_orders')->where('company_id', $companyId)->where('customer_id', $customerId)->whereNotNull('confirmed_at')->whereNotIn('status', ['confirmed', 'partially_delivered', 'delivered', 'closed', 'cancelled'])->exists()) {
            $this->review('A confirmed order state requires review.');
        }
        $unbilledReturns = [];
        foreach (SalesDelivery::query()->where('company_id', $companyId)->where('customer_id', $customerId)->where('status', 'posted')->whereNotNull('posted_at')->get() as $delivery) {
            $returnProgress = $this->returns->progress($delivery);
            foreach (SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->get() as $di) {
                $lineId = (int) $di->getAttribute('sales_order_item_id');
                $unbilledReturns[$lineId] = ($unbilledReturns[$lineId] ?? 0) + $this->math->scaled($returnProgress[(int) $di->getKey()]['unbilled'], 10000);
            }
        }
        $commitments = 0;
        $orders = DB::table('sales_orders')->where('company_id', $companyId)->where('customer_id', $customerId)
            ->where('id', '<>', $candidate->getKey())->whereNotNull('confirmed_at')
            ->whereIn('status', ['confirmed', 'partially_delivered', 'delivered', 'closed', 'cancelled'])->orderBy('id')->lockForUpdate()->get();
        foreach ($orders as $order) {
            $lines = DB::table('sales_order_items')->where('sales_order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            if ($lines->isEmpty()) {
                $this->review('A confirmed order has no commitment lines.');
            }
            $original = 0;
            $unbilled = 0;
            foreach ($lines as $line) {
                $qty = $this->math->scaled((string) $line->quantity, 10000);
                $cancelled = $this->math->scaled((string) $line->cancelled_quantity, 10000);
                $invoiced = $this->math->scaled((string) $line->invoiced_quantity, 10000);
                $delivered = $this->math->scaled((string) $line->delivered_quantity, 10000);
                $amount = $this->money((string) $line->line_total);
                if ($qty === 0 || $cancelled + $delivered > $qty || $invoiced > $delivered) {
                    $this->review('Order commitment counters require review.');
                }
                $itemId = (int) $line->id;
                if ($invoiced !== ($billedQuantities[$itemId] ?? 0)) {
                    $this->review('Order invoiced counters do not match issued source lines.');
                }
                // Remove unbilled returns with cancellations using the same complement budget as invoicing.
                $excluded = $cancelled + ($unbilledReturns[$itemId] ?? 0);
                if ($excluded + $invoiced > $qty) {
                    $this->review('Unbilled return quantities overlap billed/cancelled commitments.');
                }
                $cancelledValue = intdiv($amount, $qty) * $excluded
                    + intdiv(($amount % $qty) * $excluded + intdiv($qty, 2), $qty);
                $billed = $billedValues[$itemId] ?? 0;
                if ($billed > $amount - $cancelledValue || ($invoiced + $excluded === $qty && $billed !== $amount - $cancelledValue)) {
                    $this->review('Order billing and cancelled monetary values do not reconcile.');
                }
                $original = $this->add($original, $amount);
                $unbilled = $this->add($unbilled, $amount - $cancelledValue - $billed);
            }
            if ($original !== $this->money((string) $order->grand_total)) {
                $this->review('Order header and line values do not reconcile.');
            }
            if ($unbilled > 0 && (string) $order->currency_code !== $currency) {
                $this->review('Unbilled commitment currency requires review before new credit.');
            }
            if ((string) $order->currency_code === $currency) {
                $commitments = $this->add($commitments, $unbilled);
            }
        }
        $amount = $this->money((string) $candidate->getAttribute('grand_total'));
        $limit = $this->money((string) $customer->getAttribute('credit_limit'));
        $grossExposure = $this->add($this->add($receivable, $commitments), $amount);
        $availableAdvance = $this->money($this->receipts->availableAdvance($companyId, $customerId, $currency));
        $advanceOffset = min($grossExposure, $availableAdvance);
        $projected = $grossExposure - $advanceOffset;

        return [
            'currency_code' => $currency, 'credit_limit' => $this->math->format($limit),
            'open_receivable' => $this->math->format($receivable), 'unbilled_commitments' => $this->math->format($commitments),
            'order_amount' => $this->math->format($amount), 'projected_exposure' => $this->math->format($projected),
            'within_limit' => $projected <= $limit, 'zero_limit_policy' => 'no_unsecured_credit',
            'gross_exposure' => $this->math->format($grossExposure), 'available_advance' => $this->math->format($availableAdvance), 'advance_offset' => $this->math->format($advanceOffset), 'evaluated_at' => now()->toIso8601String(),
        ];
    }

    public function money(string $value): int
    {
        if (preg_match('/\A[0-9]{1,16}(?:\.[0-9]{1,2})?\z/', $value) !== 1) {
            $this->review('Commercial amounts require valid nonnegative decimal values.');
        }

        // SQLite numeric affinity may return 100 / 100.5; MySQL returns 100.00 / 100.50.
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    private function add(int $left, int $right): int
    {
        if ($right < 0 || $right >= self::MAX_TOTAL - $left) {
            $this->review('Credit exposure exceeds the supported aggregate range.');
        }

        return $left + $right;
    }

    private function review(string $message): never
    {
        throw ValidationException::withMessages(['credit' => $message]);
    }
}
