<?php

namespace App\Http\Controllers\ERP\HRM;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Storage;

class EmployeeController extends Controller
{
    public function index(
        Request $request
    ): Response {
        $filters = [
            'search' =>
                $request
                    ->string('search')
                    ->toString(),

            'company_id' =>
                $request->input(
                    'company_id'
                ),

            'branch_id' =>
                $request->input(
                    'branch_id'
                ),

            'department_id' =>
                $request->input(
                    'department_id'
                ),

            'designation_id' =>
                $request->input(
                    'designation_id'
                ),

            'employment_status' =>
                $request->input(
                    'employment_status'
                ),
        ];

        $employees =
            Employee::query()
                ->with([
                    'user:id,name,email',

                    'company:id,name,code',

                    'branch:id,company_id,name,code',

                    'department:id,company_id,branch_id,name,code',

                    'designation:id,company_id,department_id,name,code',
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
                                        'employee_number',
                                        'like',
                                        "%{$search}%"
                                    )
                                    ->orWhere(
                                        'first_name',
                                        'like',
                                        "%{$search}%"
                                    )
                                    ->orWhere(
                                        'middle_name',
                                        'like',
                                        "%{$search}%"
                                    )
                                    ->orWhere(
                                        'last_name',
                                        'like',
                                        "%{$search}%"
                                    )
                                    ->orWhere(
                                        'work_email',
                                        'like',
                                        "%{$search}%"
                                    )
                                    ->orWhere(
                                        'work_phone',
                                        'like',
                                        "%{$search}%"
                                    )
                                    ->orWhere(
                                        'personal_phone',
                                        'like',
                                        "%{$search}%"
                                    );
                            }
                        );
                    }
                )
                ->when(
                    $filters['company_id'],
                    fn (
                        $query,
                        $companyId
                    ) =>
                        $query->where(
                            'company_id',
                            $companyId
                        )
                )
                ->when(
                    $filters['branch_id'],
                    fn (
                        $query,
                        $branchId
                    ) =>
                        $query->where(
                            'branch_id',
                            $branchId
                        )
                )
                ->when(
                    $filters['department_id'],
                    fn (
                        $query,
                        $departmentId
                    ) =>
                        $query->where(
                            'department_id',
                            $departmentId
                        )
                )
                ->when(
                    $filters['designation_id'],
                    fn (
                        $query,
                        $designationId
                    ) =>
                        $query->where(
                            'designation_id',
                            $designationId
                        )
                )
                ->when(
                    $filters['employment_status'],
                    fn (
                        $query,
                        $status
                    ) =>
                        $query->where(
                            'employment_status',
                            $status
                        )
                )
                ->latest('id')
                ->paginate(15)
                ->withQueryString();

        return Inertia::render(
            'ERP/HRM/Employees/Index',
            [
                'employees' =>
                    $employees,

                'companies' =>
                    $this->companies(),

                'branches' =>
                    $this->branches(),

                'departments' =>
                    $this->departments(),

                'designations' =>
                    $this->designations(),

                'filters' =>
                    $filters,
            ]
        );
    }

    public function create(): Response
    {
        return Inertia::render(
            'ERP/HRM/Employees/Create',
            [
                'companies' =>
                    $this->companies(
                        activeOnly: true
                    ),

                'branches' =>
                    $this->branches(
                        activeOnly: true
                    ),

                'departments' =>
                    $this->departments(
                        activeOnly: true
                    ),

                'designations' =>
                    $this->designations(
                        activeOnly: true
                    ),

                'users' =>
                    $this->availableUsers(),
            ]
        );
    }

    public function store(
        Request $request
    ): RedirectResponse {
        $validated =
            $this->validateEmployee(
                $request
            );

        DB::transaction(
            function () use (
                $request,
                $validated
            ) {
                $photoPath = null;

                if (
                    $request->hasFile(
                        'photo'
                    )
                ) {
                    $photoPath =
                        $request
                            ->file('photo')
                            ->store(
                                'employees',
                                'public'
                            );
                }

                Employee::create([
                    ...$validated,

                    'user_id' =>
                        $validated['user_id']
                            ?? null,

                    'branch_id' =>
                        $validated['branch_id']
                            ?? null,

                    'department_id' =>
                        $validated['department_id']
                            ?? null,

                    'designation_id' =>
                        $validated['designation_id']
                            ?? null,

                    'employee_number' =>
                        strtoupper(
                            $validated[
                                'employee_number'
                            ]
                        ),

                    'photo' =>
                        $photoPath,
                ]);
            }
        );

        return to_route(
            'erp.employees.index'
        )->with(
            'success',
            'Employee created successfully.'
        );
    }

    public function edit(
        Employee $employee
    ): Response {
        $employee->load([
            'user:id,name,email',
            'company:id,name,code',
            'branch:id,company_id,name,code',
            'department:id,company_id,branch_id,name,code',
            'designation:id,company_id,department_id,name,code',
        ]);

        return Inertia::render(
            'ERP/HRM/Employees/Edit',
            [
                'employee' =>
                    $employee,

                'companies' =>
                    $this->companies(
                        activeOnly: true,
                        currentId:
                            $employee
                                ->company_id
                    ),

                'branches' =>
                    $this->branches(
                        activeOnly: true,
                        currentId:
                            $employee
                                ->branch_id
                    ),

                'departments' =>
                    $this->departments(
                        activeOnly: true,
                        currentId:
                            $employee
                                ->department_id
                    ),

                'designations' =>
                    $this->designations(
                        activeOnly: true,
                        currentId:
                            $employee
                                ->designation_id
                    ),

                'users' =>
                    $this->availableUsers(
                        $employee
                    ),
            ]
        );
    }

    public function update(
        Request $request,
        Employee $employee
    ): RedirectResponse {
        $validated =
            $this->validateEmployee(
                $request,
                $employee
            );

        DB::transaction(
            function () use (
                $request,
                $validated,
                $employee
            ) {
                $photoPath =
                    $employee->photo;

                if (
                    $request->hasFile(
                        'photo'
                    )
                ) {
                    if (
                        $employee->photo &&
                        Storage::disk(
                            'public'
                        )->exists(
                            $employee->photo
                        )
                    ) {
                        Storage::disk(
                            'public'
                        )->delete(
                            $employee->photo
                        );
                    }

                    $photoPath =
                        $request
                            ->file('photo')
                            ->store(
                                'employees',
                                'public'
                            );
                }

                $employee->update([
                    ...$validated,

                    'user_id' =>
                        $validated['user_id']
                            ?? null,

                    'branch_id' =>
                        $validated['branch_id']
                            ?? null,

                    'department_id' =>
                        $validated['department_id']
                            ?? null,

                    'designation_id' =>
                        $validated['designation_id']
                            ?? null,

                    'employee_number' =>
                        strtoupper(
                            $validated[
                                'employee_number'
                            ]
                        ),

                    'photo' =>
                        $photoPath,
                ]);
            }
        );

        return to_route(
            'erp.employees.index'
        )->with(
            'success',
            'Employee updated successfully.'
        );
    }

   public function show(
        Employee $employee
    ): Response {
        $employee->load([
            'user:id,name,email',

            'company:id,name,code',

            'branch:id,company_id,name,code',

            'department:id,company_id,branch_id,name,code',

            'designation:id,company_id,department_id,name,code',

            'documents' => fn ($query) =>
                $query
                    ->latest('id'),

            'employmentHistories' => fn ($query) =>
                $query
                    ->with([
                        'company:id,name,code',
                        'branch:id,name,code',
                        'department:id,name,code',
                        'designation:id,name,code',
                    ])
                    ->orderByDesc('start_date')
                    ->orderByDesc('id'),
        ]);

        return Inertia::render(
            'ERP/HRM/Employees/Show',
            [
                'employee' =>
                    $employee,
            ]
        );
    }
   
    public function destroy(
        Employee $employee
    ): RedirectResponse {
        DB::transaction(
            function () use (
                $employee
            ) {
                $photo =
                    $employee->photo;

                $employee->delete();

                if (
                    $photo &&
                    Storage::disk(
                        'public'
                    )->exists(
                        $photo
                    )
                ) {
                    Storage::disk(
                        'public'
                    )->delete(
                        $photo
                    );
                }
            }
        );

        return to_route(
            'erp.employees.index'
        )->with(
            'success',
            'Employee deleted successfully.'
        );
    }

    private function validateEmployee(
        Request $request,
        ?Employee $employee = null
    ): array {
        $companyId =
            (int) $request->input(
                'company_id'
            );

        $departmentId =
            $request->filled(
                'department_id'
            )
                ? (int) $request->input(
                    'department_id'
                )
                : null;

        return $request->validate([
            'user_id' => [
                'nullable',
                'integer',

                Rule::exists(
                    'users',
                    'id'
                ),

                Rule::unique(
                    'employees',
                    'user_id'
                )->ignore(
                    $employee?->id
                ),
            ],

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
                    fn ($query) =>
                        $query->where(
                            'company_id',
                            $companyId
                        )
                ),
            ],

            'department_id' => [
                'nullable',
                'integer',

                Rule::exists(
                    'departments',
                    'id'
                )->where(
                    fn ($query) =>
                        $query->where(
                            'company_id',
                            $companyId
                        )
                ),
            ],

            'designation_id' => [
                'nullable',
                'integer',

                Rule::exists(
                    'designations',
                    'id'
                )->where(
                    function (
                        $query
                    ) use (
                        $companyId,
                        $departmentId
                    ) {
                        $query->where(
                            'company_id',
                            $companyId
                        );

                        if (
                            $departmentId
                        ) {
                            $query->where(
                                function (
                                    $query
                                ) use (
                                    $departmentId
                                ) {
                                    $query
                                        ->whereNull(
                                            'department_id'
                                        )
                                        ->orWhere(
                                            'department_id',
                                            $departmentId
                                        );
                                }
                            );
                        }
                    }
                ),
            ],

            'employee_number' => [
                'required',
                'string',
                'max:50',

                Rule::unique(
                    'employees',
                    'employee_number'
                )->ignore(
                    $employee?->id
                ),
            ],

            'first_name' => [
                'required',
                'string',
                'max:255',
            ],

            'middle_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'last_name' => [
                'required',
                'string',
                'max:255',
            ],

            'preferred_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'work_email' => [
                'nullable',
                'email',
                'max:255',
            ],

            'work_phone' => [
                'nullable',
                'string',
                'max:50',
            ],

            'personal_email' => [
                'nullable',
                'email',
                'max:255',
            ],

            'personal_phone' => [
                'nullable',
                'string',
                'max:50',
            ],

            'date_of_birth' => [
                'nullable',
                'date',
                'before:today',
            ],

            'gender' => [
                'nullable',

                Rule::in([
                    'male',
                    'female',
                    'other',
                    'prefer_not_to_say',
                ]),
            ],

            'marital_status' => [
                'nullable',

                Rule::in([
                    'single',
                    'married',
                    'divorced',
                    'widowed',
                    'other',
                ]),
            ],

            'nationality' => [
                'nullable',
                'string',
                'max:100',
            ],

            'national_id' => [
                'nullable',
                'string',
                'max:100',
            ],

            'passport_number' => [
                'nullable',
                'string',
                'max:100',
            ],

            'joining_date' => [
                'nullable',
                'date',
            ],

            'probation_end_date' => [
                'nullable',
                'date',

                'after_or_equal:joining_date',
            ],

            'confirmation_date' => [
                'nullable',
                'date',

                'after_or_equal:joining_date',
            ],

            'termination_date' => [
                'nullable',
                'date',

                'after_or_equal:joining_date',
            ],

            'employment_type' => [
                'required',

                Rule::in([
                    'full_time',
                    'part_time',
                    'contract',
                    'temporary',
                    'intern',
                ]),
            ],

            'employment_status' => [
                'required',

                Rule::in([
                    'active',
                    'probation',
                    'inactive',
                    'resigned',
                    'terminated',
                    'retired',
                ]),
            ],

            'present_address' => [
                'nullable',
                'string',
            ],

            'permanent_address' => [
                'nullable',
                'string',
            ],

            'city' => [
                'nullable',
                'string',
                'max:255',
            ],

            'state' => [
                'nullable',
                'string',
                'max:255',
            ],

            'postal_code' => [
                'nullable',
                'string',
                'max:30',
            ],

            'country' => [
                'nullable',
                'string',
                'max:255',
            ],

            'emergency_contact_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'emergency_contact_relationship' => [
                'nullable',
                'string',
                'max:100',
            ],

            'emergency_contact_phone' => [
                'nullable',
                'string',
                'max:50',
            ],

            'photo' => [
                'nullable',
                'image',
                'max:5120',
            ],

            'notes' => [
                'nullable',
                'string',
            ],
        ]);
    }

    private function companies(
        bool $activeOnly = false,
        ?int $currentId = null
    ) {
        return Company::query()
            ->when(
                $activeOnly,
                function (
                    $query
                ) use (
                    $currentId
                ) {
                    $query->where(
                        function (
                            $query
                        ) use (
                            $currentId
                        ) {
                            $query->where(
                                'status',
                                'active'
                            );

                            if (
                                $currentId
                            ) {
                                $query->orWhere(
                                    'id',
                                    $currentId
                                );
                            }
                        }
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
                'country',
            ]);
    }

    private function branches(
        bool $activeOnly = false,
        ?int $currentId = null
    ) {
        return Branch::query()
            ->when(
                $activeOnly,
                function (
                    $query
                ) use (
                    $currentId
                ) {
                    $query->where(
                        function (
                            $query
                        ) use (
                            $currentId
                        ) {
                            $query->where(
                                'status',
                                'active'
                            );

                            if (
                                $currentId
                            ) {
                                $query->orWhere(
                                    'id',
                                    $currentId
                                );
                            }
                        }
                    );
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
    }

    private function departments(
        bool $activeOnly = false,
        ?int $currentId = null
    ) {
        return Department::query()
            ->when(
                $activeOnly,
                function (
                    $query
                ) use (
                    $currentId
                ) {
                    $query->where(
                        function (
                            $query
                        ) use (
                            $currentId
                        ) {
                            $query->where(
                                'status',
                                'active'
                            );

                            if (
                                $currentId
                            ) {
                                $query->orWhere(
                                    'id',
                                    $currentId
                                );
                            }
                        }
                    );
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
    }

    private function designations(
        bool $activeOnly = false,
        ?int $currentId = null
    ) {
        return Designation::query()
            ->when(
                $activeOnly,
                function (
                    $query
                ) use (
                    $currentId
                ) {
                    $query->where(
                        function (
                            $query
                        ) use (
                            $currentId
                        ) {
                            $query->where(
                                'status',
                                'active'
                            );

                            if (
                                $currentId
                            ) {
                                $query->orWhere(
                                    'id',
                                    $currentId
                                );
                            }
                        }
                    );
                }
            )
            ->orderBy(
                'sort_order'
            )
            ->orderBy('name')
            ->get([
                'id',
                'company_id',
                'department_id',
                'name',
                'code',
            ]);
    }

    private function availableUsers(
        ?Employee $employee = null
    ) {
        return User::query()
            ->where(
                function (
                    $query
                ) use (
                    $employee
                ) {
                    $query
                        ->whereDoesntHave(
                            'employee'
                        );

                    if (
                        $employee?->user_id
                    ) {
                        $query->orWhere(
                            'id',
                            $employee
                                ->user_id
                        );
                    }
                }
            )
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'email',
            ]);
    }
}