<?php

declare(strict_types=1);

namespace App\Services\ERP\Sales;

use App\Models\SalesCreditNote;
use App\Models\SalesCreditNoteItem;
use App\Models\SalesDelivery;
use App\Models\SalesDeliveryItem;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use Illuminate\Support\Facades\DB;

/** Internal source/counter reader; no HTTP authorization or financial writes. */
final class SalesCreditNoteLedger
{
    public function __construct(private readonly SalesCreditMath $math, private readonly SalesReturnLedger $returns) {}

    /** @return array{total: string, items: array<int, array<string, string>>} */
    public function progress(SalesInvoice $invoice, bool $strict = false): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Credit ledger requires a caller transaction and company lock.');
        }
        $lines = SalesInvoiceItem::query()->where('sales_invoice_id', $invoice->getKey())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $state = [];
        $legacy = false;
        $total = '0.00';
        foreach ($lines as $line) {
            $state[(int) $line->getKey()] = ['quantity' => '0.0000', 'amount' => '0.00', 'subtotal' => '0.00', 'discount_amount' => '0.00', 'tax_amount' => '0.00', 'line_total' => '0.00'];
        }
        foreach (SalesCreditNote::query()->where('sales_invoice_id', $invoice->getKey())->orderBy('issue_sequence')->orderBy('id')->lockForUpdate()->get() as $note) {
            if (! in_array($note->getAttribute('status'), ['draft', 'issued', 'cancelled'], true) || (($note->getAttribute('status') === 'issued') !== ($note->getAttribute('issued_at') !== null)) || (int) $note->getAttribute('company_id') !== (int) $invoice->getAttribute('company_id') || (int) $note->getAttribute('customer_id') !== (int) $invoice->getAttribute('customer_id') || $note->getAttribute('currency_code') !== $invoice->getAttribute('currency_code')) {
                $this->math->fail('Credit note ownership or issue state requires review.');
            }
            if ($note->getAttribute('status') !== 'issued') {
                continue;
            }
            $items = SalesCreditNoteItem::query()->where('sales_credit_note_id', $note->getKey())->orderBy('line_number')->lockForUpdate()->get();
            // Read compatibility for the explicit header-only pre-5.9 test fixtures; never extend these with new credits.
            if ($items->isEmpty() && $note->getAttribute('payload_hash') === null && ! $strict) {
                $legacy = true;
                $total = $this->math->money(bcadd($total, (string) $note->getAttribute('grand_total'), 2));

                continue;
            }
            if ($items->isEmpty() || $note->getAttribute('payload_hash') === null || $note->getAttribute('idempotency_key') === null || $note->getAttribute('issue_sequence') === null || (int) $note->getAttribute('created_by') === (int) $note->getAttribute('issued_by') || $note->getAttribute('created_by') === null) {
                $this->math->fail('Issued credits require line proof and independent issue approval.');
            }
            $totals = ['subtotal' => '0.00', 'discount_total' => '0.00', 'tax_total' => '0.00', 'grand_total' => '0.00'];
            $seen = [];
            foreach ($items as $item) {
                $id = (int) $item->getAttribute('sales_invoice_item_id');
                $line = $lines->get($id);
                if (! $line instanceof SalesInvoiceItem || isset($seen[$id])) {
                    $this->math->fail('Credit lines require unique invoice source lines.');
                } $seen[$id] = true;
                foreach (['product_id', 'product_variant_id', 'unit_id', 'product_snapshot', 'description', 'unit_price', 'discount_percent', 'tax_percent'] as $field) {
                    if ($item->getAttribute($field) !== $line->getAttribute($field)) {
                        $this->math->fail('Credit pricing or source snapshots changed.');
                    }
                }
                $quantity = $this->returns->quantity((string) $item->getAttribute('quantity'));
                $amount = $this->math->money((string) $item->getAttribute('line_total'), true);
                if (! in_array($item->getAttribute('credit_type'), ['quantity', 'value'], true) || ($item->getAttribute('credit_type') === 'value' && bccomp($quantity, '0', 4) !== 0)) {
                    $this->math->fail('Credit type/quantity requires review.');
                }
                if ($item->getAttribute('credit_type') === 'quantity' && $this->math->quantityValue($line, $state[$id]['quantity'], $quantity) !== $amount) {
                    $this->math->fail('Quantity credit differs from its original invoice price budget.');
                }
                foreach ($this->math->slice($line, $state[$id]['amount'], $amount) as $field => $value) {
                    if ($item->getAttribute($field) !== $value) {
                        $this->math->fail('Credit components do not match the original remaining invoice budget.');
                    } $state[$id][$field] = bcadd($state[$id][$field], $value, 2);
                }
                $state[$id]['quantity'] = bcadd($state[$id]['quantity'], $quantity, 4);
                $state[$id]['amount'] = bcadd($state[$id]['amount'], $amount, 2);
                $returnId = $item->getAttribute('sales_return_item_id');
                if ($returnId !== null) {
                    $ri = SalesReturnItem::query()->findOrFail($returnId);
                    $rh = SalesReturn::query()->where('company_id', $invoice->getAttribute('company_id'))->findOrFail($ri->getAttribute('sales_return_id'));
                    $di = SalesDeliveryItem::query()->findOrFail($line->getAttribute('sales_delivery_item_id'));
                    $dh = SalesDelivery::query()->findOrFail($di->getAttribute('sales_delivery_id'));
                    $this->returns->progress($dh);
                    if ($item->getAttribute('credit_type') !== 'quantity' || $rh->getAttribute('status') !== 'posted' || (int) $rh->getAttribute('customer_id') !== (int) $invoice->getAttribute('customer_id') || $rh->getAttribute('currency_code') !== $invoice->getAttribute('currency_code') || (int) $ri->getAttribute('sales_delivery_item_id') !== (int) $di->getKey() || (int) $note->getAttribute('sales_return_id') !== (int) $rh->getKey()) {
                        $this->math->fail('Credit return attribution requires matching posted billed returns.');
                    }
                    $used = $this->returnUsed((int) $returnId);
                    if (bccomp(bcadd((string) $used, '0', 4), (string) $ri->getAttribute('credit_review_quantity'), 4) > 0) {
                        $this->math->fail('Return quantity has already been credited.');
                    }
                } elseif ($note->getAttribute('sales_return_id') !== null) {
                    $this->math->fail('Every return-linked credit line needs a matching return item.');
                }
                foreach (['subtotal' => 'subtotal', 'discount_total' => 'discount_amount', 'tax_total' => 'tax_amount', 'grand_total' => 'line_total'] as $header => $field) {
                    $totals[$header] = bcadd($totals[$header], (string) $item->getAttribute($field), 2);
                }
            }
            foreach ($totals as $header => $value) {
                if ($note->getAttribute($header) !== $value) {
                    $this->math->fail('Credit header/line totals do not reconcile.');
                }
            }
            $total = $this->math->money(bcadd($total, $totals['grand_total'], 2));
        }
        if (bccomp($total, (string) $invoice->getAttribute('grand_total'), 2) > 0) {
            $this->math->fail('Credits exceed original invoice value.');
        }
        if (! $legacy) {
            foreach ($lines as $line) {
                $p = $state[(int) $line->getKey()];
                if ($line->getAttribute('credited_quantity') !== $p['quantity'] || $line->getAttribute('credited_amount') !== $p['amount']) {
                    $this->math->fail('Invoice credit counters do not reconcile to issued credit lines.');
                }
            }
        }

        return ['total' => $total, 'items' => $state];
    }

    public function returnUsed(int $returnItemId): string
    {
        $used = '0.0000';
        foreach (SalesCreditNoteItem::query()->where('sales_return_item_id', $returnItemId)->whereIn('sales_credit_note_id', SalesCreditNote::query()->where('status', 'issued')->select('id'))->get() as $item) {
            $used = bcadd($used, (string) $item->getAttribute('quantity'), 4);
        }

        return $used;
    }
}
