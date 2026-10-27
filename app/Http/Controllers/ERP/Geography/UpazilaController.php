<?php

namespace App\Http\Controllers\ERP\Geography;

use App\Http\Controllers\Controller;
use App\Models\District;
use App\Models\Division;
use App\Models\Upazila;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UpazilaController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => [
                'nullable',
                'string',
                'max:255',
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

            'status' => [
                'nullable',
                Rule::in([
                    'active',
                    'inactive',
                ]),
            ],
        ]);

        $upazilas = Upazila::query()
            ->with([
                'district:id,division_id,name,name_bn,code,is_active',
                'district.division:id,name,name_bn,code,is_active',
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
                                    'name_bn',
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
            )

            ->when(
                $filters['division_id'] ?? null,
                function ($query, $divisionId) {
                    $query->whereHas(
                        'district',
                        fn ($query) => $query->where(
                            'division_id',
                            $divisionId
                        )
                    );
                }
            )

            ->when(
                $filters['district_id'] ?? null,
                fn ($query, $districtId) => $query->where(
                    'district_id',
                    $districtId
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

            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $divisions = Division::query()
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'name_bn',
                'code',
                'is_active',
            ]);

        $districts = District::query()
            ->with([
                'division:id,name,name_bn,code,is_active',
            ])
            ->orderBy('name')
            ->get([
                'id',
                'division_id',
                'name',
                'name_bn',
                'code',
                'is_active',
            ]);

        return Inertia::render(
            'ERP/Geography/Upazilas/Index',
            [
                'upazilas' => $upazilas,
                'divisions' => $divisions,
                'districts' => $districts,
                'filters' => $filters,
            ]
        );
    }

    public function create(): Response
    {
        $divisions = Division::query()
            ->where(
                'is_active',
                true
            )
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'name_bn',
                'code',
            ]);

        $districts = District::query()
            ->where(
                'is_active',
                true
            )
            ->whereHas(
                'division',
                fn ($query) => $query->where(
                    'is_active',
                    true
                )
            )
            ->orderBy('name')
            ->get([
                'id',
                'division_id',
                'name',
                'name_bn',
                'code',
            ]);

        return Inertia::render(
            'ERP/Geography/Upazilas/Create',
            [
                'divisions' => $divisions,
                'districts' => $districts,
            ]
        );
    }

    public function store(
        Request $request
    ): RedirectResponse {
        $validated = $this->validateData(
            $request
        );

        Upazila::create(
            $validated
        );

        return redirect()
            ->route(
                'erp.upazilas.index'
            )
            ->with(
                'success',
                'Upazila created successfully.'
            );
    }

    public function edit(
        Upazila $upazila
    ): Response {
        $upazila->load(
            'district:id,division_id,name,name_bn,code,is_active'
        );

        $district = District::query()
            ->find($upazila->district_id);

        $divisionId = $district?->division_id;

        $divisions = Division::query()
            ->where(
                function ($query) use ($divisionId) {
                    $query
                        ->where(
                            'is_active',
                            true
                        );

                    if ($divisionId) {
                        $query->orWhere(
                            'id',
                            $divisionId
                        );
                    }
                }
            )
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'name_bn',
                'code',
                'is_active',
            ]);

        $districts = District::query()
            ->where(
                function ($query) use ($upazila) {
                    $query
                        ->where(
                            'is_active',
                            true
                        )
                        ->orWhere(
                            'id',
                            $upazila->district_id
                        );
                }
            )
            ->orderBy('name')
            ->get([
                'id',
                'division_id',
                'name',
                'name_bn',
                'code',
                'is_active',
            ]);

        return Inertia::render(
            'ERP/Geography/Upazilas/Edit',
            [
                'upazila' => $upazila,

                'division_id' => $divisionId,

                'divisions' => $divisions,

                'districts' => $districts,
            ]
        );
    }

    public function update(
        Request $request,
        Upazila $upazila
    ): RedirectResponse {
        $validated = $this->validateData(
            $request,
            $upazila
        );

        $upazila->update(
            $validated
        );

        return redirect()
            ->route(
                'erp.upazilas.index'
            )
            ->with(
                'success',
                'Upazila updated successfully.'
            );
    }

    public function destroy(
        Upazila $upazila
    ): RedirectResponse {
        if (
            $upazila->dealers()->exists() ||
            DB::table('dealer_applications')
                ->where('upazila_id', $upazila->getKey())
                ->exists()
        ) {
            return back()->withErrors([
                'upazila' => 'This Upazila cannot be deleted because Dealer records or Dealer applications reference it.',
            ]);
        }

        $upazila->delete();

        return redirect()
            ->route(
                'erp.upazilas.index'
            )
            ->with(
                'success',
                'Upazila deleted successfully.'
            );
    }

    private function validateData(
        Request $request,
        ?Upazila $upazila = null
    ): array {
        return $request->validate([
            'district_id' => [
                'required',
                'integer',

                Rule::exists(
                    'districts',
                    'id'
                )->where(
                    function ($query) use ($upazila) {
                        $query->where(
                            'is_active',
                            true
                        );

                        if ($upazila) {
                            $query->orWhere(
                                'id',
                                $upazila->district_id
                            );
                        }
                    }
                ),
            ],

            'name' => [
                'required',
                'string',
                'max:255',

                Rule::unique(
                    'upazilas',
                    'name'
                )
                    ->where(
                        fn ($query) => $query->where(
                            'district_id',
                            $request->integer(
                                'district_id'
                            )
                        )
                    )
                    ->ignore(
                        $upazila?->id
                    ),
            ],

            'name_bn' => [
                'nullable',
                'string',
                'max:255',
            ],

            'code' => [
                'nullable',
                'string',
                'max:50',

                Rule::unique(
                    'upazilas',
                    'code'
                )->ignore(
                    $upazila?->id
                ),
            ],

            'is_active' => [
                'required',
                'boolean',
            ],
        ]);
    }
}
