<?php

namespace App\Http\Controllers\ERP\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\ERP\Inventory\OpeningStockRequest;
use App\Models\Company;
use App\Models\OpeningStock;
use App\Models\OpeningStockItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\ERP\Accounting\Source\Purchase\AccountingPurchasePosting;
use App\Services\Inventory\InventoryService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OpeningStockController extends Controller
{
    public function index(Request $request): Response
    {
        $query = OpeningStock::query()
            ->with([
                'company:id,name',
                'warehouse:id,company_id,code,name',
                'creator:id,name',
                'poster:id,name',
            ]);

        /*
         * Search
         */
        if ($request->filled('search')) {
            $search = trim(
                (string) $request->input('search')
            );

            $query->where(
                function ($query) use ($search) {
                    $query
                        ->where(
                            'document_number',
                            'like',
                            "%{$search}%"
                        )
                        ->orWhereHas(
                            'warehouse',
                            function ($warehouseQuery) use ($search) {
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

        /*
         * Company filter
         */
        if ($request->filled('company_id')) {
            $query->where(
                'company_id',
                (int) $request->input('company_id')
            );
        }

        /*
         * Warehouse filter
         */
        if ($request->filled('warehouse_id')) {
            $query->where(
                'warehouse_id',
                (int) $request->input('warehouse_id')
            );
        }

        /*
         * Status filter
         */
        if ($request->filled('status')) {
            $query->where(
                'status',
                $request->input('status')
            );
        }

        /*
         * Date filters
         */
        if ($request->filled('date_from')) {
            $query->whereDate(
                'document_date',
                '>=',
                $request->input('date_from')
            );
        }

        if ($request->filled('date_to')) {
            $query->whereDate(
                'document_date',
                '<=',
                $request->input('date_to')
            );
        }

        /*
         * Pagination
         */
        $perPage = (int) $request->input(
            'per_page',
            15
        );

        if (
            ! in_array(
                $perPage,
                [10, 15, 25, 50, 100],
                true
            )
        ) {
            $perPage = 15;
        }

        return Inertia::render(
            'ERP/Inventory/OpeningStocks/Index',
            [
                'openingStocks' => $query
                    ->orderByDesc('document_date')
                    ->orderByDesc('id')
                    ->paginate($perPage)
                    ->withQueryString(),

                'filters' => [
                    'search' => $request->input(
                        'search',
                        ''
                    ),

                    'company_id' => $request->input(
                        'company_id',
                        ''
                    ),

                    'warehouse_id' => $request->input(
                        'warehouse_id',
                        ''
                    ),

                    'status' => $request->input(
                        'status',
                        ''
                    ),

                    'date_from' => $request->input(
                        'date_from',
                        ''
                    ),

                    'date_to' => $request->input(
                        'date_to',
                        ''
                    ),

                    'per_page' => $perPage,
                ],

                'companies' => $this->companyOptions(),

                'warehouses' => $this->warehouseOptions(),

                'statuses' => OpeningStock::statuses(),
            ]
        );
    }

    public function create(): Response
    {
        return Inertia::render(
            'ERP/Inventory/OpeningStocks/Create',
            $this->formOptions()
        );
    }

    public function store(
        OpeningStockRequest $request
    ): RedirectResponse {
        $data = $this->validateDocument(
            $request
        );

        $openingStock = DB::transaction(
            function () use ($data) {
                $openingStock =
                    OpeningStock::create([
                        'company_id' => $data['company_id'],

                        'warehouse_id' => $data['warehouse_id'],

                        'document_number' => $this->nextDocumentNumber(),

                        'document_date' => $data['document_date'],

                        'status' => OpeningStock::STATUS_DRAFT,

                        'notes' => $data['notes'] ?? null,

                        'created_by' => auth()->id(),

                        'total_items' => 0,

                        'total_quantity' => 0,

                        'total_value' => 0,
                    ]);

                $this->replaceItems(
                    $openingStock,
                    $data['items']
                );

                $this->refreshTotals(
                    $openingStock
                );

                return $openingStock;
            }
        );

        return redirect()
            ->route(
                'erp.inventory.opening-stocks.show',
                $openingStock
            )
            ->with(
                'success',
                'Opening stock draft created successfully.'
            );
    }

    public function show(
        OpeningStock $openingStock
    ): Response {
        $openingStock->load([
            'company:id,name',

            'warehouse:id,company_id,code,name',

            'creator:id,name',

            'poster:id,name',

            'items' => fn ($query) => $query->orderBy('id'),

            'items.product:id,company_id,name,sku',

            'items.variant:id,product_id,name,sku',
        ]);

        return Inertia::render(
            'ERP/Inventory/OpeningStocks/Show',
            [
                'openingStock' => $openingStock,
            ]
        );
    }

    public function edit(
        OpeningStock $openingStock
    ): Response {
        $this->ensureDraft(
            $openingStock
        );

        $openingStock->load([
            'items' => fn ($query) => $query->orderBy('id'),

            'items.product:id,company_id,name,sku',

            'items.variant:id,product_id,name,sku',
        ]);

        return Inertia::render(
            'ERP/Inventory/OpeningStocks/Edit',
            [
                'openingStock' => $openingStock,

                ...$this->formOptions(),
            ]
        );
    }

    public function update(
        OpeningStockRequest $request,
        OpeningStock $openingStock
    ): RedirectResponse {
        /*
         * Quick state check before validation.
         */
        $this->ensureDraft(
            $openingStock
        );

        $data = $this->validateDocument(
            $request
        );

        DB::transaction(
            function () use ($openingStock, $data) {
                /*
                 * Lock the document before modification.
                 *
                 * This is especially important once
                 * posting is introduced in 3.2.3F.
                 */
                $locked = OpeningStock::query()
                    ->lockForUpdate()
                    ->findOrFail(
                        $openingStock->id
                    );

                /*
                 * Recheck after acquiring the lock.
                 */
                $this->ensureDraft(
                    $locked
                );

                $locked->update([
                    'company_id' => $data['company_id'],

                    'warehouse_id' => $data['warehouse_id'],

                    'document_date' => $data['document_date'],

                    'notes' => $data['notes'] ?? null,
                ]);

                $this->replaceItems(
                    $locked,
                    $data['items']
                );

                $this->refreshTotals(
                    $locked
                );
            }
        );

        return redirect()
            ->route(
                'erp.inventory.opening-stocks.show',
                $openingStock
            )
            ->with(
                'success',
                'Opening stock draft updated successfully.'
            );
    }

    public function destroy(
        OpeningStock $openingStock
    ): RedirectResponse {
        /*
         * Quick state check.
         */
        $this->ensureDraft(
            $openingStock
        );

        DB::transaction(
            function () use ($openingStock) {
                /*
                 * Lock before deleting so a future
                 * posting request cannot race with delete.
                 */
                $locked = OpeningStock::query()
                    ->lockForUpdate()
                    ->findOrFail(
                        $openingStock->id
                    );

                $this->ensureDraft(
                    $locked
                );

                /*
                 * opening_stock_items are removed by
                 * cascadeOnDelete() from the migration.
                 */
                $locked->delete();
            }
        );

        return redirect()
            ->route(
                'erp.inventory.opening-stocks.index'
            )
            ->with(
                'success',
                'Opening stock draft deleted successfully.'
            );
    }

    /**
     * Validate the normal request rules first,
     * then perform business-level validation.
     *
     * @return array<string, mixed>
     */
    private function validateDocument(
        OpeningStockRequest $request
    ): array {
        $data = $request->validated();

        $this->validateWarehouseCompany(
            (int) $data['company_id'],
            (int) $data['warehouse_id']
        );

        $this->validateItems(
            (int) $data['company_id'],
            $data['items']
        );

        return $data;
    }

    /**
     * Warehouse must belong to the selected company.
     */
    private function validateWarehouseCompany(
        int $companyId,
        int $warehouseId
    ): void {
        $valid = Warehouse::query()
            ->whereKey($warehouseId)
            ->where(
                'company_id',
                $companyId
            )
            ->exists();

        if (! $valid) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'The selected warehouse does not belong to the selected company.',
            ]);
        }
    }

    /**
     * Validate:
     *
     * - product belongs to company
     * - variant belongs to product
     * - duplicate product/variant combinations
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function validateItems(
        int $companyId,
        array $items
    ): void {
        $seen = [];

        foreach ($items as $index => $item) {
            $productId =
                (int) $item['product_id'];

            $variantId =
                isset(
                    $item['product_variant_id']
                ) &&
                $item['product_variant_id'] !== ''
                ? (int) $item[
                    'product_variant_id'
                ]
                : null;

            /*
             * Products in this project are company-scoped.
             */
            $productValid = Product::query()
                ->whereKey($productId)
                ->where(
                    'company_id',
                    $companyId
                )
                ->exists();

            if (! $productValid) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => 'The selected product does not belong to the selected company.',
                ]);
            }

            /*
             * If a variant is selected, it must belong
             * to the selected product.
             */
            if ($variantId !== null) {
                $variantValid =
                    ProductVariant::query()
                        ->whereKey(
                            $variantId
                        )
                        ->where(
                            'product_id',
                            $productId
                        )
                        ->exists();

                if (! $variantValid) {
                    throw ValidationException::withMessages([
                        "items.{$index}.product_variant_id" => 'The selected variant does not belong to the selected product.',
                    ]);
                }
            }

            /*
             * Normalize NULL variant to zero only for
             * duplicate detection.
             *
             * Examples:
             *
             * 5:0  = Product 5, no variant
             * 5:12 = Product 5, Variant 12
             */
            $duplicateKey =
                $productId.
                ':'.
                ($variantId ?? 0);

            if (isset($seen[$duplicateKey])) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => 'The same product and variant cannot appear more than once.',
                ]);
            }

            $seen[$duplicateKey] = true;
        }
    }

    /**
     * Replace all lines of a draft document.
     *
     * The request is never trusted for total_cost.
     * It is calculated here on the backend.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function replaceItems(
        OpeningStock $openingStock,
        array $items
    ): void {
        $openingStock
            ->items()
            ->delete();

        foreach ($items as $item) {
            $quantity = round(
                (float) $item['quantity'],
                4
            );

            $unitCost = round(
                (float) $item['unit_cost'],
                4
            );

            $totalCost = round(
                $quantity * $unitCost,
                4
            );

            $variantId =
                isset(
                    $item['product_variant_id']
                ) &&
                $item['product_variant_id'] !== ''
                ? (int) $item[
                    'product_variant_id'
                ]
                : null;

            $openingStock
                ->items()
                ->create([
                    'product_id' => (int) $item['product_id'],

                    'product_variant_id' => $variantId,

                    'quantity' => $quantity,

                    'unit_cost' => $unitCost,

                    'total_cost' => $totalCost,
                ]);
        }
    }

    /**
     * Recalculate document totals from persisted items.
     *
     * React does not control these values.
     */
    private function refreshTotals(
        OpeningStock $openingStock
    ): void {
        /** @var \stdClass|null $totals */
        $totals = $openingStock
            ->items()
            ->selectRaw(
                '
                COUNT(*) as total_items,
                COALESCE(SUM(quantity), 0) as total_quantity,
                COALESCE(SUM(total_cost), 0) as total_value
                '
            )
            ->toBase()
            ->first();

        $openingStock->update([
            'total_items' => $totals !== null ? (int) $totals->total_items : 0,
            'total_quantity' => $totals !== null ? ($totals->total_quantity ?? 0) : 0,
            'total_value' => $totals !== null ? ($totals->total_value ?? 0) : 0,
        ]);
    }

    /**
     * Only draft documents may be modified.
     */
    private function ensureDraft(
        OpeningStock $openingStock
    ): void {
        if (! $openingStock->isDraft()) {
            abort(
                422,
                'Posted opening stock documents cannot be modified.'
            );
        }
    }

    /**
     * Generate:
     *
     * OS-2026-000001
     * OS-2026-000002
     * ...
     */
    private function nextDocumentNumber(): string
    {
        $year = Carbon::now()->year;

        /*
         * Lock the latest row while calculating the
         * next sequence.
         *
         * document_number UNIQUE remains the final
         * database safeguard.
         */
        $latest = OpeningStock::query()
            ->where(
                'document_number',
                'like',
                "OS-{$year}-%"
            )
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $nextSequence = 1;

        if ($latest) {
            $parts = explode(
                '-',
                $latest->document_number
            );

            $lastSequence =
                (int) end($parts);

            $nextSequence =
                $lastSequence + 1;
        }

        return sprintf(
            'OS-%d-%06d',
            $year,
            $nextSequence
        );
    }

    /**
     * Data required by Create/Edit.
     *
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'companies' => $this->companyOptions(),

            'warehouses' => $this->warehouseOptions(),

            /*
             * company_id is intentionally included.
             *
             * Form.tsx will use it for:
             *
             * Company
             *   ↓
             * Available Products
             */
            'products' => Product::query()
                ->with([
                    'variants' => fn ($query) => $query
                        ->select([
                            'id',
                            'product_id',
                            'name',
                            'sku',
                        ])
                        ->orderBy('name'),
                ])
                ->select([
                    'id',
                    'company_id',
                    'name',
                    'sku',
                ])
                ->orderBy('name')
                ->get(),
        ];
    }

    /**
     * Company dropdown options.
     */
    private function companyOptions()
    {
        return Company::query()
            ->select([
                'id',
                'name',
            ])
            ->orderBy('name')
            ->get();
    }

    /**
     * Warehouse dropdown options.
     *
     * company_id is required by Form.tsx so the
     * warehouse dropdown can react to company changes.
     */
    private function warehouseOptions()
    {
        return Warehouse::query()
            ->select([
                'id',
                'company_id',
                'code',
                'name',
                'is_active',
            ])
            ->orderBy('name')
            ->get();
    }

    public function post(
        OpeningStock $openingStock,
        InventoryService $inventoryService
    ): RedirectResponse {
        DB::transaction(function () use ($openingStock, $inventoryService): void {
            app(AccountingPurchasePosting::class)->before((int) $openingStock->company_id, true);
            $document = OpeningStock::query()
                ->whereKey($openingStock->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            Gate::authorize('erp.opening-stocks.post');

            $user = auth()->user();
            abort_unless(
                $user && (
                    $user->hasRole('super-admin') ||
                    $user->employee()
                        ->where('company_id', $document->company_id)
                        ->exists()
                ),
                403,
                'You are not authorized to post opening stock for this company.'
            );

            if (! $document->canBePosted()) {
                throw ValidationException::withMessages([
                    'status' => 'Only draft opening stock documents can be posted.',
                ]);
            }

            $document->load([
                'warehouse',
                'items.product',
                'items.variant',
            ]);

            /** @var Warehouse|null $warehouse */
            $warehouse = $document->warehouse;

            if (
                ! $warehouse ||
                (int) $warehouse->company_id !==
                (int) $document->company_id
            ) {
                throw ValidationException::withMessages([
                    'warehouse_id' => 'The warehouse does not belong to the document company.',
                ]);
            }

            if (! $warehouse->is_active) {
                throw ValidationException::withMessages([
                    'warehouse_id' => 'Cannot post opening stock to an inactive warehouse.',
                ]);
            }

            /** @var EloquentCollection<int, OpeningStockItem> $items */
            $items = $document->items;

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'Add at least one stock item before posting.',
                ]);
            }

            $seen = [];

            foreach ($items as $index => $item) {
                /** @var Product|null $product */
                $product = $item->product;
                /** @var ProductVariant|null $variant */
                $variant = $item->variant;

                if (
                    ! $product ||
                    (int) $product->company_id !==
                    (int) $document->company_id
                ) {
                    throw ValidationException::withMessages([
                        "items.{$index}.product_id" => 'The product does not belong to the document company.',
                    ]);
                }

                if (
                    ($item->product_variant_id !== null && ! $variant) ||
                    ($variant && (int) $variant->product_id !== (int) $product->getKey()) ||
                    ($product->has_variants && ! $variant) ||
                    (! $product->has_variants && $variant)
                ) {
                    throw ValidationException::withMessages([
                        "items.{$index}.product_variant_id" => 'Select an existing variant belonging to this product when variants are required; products without variants must not have one.',
                    ]);
                }

                $key = $item->product_id.':'.
                    ($item->product_variant_id ?? 0);

                if (isset($seen[$key])) {
                    throw ValidationException::withMessages([
                        "items.{$index}.product_id" => 'Duplicate product and variant combination.',
                    ]);
                }

                $seen[$key] = true;

                if (
                    bccomp(
                        (string) $item->quantity,
                        '0.0000',
                        4
                    ) <= 0
                ) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity" => 'Quantity must be greater than zero.',
                    ]);
                }

                if (
                    bccomp(
                        (string) $item->unit_cost,
                        '0.0000',
                        4
                    ) < 0
                ) {
                    throw ValidationException::withMessages([
                        "items.{$index}.unit_cost" => 'Unit cost cannot be negative.',
                    ]);
                }

                $inventoryService->stockIn(
                    warehouse: $warehouse,
                    product: $product,
                    quantity: (string) $item->quantity,
                    movementType: StockMovement::TYPE_OPENING_STOCK,
                    unitCost: (string) $item->unit_cost,
                    variant: $variant,
                    referenceType: OpeningStock::class,
                    referenceId: (int) $document->getKey(),
                    referenceNumber: $document->document_number,
                    occurredAt: now(),
                    createdBy: auth()->id(),
                    notes: "Opening stock {$document->document_number}",
                );
            }

            $document->status = OpeningStock::STATUS_POSTED;
            $document->posted_by = auth()->id();
            $document->posted_at = now();
            $document->save();
        }, 5);

        return redirect()
            ->route(
                'erp.inventory.opening-stocks.show',
                $openingStock
            )
            ->with(
                'success',
                'Opening stock posted successfully.'
            );
    }
}
