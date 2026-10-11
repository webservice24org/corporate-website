<?php

namespace App\Http\Controllers\ERP\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Company;
use App\Models\InventoryBalance;
use App\Models\ProductCategory;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryStockController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => [
                'nullable',
                'string',
                'max:150',
            ],

            'company_id' => [
                'nullable',
                'integer',
                'exists:companies,id',
            ],

            'warehouse_id' => [
                'nullable',
                'integer',
                'exists:warehouses,id',
            ],

            'category_id' => [
                'nullable',
                'integer',
                'exists:product_categories,id',
            ],

            'brand_id' => [
                'nullable',
                'integer',
                'exists:brands,id',
            ],

            'stock_status' => [
                'nullable',
                'in:in_stock,out_of_stock,low_stock,negative',
            ],

            'per_page' => [
                'nullable',
                'integer',
                'in:10,25,50,100',
            ],
        ]);

        $search = trim(
            (string) ($filters['search'] ?? '')
        );

        $companyId =
            $filters['company_id'] ?? null;

        $warehouseId =
            $filters['warehouse_id'] ?? null;

        $categoryId =
            $filters['category_id'] ?? null;

        $brandId =
            $filters['brand_id'] ?? null;

        $stockStatus =
            $filters['stock_status'] ?? null;

        $perPage =
            (int) ($filters['per_page'] ?? 25);

        $query = InventoryBalance::query()
            ->with([
                'company:id,name',

                'warehouse:id,company_id,code,name',

                'product:id,company_id,product_category_id,brand_id,name,sku,reorder_level,minimum_stock,has_variants',

                'product.category:id,name',

                'product.brand:id,name',

                'variant:id,product_id,name,sku',
            ]);

        if ($search !== '') {
            $query->where(
                function (Builder $query) use ($search) {
                    $query
                        ->whereHas(
                            'product',
                            function (Builder $productQuery) use ($search) {
                                $productQuery
                                    ->where(
                                        'name',
                                        'like',
                                        "%{$search}%"
                                    )
                                    ->orWhere(
                                        'sku',
                                        'like',
                                        "%{$search}%"
                                    );
                            }
                        )
                        ->orWhereHas(
                            'variant',
                            function (Builder $variantQuery) use ($search) {
                                $variantQuery
                                    ->where(
                                        'name',
                                        'like',
                                        "%{$search}%"
                                    )
                                    ->orWhere(
                                        'sku',
                                        'like',
                                        "%{$search}%"
                                    );
                            }
                        )
                        ->orWhereHas(
                            'warehouse',
                            function (Builder $warehouseQuery) use ($search) {
                                $warehouseQuery
                                    ->where(
                                        'name',
                                        'like',
                                        "%{$search}%"
                                    )
                                    ->orWhere(
                                        'code',
                                        'like',
                                        "%{$search}%"
                                    );
                            }
                        );
                }
            );
        }

        $query
            ->when(
                $companyId,
                fn (Builder $query) =>
                    $query->where(
                        'company_id',
                        $companyId
                    )
            )
            ->when(
                $warehouseId,
                fn (Builder $query) =>
                    $query->where(
                        'warehouse_id',
                        $warehouseId
                    )
            )
            ->when(
                $categoryId,
                fn (Builder $query) =>
                    $query->whereHas(
                        'product',
                        fn (Builder $productQuery) =>
                            $productQuery->where(
                                'product_category_id',
                                $categoryId
                            )
                    )
            )
            ->when(
                $brandId,
                fn (Builder $query) =>
                    $query->whereHas(
                        'product',
                        fn (Builder $productQuery) =>
                            $productQuery->where(
                                'brand_id',
                                $brandId
                            )
                    )
            );

        $this->applyStockStatus(
            $query,
            $stockStatus
        );

        /*
         * Clone after applying filters so summary cards
         * describe the current filtered dataset.
         */
        $summaryQuery = clone $query;

        $summary = $this->summary(
            $summaryQuery
        );

        $balances = $query
            ->orderByDesc('last_movement_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render(
            'ERP/Inventory/Stock/Index',
            [
                'balances' => $balances,

                'summary' => $summary,

                'filters' => [
                    'search' =>
                        $search,

                    'company_id' =>
                        $companyId
                            ? (string) $companyId
                            : '',

                    'warehouse_id' =>
                        $warehouseId
                            ? (string) $warehouseId
                            : '',

                    'category_id' =>
                        $categoryId
                            ? (string) $categoryId
                            : '',

                    'brand_id' =>
                        $brandId
                            ? (string) $brandId
                            : '',

                    'stock_status' =>
                        $stockStatus ?? '',

                    'per_page' =>
                        (string) $perPage,
                ],

                'companies' =>
                    Company::query()
                        ->select('id', 'name')
                        ->orderBy('name')
                        ->get(),

                'warehouses' =>
                    Warehouse::query()
                        ->select(
                            'id',
                            'company_id',
                            'code',
                            'name'
                        )
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->get(),

                'categories' =>
                    ProductCategory::query()
                        ->select('id', 'name')
                        ->orderBy('name')
                        ->get(),

                'brands' =>
                    Brand::query()
                        ->select('id', 'name')
                        ->orderBy('name')
                        ->get(),
            ]
        );
    }

    private function applyStockStatus(
        Builder $query,
        ?string $status
    ): void {
        if (!$status) {
            return;
        }

        match ($status) {
            'in_stock' =>
                $query->where(
                    'quantity_on_hand',
                    '>',
                    0
                ),

            'out_of_stock' =>
                $query->where(
                    'quantity_on_hand',
                    '=',
                    0
                ),

            'negative' =>
                $query->where(
                    'quantity_on_hand',
                    '<',
                    0
                ),

            'low_stock' =>
                $query
                    ->where(
                        'quantity_on_hand',
                        '>',
                        0
                    )
                    ->whereHas(
                        'product',
                        function (Builder $productQuery) {
                            /*
                             * The threshold comparison itself
                             * is applied through a correlated
                             * product column below.
                             */
                        }
                    )
                    ->whereRaw(
                        'inventory_balances.quantity_on_hand <= (
                            SELECT products.reorder_level
                            FROM products
                            WHERE products.id = inventory_balances.product_id
                            LIMIT 1
                        )'
                    ),

            default => null,
        };
    }

    private function summary(
        Builder $query
    ): array {
        $rows = $query->get([
            'quantity_on_hand',
            'quantity_reserved',
            'average_cost',
        ]);

        $onHand = '0.0000';
        $reserved = '0.0000';
        $available = '0.0000';
        $value = '0.0000';

        foreach ($rows as $row) {
            $rowOnHand =
                (string) $row->quantity_on_hand;

            $rowReserved =
                (string) $row->quantity_reserved;

            $rowAvailable =
                bcsub(
                    $rowOnHand,
                    $rowReserved,
                    4
                );

            $rowValue =
                bcmul(
                    $rowOnHand,
                    (string) $row->average_cost,
                    4
                );

            $onHand = bcadd(
                $onHand,
                $rowOnHand,
                4
            );

            $reserved = bcadd(
                $reserved,
                $rowReserved,
                4
            );

            $available = bcadd(
                $available,
                $rowAvailable,
                4
            );

            $value = bcadd(
                $value,
                $rowValue,
                4
            );
        }

        return [
            'stock_items' =>
                $rows->count(),

            'quantity_on_hand' =>
                $onHand,

            'quantity_reserved' =>
                $reserved,

            'quantity_available' =>
                $available,

            'inventory_value' =>
                $value,
        ];
    }
}