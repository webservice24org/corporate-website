<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\InventoryBalance;
use App\Models\InventoryReorderSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class LowStockService
{
    public const STATUS_OUT_OF_STOCK = 'out_of_stock';
    public const STATUS_CRITICAL = 'critical';
    public const STATUS_LOW_STOCK = 'low_stock';
    public const STATUS_HEALTHY = 'healthy';

    /**
     * Build a company-scoped inventory report.
     *
     * Filters:
     * - warehouse_id: int|null
     * - product_id: int|null
     * - status: string|null
     * - include_healthy: bool (default false)
     *
     * @param array<string, mixed> $filters
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     summary: array<string, int>
     * }
     */
    public function report(
        int $companyId,
        array $filters = []
    ): array {
        if ($companyId <= 0) {
            throw new InvalidArgumentException(
                'A valid company ID is required.'
            );
        }

        $warehouseId = $this->positiveId(
            $filters['warehouse_id'] ?? null
        );

        $productId = $this->positiveId(
            $filters['product_id'] ?? null
        );

        $status = $filters['status'] ?? null;

        $validStatuses = [
            self::STATUS_OUT_OF_STOCK,
            self::STATUS_CRITICAL,
            self::STATUS_LOW_STOCK,
            self::STATUS_HEALTHY,
        ];

        if (
            $status !== null &&
            $status !== '' &&
            !in_array($status, $validStatuses, true)
        ) {
            throw new InvalidArgumentException(
                'Invalid inventory stock status.'
            );
        }

        $includeHealthy = ($filters['include_healthy'] ?? false) === true;

        /** @var Collection<int, Warehouse> $warehouses */
        $warehouses = Warehouse::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->when(
                $warehouseId !== null,
                fn(Builder $query) => $query->whereKey($warehouseId)
            )
            ->orderBy('name')
            ->get(['id', 'company_id', 'name', 'code']);

        $rows = [];

        $summary = [
            'total' => 0,
            'out_of_stock' => 0,
            'critical' => 0,
            'low_stock' => 0,
            'healthy' => 0,
        ];

        if ($warehouses->isEmpty()) {
            return compact('rows', 'summary');
        }

        $warehouseIds = $warehouses
            ->pluck('id')
            ->map(fn($id): int => (int) $id)
            ->all();

        Product::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('product_type', 'physical')
            ->where('track_inventory', true)
            ->when(
                $productId !== null,
                fn(Builder $query) => $query->whereKey($productId)
            )
            ->with([
                'variants' => fn($query) => $query
                    ->where('is_active', true)
                    ->orderBy('id'),
            ])
            ->chunkById(
                100,
                function (Collection $products) use ($companyId, $warehouses, $warehouseIds, $status, $includeHealthy, &$rows, &$summary): void {
                    $productIds = $products
                        ->pluck('id')
                        ->map(fn($id): int => (int) $id)
                        ->all();

                    $balances = InventoryBalance::query()
                        ->where('company_id', $companyId)
                        ->whereIn('warehouse_id', $warehouseIds)
                        ->whereIn('product_id', $productIds)
                        ->get();

                    $settings = InventoryReorderSetting::query()
                        ->where('company_id', $companyId)
                        ->whereIn('warehouse_id', $warehouseIds)
                        ->whereIn('product_id', $productIds)
                        ->get();

                    $balanceMap = $balances->keyBy(
                        fn(InventoryBalance $balance): string =>
                            $this->key(
                                (int) $balance->warehouse_id,
                                (int) $balance->product_id,
                                (int) $balance->variant_key
                            )
                    );

                    $settingMap = $settings->keyBy(
                        fn(InventoryReorderSetting $setting): string =>
                            $this->key(
                                (int) $setting->warehouse_id,
                                (int) $setting->product_id,
                                (int) $setting->variant_key
                            )
                    );

                    foreach ($products as $product) {
                        /** @var Product $product */
                        $variants = $product->has_variants
                            ? $product->variants
                            : collect([null]);

                        // Never invent a non-variant row for a
                        // product configured to use variants.
                        if ($variants->isEmpty()) {
                            continue;
                        }

                        foreach ($warehouses as $warehouse) {
                            foreach ($variants as $variant) {
                                /** @var ProductVariant|null $variant */
                                $variantId = $variant === null
                                    ? null
                                    : (int) $variant->id;

                                $variantKey = $variantId ?? 0;

                                $key = $this->key(
                                    (int) $warehouse->id,
                                    (int) $product->id,
                                    $variantKey
                                );

                                /** @var InventoryBalance|null $balance */
                                $balance = $balanceMap->get($key);

                                /** @var InventoryReorderSetting|null $setting */
                                $setting = $settingMap->get($key);

                                // Only use a setting if its nullable
                                // variant ID matches its variant key.
                                if (
                                    $setting !== null &&
                                    (
                                        $setting->product_variant_id === null
                                        ? 0
                                        : (int) $setting->product_variant_id
                                    ) !== $variantKey
                                ) {
                                    $setting = null;
                                }

                                $onHand = (string) (
                                    $balance !== null ? $balance->quantity_on_hand : '0'
                                );

                                $reserved = (string) (
                                    $balance !== null ? $balance->quantity_reserved : '0'
                                );

                                $available = bcsub(
                                    $onHand,
                                    $reserved,
                                    4
                                );

                                $reorderLevel = (string) (
                                    $setting !== null
                                    ? $setting->reorder_level
                                    : ($product->reorder_level ?? '0')
                                );

                                $minimumStock = (string) (
                                    $setting !== null
                                    ? $setting->minimum_stock
                                    : ($product->minimum_stock ?? '0')
                                );

                                $stockStatus = $this->classify(
                                    $available,
                                    $minimumStock,
                                    $reorderLevel
                                );

                                $summary['total']++;
                                $summary[$stockStatus]++;

                                if (
                                    $status !== null &&
                                    $status !== '' &&
                                    $stockStatus !== $status
                                ) {
                                    continue;
                                }

                                if (
                                    !$includeHealthy &&
                                    ($status === null || $status === '') &&
                                    $stockStatus === self::STATUS_HEALTHY
                                ) {
                                    continue;
                                }

                                $rows[] = [
                                    'company_id' => $companyId,
                                    'warehouse_id' => (int) $warehouse->id,
                                    'warehouse_name' => $warehouse->name,
                                    'warehouse_code' => $warehouse->code,

                                    'product_id' => (int) $product->id,
                                    'product_name' => $product->name,
                                    'product_sku' => $product->sku,

                                    'product_variant_id' => $variantId,
                                    'variant_name' => $variant?->name,
                                    'variant_sku' => $variant?->sku,
                                    'variant_key' => $variantKey,

                                    'quantity_on_hand' => $onHand,
                                    'quantity_reserved' => $reserved,
                                    'quantity_available' => $available,

                                    'minimum_stock' => $minimumStock,
                                    'reorder_level' => $reorderLevel,

                                    'suggested_reorder_quantity' =>
                                        $this->suggestedQuantity(
                                            $available,
                                            $reorderLevel
                                        ),

                                    'status' => $stockStatus,

                                    'threshold_source' => $setting !== null
                                        ? 'warehouse'
                                        : 'product',
                                ];
                            }
                        }
                    }
                }
            );

        usort(
            $rows,
            function (array $a, array $b): int {
                $priority = [
                    self::STATUS_OUT_OF_STOCK => 0,
                    self::STATUS_CRITICAL => 1,
                    self::STATUS_LOW_STOCK => 2,
                    self::STATUS_HEALTHY => 3,
                ];

                $statusCompare = $priority[$a['status']]
                    <=> $priority[$b['status']];

                if ($statusCompare !== 0) {
                    return $statusCompare;
                }

                return strcmp(
                    (string) $a['product_name'],
                    (string) $b['product_name']
                );
            }
        );

        return [
            'rows' => $rows,
            'summary' => $summary,
        ];
    }

    public function classify(
        string $available,
        string $minimumStock,
        string $reorderLevel
    ): string {
        if (bccomp($available, '0', 4) <= 0) {
            return self::STATUS_OUT_OF_STOCK;
        }

        if (
            bccomp($minimumStock, '0', 4) > 0 &&
            bccomp($available, $minimumStock, 4) <= 0
        ) {
            return self::STATUS_CRITICAL;
        }

        if (
            bccomp($reorderLevel, '0', 4) > 0 &&
            bccomp($available, $reorderLevel, 4) <= 0
        ) {
            return self::STATUS_LOW_STOCK;
        }

        return self::STATUS_HEALTHY;
    }

    public function suggestedQuantity(
        string $available,
        string $reorderLevel
    ): string {
        $difference = bcsub(
            $reorderLevel,
            $available,
            4
        );

        return bccomp($difference, '0', 4) > 0
            ? $difference
            : '0.0000';
    }

    private function key(
        int $warehouseId,
        int $productId,
        int $variantKey
    ): string {
        return $warehouseId . ':' . $productId . ':' . $variantKey;
    }

    private function positiveId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (
            filter_var(
                $value,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            ) === false
        ) {
            throw new InvalidArgumentException(
                'Filter IDs must be positive integers.'
            );
        }

        return (int) $value;
    }
}
