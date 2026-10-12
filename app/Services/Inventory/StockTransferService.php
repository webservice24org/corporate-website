<?php

namespace App\Services\Inventory;

use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Validation\ValidationException;

class StockTransferService
{
    public function __construct(private readonly InventoryService $inventory)
    {
    }

    /**
     * Validate a transfer before saving a draft. No inventory is changed.
     * Item quantities and costs must be validated again when posting.
     */
    public function validateDraft(StockTransfer $transfer): void
    {
        if (!$transfer->isDraft()) {
            $this->fail('status', 'Only draft stock transfers can be changed.');
        }

        $source = Warehouse::query()->find($transfer->source_warehouse_id);
        $destination = Warehouse::query()->find($transfer->destination_warehouse_id);
        $this->validateWarehouses($transfer, $source, $destination);

        /** @var EloquentCollection<int, StockTransferItem> $items */
        $items = $transfer->items()->get();
        if ($items->isEmpty()) {
            $this->fail('items', 'Add at least one product to the transfer.');
        }

        $seen = [];
        foreach ($items as $index => $item) {
            $this->validateItem($transfer, $item, $index);
            $key = $item->product_id . ':' . ($item->product_variant_id ?? 0);
            if (isset($seen[$key])) {
                $this->fail("items.$index.product_id", 'Duplicate product and variant in this transfer.');
            }
            $seen[$key] = true;
        }
    }

    /**
     * Post a transfer exactly once, as one atomic inventory transaction.
     * Caller must authorize access to the transfer's company beforehand.
     */
    public function post(StockTransfer $transfer, ?int $postedBy = null): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $postedBy): StockTransfer {
            $locked = StockTransfer::query()
                ->whereKey($transfer->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (!$locked->isDraft()) {
                $this->fail('status', 'This transfer has already been posted.');
            }

            // Lock warehouses in a stable order before inventory service operations.
            $warehouseIds = [(int) $locked->source_warehouse_id, (int) $locked->destination_warehouse_id];
            sort($warehouseIds, SORT_NUMERIC);
            $warehouses = Warehouse::query()
                ->whereIn('id', $warehouseIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $source = $warehouses->get((int) $locked->source_warehouse_id);
            $destination = $warehouses->get((int) $locked->destination_warehouse_id);
            $this->validateWarehouses($locked, $source, $destination);

            /** @var EloquentCollection<int, StockTransferItem> $items */
            $items = $locked->items()->orderBy('product_id')->orderBy('variant_key')->orderBy('id')->get();
            if ($items->isEmpty()) {
                $this->fail('items', 'Add at least one product before posting.');
            }

            $seen = [];
            $occurredAt = now();

            foreach ($items as $index => $item) {
                [$product, $variant, $quantity] = $this->validateItem($locked, $item, $index);
                $key = $product->getKey() . ':' . ($variant?->getKey() ?? 0);
                if (isset($seen[$key])) {
                    $this->fail("items.$index.product_id", 'Duplicate product and variant in this transfer.');
                }
                $seen[$key] = true;

                // Lock the source balance before reading its cost and available quantity.
                $balance = InventoryBalance::query()
                    ->where('warehouse_id', $source->getKey())
                    ->where('product_id', $product->getKey())
                    ->where('variant_key', $variant?->getKey() ?? 0)
                    ->lockForUpdate()
                    ->first();

                $onHand = $balance !== null
                    ? (string) $balance->quantity_on_hand
                    : '0.0000';
                $reserved = $balance !== null
                    ? (string) $balance->quantity_reserved
                    : '0.0000';
                $available = bcsub($onHand, $reserved, 4);
                if (bccomp($available, $quantity, 4) < 0) {
                    $this->fail("items.$index.quantity", 'Insufficient available stock in the source warehouse.');
                }

                $cost = $balance !== null
                    ? (string) $balance->average_cost
                    : '0.0000';
                $referenceType = StockTransfer::class;
                $referenceId = (int) $locked->getKey();
                $referenceNumber = (string) $locked->document_number;

                $this->inventory->stockOut(
                    warehouse: $source,
                    product: $product,
                    quantity: $quantity,
                    movementType: StockMovement::TYPE_TRANSFER_OUT,
                    unitCost: $cost,
                    variant: $variant,
                    referenceType: $referenceType,
                    referenceId: $referenceId,
                    referenceNumber: $referenceNumber,
                    occurredAt: $occurredAt,
                    createdBy: $postedBy,
                );

                $this->inventory->stockIn(
                    warehouse: $destination,
                    product: $product,
                    quantity: $quantity,
                    movementType: StockMovement::TYPE_TRANSFER_IN,
                    unitCost: $cost,
                    variant: $variant,
                    referenceType: $referenceType,
                    referenceId: $referenceId,
                    referenceNumber: $referenceNumber,
                    occurredAt: $occurredAt,
                    createdBy: $postedBy,
                );

                $item->forceFill([
                    'unit_cost' => $cost,
                    'total_cost' => bcmul($quantity, $cost, 4),
                ])->save();
            }

            $locked->forceFill([
                'status' => StockTransfer::STATUS_POSTED,
                'posted_by' => $postedBy,
                'posted_at' => $occurredAt,
            ])->save();

            return $locked->load(['items', 'sourceWarehouse', 'destinationWarehouse']);
        }, 5);
    }

    private function validateWarehouses(
        StockTransfer $transfer,
        ?Warehouse $source,
        ?Warehouse $destination,
    ): void {
        if (!$source) {
            $this->fail('source_warehouse_id', 'Source warehouse does not exist.');
        }
        if (!$destination) {
            $this->fail('destination_warehouse_id', 'Destination warehouse does not exist.');
        }
        if ($source->getKey() === $destination->getKey()) {
            $this->fail('destination_warehouse_id', 'Source and destination warehouses must be different.');
        }
        if (
            (int) $source->company_id !== (int) $transfer->company_id ||
            (int) $destination->company_id !== (int) $transfer->company_id
        ) {
            $this->fail('company_id', 'Both warehouses must belong to the transfer company.');
        }
        if (!$source->is_active || !$destination->is_active || $source->trashed() || $destination->trashed()) {
            $this->fail('source_warehouse_id', 'Both warehouses must be active and not deleted.');
        }
    }

    /** @return array{Product, ?ProductVariant, string} */
    private function validateItem(StockTransfer $transfer, StockTransferItem $item, int $index): array
    {
        $product = Product::query()->find($item->product_id);
        if (!$product || (int) $product->company_id !== (int) $transfer->company_id || !$product->isStockItem()) {
            $this->fail("items.$index.product_id", 'Select a stock-tracked product belonging to the transfer company.');
        }

        $variant = null;
        if ($item->product_variant_id !== null) {
            $variant = ProductVariant::query()->find($item->product_variant_id);
            if (!$variant || (int) $variant->product_id !== (int) $product->getKey()) {
                $this->fail("items.$index.product_variant_id", 'Variant does not belong to the selected product.');
            }
        }
        if ((bool) $product->has_variants !== ($variant !== null)) {
            $this->fail("items.$index.product_variant_id", 'Variant selection does not match the product configuration.');
        }
        if ((int) $item->variant_key !== (int) ($variant?->getKey() ?? 0)) {
            $this->fail("items.$index.variant_key", 'Variant key does not match the selected variant.');
        }

        $raw = (string) $item->quantity;
        if (!preg_match('/^\d+(?:\.\d{1,4})?$/', $raw) || bccomp($raw, '0', 4) <= 0) {
            $this->fail("items.$index.quantity", 'Quantity must be positive with at most four decimal places.');
        }

        return [$product, $variant, bcadd($raw, '0', 4)];
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
