<?php

namespace App\Http\Controllers\ERP\Geography;

use App\Http\Controllers\Controller;
use App\Models\District;
use App\Models\Division;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DistrictController extends Controller
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

            'status' => [
                'nullable',
                Rule::in([
                    'active',
                    'inactive',
                ]),
            ],
        ]);

        $districts = District::query()
            ->with([
                'division:id,name,name_bn,code,is_active',
            ])
            ->withCount('upazilas')

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
                fn ($query, $divisionId) =>
                    $query->where(
                        'division_id',
                        $divisionId
                    )
            )

            ->when(
                ($filters['status'] ?? null) === 'active',
                fn ($query) =>
                    $query->where(
                        'is_active',
                        true
                    )
            )

            ->when(
                ($filters['status'] ?? null) === 'inactive',
                fn ($query) =>
                    $query->where(
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

        return Inertia::render(
            'ERP/Geography/Districts/Index',
            [
                'districts' => $districts,

                'divisions' => $divisions,

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

        return Inertia::render(
            'ERP/Geography/Districts/Create',
            [
                'divisions' => $divisions,
            ]
        );
    }

    public function store(
        Request $request
    ): RedirectResponse {
        $validated =
            $this->validateData(
                $request
            );

        District::create(
            $validated
        );

        return redirect()
            ->route(
                'erp.districts.index'
            )
            ->with(
                'success',
                'District created successfully.'
            );
    }

    public function edit(
        District $district
    ): Response {
        $divisions = Division::query()
            ->where(
                function ($query) use ($district) {
                    $query
                        ->where(
                            'is_active',
                            true
                        )
                        ->orWhere(
                            'id',
                            $district->division_id
                        );
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

        return Inertia::render(
            'ERP/Geography/Districts/Edit',
            [
                'district' =>
                    $district->load(
                        'division:id,name,name_bn,code,is_active'
                    ),

                'divisions' =>
                    $divisions,
            ]
        );
    }

    public function update(
        Request $request,
        District $district
    ): RedirectResponse {
        $validated =
            $this->validateData(
                $request,
                $district
            );

        $district->update(
            $validated
        );

        return redirect()
            ->route(
                'erp.districts.index'
            )
            ->with(
                'success',
                'District updated successfully.'
            );
    }

    public function destroy(
        District $district
    ): RedirectResponse {
        if (
            $district
                ->upazilas()
                ->exists()
        ) {
            return back()->withErrors([
                'district' =>
                    'This district cannot be deleted because it has upazilas assigned to it.',
            ]);
        }

        $district->delete();

        return redirect()
            ->route(
                'erp.districts.index'
            )
            ->with(
                'success',
                'District deleted successfully.'
            );
    }

    private function validateData(
        Request $request,
        ?District $district = null
    ): array {
        return $request->validate([
            'division_id' => [
                'required',
                'integer',

                Rule::exists(
                    'divisions',
                    'id'
                )->where(
                    fn ($query) =>
                        $query->where(
                            'is_active',
                            true
                        )
                        ->when(
                            $district,
                            fn ($query) =>
                                $query->orWhere(
                                    'id',
                                    $district->division_id
                                )
                        )
                ),
            ],

            'name' => [
                'required',
                'string',
                'max:255',

                Rule::unique(
                    'districts',
                    'name'
                )
                    ->where(
                        fn ($query) =>
                            $query->where(
                                'division_id',
                                $request->integer(
                                    'division_id'
                                )
                            )
                    )
                    ->ignore(
                        $district?->id
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
                    'districts',
                    'code'
                )->ignore(
                    $district?->id
                ),
            ],

            'is_active' => [
                'required',
                'boolean',
            ],
        ]);
    }
}