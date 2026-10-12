<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\ERP\Inventory\LowStockReportRequest;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Warehouse;
use App\Services\Inventory\LowStockService;
use Inertia\Inertia;
use Inertia\Response;

class LowStockReportController extends Controller
{
    public function index(
        LowStockReportRequest $request,
        LowStockService $service
    ): Response {
        /*
         * Step 1: Resolve the selected company using
         * the authenticated user's company access.
         */
        $companyId = $request->resolvedCompanyId();

        /*
         * Step 2: Validate that requested filters
         * belong to the selected company.
         */
        $request->validateCompanyFilters($companyId);

        /*
         * Step 3: Retrieve validated report filters.
         */
        $filters = $request->reportFilters();

        /*
         * Step 4: Build the company-scoped report.
         */
        $report = $service->report(
            $companyId,
            $filters
        );

        /*
         * Step 5: Limit dropdown options to companies
         * accessible to this user.
         */
        $allowedCompanyIds = $request->allowedCompanyIds();

        $companies = Company::query()
            ->whereIn('id', $allowedCompanyIds)
            ->orderBy('name')
            ->get([
                'id',
                'name',
            ]);

        $warehouses = Warehouse::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'company_id',
                'code',
                'name',
            ]);

        $products = Product::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('product_type', 'physical')
            ->where('track_inventory', true)
            ->orderBy('name')
            ->get([
                'id',
                'company_id',
                'product_category_id',
                'name',
                'sku',
            ]);

        /*
         * Only show categories used by products
         * belonging to the selected company.
         */
        $categoryIds = Product::query()
            ->where('company_id', $companyId)
            ->whereNotNull('product_category_id')
            ->distinct()
            ->pluck('product_category_id');

        $categories = ProductCategory::query()
            ->whereIn('id', $categoryIds)
            ->orderBy('name')
            ->get([
                'id',
                'name',
            ]);

        /*
         * Step 6: Return report data to Inertia.
         */
        return Inertia::render(
            'ERP/Inventory/LowStock/Index',
            [
                'rows' => $report['rows'],

                'summary' => $report['summary'],

                'filters' => [
                    'company_id' => (string) $companyId,

                    'warehouse_id' => $filters['warehouse_id'] !== null
                        ? (string) $filters['warehouse_id']
                        : '',

                    'product_id' => $filters['product_id'] !== null
                        ? (string) $filters['product_id']
                        : '',

                    'category_id' => $filters['category_id'] !== null
                        ? (string) $filters['category_id']
                        : '',

                    'status' => $filters['status'] ?? '',

                    'include_healthy' => $filters['include_healthy'],
                ],

                'companies' => $companies,

                'warehouses' => $warehouses,

                'products' => $products,

                'categories' => $categories,

                'statuses' => [
                    [
                        'value' => LowStockService::STATUS_OUT_OF_STOCK,
                        'label' => 'Out of Stock',
                    ],
                    [
                        'value' => LowStockService::STATUS_CRITICAL,
                        'label' => 'Critical',
                    ],
                    [
                        'value' => LowStockService::STATUS_LOW_STOCK,
                        'label' => 'Low Stock',
                    ],
                    [
                        'value' => LowStockService::STATUS_HEALTHY,
                        'label' => 'Healthy',
                    ],
                ],

                'canManageReorderSettings' => $request
                    ->user()
                        ?->can('erp.inventory-reorder-settings.manage') ?? false,
            ]
        );
    }
}
