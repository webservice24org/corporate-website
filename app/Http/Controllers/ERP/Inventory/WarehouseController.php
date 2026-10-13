<?php

namespace App\Http\Controllers\ERP\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\District;
use App\Models\Division;
use App\Models\InventoryBalance;
use App\Models\StockMovement;
use App\Models\Upazila;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseController extends Controller
{
    private const TYPES = [
        'main',
        'regional',
        'distribution',
        'retail',
        'transit',
        'returns',
        'other',
    ];

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

            'type' => [
                'nullable',
                Rule::in(self::TYPES),
            ],

            'status' => [
                'nullable',
                Rule::in([
                    'active',
                    'inactive',
                ]),
            ],

            'division_id' => [
                'nullable',
                'integer',
                'exists:divisions,id',
            ],

            'district_id' => [
                'nullable',
                'integer',
                'exists:districts,id',
            ],

            'upazila_id' => [
                'nullable',
                'integer',
                'exists:upazilas,id',
            ],
        ]);

        $warehouses = Warehouse::query()
            ->with([
                'company:id,name',
                'division:id,name,name_bn',
                'district:id,division_id,name,name_bn',
                'upazila:id,district_id,name,name_bn',
            ])

            ->when(
                $filters['search'] ?? null,
                function ($query, $search) {
                    $query->where(
                        function ($query) use ($search) {
                            $query
                                ->where(
                                    'name',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'code',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'contact_person',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'phone',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'email',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'address',
                                    'like',
                                    "%{$search}%"
                                );
                        }
                    );
                }
            )

            ->when(
                $filters['company_id'] ?? null,
                fn ($query, $companyId) => $query->where(
                    'company_id',
                    $companyId
                )
            )

            ->when(
                $filters['type'] ?? null,
                fn ($query, $type) => $query->where(
                    'type',
                    $type
                )
            )

            ->when(
                ($filters['status'] ?? null) === 'active',
                fn ($query) => $query->where(
                    'is_active',
                    true
                )
            )

            ->when(
                ($filters['status'] ?? null) === 'inactive',
                fn ($query) => $query->where(
                    'is_active',
                    false
                )
            )

            ->when(
                $filters['division_id'] ?? null,
                fn ($query, $divisionId) => $query->where(
                    'division_id',
                    $divisionId
                )
            )

            ->when(
                $filters['district_id'] ?? null,
                fn ($query, $districtId) => $query->where(
                    'district_id',
                    $districtId
                )
            )

            ->when(
                $filters['upazila_id'] ?? null,
                fn ($query, $upazilaId) => $query->where(
                    'upazila_id',
                    $upazilaId
                )
            )

            ->orderBy('company_id')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render(
            'ERP/Inventory/Warehouses/Index',
            [
                'warehouses' => $warehouses,

                'companies' => Company::query()
                    ->orderBy('name')
                    ->get([
                        'id',
                        'name',
                    ]),

                'divisions' => $this->divisions(),

                'districts' => $this->districts(),

                'upazilas' => $this->upazilas(),

                'warehouseTypes' => $this->warehouseTypes(),

                'filters' => $filters,
            ]
        );
    }

    public function create(): Response
    {
        return Inertia::render(
            'ERP/Inventory/Warehouses/Create',
            $this->formOptions()
        );
    }

    public function store(
        Request $request
    ): RedirectResponse {
        $data = $this->validatedData(
            $request
        );

        Warehouse::create($data);

        return redirect()
            ->route(
                'erp.inventory.warehouses.index'
            )
            ->with(
                'success',
                'Warehouse created successfully.'
            );
    }

    public function show(Warehouse $warehouse): Response
    {
        $warehouse->load([
            'company:id,name',
            'division:id,name,name_bn',
            'district:id,division_id,name,name_bn',
            'upazila:id,district_id,name,name_bn',
        ]);

        /*
         * Current inventory balances for this warehouse.
         *
         * InventoryBalance = current state
         * StockMovement    = append-only history
         */
        $balanceQuery = InventoryBalance::query()
            ->where('warehouse_id', $warehouse->id);

        $inventorySummary = [
            'sku_count' => (clone $balanceQuery)->count(),

            'on_hand' => (string) (clone $balanceQuery)
                ->sum('quantity_on_hand'),

            'reserved' => (string) (clone $balanceQuery)
                ->sum('quantity_reserved'),

            'available' => (string) (clone $balanceQuery)
                ->selectRaw(
                    'COALESCE(
                        SUM(quantity_on_hand - quantity_reserved),
                        0
                    ) as total'
                )
                ->value('total'),

            'inventory_value' => (string) (clone $balanceQuery)
                ->selectRaw(
                    'COALESCE(
                        SUM(quantity_on_hand * average_cost),
                        0
                    ) as total'
                )
                ->value('total'),
        ];

        $recentMovements = StockMovement::query()
            ->where('warehouse_id', $warehouse->id)
            ->with([
                'product:id,name,sku',
                'variant:id,product_id,name,sku',
                'creator:id,name',
            ])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return Inertia::render(
            'ERP/Inventory/Warehouses/Show',
            [
                'warehouse' => $warehouse,

                'inventorySummary' => $inventorySummary,

                'recentMovements' => $recentMovements,
            ]
        );
    }

    public function edit(
        Warehouse $warehouse
    ): Response {
        $warehouse->load([
            'company:id,name',
            'division:id,name,name_bn',
            'district:id,division_id,name,name_bn',
            'upazila:id,district_id,name,name_bn',
        ]);

        return Inertia::render(
            'ERP/Inventory/Warehouses/Edit',
            [
                ...$this->formOptions(),

                'warehouse' => $warehouse,
            ]
        );
    }

    public function update(
        Request $request,
        Warehouse $warehouse
    ): RedirectResponse {
        $data = $this->validatedData(
            $request,
            $warehouse
        );

        $warehouse->update($data);

        return redirect()
            ->route(
                'erp.inventory.warehouses.show',
                $warehouse
            )
            ->with(
                'success',
                'Warehouse updated successfully.'
            );
    }

    public function destroy(
        Warehouse $warehouse
    ): RedirectResponse {
        /*
         * Phase 3.2.1:
         * Warehouse currently has no stock balance /
         * stock movement relationships.
         *
         * In Phase 3.2.2 we will protect deletion
         * whenever inventory exists for this warehouse.
         */

        $warehouse->delete();

        return redirect()
            ->route(
                'erp.inventory.warehouses.index'
            )
            ->with(
                'success',
                'Warehouse deleted successfully.'
            );
    }

    private function validatedData(
        Request $request,
        ?Warehouse $warehouse = null
    ): array {
        $companyId = $request->integer(
            'company_id'
        );

        $data = $request->validate([
            'company_id' => [
                'required',
                'integer',
                'exists:companies,id',
            ],

            'division_id' => [
                'nullable',
                'integer',
                'exists:divisions,id',
            ],

            'district_id' => [
                'nullable',
                'integer',
                'exists:districts,id',
            ],

            'upazila_id' => [
                'nullable',
                'integer',
                'exists:upazilas,id',
            ],

            'code' => [
                'required',
                'string',
                'max:50',

                Rule::unique(
                    'warehouses',
                    'code'
                )
                    ->where(
                        fn ($query) => $query->where(
                            'company_id',
                            $companyId
                        )
                    )
                    ->ignore(
                        $warehouse?->id
                    ),
            ],

            'name' => [
                'required',
                'string',
                'max:150',
            ],

            'type' => [
                'required',
                Rule::in(self::TYPES),
            ],

            'contact_person' => [
                'nullable',
                'string',
                'max:150',
            ],

            'phone' => [
                'nullable',
                'string',
                'max:50',
            ],

            'email' => [
                'nullable',
                'email',
                'max:255',
            ],

            'address' => [
                'nullable',
                'string',
                'max:5000',
            ],

            'postal_code' => [
                'nullable',
                'string',
                'max:30',
            ],

            'is_active' => [
                'required',
                'boolean',
            ],

            'notes' => [
                'nullable',
                'string',
                'max:10000',
            ],
        ]);

        $this->validateGeography($data);

        return $data;
    }

    private function validateGeography(
        array $data
    ): void {
        $divisionId =
            $data['division_id'] ?? null;

        $districtId =
            $data['district_id'] ?? null;

        $upazilaId =
            $data['upazila_id'] ?? null;

        if (
            $districtId &&
            ! $divisionId
        ) {
            throw ValidationException::withMessages([
                'division_id' => 'Please select a division for the selected district.',
            ]);
        }

        if (
            $upazilaId &&
            ! $districtId
        ) {
            throw ValidationException::withMessages([
                'district_id' => 'Please select a district for the selected upazila.',
            ]);
        }

        if ($districtId) {
            $districtBelongsToDivision =
                District::query()
                    ->whereKey($districtId)
                    ->where(
                        'division_id',
                        $divisionId
                    )
                    ->exists();

            if (
                ! $districtBelongsToDivision
            ) {
                throw ValidationException::withMessages([
                    'district_id' => 'The selected district does not belong to the selected division.',
                ]);
            }
        }

        if ($upazilaId) {
            $upazilaBelongsToDistrict =
                Upazila::query()
                    ->whereKey($upazilaId)
                    ->where(
                        'district_id',
                        $districtId
                    )
                    ->exists();

            if (
                ! $upazilaBelongsToDistrict
            ) {
                throw ValidationException::withMessages([
                    'upazila_id' => 'The selected upazila does not belong to the selected district.',
                ]);
            }
        }
    }

    private function formOptions(): array
    {
        return [
            'companies' => Company::query()
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                ]),

            'divisions' => $this->divisions(),

            'districts' => $this->districts(),

            'upazilas' => $this->upazilas(),

            'warehouseTypes' => $this->warehouseTypes(),
        ];
    }

    private function divisions()
    {
        return Division::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'name_bn',
            ]);
    }

    private function districts()
    {
        return District::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'division_id',
                'name',
                'name_bn',
            ]);
    }

    private function upazilas()
    {
        return Upazila::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'district_id',
                'name',
                'name_bn',
            ]);
    }

    private function warehouseTypes(): array
    {
        return [
            [
                'value' => 'main',
                'label' => 'Main Warehouse',
            ],
            [
                'value' => 'regional',
                'label' => 'Regional Warehouse',
            ],
            [
                'value' => 'distribution',
                'label' => 'Distribution Warehouse',
            ],
            [
                'value' => 'retail',
                'label' => 'Retail Warehouse',
            ],
            [
                'value' => 'transit',
                'label' => 'Transit Warehouse',
            ],
            [
                'value' => 'returns',
                'label' => 'Returns Warehouse',
            ],
            [
                'value' => 'other',
                'label' => 'Other',
            ],
        ];
    }
}
