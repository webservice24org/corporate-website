<?php

namespace App\Http\Controllers\ERP\HRM;

use App\Http\Controllers\Controller;
use App\Models\AttendanceShift;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeShiftAssignmentController extends Controller
{
    public function index(
        Request $request
    ): Response {
        $query =
            EmployeeShiftAssignment::query()
                ->with([
                    'employee:id,company_id,branch_id,department_id,designation_id,employee_number,first_name,middle_name,last_name,employment_status',

                    'employee.company:id,name,code',

                    'employee.branch:id,name,code',

                    'employee.department:id,name,code',

                    'employee.designation:id,name,code',

                    'shift:id,company_id,name,code,start_time,end_time,crosses_midnight,status',

                    'assignedBy:id,name',
                ]);

        if (
            $request->filled(
                'company_id'
            )
        ) {
            $companyId =
                $request->integer(
                    'company_id'
                );

            $query->whereHas(
                'employee',
                fn (Builder $employeeQuery) => $employeeQuery->where(
                    'company_id',
                    $companyId
                )
            );
        }

        if (
            $request->filled(
                'employee_id'
            )
        ) {
            $query->where(
                'employee_id',
                $request->integer(
                    'employee_id'
                )
            );
        }

        if (
            $request->filled(
                'shift_id'
            )
        ) {
            $query->where(
                'shift_id',
                $request->integer(
                    'shift_id'
                )
            );
        }

        if (
            $request->filled(
                'status'
            )
        ) {
            $query->where(
                'status',
                $request->input(
                    'status'
                )
            );
        }

        if (
            $request->filled(
                'search'
            )
        ) {
            $search =
                trim(
                    (string) $request->input(
                        'search'
                    )
                );

            $query->whereHas(
                'employee',
                function (Builder $employeeQuery) use ($search) {
                    $employeeQuery->where(
                        function (Builder $builder) use ($search) {
                            $builder
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
                                );
                        }
                    );
                }
            );
        }

        return Inertia::render(
            'ERP/HRM/Attendance/EmployeeShifts/Index',
            [
                'assignments' => $query
                    ->orderByDesc(
                        'effective_from'
                    )
                    ->orderByDesc(
                        'id'
                    )
                    ->paginate(15)
                    ->withQueryString(),

                'companies' => $this->companies(),

                'employees' => $this->employees(),

                'shifts' => $this->shifts(),

                'filters' => [
                    'search' => $request->input(
                        'search',
                        ''
                    ),

                    'company_id' => $request->input(
                        'company_id',
                        ''
                    ),

                    'employee_id' => $request->input(
                        'employee_id',
                        ''
                    ),

                    'shift_id' => $request->input(
                        'shift_id',
                        ''
                    ),

                    'status' => $request->input(
                        'status',
                        ''
                    ),
                ],
            ]
        );
    }

    public function create(
        Request $request
    ): Response {
        return Inertia::render(
            'ERP/HRM/Attendance/EmployeeShifts/Create',
            [
                'companies' => $this->companies(),

                'employees' => $this->employees(),

                'shifts' => $this->shifts(),

                'selectedEmployeeId' => $request->filled(
                    'employee_id'
                )
                    ? $request->integer(
                        'employee_id'
                    )
                    : null,
            ]
        );
    }

    public function store(
        Request $request
    ): RedirectResponse {
        $validated =
            $this->validateAssignment(
                $request
            );

        $employee =
            Employee::findOrFail(
                $validated[
                    'employee_id'
                ]
            );

        $shift =
            AttendanceShift::findOrFail(
                $validated[
                    'shift_id'
                ]
            );

        $this->ensureSameCompany(
            $employee,
            $shift
        );

        if (
            $validated['status'] ===
            'active'
        ) {
            $this->ensureNoOverlap(
                employeeId: $employee->id,

                effectiveFrom: $validated[
                    'effective_from'
                ],

                effectiveTo: $validated[
                    'effective_to'
                ] ?? null,
            );
        }

        DB::transaction(
            function () use ($request, $employee, $shift, $validated) {
                EmployeeShiftAssignment::create([
                    ...$validated,

                    'employee_id' => $employee->id,

                    'shift_id' => $shift->id,

                    'assigned_by' => $request
                        ->user()
                        ?->id,
                ]);
            }
        );

        return to_route(
            'erp.employee-shifts.index'
        )->with(
            'success',
            'Employee shift assigned successfully.'
        );
    }

    public function edit(
        EmployeeShiftAssignment $employeeShift
    ): Response {
        $employeeShift->load([
            'employee:id,company_id,employee_number,first_name,middle_name,last_name',
            'shift:id,company_id,name,code,start_time,end_time',
        ]);

        $employee = $employeeShift->employee;

        abort_unless($employee instanceof Employee, 404);

        return Inertia::render(
            'ERP/HRM/Attendance/EmployeeShifts/Edit',
            [
                'assignment' => $employeeShift,

                'companies' => $this->companies(
                    $employee->company_id
                ),

                'employees' => $this->employees(
                    $employeeShift
                        ->employee_id
                ),

                'shifts' => $this->shifts(
                    $employeeShift
                        ->shift_id
                ),
            ]
        );
    }

    public function update(
        Request $request,
        EmployeeShiftAssignment $employeeShift
    ): RedirectResponse {
        $validated =
            $this->validateAssignment(
                $request
            );

        $employee =
            Employee::findOrFail(
                $validated[
                    'employee_id'
                ]
            );

        $shift =
            AttendanceShift::findOrFail(
                $validated[
                    'shift_id'
                ]
            );

        $this->ensureSameCompany(
            $employee,
            $shift
        );

        if (
            $validated['status'] ===
            'active'
        ) {
            $this->ensureNoOverlap(
                employeeId: $employee->id,

                effectiveFrom: $validated[
                    'effective_from'
                ],

                effectiveTo: $validated[
                    'effective_to'
                ] ?? null,

                ignoreId: $employeeShift->id,
            );
        }

        $employeeShift->update([
            ...$validated,

            'employee_id' => $employee->id,

            'shift_id' => $shift->id,
        ]);

        return to_route(
            'erp.employee-shifts.index'
        )->with(
            'success',
            'Employee shift assignment updated successfully.'
        );
    }

    public function destroy(
        EmployeeShiftAssignment $employeeShift
    ): RedirectResponse {
        $employeeShift->delete();

        return to_route(
            'erp.employee-shifts.index'
        )->with(
            'success',
            'Employee shift assignment deleted successfully.'
        );
    }

    private function validateAssignment(
        Request $request
    ): array {
        return $request->validate([
            'employee_id' => [
                'required',
                'integer',
                'exists:employees,id',
            ],

            'shift_id' => [
                'required',
                'integer',
                'exists:attendance_shifts,id',
            ],

            'effective_from' => [
                'required',
                'date',
            ],

            'effective_to' => [
                'nullable',
                'date',
                'after_or_equal:effective_from',
            ],

            'status' => [
                'required',

                Rule::in([
                    'active',
                    'inactive',
                ]),
            ],

            'notes' => [
                'nullable',
                'string',
            ],
        ]);
    }

    private function ensureSameCompany(
        Employee $employee,
        AttendanceShift $shift
    ): void {
        if (
            $employee->company_id !==
            $shift->company_id
        ) {
            throw ValidationException::withMessages([
                'shift_id' => 'The selected shift does not belong to the employee company.',
            ]);
        }
    }

    private function ensureNoOverlap(
        int $employeeId,
        string $effectiveFrom,
        ?string $effectiveTo = null,
        ?int $ignoreId = null
    ): void {
        $query =
            EmployeeShiftAssignment::query()
                ->where(
                    'employee_id',
                    $employeeId
                )
                ->where(
                    'status',
                    'active'
                )
                ->when(
                    $ignoreId,
                    fn (Builder $query) => $query->where(
                        'id',
                        '!=',
                        $ignoreId
                    )
                )
                ->where(
                    function (Builder $query) use ($effectiveFrom) {
                        $query
                            ->whereNull(
                                'effective_to'
                            )
                            ->orWhereDate(
                                'effective_to',
                                '>=',
                                $effectiveFrom
                            );
                    }
                );

        if ($effectiveTo) {
            $query->whereDate(
                'effective_from',
                '<=',
                $effectiveTo
            );
        }

        if (
            $query->exists()
        ) {
            throw ValidationException::withMessages([
                'effective_from' => 'This employee already has an active shift assignment that overlaps the selected date range.',
            ]);
        }
    }

    private function companies(
        ?int $includeCompanyId = null
    ) {
        return Company::query()
            ->where(
                function (Builder $query) use ($includeCompanyId) {
                    $query->where(
                        'status',
                        'active'
                    );

                    if (
                        $includeCompanyId
                    ) {
                        $query->orWhere(
                            'id',
                            $includeCompanyId
                        );
                    }
                }
            )
            ->orderByDesc(
                'is_default'
            )
            ->orderBy(
                'name'
            )
            ->get([
                'id',
                'name',
                'code',
            ]);
    }

    private function employees(
        ?int $includeEmployeeId = null
    ) {
        return Employee::query()
            ->with([
                'company:id,name,code',
                'branch:id,name,code',
                'department:id,name,code',
                'designation:id,name,code',
            ])
            ->where(
                function (Builder $query) use ($includeEmployeeId) {
                    $query->whereIn(
                        'employment_status',
                        [
                            'active',
                            'probation',
                        ]
                    );

                    if (
                        $includeEmployeeId
                    ) {
                        $query->orWhere(
                            'id',
                            $includeEmployeeId
                        );
                    }
                }
            )
            ->orderBy(
                'employee_number'
            )
            ->get([
                'id',
                'company_id',
                'branch_id',
                'department_id',
                'designation_id',

                'employee_number',

                'first_name',
                'middle_name',
                'last_name',

                'employment_status',
            ]);
    }

    private function shifts(
        ?int $includeShiftId = null
    ) {
        return AttendanceShift::query()
            ->where(
                function (Builder $query) use ($includeShiftId) {
                    $query->where(
                        'status',
                        'active'
                    );

                    if (
                        $includeShiftId
                    ) {
                        $query->orWhere(
                            'id',
                            $includeShiftId
                        );
                    }
                }
            )
            ->orderBy(
                'company_id'
            )
            ->orderBy(
                'start_time'
            )
            ->get([
                'id',
                'company_id',

                'name',
                'code',

                'start_time',
                'end_time',

                'crosses_midnight',
                'status',
            ]);
    }
}
