<?php

declare(strict_types=1);

namespace App\Services\ERP\Purchase;

use App\Models\Accounting\AccountingExpense;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierBillItem;
use App\Models\User;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseContract;
use App\Services\ERP\Accounting\Source\Purchase\AccountingPurchasePosting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SupplierBillService
{
    public function __construct(
        private readonly PurchaseCompanyAccess $access,
        private readonly PurchaseRequisitionService $requisitions,
        private readonly PurchaseDocumentNumberService $numbers,
        private readonly PurchaseQuoteMath $math,
        private readonly AccountingPurchasePosting $accounting,
    ) {}

    /** @return Builder<Company> */
    public function companies(User $actor, string $permission): Builder
    {
        return $this->requisitions->companies($actor, $permission);
    }

    /** @return Builder<SupplierBill> */
    public function visible(User $actor, string $permission = 'erp.supplier-bills.view'): Builder
    {
        return SupplierBill::query()->whereIn('company_id', $this->companies($actor, $permission)->select('id'));
    }

    public function find(User $actor, int $id, string $permission): SupplierBill
    {
        $bill = $this->visible($actor, $permission)->findOrFail($id);
        $this->access->authorize($actor, (int) $bill->company_id, $permission);

        return $bill;
    }

    /** @return Builder<GoodsReceipt> */
    public function sources(User $actor): Builder
    {
        return GoodsReceipt::query()->where('status', 'posted')->whereIn('company_id', $this->companies($actor, 'erp.supplier-bills.create')->select('id'));
    }

    /** @return list<array<string, mixed>> */
    public function sourceLines(GoodsReceipt $receipt): array
    {
        return GoodsReceiptItem::query()->where('goods_receipt_id', $receipt->getKey())->orderBy('id')->get()->map(function (GoodsReceiptItem $source) use ($receipt): array {
            $line = PurchaseOrderItem::query()->where('purchase_order_id', $receipt->purchase_order_id)->findOrFail($source->purchase_order_item_id);
            $billed = $this->billed((int) $source->getKey());
            $returned = $this->returned((int) $source->getKey());

            return ['id' => (int) $source->getKey(), 'product_name' => $line->product_name, 'variant_name' => $line->variant_name, 'accepted' => (string) $source->quantity_received,
                'returned' => $returned, 'billed' => $billed, 'remaining' => $this->remaining($source), 'unit_price' => (string) $line->unit_price,
                'ordered_quantity' => (string) $line->quantity, 'order_discount' => (string) $line->discount_amount, 'order_tax' => (string) $line->tax_amount];
        })->all();
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): SupplierBill
    {
        $receipt = $this->sources($actor)->findOrFail((int) $data['goods_receipt_id']);
        $this->access->authorize($actor, (int) $receipt->company_id, 'erp.supplier-bills.create');

        return DB::transaction(function () use ($actor, $receipt, $data): SupplierBill {
            Company::query()->whereKey($receipt->company_id)->lockForUpdate()->firstOrFail();
            [$receipt, $order] = $this->lockSource($receipt);
            $header = $this->header($data, (int) $order->supplier_id, (int) $receipt->company_id);
            $prepared = $this->prepare($receipt, $data['items']);
            $bill = SupplierBill::query()->create(['company_id' => $receipt->company_id, 'supplier_id' => $order->supplier_id, 'purchase_order_id' => $order->getKey(),
                'goods_receipt_id' => $receipt->getKey(), 'number' => $this->numbers->next((int) $receipt->company_id, 'bill'), 'status' => 'draft', 'revision' => 1,
                'currency_code' => $order->currency_code, 'created_by' => $actor->getKey(), ...$header, ...$this->totals($prepared)]);
            $this->saveLines($bill, $prepared);
            $this->audit($bill, $actor, 'created', null, null);

            return $bill;
        }, 5);
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, int $id, array $data): SupplierBill
    {
        $bill = $this->find($actor, $id, 'erp.supplier-bills.edit');

        return DB::transaction(function () use ($bill, $actor, $data): SupplierBill {
            Company::query()->whereKey($bill->company_id)->lockForUpdate()->firstOrFail();
            [$receipt, $order] = $this->lockSource(GoodsReceipt::query()->where('company_id', $bill->company_id)->where('purchase_order_id', $bill->purchase_order_id)->findOrFail($bill->goods_receipt_id));
            $bill = SupplierBill::query()->whereKey($bill->getKey())->lockForUpdate()->firstOrFail();
            $this->state($bill, (int) $data['revision'], 'draft');
            $this->checkHeader($bill, $receipt, $order);
            $old = $this->snapshot($bill);
            $header = $this->header($data, (int) $bill->supplier_id, (int) $bill->company_id, (int) $bill->getKey());
            $prepared = $this->prepare($receipt, $data['items']);
            SupplierBillItem::query()->where('supplier_bill_id', $bill->getKey())->delete();
            $this->saveLines($bill, $prepared);
            $bill->fill([...$header, ...$this->totals($prepared), 'revision' => (int) $bill->getAttribute('revision') + 1])->save();
            $this->audit($bill, $actor, 'updated', $old, null);

            return $bill;
        }, 5);
    }

    public function approve(User $actor, int $id, int $revision): SupplierBill
    {
        $bill = $this->find($actor, $id, 'erp.supplier-bills.approve');

        return DB::transaction(function () use ($bill, $actor, $revision): SupplierBill {
            $this->accounting->before((int) $bill->company_id);
            [$receipt, $order] = $this->lockSource(GoodsReceipt::query()->where('company_id', $bill->company_id)->where('purchase_order_id', $bill->purchase_order_id)->findOrFail($bill->goods_receipt_id));
            $bill = SupplierBill::query()->whereKey($bill->getKey())->lockForUpdate()->firstOrFail();
            if ($bill->status === 'approved') {
                $payable = DB::table('supplier_payables')->where('supplier_bill_id', $bill->getKey())->first();
                if ($payable === null || (int) $payable->company_id !== (int) $bill->company_id || (int) $payable->supplier_id !== (int) $bill->supplier_id
                    || $payable->currency_code !== $bill->currency_code || bccomp((string) $payable->amount, (string) $bill->grand_total, 2) !== 0) {
                    $this->fail('payable', 'Approved bill payable is missing or inconsistent. Legacy bills require reconciliation.');
                }

                $this->accounting->complete($actor, (int) $bill->company_id, 'supplier_bill', (int) $bill->getKey(), false);

                return $bill; // Retry creates no duplicate liability or audit event.
            }
            $this->state($bill, $revision, 'draft');
            $this->checkHeader($bill, $receipt, $order);
            if ((int) $bill->created_by === (int) $actor->getKey()) {
                $this->fail('approval', 'A different authorized user must approve this bill.');
            }
            $this->header(['supplier_invoice_number' => $bill->supplier_invoice_number, 'invoice_date' => $bill->getRawOriginal('invoice_date'), 'due_date' => $bill->getRawOriginal('due_date'), 'notes' => $bill->notes], (int) $bill->supplier_id, (int) $bill->company_id, (int) $bill->getKey());
            $stored = SupplierBillItem::query()->where('supplier_bill_id', $bill->getKey())->orderBy('id')->get();
            $prepared = $this->prepare($receipt, $stored->map(fn (SupplierBillItem $line): array => ['goods_receipt_item_id' => $line->goods_receipt_item_id, 'quantity' => $line->quantity])->all());
            if (count($prepared) !== $stored->count()) {
                $this->fail('items', 'All saved invoice lines must have positive quantities.');
            }
            foreach ($prepared as $line) {
                $saved = $stored->firstWhere('goods_receipt_item_id', $line['goods_receipt_item_id']);
                if ($saved === null || (int) $saved->purchase_order_item_id !== (int) $line['purchase_order_item_id'] || $saved->description !== $line['description']) {
                    $this->fail('items', 'Saved bill source details have changed. Review the draft.');
                }
                foreach (['quantity', 'unit_price', 'discount_amount', 'tax_amount', 'line_total'] as $key) {
                    if (bccomp((string) $saved->getAttribute($key), (string) $line[$key], $key === 'quantity' ? 4 : 2) !== 0) {
                        $this->fail('items', 'Saved invoice amounts do not match purchase terms. Review the draft.');
                    }
                }
            }
            foreach ($this->totals($prepared) as $key => $value) {
                if (bccomp((string) $bill->getAttribute($key), $value, 2) !== 0) {
                    $this->fail('totals', 'Saved bill totals are inconsistent with its lines.');
                }
            }
            if (bccomp((string) $bill->grand_total, '0', 2) <= 0) {
                $this->fail('grand_total', 'A payable bill must have a positive total.');
            }
            $old = $this->snapshot($bill);
            $bill->fill(['status' => 'approved', 'approved_by' => $actor->getKey(), 'approved_at' => now()->toDateTimeString(), 'revision' => $revision + 1])->save();
            DB::table('supplier_payables')->insert(['company_id' => $bill->company_id, 'supplier_id' => $bill->supplier_id, 'supplier_bill_id' => $bill->getKey(),
                'currency_code' => $bill->currency_code, 'amount' => $bill->grand_total, 'paid_amount' => '0.00', 'due_date' => $bill->getRawOriginal('due_date'),
                'created_by' => $actor->getKey(), 'created_at' => now()]);
            $this->audit($bill, $actor, 'approved', $old, 'Payable created from approved supplier bill.');

            $this->accounting->complete($actor, (int) $bill->company_id, 'supplier_bill', (int) $bill->getKey(), true);

            return $bill;
        }, 5);
    }

    public function cancel(User $actor, int $id, int $revision, string $reason): SupplierBill
    {
        $bill = $this->find($actor, $id, 'erp.supplier-bills.cancel');

        return DB::transaction(function () use ($bill, $actor, $revision, $reason): SupplierBill {
            Company::query()->whereKey($bill->company_id)->lockForUpdate()->firstOrFail();
            $bill = SupplierBill::query()->whereKey($bill->getKey())->lockForUpdate()->firstOrFail();
            $this->state($bill, $revision, 'draft');
            if (trim($reason) === '' || mb_strlen($reason) > 2000) {
                $this->fail('reason', 'Provide a cancellation reason of up to 2,000 characters.');
            }
            if (DB::table('supplier_payables')->where('supplier_bill_id', $bill->getKey())->exists()) {
                $this->fail('payable', 'A bill with a payable entry cannot be cancelled.');
            }
            $old = $this->snapshot($bill);
            $bill->fill(['status' => 'cancelled', 'cancelled_by' => $actor->getKey(), 'cancelled_at' => now()->toDateTimeString(), 'revision' => $revision + 1])->save();
            $this->audit($bill, $actor, 'cancelled', $old, $reason);

            return $bill;
        }, 5);
    }

    /** @return array{GoodsReceipt, PurchaseOrder} */
    private function lockSource(GoodsReceipt $receipt): array
    {
        $order = PurchaseOrder::query()->where('company_id', $receipt->company_id)->whereKey($receipt->purchase_order_id)->lockForUpdate()->firstOrFail();
        $receipt = GoodsReceipt::query()->where('company_id', $order->company_id)->where('purchase_order_id', $order->getKey())->whereKey($receipt->getKey())->lockForUpdate()->firstOrFail();
        if ($receipt->status !== 'posted') {
            $this->fail('goods_receipt_id', 'Only posted goods receipts can be billed.');
        }
        if (! Supplier::query()->where('company_id', $receipt->company_id)->whereKey($order->supplier_id)->exists()) {
            $this->fail('supplier_id', 'The source supplier does not belong to this company.');
        }

        return [$receipt, $order];
    }

    private function checkHeader(SupplierBill $bill, GoodsReceipt $receipt, PurchaseOrder $order): void
    {
        if ((int) $bill->company_id !== (int) $receipt->company_id || (int) $bill->supplier_id !== (int) $order->supplier_id || (int) $bill->purchase_order_id !== (int) $order->getKey()
            || $bill->currency_code !== $order->currency_code || ! preg_match('/^[A-Z]{3}$/D', (string) $bill->currency_code)) {
            $this->fail('goods_receipt_id', 'Bill header does not match its source purchasing document.');
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function header(array $data, int $supplierId, int $companyId, ?int $except = null): array
    {
        // Canonical vendor invoice identity: case-insensitive, trimmed and single-spaced.
        $invoice = mb_strtoupper((string) preg_replace('/\s+/u', ' ', trim((string) $data['supplier_invoice_number'])));
        if ($invoice === '' || mb_strlen($invoice) > 100) {
            $this->fail('supplier_invoice_number', 'Enter a supplier invoice number of up to 100 characters.');
        }
        $claim = app(AccountingExpenseContract::class)->claim(['company_id' => $companyId, 'payee_type' => 'supplier', 'supplier_id' => $supplierId, 'document_reference' => $invoice]);
        if (AccountingExpense::query()->where('company_id', $companyId)->where('active_claim_hash', $claim)->exists()) {
            $this->fail('supplier_invoice_number', 'This supplier invoice is already reserved by a standalone expense. Use its existing finance/settlement path.');
        }
        $duplicate = SupplierBill::query()->where('company_id', $companyId)->where('supplier_id', $supplierId);
        if ($except !== null) {
            $duplicate->whereKeyNot($except);
        }
        $exists = $duplicate->pluck('supplier_invoice_number')->contains(fn ($number): bool => mb_strtoupper((string) preg_replace('/\s+/u', ' ', trim((string) $number))) === $invoice);
        if ($exists) {
            $this->fail('supplier_invoice_number', 'This supplier invoice number is already registered, including cancelled drafts. Edit the original draft or use the supplier’s corrected invoice number.');
        }
        $date = (string) $data['invoice_date'];
        $due = empty($data['due_date']) ? null : (string) $data['due_date'];
        if (! $this->validDate($date) || $date > now()->toDateString() || ($due !== null && (! $this->validDate($due) || $due < $date))) {
            $this->fail('invoice_date', 'Use a valid, nonfuture invoice date and a due date on or after it.');
        }

        return ['supplier_invoice_number' => $invoice, 'invoice_date' => $date, 'due_date' => $due, 'notes' => $data['notes'] ?? null];
    }

    private function validDate(string $date): bool
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts)) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    /** @param array<int, array<string, mixed>> $items @return list<array<string, mixed>> */
    private function prepare(GoodsReceipt $receipt, array $items): array
    {
        $prepared = [];
        $seen = [];
        foreach ($items as $index => $item) {
            $id = (int) ($item['goods_receipt_item_id'] ?? 0);
            if (isset($seen[$id])) {
                $this->fail('items', 'Each receipt line can appear only once per bill.');
            }
            $seen[$id] = true;
            $qty = $this->math->scaled((string) ($item['quantity'] ?? ''), 4);
            $source = GoodsReceiptItem::query()->where('goods_receipt_id', $receipt->getKey())->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($qty === 0) {
                continue;
            }
            if (bccomp((string) $item['quantity'], $this->remaining($source), 4) > 0) {
                $this->fail('items.'.$index.'.quantity', 'Invoice quantity exceeds unbilled accepted quantity after posted returns.');
            }
            $orderLine = PurchaseOrderItem::query()->where('purchase_order_id', $receipt->purchase_order_id)->findOrFail($source->purchase_order_item_id);
            $ordered = $this->math->scaled((string) $orderLine->quantity, 4);
            if ($ordered === 0) {
                $this->fail('items', 'Source order quantity must be positive.');
            }
            $discount = $this->proportion((string) $orderLine->discount_amount, $qty, $ordered);
            $tax = $this->proportion((string) $orderLine->tax_amount, $qty, $ordered);
            $total = $this->math->line((string) $item['quantity'], (string) $orderLine->unit_price, $discount, $tax);
            $description = trim((string) $orderLine->product_name.' '.(string) $orderLine->variant_name);
            $prepared[] = ['goods_receipt_item_id' => $id, 'purchase_order_item_id' => (int) $orderLine->getKey(), 'description' => mb_substr($description, 0, 255),
                'quantity' => bcadd((string) $item['quantity'], '0', 4), 'unit_price' => (string) $orderLine->unit_price,
                'discount_amount' => $discount, 'tax_amount' => $tax, 'line_total' => $this->math->format($total)];
        }
        if ($prepared === []) {
            $this->fail('items', 'Enter at least one positive invoice quantity.');
        }

        return $prepared;
    }

    private function proportion(string $amount, int $qty, int $ordered): string
    {
        // BCMath avoids integer overflow for a full PO amount multiplied by four-place quantity.
        $cents = $this->math->scaled($amount, 2, 1000000000000);
        $ratio = bcdiv(bcmul((string) $cents, (string) $qty, 0), (string) $ordered, 8);
        $rounded = (int) bcadd($ratio, '0.5', 0);

        return $this->math->format($rounded);
    }

    /** @param list<array<string, mixed>> $lines @return array<string, string> */
    private function totals(array $lines): array
    {
        $subtotal = $tax = $discount = $grand = 0;
        foreach ($lines as $line) {
            $lineGrand = $this->math->scaled((string) $line['line_total'], 2, 1000000000000);
            $lineTax = $this->math->scaled((string) $line['tax_amount'], 2, 1000000000000);
            $lineDiscount = $this->math->scaled((string) $line['discount_amount'], 2, 1000000000000);
            $subtotal += $lineGrand + $lineDiscount - $lineTax;
            $tax += $lineTax;
            $discount += $lineDiscount;
            $grand += $lineGrand;
        }
        if ($grand > 100000000000000 || $subtotal > 100000000000000 || $tax > 100000000000000 || $discount > 100000000000000) {
            $this->fail('totals', 'Bill total exceeds the supported amount.');
        }

        return ['subtotal' => $this->math->format($subtotal), 'tax_total' => $this->math->format($tax), 'discount_total' => $this->math->format($discount), 'grand_total' => $this->math->format($grand)];
    }

    /** @param list<array<string, mixed>> $lines */
    private function saveLines(SupplierBill $bill, array $lines): void
    {
        foreach ($lines as $line) {
            SupplierBillItem::query()->create(['supplier_bill_id' => $bill->getKey(), ...$line]);
        }
    }

    private function remaining(GoodsReceiptItem $source): string
    {
        $remaining = bcsub(bcsub((string) $source->quantity_received, $this->returned((int) $source->getKey()), 4), $this->billed((int) $source->getKey()), 4);

        return bccomp($remaining, '0', 4) < 0 ? '0.0000' : $remaining;
    }

    public function creditReviewQuantity(GoodsReceiptItem $source): string
    {
        $excess = bcsub(bcadd($this->billed((int) $source->getKey()), $this->returned((int) $source->getKey()), 4), (string) $source->quantity_received, 4);

        $credited = '0.0000';
        $companyId = GoodsReceipt::query()->findOrFail($source->goods_receipt_id)->company_id;
        foreach (DB::table('accounting_supplier_credit_lines as cl')->join('accounting_supplier_credits as c', 'c.id', '=', 'cl.supplier_credit_id')->join('purchase_return_items as ri', 'ri.id', '=', 'cl.purchase_return_item_id')
            ->where('c.company_id', $companyId)->whereColumn('cl.company_id', 'c.company_id')->where('c.status', 'issued')->where('ri.goods_receipt_item_id', $source->getKey())->pluck('cl.quantity') as $quantity) {
            $credited = bcadd($credited, (string) $quantity, 4);
        }
        $excess = bcsub($excess, $credited, 4);

        return bccomp($excess, '0', 4) > 0 ? $excess : '0.0000';
    }

    private function billed(int $sourceId): string
    {
        $total = '0.0000';
        foreach (DB::table('supplier_bill_items')->join('supplier_bills', 'supplier_bills.id', '=', 'supplier_bill_items.supplier_bill_id')
            ->where('supplier_bill_items.goods_receipt_item_id', $sourceId)->where('supplier_bills.status', 'approved')->pluck('supplier_bill_items.quantity') as $quantity) {
            $total = bcadd($total, (string) $quantity, 4);
        }

        return $total;
    }

    private function returned(int $sourceId): string
    {
        $total = '0.0000';
        foreach (DB::table('purchase_return_items')->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_items.purchase_return_id')
            ->where('purchase_return_items.goods_receipt_item_id', $sourceId)->where('purchase_returns.status', 'posted')->pluck('purchase_return_items.quantity') as $quantity) {
            $total = bcadd($total, (string) $quantity, 4);
        }

        return $total;
    }

    private function state(SupplierBill $bill, int $revision, string $status): void
    {
        if ((int) $bill->getAttribute('revision') !== $revision) {
            $this->fail('revision', 'This bill changed. Reload the page before trying again.');
        }
        if ($bill->status !== $status) {
            $this->fail('status', 'This action is not allowed in the current bill status.');
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(SupplierBill $bill): array
    {
        return [...$bill->only(['company_id', 'purchase_order_id', 'goods_receipt_id', 'supplier_id', 'number', 'created_by', 'supplier_invoice_number', 'invoice_date', 'due_date', 'notes', 'currency_code', 'status', 'revision', 'subtotal', 'tax_total', 'discount_total', 'grand_total', 'approved_by', 'approved_at', 'cancelled_by', 'cancelled_at']),
            'items' => SupplierBillItem::query()->where('supplier_bill_id', $bill->getKey())->get()->toArray()];
    }

    /** @param array<string, mixed>|null $old */
    private function audit(SupplierBill $bill, User $actor, string $action, ?array $old, ?string $comment): void
    {
        DB::table('supplier_bill_audit_logs')->insert(['company_id' => $bill->company_id, 'supplier_bill_id' => $bill->getKey(), 'actor_id' => $actor->getKey(), 'action' => $action,
            'old_values' => $old === null ? null : json_encode($old, JSON_THROW_ON_ERROR), 'new_values' => json_encode($this->snapshot($bill), JSON_THROW_ON_ERROR), 'comment' => $comment, 'created_at' => now()]);
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
