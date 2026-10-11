<?php

namespace App\Http\Controllers\ERP\HRM;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeLeaveRequest;
use App\Models\LeaveType;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\LeaveDayCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use App\Services\Leave\LeaveAttendanceReconciler;

class EmployeeLeaveRequestController extends Controller
{
    public function index(
        Request $request
    ): Response {
        $requests =
            EmployeeLeaveRequest::query()
                ->with([
                    'employee:id,employee_number,first_name,middle_name,last_name',
                    'company:id,name',
                    'leaveType:id,name,code,is_paid',
                    'approvedBy:id,name',
                    'rejectedBy:id,name',
                ])
                ->when(
                    $request->company_id,
                    fn ($query, $companyId) =>
                        $query->where(
                            'company_id',
                            $companyId
                        )
                )
                ->when(
                    $request->leave_type_id,
                    fn ($query, $typeId) =>
                        $query->where(
                            'leave_type_id',
                            $typeId
                        )
                )
                ->when(
                    $request->status,
                    fn ($query, $status) =>
                        $query->where(
                            'status',
                            $status
                        )
                )
                ->when(
                    $request->search,
                    function (
                        $query,
                        $search
                    ) {
                        $query->whereHas(
                            'employee',
                            function ($employeeQuery) use (
                                $search
                            ) {
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
                                        'last_name',
                                        'like',
                                        "%{$search}%"
                                    );
                            }
                        );
                    }
                )
                ->orderByDesc(
                    'created_at'
                )
                ->paginate(20)
                ->withQueryString();

        return Inertia::render(
            'ERP/HRM/Leave/Requests/Index',
            [
                'leaveRequests' =>
                    $requests,

                'companies' =>
                    Company::query()
                        ->where(
                            'status',
                            'active'
                        )
                        ->orderBy('name')
                        ->get([
                            'id',
                            'name',
                        ]),

                'leaveTypes' =>
                    LeaveType::query()
                        ->active()
                        ->orderBy('name')
                        ->get([
                            'id',
                            'company_id',
                            'name',
                            'code',
                        ]),

                'filters' =>
                    $request->only([
                        'search',
                        'company_id',
                        'leave_type_id',
                        'status',
                    ]),
            ]
        );
    }

    public function create(): Response
    {
        return Inertia::render(
            'ERP/HRM/Leave/Requests/Create',
            [
                'employees' =>
                    Employee::query()
                        ->where(
                            'employment_status',
                            'active'
                        )
                        ->orderBy('first_name')
                        ->get([
                            'id',
                            'company_id',
                            'employee_number',
                            'first_name',
                            'middle_name',
                            'last_name',
                        ]),

                'leaveTypes' =>
                    LeaveType::query()
                        ->active()
                        ->orderBy('name')
                        ->get(),
            ]
        );
    }

    public function store(
        Request $request,
        LeaveDayCalculator $calculator,
        LeaveBalanceService $balanceService
    ): RedirectResponse {
        $data =
            $this->validateRequestData(
                $request
            );

            if (
                $data['duration_type']
                    === 'half_day' &&
                $data['start_date']
                    !== $data['end_date']
            ) {
                throw ValidationException::withMessages([
                    'end_date' =>
                        'Half-day leave must start and end on the same date.',
                ]);
            }

        $employee =
            Employee::query()
                ->findOrFail(
                    $data['employee_id']
                );

        $leaveType =
            LeaveType::query()
                ->where(
                    'id',
                    $data['leave_type_id']
                )
                ->where(
                    'company_id',
                    $employee->company_id
                )
                ->where(
                    'status',
                    'active'
                )
                ->firstOrFail();

        if (
            $data['duration_type']
                === 'half_day' &&
            !$leaveType->allow_half_day
        ) {
            throw ValidationException::withMessages([
                'duration_type' =>
                    'This leave type does not allow half-day leave.',
            ]);
        }

        $this->ensureNoOverlap(
            $employee->id,
            $data['start_date'],
            $data['end_date']
        );

        $days =
            $calculator->calculate(
                $employee,
                $leaveType,
                $data['start_date'],
                $data['end_date'],
                $data['duration_type']
            );

        if ($days <= 0) {
            throw ValidationException::withMessages([
                'start_date' =>
                    'The selected dates contain no chargeable leave days.',
            ]);
        }

        $year =
            CarbonImmutable::parse(
                $data['start_date']
            )->year;

        $balance =
            $balanceService
                ->recalculate(
                    $employee,
                    $leaveType,
                    $year
                );

        if (
            $balance->available_days <
            $days
        ) {
            throw ValidationException::withMessages([
                'leave_type_id' =>
                    "Insufficient leave balance. Available: {$balance->available_days} day(s).",
            ]);
        }

        if (
            $leaveType
                ->requires_attachment &&
            !$request->hasFile(
                'attachment'
            )
        ) {
            throw ValidationException::withMessages([
                'attachment' =>
                    'An attachment is required for this leave type.',
            ]);
        }

        $attachment = null;

        if (
            $request->hasFile(
                'attachment'
            )
        ) {
            $attachment =
                $request
                    ->file('attachment')
                    ->store(
                        "employees/{$employee->id}/leave",
                        'public'
                    );
        }

        DB::transaction(
            function () use (
                $data,
                $employee,
                $days,
                $attachment,
                $balanceService,
                $leaveType,
                $year
            ): void {
                EmployeeLeaveRequest::create([
                    ...$data,

                    'company_id' =>
                        $employee->company_id,

                    'total_days' =>
                        $days,

                    'attachment' =>
                        $attachment,

                    'status' =>
                        'pending',
                ]);

                $balanceService
                    ->recalculate(
                        $employee,
                        $leaveType,
                        $year
                    );
            }
        );

        return redirect()
            ->route(
                'erp.leave-requests.index'
            )
            ->with(
                'success',
                'Leave request submitted successfully.'
            );
    }

    public function show(
        EmployeeLeaveRequest $leaveRequest
    ): Response {
        $leaveRequest->load([
            'employee.company',
            'employee.branch',
            'employee.department',
            'employee.designation',
            'leaveType',
            'approvedBy:id,name',
            'rejectedBy:id,name',
            'cancelledBy:id,name',
        ]);

        return Inertia::render(
            'ERP/HRM/Leave/Requests/Show',
            [
                'leaveRequest' =>
                    $leaveRequest,
            ]
        );
    }

    public function approve(
        EmployeeLeaveRequest $leaveRequest,
        LeaveBalanceService $balanceService,
        LeaveAttendanceReconciler $attendanceReconciler
    ): RedirectResponse {
        if (
            $leaveRequest->status
                !== 'pending'
        ) {
            return back()->with(
                'error',
                'Only pending leave requests can be approved.'
            );
        }

        $leaveRequest->load([
            'employee',
            'leaveType',
        ]);

        DB::transaction(
            function () use (
                $leaveRequest,
                $balanceService
            ): void {
                $leaveRequest->update([
                    'status' =>
                        'approved',

                    'approved_by' =>
                        auth()->id(),

                    'approved_at' =>
                        now(),

                    'rejected_by' =>
                        null,

                    'rejected_at' =>
                        null,

                    'rejection_reason' =>
                        null,
                ]);

                $year =
                    CarbonImmutable::parse(
                        $leaveRequest
                            ->start_date
                    )->year;

                $balanceService
                    ->recalculate(
                        $leaveRequest
                            ->employee,

                        $leaveRequest
                            ->leaveType,

                        $year
                    );
            }
        );

        $attendanceReconciler
        ->reconcile(
            $leaveRequest->fresh([
                'leaveType',
            ])
        );

        return back()->with(
            'success',
            'Leave request approved successfully.'
        );
    }

    public function reject(
        Request $request,
        EmployeeLeaveRequest $leaveRequest,
        LeaveBalanceService $balanceService
    ): RedirectResponse {
        $data =
            $request->validate([
                'rejection_reason' => [
                    'required',
                    'string',
                    'max:1000',
                ],
            ]);

        if (
            $leaveRequest->status
                !== 'pending'
        ) {
            return back()->with(
                'error',
                'Only pending leave requests can be rejected.'
            );
        }

        $leaveRequest->load([
            'employee',
            'leaveType',
        ]);

        DB::transaction(
            function () use (
                $leaveRequest,
                $data,
                $balanceService
            ): void {
                $leaveRequest->update([
                    'status' =>
                        'rejected',

                    'rejected_by' =>
                        auth()->id(),

                    'rejected_at' =>
                        now(),

                    'rejection_reason' =>
                        $data[
                            'rejection_reason'
                        ],
                ]);

                $year =
                    CarbonImmutable::parse(
                        $leaveRequest
                            ->start_date
                    )->year;

                $balanceService
                    ->recalculate(
                        $leaveRequest
                            ->employee,

                        $leaveRequest
                            ->leaveType,

                        $year
                    );
            }
        );

        return back()->with(
            'success',
            'Leave request rejected.'
        );
    }

    private function ensureNoOverlap(
        int $employeeId,
        string $startDate,
        string $endDate
    ): void {
        $exists =
            EmployeeLeaveRequest::query()
                ->where(
                    'employee_id',
                    $employeeId
                )
                ->whereIn(
                    'status',
                    [
                        'pending',
                        'approved',
                    ]
                )
                ->whereDate(
                    'start_date',
                    '<=',
                    $endDate
                )
                ->whereDate(
                    'end_date',
                    '>=',
                    $startDate
                )
                ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'start_date' =>
                    'This employee already has a pending or approved leave request overlapping these dates.',
            ]);
        }
    }

    private function validateRequestData(
        Request $request
    ): array {
        return $request->validate([
            'employee_id' => [
                'required',
                'exists:employees,id',
            ],

            'leave_type_id' => [
                'required',
                'exists:leave_types,id',
            ],

            'start_date' => [
                'required',
                'date',
            ],

            'end_date' => [
                'required',
                'date',
                'after_or_equal:start_date',
            ],

            'duration_type' => [
                'required',
                Rule::in([
                    'full_day',
                    'half_day',
                ]),
            ],

            'half_day_period' => [
                Rule::requiredIf(
                    $request->duration_type
                        === 'half_day'
                ),

                'nullable',

                Rule::in([
                    'first_half',
                    'second_half',
                ]),
            ],

            'reason' => [
                'nullable',
                'string',
                'max:3000',
            ],

            'attachment' => [
                'nullable',
                'file',
                'max:5120',
                'mimes:pdf,jpg,jpeg,png,webp',
            ],
        ]);
    }

    public function cancel(
        Request $request,
        EmployeeLeaveRequest $leaveRequest,
        LeaveBalanceService $balanceService,
        LeaveAttendanceReconciler $attendanceReconciler
    ): RedirectResponse {
        $data =
            $request->validate([
                'cancellation_reason' => [
                    'required',
                    'string',
                    'max:2000',
                ],
            ]);

        if (
            !in_array(
                $leaveRequest->status,
                [
                    'pending',
                    'approved',
                ],
                true
            )
        ) {
            return back()->with(
                'error',
                'Only pending or approved leave requests can be cancelled.'
            );
        }

        $leaveRequest->load([
            'employee',
            'leaveType',
        ]);

        $wasApproved =
            $leaveRequest->status ===
            'approved';

        DB::transaction(
            function () use (
                $leaveRequest,
                $data,
                $balanceService
            ): void {
                $leaveRequest->update([
                    'status' =>
                        'cancelled',

                    'cancelled_at' =>
                        now(),

                    'cancelled_by' =>
                        auth()->id(),

                    'cancellation_reason' =>
                        $data[
                            'cancellation_reason'
                        ],
                ]);

                $year =
                    CarbonImmutable::parse(
                        $leaveRequest
                            ->start_date
                    )->year;

                $balanceService
                    ->recalculate(
                        $leaveRequest
                            ->employee,

                        $leaveRequest
                            ->leaveType,

                        $year
                    );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Reverse attendance only for previously approved leave
        |--------------------------------------------------------------------------
        */

        if ($wasApproved) {
            $attendanceReconciler
                ->reverse(
                    $leaveRequest->fresh([
                        'leaveType',
                    ]),
                    auth()->id()
                );
        }

        return back()->with(
            'success',
            $wasApproved
                ? 'Approved leave cancelled. Leave balance and attendance were reconciled.'
                : 'Pending leave request cancelled successfully.'
        );
    }





}