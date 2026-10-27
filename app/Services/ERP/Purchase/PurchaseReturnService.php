<?php

declare(strict_types=1);

namespace App\Services\ERP\Purchase;

use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ERP\Accounting\Source\Purchase\AccountingInventoryContext;
use App\Services\ERP\Accounting\Source\Purchase\AccountingPurchasePosting;
use App\Services\Inventory\InventoryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PurchaseReturnService
{
    public function __construct(
        private readonly PurchaseCompanyAccess $access,
        private readonly PurchaseRequisitionService $requisitions,
        private readonly PurchaseDocumentNumberService $numbers,
        private readonly InventoryService $inventory,
        private readonly AccountingPurchasePosting $accounting,
    ) {}

    /** @return Builder<Company> */
    public function companies(User $actor, string $permission): Builder
    {
        return $this->requisitions->companies($actor, $permission);
    }

    /** @return Builder<PurchaseReturn> */
    public function visible(User $actor, string $permission = 'erp.purchase-returns.view'): Builder
    {
        return PurchaseReturn::query()->whereIn('company_id', $this->companies($actor, $permission)->select('id'));
    }

    public function find(User $actor, int $id, string $permission): PurchaseReturn
    {
        $record = $this->visible($actor, $permission)->findOrFail($id);
        $this->access->authorize($actor, (int) $record->company_id, $permission);

        return $record;
    }

    /** @return Builder<GoodsReceipt> */
    public function sources(User $actor): Builder
    {
        return GoodsReceipt::query()->where('status', 'posted')->whereIn('company_id', $this->companies($actor, 'erp.purchase-returns.create')->select('id'));
    }

    /** @return list<array<string, mixed>> */
    public function sourceLines(GoodsReceipt $receipt): array
    {
        return GoodsReceiptItem::query()->where('goods_receipt_id', $receipt->getKey())->orderBy('id')->get()->map(function (GoodsReceiptItem $line) use ($receipt): array {
            $orderLine = PurchaseOrderItem::query()->where('purchase_order_id', $receipt->purchase_order_id)->findOrFail($line->purchase_order_item_id);
            $returned = $this->returned((int) $line->getKey());

            return ['id' => (int) $line->getKey(), 'product_name' => $orderLine->product_name, 'variant_name' => $orderLine->variant_name,
                'accepted' => (string) $line->quantity_received, 'returned' => $returned,
                'remaining' => bcsub((string) $line->quantity_received, $returned, 4), 'unit_cost' => (string) $line->unit_cost, 'is_stock_item' => (bool) $line->getAttribute('is_stock_item')];
        })->all();
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): PurchaseReturn
    {
        $receipt = $this->sources($actor)->findOrFail((int) $data['goods_receipt_id']);
        $this->access->authorize($actor, (int) $receipt->company_id, 'erp.purchase-returns.create');

        return DB::transaction(function () use ($actor, $data, $receipt): PurchaseReturn {
            // Same source lock order as goods receiving: PO, GRN, warehouse, products.
            Company::query()->whereKey($receipt->company_id)->lockForUpdate()->firstOrFail();
            $receipt = $this->lockSource($receipt);
            $prepared = $this->prepare($receipt, $data['items']);
            $record = PurchaseReturn::query()->create(['company_id' => $receipt->company_id, 'purchase_order_id' => $receipt->purchase_order_id,
                'goods_receipt_id' => $receipt->getKey(), 'supplier_id' => PurchaseOrder::query()->findOrFail($receipt->purchase_order_id)->supplier_id,
                'warehouse_id' => $receipt->warehouse_id, 'number' => $this->numbers->next((int) $receipt->company_id, 'return'),
                'status' => 'draft', 'revision' => 1, 'created_by' => $actor->getKey(), 'reason' => $data['reason']]);
            foreach ($prepared as $line) {
                PurchaseReturnItem::query()->create(['purchase_return_id' => $record->getKey(), ...$line]);
            }
            $this->audit($record, $actor, 'created', null, (string) $record->reason);

            return $record;
        }, 5);
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, int $id, array $data): PurchaseReturn
    {
        $record = $this->find($actor, $id, 'erp.purchase-returns.edit');

        return DB::transaction(function () use ($actor, $record, $data): PurchaseReturn {
            Company::query()->whereKey($record->company_id)->lockForUpdate()->firstOrFail();
            $receipt = $this->lockSource(GoodsReceipt::query()->where('company_id', $record->company_id)->where('purchase_order_id', $record->purchase_order_id)->where('warehouse_id', $record->warehouse_id)->findOrFail($record->goods_receipt_id));
            $record = PurchaseReturn::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $this->state($record, (int) $data['revision'], ['draft']);
            $this->checkHeader($record, $receipt);
            $old = $this->snapshot($record);
            $prepared = $this->prepare($receipt, $data['items']);
            PurchaseReturnItem::query()->where('purchase_return_id', $record->getKey())->delete();
            foreach ($prepared as $line) {
                PurchaseReturnItem::query()->create(['purchase_return_id' => $record->getKey(), ...$line]);
            }
            $record->fill(['reason' => $data['reason'], 'revision' => (int) $record->getAttribute('revision') + 1])->save();
            $this->audit($record, $actor, 'updated', $old, (string) $record->reason);

            return $record;
        }, 5);
    }

    public function approve(User $actor, int $id, int $revision): PurchaseReturn
    {
        $record = $this->find($actor, $id, 'erp.purchase-returns.approve');

        return DB::transaction(function () use ($actor, $record, $revision): PurchaseReturn {
            Company::query()->whereKey($record->company_id)->lockForUpdate()->firstOrFail();
            $receipt = $this->lockSource(GoodsReceipt::query()->where('company_id', $record->company_id)->where('purchase_order_id', $record->purchase_order_id)->where('warehouse_id', $record->warehouse_id)->findOrFail($record->goods_receipt_id));
            $record = PurchaseReturn::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $this->state($record, $revision, ['draft']);
            $this->checkHeader($record, $receipt);
            if ((int) $record->created_by === (int) $actor->getKey()) {
                $this->fail('approval', 'A different authorized user must approve this return.');
            }
            $this->validateStored($record, $receipt);
            $old = $this->snapshot($record);
            $record->fill(['status' => 'approved', 'approved_by' => $actor->getKey(), 'approved_at' => now()->toDateTimeString(), 'revision' => $revision + 1])->save();
            $this->audit($record, $actor, 'approved', $old, null);

            return $record;
        }, 5);
    }

    public function cancel(User $actor, int $id, int $revision, string $reason): PurchaseReturn
    {
        $record = $this->find($actor, $id, 'erp.purchase-returns.cancel');

        return DB::transaction(function () use ($record, $actor, $revision, $reason): PurchaseReturn {
            Company::query()->whereKey($record->company_id)->lockForUpdate()->firstOrFail();
            $record = PurchaseReturn::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $this->state($record, $revision, ['draft', 'approved']);
            if (trim($reason) === '' || mb_strlen($reason) > 2000) {
                $this->fail('reason', 'Provide a cancellation reason of up to 2,000 characters.');
            }
            $old = $this->snapshot($record);
            $record->fill(['status' => 'cancelled', 'cancelled_by' => $actor->getKey(), 'cancelled_at' => now()->toDateTimeString(), 'revision' => $revision + 1])->save();
            $this->audit($record, $actor, 'cancelled', $old, $reason);

            return $record;
        }, 5);
    }

    public function post(User $actor, int $id, int $revision): PurchaseReturn
    {
        $record = $this->find($actor, $id, 'erp.purchase-returns.post');

        return DB::transaction(function () use ($record, $actor, $revision): PurchaseReturn {
            $this->accounting->before((int) $record->company_id);

            return AccountingInventoryContext::run((int) $record->company_id, 'purchase_return', (int) $record->getKey(), (int) $actor->getKey(), function () use ($record, $actor, $revision): PurchaseReturn {
                $receipt = $this->lockSource(GoodsReceipt::query()->where('company_id', $record->company_id)->where('purchase_order_id', $record->purchase_order_id)->where('warehouse_id', $record->warehouse_id)->findOrFail($record->goods_receipt_id));
                $record = PurchaseReturn::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
                if ($record->status === 'posted') {
                    $this->accounting->complete($actor, (int) $record->company_id, 'purchase_return', (int) $record->getKey(), false);

                    return $record; // Safe retry: no second ledger entry, even with the old revision.
                }
                $this->state($record, $revision, ['approved']);
                $this->checkHeader($record, $receipt);
                if ($record->getAttribute('approved_by') === null || (int) $record->getAttribute('approved_by') === (int) $record->created_by || $record->getAttribute('approved_at') === null) {
                    $this->fail('approval', 'Independent approval is required before posting.');
                }
                $this->validateStored($record, $receipt);
                $order = PurchaseOrder::query()->findOrFail($receipt->purchase_order_id);
                $company = Company::query()->findOrFail($receipt->company_id);
                if (strtoupper((string) $order->currency_code) !== strtoupper((string) $company->currency_code)) {
                    $this->fail('currency', 'Return stock valuation requires the order and company currencies to match.');
                }
                $warehouse = Warehouse::query()->where('company_id', $receipt->company_id)->where('is_active', true)->whereKey($receipt->warehouse_id)->lockForUpdate()->firstOrFail();
                $items = PurchaseReturnItem::query()->where('purchase_return_id', $record->getKey())->get();
                // Stable product/variant order matches receiving and stock-transfer locking.
                $ordered = $items->sortBy(function (PurchaseReturnItem $line): string {
                    $source = GoodsReceiptItem::query()->findOrFail($line->goods_receipt_item_id);
                    $poLine = PurchaseOrderItem::query()->findOrFail($source->purchase_order_item_id);

                    return sprintf('%020d-%020d-%020d', $poLine->product_id, $poLine->product_variant_id ?? 0, $line->getKey());
                });
                $old = $this->snapshot($record);
                foreach ($ordered as $line) {
                    $source = GoodsReceiptItem::query()->where('goods_receipt_id', $receipt->getKey())->findOrFail($line->goods_receipt_item_id);
                    $poLine = PurchaseOrderItem::query()->where('purchase_order_id', $receipt->purchase_order_id)->findOrFail($source->purchase_order_item_id);
                    $product = Product::query()->where('company_id', $receipt->company_id)->whereKey($poLine->product_id)->lockForUpdate()->firstOrFail();
                    $variant = $poLine->product_variant_id === null ? null : ProductVariant::query()->where('product_id', $product->getKey())->whereKey($poLine->product_variant_id)->lockForUpdate()->firstOrFail();
                    if (! $product->is_active || ($variant !== null && ! $variant->is_active) || (bool) $product->has_variants !== ($variant !== null) || $product->isStockItem() !== (bool) $line->getAttribute('is_stock_item')) {
                        $this->fail('items', 'Source product or variant is inactive, or its inventory configuration has changed.');
                    }
                    if (! $product->isStockItem()) {
                        continue; // Non-stock acceptance is operational only, with no inventory ledger.
                    }
                    $balance = InventoryBalance::query()->where('company_id', $receipt->company_id)->where('warehouse_id', $warehouse->getKey())
                        ->where('product_id', $product->getKey())->where('variant_key', $variant?->getKey() ?? 0)->lockForUpdate()->first();
                    if ($balance === null || bccomp(bcsub((string) $balance->quantity_on_hand, (string) $balance->quantity_reserved, 4), (string) $line->quantity, 4) < 0) {
                        $this->fail('stock', 'Insufficient unreserved stock in the original receiving warehouse.');
                    }
                    $movement = $this->inventory->stockOut($warehouse, $product, (string) $line->quantity, StockMovement::TYPE_PURCHASE_RETURN,
                        (string) $balance->average_cost, $variant, PurchaseReturn::class, (int) $record->getKey(), (string) $record->number, now(), (int) $actor->getKey(), (string) $record->reason);
                    $line->fill(['unit_cost' => (string) $movement->unit_cost, 'stock_movement_id' => $movement->getKey()])->save();
                }
                $record->fill(['status' => 'posted', 'posted_by' => $actor->getKey(), 'posted_at' => now()->toDateTimeString(), 'revision' => $revision + 1])->save();
                $this->audit($record, $actor, 'posted', $old, (string) $record->reason);

                $this->accounting->complete($actor, (int) $record->company_id, 'purchase_return', (int) $record->getKey(), true);

                return $record;
            });
        }, 5);
    }

    private function lockSource(GoodsReceipt $receipt): GoodsReceipt
    {
        $order = PurchaseOrder::query()->where('company_id', $receipt->company_id)->whereKey($receipt->purchase_order_id)->lockForUpdate()->firstOrFail();
        $receipt = GoodsReceipt::query()->where('company_id', $order->company_id)->where('purchase_order_id', $order->getKey())->whereKey($receipt->getKey())->lockForUpdate()->firstOrFail();
        if ($receipt->status !== 'posted') {
            $this->fail('goods_receipt_id', 'Only posted receipts can be returned.');
        }
        if (! Supplier::query()->where('company_id', $order->company_id)->whereKey($order->supplier_id)->exists()) {
            $this->fail('goods_receipt_id', 'Source supplier does not belong to this company.');
        }

        return $receipt;
    }

    private function checkHeader(PurchaseReturn $record, GoodsReceipt $receipt): void
    {
        $order = PurchaseOrder::query()->findOrFail($receipt->purchase_order_id);
        if ((int) $record->company_id !== (int) $receipt->company_id || (int) $record->warehouse_id !== (int) $receipt->warehouse_id || (int) $record->purchase_order_id !== (int) $receipt->purchase_order_id || (int) $record->supplier_id !== (int) $order->supplier_id) {
            $this->fail('goods_receipt_id', 'Return header does not match its source receipt.');
        }
    }

    /** @param array<int, array<string, mixed>> $items @return list<array<string, mixed>> */
    private function prepare(GoodsReceipt $receipt, array $items): array
    {
        $prepared = [];
        $seen = [];
        foreach ($items as $index => $item) {
            $id = (int) ($item['goods_receipt_item_id'] ?? 0);
            if (isset($seen[$id])) {
                $this->fail('items', 'Each source receipt line can appear only once.');
            }
            $seen[$id] = true;
            $raw = (string) ($item['quantity'] ?? '');
            if (! preg_match('/^[0-9]+(?:\.[0-9]{1,4})?$/D', $raw) || bccomp($raw, '0', 4) < 0 || bccomp($raw, '1000000', 4) > 0) {
                $this->fail('items.'.$index.'.quantity', 'Use a quantity between 0 and 1,000,000 with up to four decimal places.');
            }
            $source = GoodsReceiptItem::query()->where('goods_receipt_id', $receipt->getKey())->whereKey($id)->lockForUpdate()->firstOrFail();
            if (bccomp($raw, '0', 4) === 0) {
                continue;
            }
            $poLine = PurchaseOrderItem::query()->where('purchase_order_id', $receipt->purchase_order_id)->findOrFail($source->purchase_order_item_id);
            if (bccomp($raw, bcsub((string) $source->quantity_received, $this->returned($id), 4), 4) > 0) {
                $this->fail('items.'.$index.'.quantity', 'Return quantity exceeds the accepted quantity remaining on this receipt line.');
            }
            if ((bool) $source->getAttribute('is_stock_item')) {
                $movement = StockMovement::query()->find($source->stock_movement_id);
                if ($movement === null || $movement->movement_type !== StockMovement::TYPE_PURCHASE_RECEIPT || $movement->direction !== StockMovement::DIRECTION_IN
                    || $movement->reference_type !== GoodsReceipt::class || (int) $movement->reference_id !== (int) $receipt->getKey()
                    || (int) $movement->company_id !== (int) $receipt->company_id || (int) $movement->warehouse_id !== (int) $receipt->warehouse_id
                    || (int) $movement->product_id !== (int) $poLine->product_id || (int) ($movement->product_variant_id ?? 0) !== (int) ($poLine->product_variant_id ?? 0)
                    || bccomp((string) $movement->quantity, (string) $source->quantity_received, 4) !== 0 || bccomp((string) $movement->unit_cost, (string) $source->unit_cost, 4) !== 0) {
                    $this->fail('items', 'Source receipt stock history is incomplete or inconsistent.');
                }
            } elseif ($source->stock_movement_id !== null) {
                $this->fail('items', 'Non-stock receipt line has an unexpected ledger entry.');
            }
            $prepared[] = ['goods_receipt_item_id' => $id, 'quantity' => bcadd($raw, '0', 4), 'unit_cost' => (string) $source->unit_cost,
                'source_unit_cost' => (string) $source->unit_cost, 'is_stock_item' => (bool) $source->getAttribute('is_stock_item')];
        }
        if ($prepared === []) {
            $this->fail('items', 'Enter at least one positive return quantity.');
        }

        return $prepared;
    }

    private function validateStored(PurchaseReturn $record, GoodsReceipt $receipt): void
    {
        $stored = PurchaseReturnItem::query()->where('purchase_return_id', $record->getKey())->get();
        $prepared = $this->prepare($receipt, $stored->map(fn (PurchaseReturnItem $line): array => ['goods_receipt_item_id' => $line->goods_receipt_item_id, 'quantity' => $line->quantity])->all());
        if (count($prepared) !== $stored->count()) {
            $this->fail('items', 'Every saved return line must have a positive quantity.');
        }
        foreach ($prepared as $line) {
            $saved = $stored->firstWhere('goods_receipt_item_id', $line['goods_receipt_item_id']);
            if ($saved === null || $saved->stock_movement_id !== null || $saved->getAttribute('source_unit_cost') === null || $saved->getAttribute('is_stock_item') === null
                || bccomp((string) $saved->getAttribute('source_unit_cost'), (string) $line['source_unit_cost'], 4) !== 0 || (bool) $saved->getAttribute('is_stock_item') !== $line['is_stock_item']) {
                $this->fail('items', 'Saved return details do not match the source. Legacy returns require manual review.');
            }
        }
    }

    private function returned(int $sourceId): string
    {
        $total = '0.0000';
        $quantities = DB::table('purchase_return_items')->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_items.purchase_return_id')
            ->where('goods_receipt_item_id', $sourceId)->where('purchase_returns.status', 'posted')->pluck('quantity');
        foreach ($quantities as $quantity) {
            $total = bcadd($total, (string) $quantity, 4);
        }

        return $total;
    }

    /** @param list<string> $allowed */
    private function state(PurchaseReturn $record, int $revision, array $allowed): void
    {
        if ((int) $record->getAttribute('revision') !== $revision) {
            $this->fail('revision', 'This return changed. Reload the page before trying again.');
        }
        if (! in_array($record->status, $allowed, true)) {
            $this->fail('status', 'This action is not allowed in the current return status.');
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(PurchaseReturn $record): array
    {
        return [...$record->only(['company_id', 'purchase_order_id', 'goods_receipt_id', 'supplier_id', 'warehouse_id', 'number', 'created_by', 'status', 'revision', 'reason', 'approved_by', 'approved_at', 'posted_by', 'posted_at', 'cancelled_by', 'cancelled_at']),
            'items' => PurchaseReturnItem::query()->where('purchase_return_id', $record->getKey())->get()->toArray()];
    }

    /** @param array<string, mixed>|null $old */
    private function audit(PurchaseReturn $record, User $actor, string $action, ?array $old, ?string $comment): void
    {
        DB::table('purchase_return_audit_logs')->insert(['company_id' => $record->company_id, 'purchase_return_id' => $record->getKey(), 'actor_id' => $actor->getKey(),
            'action' => $action, 'old_values' => $old === null ? null : json_encode($old, JSON_THROW_ON_ERROR), 'new_values' => json_encode($this->snapshot($record), JSON_THROW_ON_ERROR),
            'comment' => $comment, 'created_at' => now()]);
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
