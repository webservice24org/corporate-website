<?php

namespace App\Http\Controllers\ERP\HRM;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalance;
use App\Models\EmployeeLeaveBalanceAdjustment;
use App\Models\LeaveType;
use App\Services\Leave\LeaveBalanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LeaveBalanceController extends Controller
{
    public function index(
        Request $request,
        LeaveBalanceService $balanceService
    ): Response {
        $companies = $this->getActiveCompanies();

        $companyId = $this->resolveCompanyId(
            $request,
            $companies
        );

        $year = $this->resolveYear($request);

        $this->ensureBalanceRowsExist(
            $companyId,
            $year,
            $balanceService
        );

        $balances = $this->getBalances(
            $request,
            $companyId,
            $year
        );

        $leaveTypes = $this->getActiveLeaveTypes(
            $companyId
        );

        $recentAdjustments = $this->getRecentAdjustments(
            $companyId,
            $year
        );

        return Inertia::render(
            'ERP/HRM/Leave/Balances/Index',
            [
                'balances' => $balances,

                'companies' => $companies,

                'leaveTypes' => $leaveTypes,

                'recentAdjustments' => $recentAdjustments,

                'filters' => [
                    'search' => $request->search,

                    'company_id' => $companyId
                            ? (string) $companyId
                            : '',

                    'leave_type_id' => $request->leave_type_id,

                    'year' => (string) $year,
                ],
            ]
        );
    }

    public function adjust(
        Request $request,
        LeaveBalanceService $balanceService
    ): RedirectResponse {
        $data = $request->validate([
            'employee_id' => [
                'required',
                'integer',
                'exists:employees,id',
            ],

            'leave_type_id' => [
                'required',
                'integer',
                'exists:leave_types,id',
            ],

            'year' => [
                'required',
                'integer',
                'min:2000',
                'max:2100',
            ],

            'days' => [
                'required',
                'numeric',
                'not_in:0',
                'between:-365,365',
            ],

            'reason' => [
                'required',
                'string',
                'max:2000',
            ],
        ]);

        $employee = Employee::query()
            ->findOrFail(
                $data['employee_id']
            );

        $leaveType = LeaveType::query()
            ->where(
                'id',
                $data['leave_type_id']
            )
            ->where(
                'company_id',
                $employee->company_id
            )
            ->first();

        if (! $leaveType) {
            throw ValidationException::withMessages([
                'leave_type_id' => 'The selected leave type does not belong to this employee company.',
            ]);
        }

        DB::transaction(
            function () use (
                $data,
                $employee,
                $leaveType,
                $balanceService
            ): void {
                EmployeeLeaveBalanceAdjustment::create([
                    'employee_id' => $employee->id,

                    'leave_type_id' => $leaveType->id,

                    'year' => $data['year'],

                    'days' => $data['days'],

                    'adjustment_type' => 'manual',

                    'reason' => $data['reason'],

                    'adjusted_by' => auth()->id(),
                ]);

                $balanceService->recalculate(
                    $employee,
                    $leaveType,
                    (int) $data['year']
                );
            }
        );

        return back()->with(
            'success',
            'Leave balance adjusted successfully.'
        );
    }

    private function getActiveCompanies(): Collection
    {
        return Company::query()
            ->where(
                'status',
                'active'
            )
            ->orderBy('name')
            ->get([
                'id',
                'name',
            ]);
    }

    private function resolveCompanyId(
        Request $request,
        Collection $companies
    ): ?int {
        if (
            $request->filled(
                'company_id'
            )
        ) {
            return (int) $request->company_id;
        }

        $firstCompany = $companies->first();

        return $firstCompany
            ? (int) $firstCompany->id
            : null;
    }

    private function resolveYear(
        Request $request
    ): int {
        return $request->filled('year')
            ? (int) $request->year
            : now()->year;
    }

    private function ensureBalanceRowsExist(
        ?int $companyId,
        int $year,
        LeaveBalanceService $balanceService
    ): void {
        if (! $companyId) {
            return;
        }

        $employees = Employee::query()
            ->where(
                'company_id',
                $companyId
            )
            ->where(
                'employment_status',
                'active'
            )
            ->get();

        $leaveTypes = LeaveType::query()
            ->where(
                'company_id',
                $companyId
            )
            ->where(
                'status',
                'active'
            )
            ->get();

        foreach ($employees as $employee) {
            foreach ($leaveTypes as $leaveType) {
                $balanceService->recalculate(
                    $employee,
                    $leaveType,
                    $year
                );
            }
        }
    }

    private function getBalances(
        Request $request,
        ?int $companyId,
        int $year
    ): LengthAwarePaginator {
        $balances = EmployeeLeaveBalance::query()
            ->with([
                'employee:id,employee_number,first_name,middle_name,last_name,company_id,branch_id,department_id',
                'employee.company:id,name',
                'employee.branch:id,name',
                'employee.department:id,name',
                'leaveType:id,name,code,is_paid',
            ])
            ->when(
                $companyId,
                function ($query, $companyId) {
                    $query->whereHas(
                        'employee',
                        fn ($employeeQuery) => $employeeQuery->where(
                            'company_id',
                            $companyId
                        )
                    );
                }
            )
            ->where(
                'year',
                $year
            )
            ->when(
                $request->search,
                function ($query, $search) {
                    $query->whereHas(
                        'employee',
                        function ($employeeQuery) use ($search) {
                            $employeeQuery
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
            )
            ->when(
                $request->leave_type_id,
                fn ($query, $leaveTypeId) => $query->where(
                    'leave_type_id',
                    $leaveTypeId
                )
            )
            ->orderBy('employee_id')
            ->orderBy('leave_type_id')
            ->paginate(25)
            ->withQueryString();

        $balances->through(
            function (EmployeeLeaveBalance $balance): array {
                return [
                    'id' => $balance->id,

                    'year' => $balance->year,

                    'opening_balance' => (float) $balance->opening_balance,

                    'entitled_days' => (float) $balance->entitled_days,

                    'adjustment_days' => (float) $balance->adjustment_days,

                    'used_days' => (float) $balance->used_days,

                    'pending_days' => (float) $balance->pending_days,

                    'available_days' => $balance->available_days,

                    'employee' => $balance->employee,

                    'leave_type' => $balance->leaveType,
                ];
            }
        );

        return $balances;
    }

    private function getActiveLeaveTypes(
        ?int $companyId
    ): Collection {
        return LeaveType::query()
            ->when(
                $companyId,
                fn ($query, $companyId) => $query->where(
                    'company_id',
                    $companyId
                )
            )
            ->where(
                'status',
                'active'
            )
            ->orderBy('name')
            ->get([
                'id',
                'company_id',
                'name',
                'code',
            ]);
    }

    private function getRecentAdjustments(
        ?int $companyId,
        int $year
    ): Collection {
        return EmployeeLeaveBalanceAdjustment::query()
            ->with([
                'employee:id,employee_number,first_name,middle_name,last_name',
                'leaveType:id,name,code',
                'adjustedBy:id,name',
            ])
            ->when(
                $companyId,
                function ($query, $companyId) {
                    $query->whereHas(
                        'employee',
                        fn ($employeeQuery) => $employeeQuery->where(
                            'company_id',
                            $companyId
                        )
                    );
                }
            )
            ->where(
                'year',
                $year
            )
            ->latest()
            ->limit(20)
            ->get();
    }
}
