<?php

namespace App\Http\Controllers\ERP\HRM;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\AttendanceShift;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Services\Attendance\AttendanceProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceRecordController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'company_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'shift_id' => ['nullable', 'integer'],
            'attendance_status' => [
                'nullable',
                'string',
                'max:50',
            ],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $records = AttendanceRecord::query()
            ->with([
                'company:id,name',
                'employee:id,employee_number,first_name,middle_name,last_name,preferred_name',
                'shift:id,name,code,start_time,end_time',
                'branch:id,name',
                'department:id,name',
                'designation:id,name',
            ])

            ->when(
                $filters['search'] ?? null,
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
                                )
                                ->orWhere(
                                    'preferred_name',
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
                $filters['branch_id'] ?? null,
                fn ($query, $branchId) => $query->where(
                    'branch_id',
                    $branchId
                )
            )

            ->when(
                $filters['department_id'] ?? null,
                fn ($query, $departmentId) => $query->where(
                    'department_id',
                    $departmentId
                )
            )

            ->when(
                $filters['shift_id'] ?? null,
                fn ($query, $shiftId) => $query->where(
                    'shift_id',
                    $shiftId
                )
            )

            ->when(
                $filters['attendance_status'] ?? null,
                fn ($query, $status) => $query->where(
                    'attendance_status',
                    $status
                )
            )

            ->when(
                $filters['date_from'] ?? null,
                fn ($query, $dateFrom) => $query->whereDate(
                    'attendance_date',
                    '>=',
                    $dateFrom
                )
            )

            ->when(
                $filters['date_to'] ?? null,
                fn ($query, $dateTo) => $query->whereDate(
                    'attendance_date',
                    '<=',
                    $dateTo
                )
            )

            ->orderByDesc('attendance_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render(
            'ERP/HRM/Attendance/Records/Index',
            [
                'records' => $records,

                'filters' => [
                    'search' => $filters['search'] ?? '',
                    'company_id' => $filters['company_id'] ?? '',
                    'branch_id' => $filters['branch_id'] ?? '',
                    'department_id' => $filters['department_id'] ?? '',
                    'shift_id' => $filters['shift_id'] ?? '',
                    'attendance_status' => $filters['attendance_status'] ?? '',
                    'date_from' => $filters['date_from'] ?? '',
                    'date_to' => $filters['date_to'] ?? '',
                ],

                'companies' => Company::query()
                    ->where('status', 'active')
                    ->orderBy('name')
                    ->get([
                        'id',
                        'name',
                    ]),

                'branches' => Branch::query()
                    ->where('status', 'active')
                    ->orderBy('name')
                    ->get([
                        'id',
                        'company_id',
                        'name',
                    ]),

                'departments' => Department::query()
                    ->where('status', 'active')
                    ->orderBy('name')
                    ->get([
                        'id',
                        'company_id',
                        'branch_id',
                        'name',
                    ]),

                'shifts' => AttendanceShift::query()
                    ->where('status', 'active')
                    ->orderBy('name')
                    ->get([
                        'id',
                        'company_id',
                        'name',
                        'code',
                    ]),
            ]
        );
    }

    public function show(
        AttendanceRecord $attendanceRecord
    ): Response {
        $attendanceRecord->load([
            'company:id,name,timezone',
            'employee:id,employee_number,first_name,middle_name,last_name,preferred_name,work_email,work_phone,photo',
            'shift',
            'branch:id,name',
            'department:id,name',
            'designation:id,name',

            'rawPunches' => function ($query) {
                $query
                    ->with([
                        'device:id,name,code,provider,model,serial_number,timezone',
                    ])
                    ->orderBy('punched_at');
            },
        ]);

        return Inertia::render(
            'ERP/HRM/Attendance/Records/Show',
            [
                'record' => $attendanceRecord,
            ]
        );
    }

    public function reprocess(
        AttendanceRecord $attendanceRecord,
        AttendanceProcessor $processor
    ): RedirectResponse {
        $result =
            $processor->reprocessRecord(
                $attendanceRecord
            );

        if (! $result) {
            return back()->with(
                'error',
                'Attendance could not be reprocessed. Check the employee shift assignment and available raw punches.'
            );
        }

        return back()->with(
            'success',
            'Attendance record reprocessed successfully.'
        );
    }
}
