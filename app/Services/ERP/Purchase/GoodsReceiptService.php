<?php

declare(strict_types=1);

namespace App\Services\ERP\Purchase;

use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ERP\Accounting\Source\Purchase\AccountingInventoryContext;
use App\Services\ERP\Accounting\Source\Purchase\AccountingPurchasePosting;
use App\Services\Inventory\InventoryService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GoodsReceiptService
{
    public function __construct(
        private readonly PurchaseCompanyAccess $access,
        private readonly PurchaseRequisitionService $companiesAccess,
        private readonly PurchaseDocumentNumberService $numbers,
        private readonly PurchaseQuoteMath $math,
        private readonly InventoryService $inventory,
        private readonly AccountingPurchasePosting $accounting,
    ) {}

    /** @return Builder<Company> */
    public function companies(User $actor, string $permission): Builder
    {
        return $this->companiesAccess->companies($actor, $permission);
    }

    /** @return Builder<GoodsReceipt> */
    public function visible(User $actor, string $permission = 'erp.goods-receipts.view'): Builder
    {
        return GoodsReceipt::query()->whereIn('company_id', $this->companies($actor, $permission)->select('id'));
    }

    public function find(User $actor, int $id, string $permission): GoodsReceipt
    {
        $receipt = $this->visible($actor, $permission)->findOrFail($id);
        $this->access->authorize($actor, (int) $receipt->company_id, $permission);

        return $receipt;
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): GoodsReceipt
    {
        $companyId = (int) $data['company_id'];
        $this->access->authorize($actor, $companyId, 'erp.goods-receipts.create');

        return DB::transaction(function () use ($actor, $companyId, $data): GoodsReceipt {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $order = PurchaseOrder::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($data['purchase_order_id']);
            $this->receivable($order);
            $this->warehouse($companyId, (int) $data['warehouse_id']);
            $prepared = $this->prepare($order, $data['items']);
            $receipt = GoodsReceipt::query()->create([
                'company_id' => $companyId, 'purchase_order_id' => $order->getKey(), 'warehouse_id' => (int) $data['warehouse_id'],
                'number' => $this->numbers->next($companyId, 'receipt'), 'status' => 'draft', 'revision' => 1,
                'received_at' => Carbon::parse($data['received_at'])->toDateTimeString(), 'created_by' => $actor->getKey(), 'notes' => $data['notes'] ?? null,
            ]);
            $this->replaceItems($receipt, $prepared);
            $this->audit($actor, $receipt, 'created', null);

            return $receipt->refresh();
        }, 5);
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, int $id, array $data): GoodsReceipt
    {
        $peek = $this->find($actor, $id, 'erp.goods-receipts.edit');

        return DB::transaction(function () use ($actor, $id, $data, $peek): GoodsReceipt {
            Company::query()->whereKey($peek->company_id)->lockForUpdate()->firstOrFail();
            $order = PurchaseOrder::query()->where('company_id', $peek->company_id)->lockForUpdate()->findOrFail($peek->purchase_order_id);
            $receipt = $this->visible($actor, 'erp.goods-receipts.edit')->lockForUpdate()->findOrFail($id);
            $this->draft($receipt, (int) $data['revision']);
            $this->receivable($order);
            $this->warehouse((int) $receipt->company_id, (int) $data['warehouse_id']);
            $prepared = $this->prepare($order, $data['items']);
            $old = $this->snapshot($receipt);
            $receipt->fill(['warehouse_id' => (int) $data['warehouse_id'], 'received_at' => Carbon::parse($data['received_at'])->toDateTimeString(), 'notes' => $data['notes'] ?? null,
                'revision' => (int) $receipt->getAttribute('revision') + 1]);
            $receipt->save();
            $this->replaceItems($receipt, $prepared);
            $this->audit($actor, $receipt, 'updated', $old);

            return $receipt->refresh();
        }, 5);
    }

    public function post(User $actor, int $id, int $revision): GoodsReceipt
    {
        $peek = $this->find($actor, $id, 'erp.goods-receipts.post');

        return DB::transaction(function () use ($actor, $id, $revision, $peek): GoodsReceipt {
            $this->accounting->before((int) $peek->company_id);

            return AccountingInventoryContext::run((int) $peek->company_id, 'goods_receipt', $id, (int) $actor->getKey(), function () use ($actor, $id, $revision, $peek): GoodsReceipt {
                // Company lock is held; continue with order -> receipt -> warehouse -> sorted product/variant locks.
                $order = PurchaseOrder::query()->where('company_id', $peek->company_id)->lockForUpdate()->findOrFail($peek->purchase_order_id);
                $receipt = $this->visible($actor, 'erp.goods-receipts.post')->lockForUpdate()->findOrFail($id);
                if ((int) $receipt->purchase_order_id !== (int) $order->getKey()) {
                    throw ValidationException::withMessages(['purchase_order_id' => 'The receipt source order changed. Reload before posting.']);
                }
                if ($receipt->status === 'posted') {
                    $this->accounting->complete($actor, (int) $receipt->company_id, 'goods_receipt', (int) $receipt->getKey(), false);

                    return $receipt; // Successful retry: never append stock twice.
                }
                $this->draft($receipt, $revision);
                $this->receivable($order);
                $warehouse = Warehouse::query()->where('company_id', $receipt->company_id)->where('is_active', true)->lockForUpdate()->findOrFail($receipt->warehouse_id);
                $company = Company::query()->findOrFail($receipt->company_id);
                if (strtoupper((string) $company->currency_code) !== strtoupper((string) $order->currency_code)) {
                    throw ValidationException::withMessages(['currency' => 'Inventory valuation requires the order and current company currency to match.']);
                }
                $stored = GoodsReceiptItem::query()->where('goods_receipt_id', $id)->get();
                $input = $stored->map(fn (GoodsReceiptItem $item): array => $item->only(['purchase_order_item_id', 'quantity_received', 'quantity_rejected']))->all();
                $prepared = $this->prepare($order, $input);
                $byLine = $stored->keyBy('purchase_order_item_id');
                $old = $this->snapshot($receipt);
                $beforeStatus = (string) $order->status;
                $oldOrder = $order->attributesToArray();
                $rawReceivedAt = $receipt->getRawOriginal('received_at');
                if (! is_string($rawReceivedAt) || $rawReceivedAt === '') {
                    throw ValidationException::withMessages(['received_at' => 'Record an actual received date and time before posting.']);
                }
                $occurredAt = Carbon::parse($rawReceivedAt);
                if ($occurredAt->isFuture()) {
                    throw ValidationException::withMessages(['received_at' => 'Received time cannot be in the future.']);
                }
                foreach ($prepared as $line) {
                    $item = $byLine->get($line['purchase_order_item_id']);
                    if (! $item instanceof GoodsReceiptItem || $item->stock_movement_id !== null
                        || (bool) $item->getAttribute('is_stock_item') !== $line['is_stock_item']
                        || bccomp((string) $item->unit_cost, $line['unit_cost'], 4) !== 0) {
                        throw ValidationException::withMessages(['items' => 'Receipt cost, inventory classification or movement links changed. Refresh the draft before posting.']);
                    }
                    $poItem = PurchaseOrderItem::query()->where('purchase_order_id', $order->getKey())->lockForUpdate()->findOrFail($line['purchase_order_item_id']);
                    $product = Product::query()->where('company_id', $order->company_id)->where('is_active', true)->lockForUpdate()->findOrFail($poItem->product_id);
                    $variant = $poItem->product_variant_id === null ? null : ProductVariant::query()->where('product_id', $product->getKey())->where('is_active', true)->lockForUpdate()->findOrFail($poItem->product_variant_id);
                    if ($product->isStockItem() !== $line['is_stock_item'] || (bool) $product->has_variants !== ($variant !== null)) {
                        throw ValidationException::withMessages(['items' => 'The product inventory/variant configuration changed. Refresh the receipt draft.']);
                    }
                    if ($line['is_stock_item'] && bccomp($line['quantity_received'], '0', 4) > 0) {
                        $movement = $this->inventory->stockIn(
                            warehouse: $warehouse, product: $product, quantity: $line['quantity_received'], movementType: StockMovement::TYPE_PURCHASE_RECEIPT,
                            unitCost: $line['unit_cost'], variant: $variant, referenceType: GoodsReceipt::class, referenceId: (int) $receipt->getKey(),
                            referenceNumber: (string) $receipt->number, occurredAt: $occurredAt, createdBy: (int) $actor->getKey(), notes: $receipt->notes,
                        );
                        $item->setAttribute('stock_movement_id', $movement->getKey());
                        $item->save();
                    }
                    $poItem->setAttribute('received_quantity', bcadd((string) $poItem->received_quantity, $line['quantity_received'], 4));
                    $poItem->save();
                }
                $all = PurchaseOrderItem::query()->where('purchase_order_id', $order->getKey())->get();
                $complete = $all->every(fn (PurchaseOrderItem $item): bool => bccomp((string) $item->received_quantity, (string) $item->quantity, 4) === 0);
                $any = $all->contains(fn (PurchaseOrderItem $item): bool => bccomp((string) $item->received_quantity, '0', 4) > 0);
                $newStatus = $complete ? 'received' : ($any ? 'partially_received' : 'ordered');
                $order->fill(['status' => $newStatus, 'revision' => (int) $order->getAttribute('revision') + 1]);
                $order->save();
                $receipt->fill(['status' => 'posted', 'posted_by' => $actor->getKey(), 'posted_at' => now()->toDateTimeString(), 'revision' => $revision + 1]);
                $receipt->save();
                if ($beforeStatus !== $newStatus) {
                    DB::table('purchase_workflow_events')->insert(['company_id' => $order->company_id, 'actor_id' => $actor->getKey(), 'document_type' => 'order', 'document_id' => $order->getKey(),
                        'from_status' => $beforeStatus, 'to_status' => $newStatus, 'comment' => 'Goods receipt '.$receipt->number, 'created_at' => now()]);
                }
                DB::table('purchase_order_audit_logs')->insert(['company_id' => $order->company_id, 'purchase_order_id' => $order->getKey(), 'actor_id' => $actor->getKey(), 'action' => 'goods_receipt',
                    'old_values' => json_encode($oldOrder, JSON_THROW_ON_ERROR), 'new_values' => json_encode([...$order->attributesToArray(), 'goods_receipt_id' => $id], JSON_THROW_ON_ERROR), 'comment' => (string) $receipt->number, 'created_at' => now()]);
                $this->audit($actor, $receipt, 'posted', $old);

                $this->accounting->complete($actor, (int) $receipt->company_id, 'goods_receipt', (int) $receipt->getKey(), true);

                return $receipt->refresh();
            });
        }, 5);
    }

    public function cancel(User $actor, int $id, int $revision, string $reason): GoodsReceipt
    {
        $peek = $this->find($actor, $id, 'erp.goods-receipts.cancel');

        return DB::transaction(function () use ($actor, $id, $revision, $reason, $peek): GoodsReceipt {
            Company::query()->whereKey($peek->company_id)->lockForUpdate()->firstOrFail();
            PurchaseOrder::query()->where('company_id', $peek->company_id)->lockForUpdate()->findOrFail($peek->purchase_order_id);
            $receipt = $this->visible($actor, 'erp.goods-receipts.cancel')->lockForUpdate()->findOrFail($id);
            $this->draft($receipt, $revision);
            if (trim($reason) === '') {
                throw ValidationException::withMessages(['reason' => 'Record a cancellation reason.']);
            }
            $old = $this->snapshot($receipt);
            $receipt->fill(['status' => 'cancelled', 'cancelled_by' => $actor->getKey(), 'cancelled_at' => now()->toDateTimeString(), 'revision' => $revision + 1]);
            $receipt->save();
            $this->audit($actor, $receipt, 'cancelled', $old, trim($reason));

            return $receipt->refresh();
        }, 5);
    }

    public function closeOrder(User $actor, int $id, int $revision, string $comment): PurchaseOrder
    {
        $query = PurchaseOrder::query()->whereIn('company_id', $this->companies($actor, 'erp.purchase-orders.close')->select('id'));
        $peek = (clone $query)->findOrFail($id);
        $this->access->authorize($actor, (int) $peek->company_id, 'erp.purchase-orders.close');
        if (trim($comment) === '') {
            throw ValidationException::withMessages(['comment' => 'Record a reason for closing the order.']);
        }

        return DB::transaction(function () use ($actor, $id, $revision, $comment, $peek): PurchaseOrder {
            Company::query()->whereKey($peek->company_id)->lockForUpdate()->firstOrFail();
            $order = PurchaseOrder::query()->where('company_id', $peek->company_id)->lockForUpdate()->findOrFail($id);
            if ($order->status !== 'received') {
                throw ValidationException::withMessages(['status' => 'Only fully received orders can be closed.']);
            }
            if ((int) $order->getAttribute('revision') !== $revision) {
                throw ValidationException::withMessages(['revision' => 'The order changed in another session. Reload it.']);
            }
            $items = PurchaseOrderItem::query()->where('purchase_order_id', $id)->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'This order has no lines to close.']);
            }
            $posted = GoodsReceiptItem::query()->whereIn('purchase_order_item_id', $items->pluck('id'))->whereHas('goods_receipt', fn ($query) => $query
                ->where('purchase_order_id', $id)->where('company_id', $order->company_id)->where('status', 'posted'))->get();
            $totals = [];
            foreach ($posted as $line) {
                $key = (int) $line->purchase_order_item_id;
                $totals[$key] = bcadd($totals[$key] ?? '0', (string) $line->quantity_received, 4);
            }
            foreach ($items as $item) {
                if (bccomp((string) $item->received_quantity, (string) $item->quantity, 4) !== 0
                    || bccomp($totals[(int) $item->getKey()] ?? '0', (string) $item->quantity, 4) !== 0) {
                    throw ValidationException::withMessages(['items' => 'Every order line must be fully accepted in posted receipt history.']);
                }
            }
            $old = $order->attributesToArray();
            $order->fill(['status' => 'closed', 'closed_at' => now()->toDateTimeString(), 'revision' => $revision + 1]);
            $order->save();
            DB::table('purchase_workflow_events')->insert(['company_id' => $order->company_id, 'actor_id' => $actor->getKey(), 'document_type' => 'order', 'document_id' => $id,
                'from_status' => 'received', 'to_status' => 'closed', 'comment' => $comment, 'created_at' => now()]);
            DB::table('purchase_order_audit_logs')->insert(['company_id' => $order->company_id, 'purchase_order_id' => $id, 'actor_id' => $actor->getKey(), 'action' => 'closed',
                'old_values' => json_encode($old, JSON_THROW_ON_ERROR), 'new_values' => json_encode($order->attributesToArray(), JSON_THROW_ON_ERROR), 'comment' => $comment, 'created_at' => now()]);

            return $order->refresh();
        }, 5);
    }

    /** @param array<int, array<string, mixed>> $inputs @return array<int, array<string, mixed>> */
    private function prepare(PurchaseOrder $order, array $inputs): array
    {
        $poItems = PurchaseOrderItem::query()->where('purchase_order_id', $order->getKey())->orderBy('product_id')->orderBy('product_variant_id')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $posted = GoodsReceiptItem::query()->whereIn('purchase_order_item_id', $poItems->keys())->whereHas('goods_receipt', fn ($query) => $query
            ->where('status', 'posted')->where('company_id', $order->company_id)->where('purchase_order_id', $order->getKey()))->get();
        $totals = [];
        foreach ($posted as $line) {
            $key = (int) $line->purchase_order_item_id;
            $totals[$key] = bcadd($totals[$key] ?? '0', (string) $line->quantity_received, 4);
        }
        foreach ($poItems as $item) {
            if (bccomp((string) $item->received_quantity, $totals[(int) $item->getKey()] ?? '0', 4) !== 0) {
                throw ValidationException::withMessages(['purchase_order_id' => 'Order received quantities do not match posted receipt history.']);
            }
        }
        $seen = [];
        $prepared = [];
        foreach ($inputs as $index => $input) {
            $key = (int) $input['purchase_order_item_id'];
            $item = $poItems->get($key);
            if (! $item instanceof PurchaseOrderItem || isset($seen[$key])) {
                throw ValidationException::withMessages(['items.'.$index.'.purchase_order_item_id' => 'Select each line from this order at most once.']);
            }
            $seen[$key] = true;
            $accepted = $this->math->scaled((string) $input['quantity_received'], 4);
            $rejected = $this->math->scaled((string) ($input['quantity_rejected'] ?? '0'), 4);
            if ($accepted + $rejected === 0) {
                continue;
            }
            $remaining = $this->math->scaled((string) $item->quantity, 4) - $this->math->scaled((string) $item->received_quantity, 4);
            if ($accepted + $rejected > $remaining) {
                throw ValidationException::withMessages(['items.'.$index.'.quantity_received' => 'Accepted plus rejected quantity exceeds this line’s outstanding quantity. Reload or edit the draft.']);
            }
            $product = Product::query()->where('company_id', $order->company_id)->where('is_active', true)->findOrFail($item->product_id);
            $variant = $item->product_variant_id === null ? null : ProductVariant::query()->where('product_id', $product->getKey())->where('is_active', true)->findOrFail($item->product_variant_id);
            if ((bool) $product->has_variants !== ($variant !== null)) {
                throw ValidationException::withMessages(['items' => 'Product variant configuration does not match the ordered line.']);
            }
            $baseCents = $this->math->line((string) $item->quantity, (string) $item->unit_price, '0', '0');
            $discountCents = $this->math->scaled((string) $item->discount_amount, 2, 1000000000000);
            if ($discountCents > $baseCents) {
                throw ValidationException::withMessages(['items' => 'Order discount exceeds its line amount.']);
            }
            $cost = bcadd(bcdiv($this->math->format($baseCents - $discountCents), (string) $item->quantity, 8), '0.00005', 4);
            $prepared[$key] = ['purchase_order_item_id' => $key, 'quantity_received' => bcadd((string) $input['quantity_received'], '0', 4),
                'quantity_rejected' => bcadd((string) ($input['quantity_rejected'] ?? '0'), '0', 4), 'unit_cost' => $cost, 'is_stock_item' => $product->isStockItem()];
        }
        if ($prepared === []) {
            throw ValidationException::withMessages(['items' => 'Enter a positive accepted or rejected quantity on at least one line.']);
        }
        // Return in stable product/variant order for the inventory lock sequence.
        $sorted = [];
        foreach ($poItems as $item) {
            if (isset($prepared[(int) $item->getKey()])) {
                $sorted[] = $prepared[(int) $item->getKey()];
            }
        }

        return $sorted;
    }

    private function receivable(PurchaseOrder $order): void
    {
        if (! in_array($order->status, ['ordered', 'partially_received'], true)) {
            throw ValidationException::withMessages(['purchase_order_id' => 'Only ordered or partially received orders can receive goods.']);
        }
    }

    private function warehouse(int $companyId, int $id): Warehouse
    {
        return Warehouse::query()->where('company_id', $companyId)->where('is_active', true)->findOrFail($id);
    }

    private function draft(GoodsReceipt $receipt, int $revision): void
    {
        if ($receipt->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only draft receipts can be changed.']);
        }
        if ((int) $receipt->getAttribute('revision') !== $revision) {
            throw ValidationException::withMessages(['revision' => 'This receipt changed in another session. Reload before continuing.']);
        }
    }

    /** @param array<int, array<string, mixed>> $items */
    private function replaceItems(GoodsReceipt $receipt, array $items): void
    {
        GoodsReceiptItem::query()->where('goods_receipt_id', $receipt->getKey())->delete();
        foreach ($items as $item) {
            GoodsReceiptItem::query()->create([...$item, 'goods_receipt_id' => $receipt->getKey()]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(GoodsReceipt $receipt): array
    {
        return [...$receipt->attributesToArray(), 'items' => GoodsReceiptItem::query()->where('goods_receipt_id', $receipt->getKey())->get()->toArray()];
    }

    /** @param array<string, mixed>|null $old */
    private function audit(User $actor, GoodsReceipt $receipt, string $action, ?array $old, ?string $comment = null): void
    {
        DB::table('goods_receipt_audit_logs')->insert(['company_id' => $receipt->company_id, 'goods_receipt_id' => $receipt->getKey(), 'actor_id' => $actor->getKey(), 'action' => $action,
            'old_values' => $old === null ? null : json_encode($old, JSON_THROW_ON_ERROR), 'new_values' => json_encode($this->snapshot($receipt), JSON_THROW_ON_ERROR), 'comment' => $comment, 'created_at' => now()]);
    }
}
