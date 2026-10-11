<?php

namespace App\Services\Inventory;

use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class InventoryService
{
    /**
     * Increase physical stock.
     *
     * @throws Throwable
     */
    public function stockIn(
        Warehouse $warehouse,
        Product $product,
        string|int|float $quantity,
        string $movementType,
        string|int|float|null $unitCost = null,
        ?ProductVariant $variant = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $referenceNumber = null,
        ?CarbonInterface $occurredAt = null,
        ?int $createdBy = null,
        ?string $notes = null,
    ): StockMovement {
        return $this->postMovement(
            warehouse: $warehouse,
            product: $product,
            quantity: $quantity,
            movementType: $movementType,
            direction: StockMovement::DIRECTION_IN,
            unitCost: $unitCost,
            variant: $variant,
            referenceType: $referenceType,
            referenceId: $referenceId,
            referenceNumber: $referenceNumber,
            occurredAt: $occurredAt,
            createdBy: $createdBy,
            notes: $notes,
        );
    }

    /**
     * Decrease physical stock.
     *
     * @throws Throwable
     */
    public function stockOut(
        Warehouse $warehouse,
        Product $product,
        string|int|float $quantity,
        string $movementType,
        string|int|float|null $unitCost = null,
        ?ProductVariant $variant = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $referenceNumber = null,
        ?CarbonInterface $occurredAt = null,
        ?int $createdBy = null,
        ?string $notes = null,
    ): StockMovement {
        return $this->postMovement(
            warehouse: $warehouse,
            product: $product,
            quantity: $quantity,
            movementType: $movementType,
            direction: StockMovement::DIRECTION_OUT,
            unitCost: $unitCost,
            variant: $variant,
            referenceType: $referenceType,
            referenceId: $referenceId,
            referenceNumber: $referenceNumber,
            occurredAt: $occurredAt,
            createdBy: $createdBy,
            notes: $notes,
        );
    }

    /**
     * Post a physical stock movement and update the
     * corresponding current inventory balance atomically.
     *
     * This is the central write path for physical inventory.
     *
     * @throws Throwable
     */
    public function postMovement(
        Warehouse $warehouse,
        Product $product,
        string|int|float $quantity,
        string $movementType,
        string $direction,
        string|int|float|null $unitCost = null,
        ?ProductVariant $variant = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $referenceNumber = null,
        ?CarbonInterface $occurredAt = null,
        ?int $createdBy = null,
        ?string $notes = null,
    ): StockMovement {
        $this->validateMovementType(
            $movementType
        );

        $this->validateDirection(
            $direction
        );

        $this->validateProductForInventory(
            $product,
            $variant
        );

        $this->validateWarehouseCompany(
            $warehouse,
            $product
        );

        $quantity = $this->normalizePositiveDecimal(
            $quantity,
            'quantity'
        );

        $unitCost = $this->normalizeNonNegativeDecimal(
            $unitCost ?? 0,
            'unit_cost'
        );

        return DB::transaction(
            function () use (
                $warehouse,
                $product,
                $variant,
                $quantity,
                $movementType,
                $direction,
                $unitCost,
                $referenceType,
                $referenceId,
                $referenceNumber,
                $occurredAt,
                $createdBy,
                $notes,
            ): StockMovement {
                /*
                 * Re-fetch and lock the Warehouse.
                 *
                 * This prevents the warehouse from changing
                 * state underneath an inventory transaction.
                 */
                $lockedWarehouse =
                    Warehouse::query()
                        ->whereKey(
                            $warehouse->getKey()
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                /*
                 * Re-fetch and lock the Product because
                 * inventory rules such as:
                 *
                 * track_inventory
                 * allow_negative_stock
                 * has_variants
                 *
                 * affect this transaction.
                 */
                $lockedProduct =
                    Product::query()
                        ->whereKey(
                            $product->getKey()
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                $lockedVariant = null;

                if ($variant !== null) {
                    $lockedVariant =
                        ProductVariant::query()
                            ->whereKey(
                                $variant->getKey()
                            )
                            ->lockForUpdate()
                            ->firstOrFail();
                }

                /*
                 * Revalidate after acquiring locks.
                 */
                $this->validateProductForInventory(
                    $lockedProduct,
                    $lockedVariant
                );

                $this->validateWarehouseCompany(
                    $lockedWarehouse,
                    $lockedProduct
                );

                $balance =
                    $this->lockBalance(
                        warehouse:
                            $lockedWarehouse,
                        product:
                            $lockedProduct,
                        variant:
                            $lockedVariant,
                    );

                $before =
                    $this->decimal(
                        $balance->quantity_on_hand
                    );

                $reserved =
                    $this->decimal(
                        $balance->quantity_reserved
                    );

                $oldAverageCost =
                    $this->decimal(
                        $balance->average_cost
                    );

                if (
                    $direction ===
                    StockMovement::DIRECTION_IN
                ) {
                    $after = bcadd(
                        $before,
                        $quantity,
                        4
                    );

                    $newAverageCost =
                        $this->calculateIncomingAverageCost(
                            quantityBefore:
                                $before,
                            averageCostBefore:
                                $oldAverageCost,
                            incomingQuantity:
                                $quantity,
                            incomingUnitCost:
                                $unitCost,
                        );
                } else {
                    $after = bcsub(
                        $before,
                        $quantity,
                        4
                    );

                    /*
                     * Unless negative stock is explicitly
                     * enabled on Product, physical stock
                     * cannot go below zero.
                     */
                    if (
                        !$lockedProduct
                            ->allow_negative_stock &&
                        bccomp(
                            $after,
                            '0.0000',
                            4
                        ) < 0
                    ) {
                        throw ValidationException::withMessages([
                            'quantity' =>
                                'Insufficient stock. This product does not allow negative stock.',
                        ]);
                    }

                    /*
                     * Reserved quantity cannot exceed
                     * physical stock after an ordinary
                     * stock-out.
                     *
                     * Sales fulfillment will later release
                     * its reservation before/with shipment.
                     */
                    if (
                        bccomp(
                            $after,
                            $reserved,
                            4
                        ) < 0
                    ) {
                        throw ValidationException::withMessages([
                            'quantity' =>
                                'This stock-out would reduce on-hand stock below the currently reserved quantity.',
                        ]);
                    }

                    /*
                     * OUT movements do not recalculate
                     * weighted average cost.
                     */
                    $newAverageCost =
                        $oldAverageCost;
                }

                $movementTime =
                    $occurredAt ?? now();

                $totalCost = bcmul(
                    $quantity,
                    $unitCost,
                    4
                );

                /*
                 * Update current state.
                 */
                $balance->quantity_on_hand =
                    $after;

                $balance->average_cost =
                    $newAverageCost;

                $balance->last_movement_at =
                    $movementTime;

                $balance->save();

                /*
                 * Append immutable ledger entry.
                 */
                return StockMovement::create([
                    'company_id' =>
                        $lockedWarehouse
                            ->company_id,

                    'warehouse_id' =>
                        $lockedWarehouse
                            ->getKey(),

                    'product_id' =>
                        $lockedProduct
                            ->getKey(),

                    'product_variant_id' =>
                        $lockedVariant
                            ?->getKey(),

                    'movement_type' =>
                        $movementType,

                    'direction' =>
                        $direction,

                    'quantity' =>
                        $quantity,

                    'unit_cost' =>
                        $unitCost,

                    'total_cost' =>
                        $totalCost,

                    'balance_before' =>
                        $before,

                    'balance_after' =>
                        $after,

                    'reference_type' =>
                        $referenceType,

                    'reference_id' =>
                        $referenceId,

                    'reference_number' =>
                        $referenceNumber,

                    'occurred_at' =>
                        $movementTime,

                    'created_by' =>
                        $createdBy,

                    'notes' =>
                        $notes,
                ]);
            },
            5
        );
    }

    /**
     * Get the current inventory balance.
     *
     * This does not create a row.
     */
    public function getBalance(
    Warehouse $warehouse,
    Product $product,
    ?ProductVariant $variant = null,
    ): ?InventoryBalance {
        return InventoryBalance::query()
            ->where(
                'warehouse_id',
                $warehouse->getKey()
            )
            ->where(
                'product_id',
                $product->getKey()
            )
            ->where(
                'variant_key',
                $variant?->getKey() ?? 0
            )
            ->first();
    }

    /**
     * Get on-hand quantity.
     */
    public function quantityOnHand(
        Warehouse $warehouse,
        Product $product,
        ?ProductVariant $variant = null,
    ): string {
        return $this->decimal(
            $this->getBalance(
                $warehouse,
                $product,
                $variant
            )?->quantity_on_hand ?? 0
        );
    }

    /**
     * Get reserved quantity.
     */
    public function quantityReserved(
        Warehouse $warehouse,
        Product $product,
        ?ProductVariant $variant = null,
    ): string {
        return $this->decimal(
            $this->getBalance(
                $warehouse,
                $product,
                $variant
            )?->quantity_reserved ?? 0
        );
    }

    /**
     * Available = on hand - reserved.
     */
    public function quantityAvailable(
        Warehouse $warehouse,
        Product $product,
        ?ProductVariant $variant = null,
    ): string {
        $balance =
            $this->getBalance(
                $warehouse,
                $product,
                $variant
            );

        if (!$balance) {
            return '0.0000';
        }

        return bcsub(
            $this->decimal(
                $balance->quantity_on_hand
            ),
            $this->decimal(
                $balance->quantity_reserved
            ),
            4
        );
    }

    /**
     * Find/create and lock exactly one inventory
     * balance row.
     */
    private function lockBalance(
    Warehouse $warehouse,
    Product $product,
    ?ProductVariant $variant,
    ): InventoryBalance {
        $variantKey =
            $variant?->getKey() ?? 0;

        $balance =
            InventoryBalance::query()
                ->where(
                    'warehouse_id',
                    $warehouse->getKey()
                )
                ->where(
                    'product_id',
                    $product->getKey()
                )
                ->where(
                    'variant_key',
                    $variantKey
                )
                ->lockForUpdate()
                ->first();

        if ($balance) {
            if (
                (int) $balance->company_id !==
                (int) $warehouse->company_id
            ) {
                throw ValidationException::withMessages([
                    'warehouse_id' =>
                        'The existing inventory balance belongs to a different company.',
                ]);
            }

            return $balance;
        }

        /*
        * The UNIQUE database constraint protects the
        * first creation of the balance identity.
        */
        InventoryBalance::create([
            'company_id' =>
                $warehouse->company_id,

            'warehouse_id' =>
                $warehouse->getKey(),

            'product_id' =>
                $product->getKey(),

            'product_variant_id' =>
                $variant?->getKey(),

            'variant_key' =>
                $variantKey,

            'quantity_on_hand' =>
                '0.0000',

            'quantity_reserved' =>
                '0.0000',

            'average_cost' =>
                '0.0000',

            'last_movement_at' =>
                null,
        ]);

        return InventoryBalance::query()
            ->where(
                'warehouse_id',
                $warehouse->getKey()
            )
            ->where(
                'product_id',
                $product->getKey()
            )
            ->where(
                'variant_key',
                $variantKey
            )
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function validateProductForInventory(
        Product $product,
        ?ProductVariant $variant,
    ): void {
        if (
            !$product->isStockItem()
        ) {
            throw ValidationException::withMessages([
                'product_id' =>
                    'The selected product is not configured as an inventory stock item.',
            ]);
        }

        if ($product->has_variants) {
            if ($variant === null) {
                throw ValidationException::withMessages([
                    'product_variant_id' =>
                        'A product variant is required for this product.',
                ]);
            }

            if (
                (int) $variant->product_id !==
                (int) $product->getKey()
            ) {
                throw ValidationException::withMessages([
                    'product_variant_id' =>
                        'The selected variant does not belong to the selected product.',
                ]);
            }

            return;
        }

        if ($variant !== null) {
            throw ValidationException::withMessages([
                'product_variant_id' =>
                    'This product does not use variants.',
            ]);
        }
    }

    private function validateWarehouseCompany(
        Warehouse $warehouse,
        Product $product,
    ): void {
        if (
            (int) $warehouse->company_id !==
            (int) $product->company_id
        ) {
            throw ValidationException::withMessages([
                'warehouse_id' =>
                    'The warehouse and product must belong to the same company.',
            ]);
        }

        if (!$warehouse->is_active) {
            throw ValidationException::withMessages([
                'warehouse_id' =>
                    'Inventory transactions cannot be posted to an inactive warehouse.',
            ]);
        }
    }

    private function validateMovementType(
        string $movementType
    ): void {
        if (
            !in_array(
                $movementType,
                StockMovement::movementTypes(),
                true
            )
        ) {
            throw ValidationException::withMessages([
                'movement_type' =>
                    'The selected stock movement type is invalid.',
            ]);
        }
    }

    private function validateDirection(
        string $direction
    ): void {
        if (
            !in_array(
                $direction,
                StockMovement::directions(),
                true
            )
        ) {
            throw ValidationException::withMessages([
                'direction' =>
                    'The selected stock movement direction is invalid.',
            ]);
        }
    }

    /**
     * Weighted average cost for incoming stock.
     */
    private function calculateIncomingAverageCost(
        string $quantityBefore,
        string $averageCostBefore,
        string $incomingQuantity,
        string $incomingUnitCost,
    ): string {
        /*
         * Existing inventory value.
         */
        $existingValue = bcmul(
            $quantityBefore,
            $averageCostBefore,
            8
        );

        /*
         * Incoming inventory value.
         */
        $incomingValue = bcmul(
            $incomingQuantity,
            $incomingUnitCost,
            8
        );

        $newQuantity = bcadd(
            $quantityBefore,
            $incomingQuantity,
            4
        );

        if (
            bccomp(
                $newQuantity,
                '0.0000',
                4
            ) === 0
        ) {
            return '0.0000';
        }

        $newValue = bcadd(
            $existingValue,
            $incomingValue,
            8
        );

        return bcdiv(
            $newValue,
            $newQuantity,
            4
        );
    }

    private function normalizePositiveDecimal(
        string|int|float $value,
        string $field
    ): string {
        $normalized =
            $this->normalizeDecimal(
                $value,
                $field
            );

        if (
            bccomp(
                $normalized,
                '0.0000',
                4
            ) <= 0
        ) {
            throw ValidationException::withMessages([
                $field =>
                    ucfirst(
                        str_replace(
                            '_',
                            ' ',
                            $field
                        )
                    ) .
                    ' must be greater than zero.',
            ]);
        }

        return $normalized;
    }

    private function normalizeNonNegativeDecimal(
        string|int|float $value,
        string $field
    ): string {
        $normalized =
            $this->normalizeDecimal(
                $value,
                $field
            );

        if (
            bccomp(
                $normalized,
                '0.0000',
                4
            ) < 0
        ) {
            throw ValidationException::withMessages([
                $field =>
                    ucfirst(
                        str_replace(
                            '_',
                            ' ',
                            $field
                        )
                    ) .
                    ' cannot be negative.',
            ]);
        }

        return $normalized;
    }

    private function normalizeDecimal(
        string|int|float $value,
        string $field
    ): string {
        if (
            !is_numeric($value)
        ) {
            throw ValidationException::withMessages([
                $field =>
                    ucfirst(
                        str_replace(
                            '_',
                            ' ',
                            $field
                        )
                    ) .
                    ' must be numeric.',
            ]);
        }

        /*
         * number_format gives us a predictable
         * four-decimal representation for values
         * entering the service.
         */
        return number_format(
            (float) $value,
            4,
            '.',
            ''
        );
    }

    private function decimal(
        string|int|float|null $value
    ): string {
        return number_format(
            (float) ($value ?? 0),
            4,
            '.',
            ''
        );
    }
}