<?php

namespace App\Http\Controllers\ERP\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Company;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockMovementController extends Controller
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

            'movement_type' => [
                'nullable',
                'string',
                'in:' . implode(
                    ',',
                    StockMovement::movementTypes()
                ),
            ],

            'direction' => [
                'nullable',
                'in:' . implode(
                    ',',
                    StockMovement::directions()
                ),
            ],

            'date_from' => [
                'nullable',
                'date',
            ],

            'date_to' => [
                'nullable',
                'date',
                'after_or_equal:date_from',
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

        $movementType =
            $filters['movement_type'] ?? null;

        $direction =
            $filters['direction'] ?? null;

        $dateFrom =
            $filters['date_from'] ?? null;

        $dateTo =
            $filters['date_to'] ?? null;

        $perPage =
            (int) ($filters['per_page'] ?? 25);

        $query =
            StockMovement::query()
                ->with([
                    'company:id,name',

                    'warehouse:id,company_id,code,name',

                    'product:id,company_id,product_category_id,brand_id,name,sku,has_variants',

                    'product.category:id,name',

                    'product.brand:id,name',

                    'variant:id,product_id,name,sku',

                    'creator:id,name',
                ]);

        /*
         * General search.
         */
        if ($search !== '') {
            $query->where(
                function (Builder $query) use ($search) {
                    $query
                        ->where(
                            'reference_number',
                            'like',
                            "%{$search}%"
                        )
                        ->orWhereHas(
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
                        )
                        ->orWhereHas(
                            'creator',
                            fn (Builder $creatorQuery) =>
                                $creatorQuery->where(
                                    'name',
                                    'like',
                                    "%{$search}%"
                                )
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
            )

            ->when(
                $movementType,
                fn (Builder $query) =>
                    $query->where(
                        'movement_type',
                        $movementType
                    )
            )

            ->when(
                $direction,
                fn (Builder $query) =>
                    $query->where(
                        'direction',
                        $direction
                    )
            )

            ->when(
                $dateFrom,
                fn (Builder $query) =>
                    $query->whereDate(
                        'occurred_at',
                        '>=',
                        $dateFrom
                    )
            )

            ->when(
                $dateTo,
                fn (Builder $query) =>
                    $query->whereDate(
                        'occurred_at',
                        '<=',
                        $dateTo
                    )
            );

        /*
         * Clone before pagination for filtered summary.
         */
        $summaryQuery =
            clone $query;

        $summary =
            $this->summary(
                $summaryQuery
            );

        $movements =
            $query
                ->orderByDesc(
                    'occurred_at'
                )
                ->orderByDesc('id')
                ->paginate($perPage)
                ->withQueryString();

        return Inertia::render(
            'ERP/Inventory/StockMovements/Index',
            [
                'movements' =>
                    $movements,

                'summary' =>
                    $summary,

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

                    'movement_type' =>
                        $movementType ?? '',

                    'direction' =>
                        $direction ?? '',

                    'date_from' =>
                        $dateFrom ?? '',

                    'date_to' =>
                        $dateTo ?? '',

                    'per_page' =>
                        (string) $perPage,
                ],

                'companies' =>
                    Company::query()
                        ->select(
                            'id',
                            'name'
                        )
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
                        ->orderBy('name')
                        ->get(),

                'categories' =>
                    ProductCategory::query()
                        ->select(
                            'id',
                            'name'
                        )
                        ->orderBy('name')
                        ->get(),

                'brands' =>
                    Brand::query()
                        ->select(
                            'id',
                            'name'
                        )
                        ->orderBy('name')
                        ->get(),

                'movementTypes' =>
                    collect(
                        StockMovement::movementTypes()
                    )
                        ->map(
                            fn (string $type) => [
                                'value' => $type,
                                'label' =>
                                    $this->movementTypeLabel(
                                        $type
                                    ),
                            ]
                        )
                        ->values(),
            ]
        );
    }

    private function summary(
        Builder $query
    ): array {
        $totalMovements =
            (clone $query)->count();

        $quantityIn =
            (clone $query)
                ->where(
                    'direction',
                    StockMovement::DIRECTION_IN
                )
                ->sum('quantity');

        $quantityOut =
            (clone $query)
                ->where(
                    'direction',
                    StockMovement::DIRECTION_OUT
                )
                ->sum('quantity');

        $valueIn =
            (clone $query)
                ->where(
                    'direction',
                    StockMovement::DIRECTION_IN
                )
                ->sum('total_cost');

        $valueOut =
            (clone $query)
                ->where(
                    'direction',
                    StockMovement::DIRECTION_OUT
                )
                ->sum('total_cost');

        return [
            'total_movements' =>
                $totalMovements,

            'quantity_in' =>
                (string) $quantityIn,

            'quantity_out' =>
                (string) $quantityOut,

            'value_in' =>
                (string) $valueIn,

            'value_out' =>
                (string) $valueOut,
        ];
    }

    private function movementTypeLabel(
        string $type
    ): string {
        return match ($type) {
            StockMovement::TYPE_OPENING_STOCK =>
                'Opening Stock',

            StockMovement::TYPE_PURCHASE_RECEIPT =>
                'Purchase Receipt',

            StockMovement::TYPE_SALE =>
                'Sale',

            StockMovement::TYPE_SALE_RETURN =>
                'Sale Return',

            StockMovement::TYPE_PURCHASE_RETURN =>
                'Purchase Return',

            StockMovement::TYPE_ADJUSTMENT_IN =>
                'Adjustment In',

            StockMovement::TYPE_ADJUSTMENT_OUT =>
                'Adjustment Out',

            StockMovement::TYPE_TRANSFER_IN =>
                'Transfer In',

            StockMovement::TYPE_TRANSFER_OUT =>
                'Transfer Out',

            default =>
                ucwords(
                    str_replace(
                        '_',
                        ' ',
                        $type
                    )
                ),
        };
    }
}