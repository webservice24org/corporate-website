<?php

namespace App\Http\Controllers\ERP\Organization;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentController extends Controller
{
    public function index(
        Request $request
    ): Response {
        $filters = [
            'search' => $request->string(
                'search'
            )->toString(),

            'company_id' => $request->input(
                'company_id'
            ),

            'branch_id' => $request->input(
                'branch_id'
            ),

            'status' => $request->input(
                'status'
            ),
        ];

        $departments =
            Department::query()
                ->with([
                    'company:id,name,code',
                    'branch:id,company_id,name,code',
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
                        'branch_id'
                    ],
                    fn (
                        $query,
                        $branchId
                    ) => $query->where(
                        'branch_id',
                        $branchId
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
                ->latest('id')
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

        $branches =
            Branch::query()
                ->orderByDesc(
                    'is_default'
                )
                ->orderBy('name')
                ->get([
                    'id',
                    'company_id',
                    'name',
                    'code',
                ]);

        return Inertia::render(
            'ERP/Organization/Departments/Index',
            [
                'departments' => $departments,

                'companies' => $companies,

                'branches' => $branches,

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

        $branches =
            Branch::query()
                ->active()
                ->orderByDesc(
                    'is_default'
                )
                ->orderBy('name')
                ->get([
                    'id',
                    'company_id',
                    'name',
                    'code',
                ]);

        return Inertia::render(
            'ERP/Organization/Departments/Create',
            [
                'companies' => $companies,

                'branches' => $branches,
            ]
        );
    }

    public function store(
        Request $request
    ): RedirectResponse {
        $validated =
            $this->validateDepartment(
                $request
            );

        Department::create([
            ...$validated,

            'code' => strtoupper(
                $validated['code']
            ),

            'branch_id' => $validated[
                    'branch_id'
                ] ?? null,
        ]);

        return to_route(
            'erp.departments.index'
        )->with(
            'success',
            'Department created successfully.'
        );
    }

    public function edit(
        Department $department
    ): Response {
        $department->load([
            'company:id,name,code',
            'branch:id,company_id,name,code',
        ]);

        /*
         * Show active companies,
         * while always keeping the
         * department's current company
         * available.
         */
        $companies =
            Company::query()
                ->where(
                    function (
                        $query
                    ) use (
                        $department
                    ) {
                        $query
                            ->where(
                                'status',
                                'active'
                            )
                            ->orWhere(
                                'id',
                                $department
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

        /*
         * Active branches plus the
         * currently selected branch,
         * if it has become inactive.
         */
        $branches =
            Branch::query()
                ->where(
                    function (
                        $query
                    ) use (
                        $department
                    ) {
                        $query->where(
                            'status',
                            'active'
                        );

                        if (
                            $department
                                ->branch_id
                        ) {
                            $query->orWhere(
                                'id',
                                $department
                                    ->branch_id
                            );
                        }
                    }
                )
                ->orderByDesc(
                    'is_default'
                )
                ->orderBy('name')
                ->get([
                    'id',
                    'company_id',
                    'name',
                    'code',
                ]);

        return Inertia::render(
            'ERP/Organization/Departments/Edit',
            [
                'department' => $department,

                'companies' => $companies,

                'branches' => $branches,
            ]
        );
    }

    public function update(
        Request $request,
        Department $department
    ): RedirectResponse {
        $validated =
            $this->validateDepartment(
                $request,
                $department
            );

        $department->update([
            ...$validated,

            'code' => strtoupper(
                $validated['code']
            ),

            'branch_id' => $validated[
                    'branch_id'
                ] ?? null,
        ]);

        return to_route(
            'erp.departments.index'
        )->with(
            'success',
            'Department updated successfully.'
        );
    }

    public function destroy(
        Department $department
    ): RedirectResponse {
        /*
         * We can add employee/designation
         * dependency protection later when
         * those modules are created.
         */

        $department->delete();

        return to_route(
            'erp.departments.index'
        )->with(
            'success',
            'Department deleted successfully.'
        );
    }

    private function validateDepartment(
        Request $request,
        ?Department $department = null
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

            'branch_id' => [
                'nullable',
                'integer',

                Rule::exists(
                    'branches',
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
                    'departments',
                    'code'
                )
                    ->where(
                        fn ($query) => $query->where(
                            'company_id',
                            $companyId
                        )
                    )
                    ->ignore(
                        $department?->id
                    ),
            ],

            'description' => [
                'nullable',
                'string',
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
