<?php

namespace App\Http\Controllers\ERP\Organization;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Department;
use App\Models\Designation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DesignationController extends Controller
{
    public function index(
        Request $request
    ): Response {
        $filters = [
            'search' => $request
                ->string('search')
                ->toString(),

            'company_id' => $request->input(
                'company_id'
            ),

            'department_id' => $request->input(
                'department_id'
            ),

            'status' => $request->input(
                'status'
            ),
        ];

        $designations =
            Designation::query()
                ->with([
                    'company:id,name,code',
                    'department:id,company_id,branch_id,name,code',
                    'department.branch:id,name,code',
                ])
                ->when(
                    $filters['search'],
                    function (
                        $query,
                        $search
                    ) {
                        $query->where(
                            function (
                                $query
                            ) use (
                                $search
                            ) {
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
                                        'description',
                                        'like',
                                        "%{$search}%"
                                    );
                            }
                        );
                    }
                )
                ->when(
                    $filters[
                        'company_id'
                    ],
                    fn (
                        $query,
                        $companyId
                    ) => $query->where(
                        'company_id',
                        $companyId
                    )
                )
                ->when(
                    $filters[
                        'department_id'
                    ],
                    fn (
                        $query,
                        $departmentId
                    ) => $query->where(
                        'department_id',
                        $departmentId
                    )
                )
                ->when(
                    $filters['status'],
                    fn (
                        $query,
                        $status
                    ) => $query->where(
                        'status',
                        $status
                    )
                )
                ->orderBy(
                    'sort_order'
                )
                ->orderBy('name')
                ->paginate(15)
                ->withQueryString();

        $companies =
            Company::query()
                ->orderByDesc(
                    'is_default'
                )
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'code',
                ]);

        $departments =
            Department::query()
                ->orderBy('name')
                ->get([
                    'id',
                    'company_id',
                    'branch_id',
                    'name',
                    'code',
                ]);

        return Inertia::render(
            'ERP/Organization/Designations/Index',
            [
                'designations' => $designations,

                'companies' => $companies,

                'departments' => $departments,

                'filters' => $filters,
            ]
        );
    }

    public function create(): Response
    {
        $companies =
            Company::query()
                ->active()
                ->orderByDesc(
                    'is_default'
                )
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'code',
                ]);

        $departments =
            Department::query()
                ->active()
                ->orderBy('name')
                ->get([
                    'id',
                    'company_id',
                    'branch_id',
                    'name',
                    'code',
                ]);

        return Inertia::render(
            'ERP/Organization/Designations/Create',
            [
                'companies' => $companies,

                'departments' => $departments,
            ]
        );
    }

    public function store(
        Request $request
    ): RedirectResponse {
        $validated =
            $this->validateDesignation(
                $request
            );

        Designation::create([
            ...$validated,

            'code' => strtoupper(
                $validated['code']
            ),

            'department_id' => $validated[
                    'department_id'
                ] ?? null,

            'sort_order' => $validated[
                    'sort_order'
                ] ?? 0,
        ]);

        return to_route(
            'erp.designations.index'
        )->with(
            'success',
            'Designation created successfully.'
        );
    }

    public function edit(
        Designation $designation
    ): Response {
        $designation->load([
            'company:id,name,code',

            'department:id,company_id,branch_id,name,code',
        ]);

        $companies =
            Company::query()
                ->where(
                    function (
                        $query
                    ) use (
                        $designation
                    ) {
                        $query
                            ->where(
                                'status',
                                'active'
                            )
                            ->orWhere(
                                'id',
                                $designation
                                    ->company_id
                            );
                    }
                )
                ->orderByDesc(
                    'is_default'
                )
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'code',
                ]);

        $departments =
            Department::query()
                ->where(
                    function (
                        $query
                    ) use (
                        $designation
                    ) {
                        $query->where(
                            'status',
                            'active'
                        );

                        if (
                            $designation
                                ->department_id
                        ) {
                            $query->orWhere(
                                'id',
                                $designation
                                    ->department_id
                            );
                        }
                    }
                )
                ->orderBy('name')
                ->get([
                    'id',
                    'company_id',
                    'branch_id',
                    'name',
                    'code',
                ]);

        return Inertia::render(
            'ERP/Organization/Designations/Edit',
            [
                'designation' => $designation,

                'companies' => $companies,

                'departments' => $departments,
            ]
        );
    }

    public function update(
        Request $request,
        Designation $designation
    ): RedirectResponse {
        $validated =
            $this->validateDesignation(
                $request,
                $designation
            );

        $designation->update([
            ...$validated,

            'code' => strtoupper(
                $validated['code']
            ),

            'department_id' => $validated[
                    'department_id'
                ] ?? null,

            'sort_order' => $validated[
                    'sort_order'
                ] ?? 0,
        ]);

        return to_route(
            'erp.designations.index'
        )->with(
            'success',
            'Designation updated successfully.'
        );
    }

    public function destroy(
        Designation $designation
    ): RedirectResponse {
        /*
         * Employee dependency protection
         * will be added when Employee
         * Management is implemented.
         */

        $designation->delete();

        return to_route(
            'erp.designations.index'
        )->with(
            'success',
            'Designation deleted successfully.'
        );
    }

    private function validateDesignation(
        Request $request,
        ?Designation $designation = null
    ): array {
        $companyId =
            (int) $request->input(
                'company_id'
            );

        return $request->validate([
            'company_id' => [
                'required',
                'integer',
                'exists:companies,id',
            ],

            /*
             * If a department is supplied,
             * it MUST belong to the selected
             * company.
             */
            'department_id' => [
                'nullable',
                'integer',

                Rule::exists(
                    'departments',
                    'id'
                )->where(
                    fn ($query) => $query->where(
                        'company_id',
                        $companyId
                    )
                ),
            ],

            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'code' => [
                'required',
                'string',
                'max:50',

                Rule::unique(
                    'designations',
                    'code'
                )
                    ->where(
                        fn ($query) => $query->where(
                            'company_id',
                            $companyId
                        )
                    )
                    ->ignore(
                        $designation?->id
                    ),
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'sort_order' => [
                'nullable',
                'integer',
                'min:0',
                'max:999999',
            ],

            'status' => [
                'required',

                Rule::in([
                    'active',
                    'inactive',
                ]),
            ],
        ]);
    }
}
