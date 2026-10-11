<?php

namespace App\Http\Controllers\ERP\HRM;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeEmploymentHistory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeEmploymentHistoryController extends Controller
{
    public function create(
        Employee $employee
    ): Response {
        return Inertia::render(
            'ERP/HRM/Employees/EmploymentHistory/Create',
            [
                'employee' =>
                    $this->employeeData(
                        $employee
                    ),

                ...$this->organizationData(),
            ]
        );
    }

    public function store(
        Request $request,
        Employee $employee
    ): RedirectResponse {
        $validated =
            $this->validateHistory(
                $request
            );

        $this->saveHistory(
            $employee,
            new EmployeeEmploymentHistory(),
            $validated
        );

        return to_route(
            'erp.employees.show',
            $employee
        )->with(
            'success',
            'Employment history added successfully.'
        );
    }

    public function edit(
        Employee $employee,
        EmployeeEmploymentHistory $history
    ): Response {
        $this->ensureEmployeeOwnsHistory(
            $employee,
            $history
        );

        return Inertia::render(
            'ERP/HRM/Employees/EmploymentHistory/Edit',
            [
                'employee' =>
                    $this->employeeData(
                        $employee
                    ),

                'history' =>
                    $history,

                ...$this->organizationData(),
            ]
        );
    }

    public function update(
        Request $request,
        Employee $employee,
        EmployeeEmploymentHistory $history
    ): RedirectResponse {
        $this->ensureEmployeeOwnsHistory(
            $employee,
            $history
        );

        $validated =
            $this->validateHistory(
                $request
            );

        $this->saveHistory(
            $employee,
            $history,
            $validated
        );

        return to_route(
            'erp.employees.show',
            $employee
        )->with(
            'success',
            'Employment history updated successfully.'
        );
    }

    public function destroy(
        Employee $employee,
        EmployeeEmploymentHistory $history
    ): RedirectResponse {
        $this->ensureEmployeeOwnsHistory(
            $employee,
            $history
        );

        $history->delete();

        return to_route(
            'erp.employees.show',
            $employee
        )->with(
            'success',
            'Employment history deleted successfully.'
        );
    }

    private function saveHistory(
        Employee $employee,
        EmployeeEmploymentHistory $history,
        array $validated
    ): void {
        $recordType =
            $validated[
                'record_type'
            ];

        if (
            $recordType ===
            'internal'
        ) {
            $company =
                !empty(
                    $validated[
                        'company_id'
                    ]
                )
                    ? Company::find(
                        $validated[
                            'company_id'
                        ]
                    )
                    : null;

            $branch =
                !empty(
                    $validated[
                        'branch_id'
                    ]
                )
                    ? Branch::find(
                        $validated[
                            'branch_id'
                        ]
                    )
                    : null;

            $department =
                !empty(
                    $validated[
                        'department_id'
                    ]
                )
                    ? Department::find(
                        $validated[
                            'department_id'
                        ]
                    )
                    : null;

            $designation =
                !empty(
                    $validated[
                        'designation_id'
                    ]
                )
                    ? Designation::find(
                        $validated[
                            'designation_id'
                        ]
                    )
                    : null;

            $history->fill([
                ...$validated,

                'employee_id' =>
                    $employee->id,

                'employer_name' =>
                    $company?->name,

                'branch_name' =>
                    $branch?->name,

                'department_name' =>
                    $department?->name,

                'designation_name' =>
                    $designation?->name,
            ]);
        } else {
            $history->fill([
                ...$validated,

                'employee_id' =>
                    $employee->id,

                'company_id' =>
                    null,

                'branch_id' =>
                    null,

                'department_id' =>
                    null,

                'designation_id' =>
                    null,
            ]);
        }

        if (
            $history->is_current
        ) {
            EmployeeEmploymentHistory::query()
                ->where(
                    'employee_id',
                    $employee->id
                )
                ->when(
                    $history->exists,
                    fn ($query) =>
                        $query->where(
                            'id',
                            '!=',
                            $history->id
                        )
                )
                ->update([
                    'is_current' =>
                        false,
                ]);
        }

        $history->save();
    }

    private function validateHistory(
        Request $request
    ): array {
        $recordType =
            $request->input(
                'record_type'
            );

        $companyId =
            $request->filled(
                'company_id'
            )
                ? (int) $request->input(
                    'company_id'
                )
                : null;

        return $request->validate([
            'record_type' => [
                'required',

                Rule::in([
                    'internal',
                    'external',
                ]),
            ],

            'company_id' => [
                Rule::requiredIf(
                    $recordType ===
                    'internal'
                ),

                'nullable',
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
                    fn ($query) =>
                        $query->where(
                            'company_id',
                            $companyId
                        )
                ),
            ],

            'employer_name' => [
                Rule::requiredIf(
                    $recordType ===
                    'external'
                ),

                'nullable',
                'string',
                'max:255',
            ],

            'branch_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'department_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'designation_name' => [
                Rule::requiredIf(
                    $recordType ===
                    'external'
                ),

                'nullable',
                'string',
                'max:255',
            ],

            'employment_type' => [
                'nullable',

                Rule::in([
                    'full_time',
                    'part_time',
                    'contract',
                    'temporary',
                    'intern',
                ]),
            ],

            'start_date' => [
                'required',
                'date',
            ],

            'end_date' => [
                'nullable',
                'date',
                'after_or_equal:start_date',
            ],

            'is_current' => [
                'required',
                'boolean',
            ],

            'reason_for_leaving' => [
                'nullable',
                'string',
                'max:255',
            ],

            'notes' => [
                'nullable',
                'string',
            ],
        ]);
    }

    private function organizationData(): array
    {
        return [
            'companies' =>
                Company::query()
                    ->orderByDesc(
                        'is_default'
                    )
                    ->orderBy('name')
                    ->get([
                        'id',
                        'name',
                        'code',
                    ]),

            'branches' =>
                Branch::query()
                    ->orderBy('name')
                    ->get([
                        'id',
                        'company_id',
                        'name',
                        'code',
                    ]),

            'departments' =>
                Department::query()
                    ->orderBy('name')
                    ->get([
                        'id',
                        'company_id',
                        'branch_id',
                        'name',
                        'code',
                    ]),

            'designations' =>
                Designation::query()
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
                    ]),
        ];
    }

    private function employeeData(
        Employee $employee
    ): Employee {
        return $employee->load([
            'company:id,name,code',
        ]);
    }

    private function ensureEmployeeOwnsHistory(
        Employee $employee,
        EmployeeEmploymentHistory $history
    ): void {
        abort_unless(
            $history->employee_id ===
                $employee->id,
            404
        );
    }
}