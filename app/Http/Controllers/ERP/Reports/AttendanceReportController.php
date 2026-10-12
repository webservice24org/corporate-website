<?php

namespace App\Http\Controllers\ERP\Reports;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Services\Attendance\AttendanceReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceReportController extends Controller
{
    public function index(
        Request $request,
        AttendanceReportService $reportService
    ): Response {
        $filters =
            $this->filters(
                $request,
                $reportService
            );

        $records =
            $reportService
                ->paginate(
                    $filters
                );

        return Inertia::render(
            'ERP/Reports/Attendance/Index',
            [
                'records' => $records,

                'summary' => $reportService
                    ->summary(
                        $filters
                    ),

                'statusSummary' => $reportService
                    ->statusSummary(
                        $filters
                    ),

                'employeeSummary' => $reportService
                    ->employeeSummary(
                        $filters
                    ),

                'companies' => Company::query()
                    ->where(
                        'status',
                        'active'
                    )
                    ->orderBy('name')
                    ->get([
                        'id',
                        'name',
                    ]),

                'branches' => Branch::query()
                    ->when(
                        $filters[
                            'company_id'
                        ] ?? null,
                        fn(
                        $query,
                        $companyId
                    ) => $query->where(
                                'company_id',
                                $companyId
                            )
                    )
                    ->orderBy('name')
                    ->get([
                        'id',
                        'company_id',
                        'name',
                    ]),

                'departments' => Department::query()
                    ->when(
                        $filters[
                            'company_id'
                        ] ?? null,
                        fn(
                        $query,
                        $companyId
                    ) => $query->where(
                                'company_id',
                                $companyId
                            )
                    )
                    ->orderBy('name')
                    ->get([
                        'id',
                        'company_id',
                        'name',
                    ]),

                'employees' => Employee::query()
                    ->when(
                        $filters[
                            'company_id'
                        ] ?? null,
                        fn(
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
                        ] ?? null,
                        fn(
                        $query,
                        $branchId
                    ) => $query->where(
                                'branch_id',
                                $branchId
                            )
                    )
                    ->when(
                        $filters[
                            'department_id'
                        ] ?? null,
                        fn(
                        $query,
                        $departmentId
                    ) => $query->where(
                                'department_id',
                                $departmentId
                            )
                    )
                    ->orderBy(
                        'employee_number'
                    )
                    ->get([
                        'id',
                        'company_id',
                        'branch_id',
                        'department_id',
                        'employee_number',
                        'first_name',
                        'middle_name',
                        'last_name',
                    ]),

                'filters' => $filters,
            ]
        );
    }

    public function exportCsv(
        Request $request,
        AttendanceReportService $reportService
    ): StreamedResponse {
        $filters =
            $this->filters(
                $request,
                $reportService
            );

        $filename =
            sprintf(
                'attendance-report-%s-%s.csv',
                $filters['date_from'],
                $filters['date_to']
            );

        return response()->streamDownload(
            function () use ($filters, $reportService): void {
                $handle =
                    fopen(
                        'php://output',
                        'w'
                    );

                /*
                 * UTF-8 BOM helps Excel display
                 * Bengali employee names correctly.
                 */
                fwrite(
                    $handle,
                    "\xEF\xBB\xBF"
                );

                fputcsv(
                    $handle,
                    [
                        'Date',
                        'Employee Number',
                        'Employee Name',
                        'Company',
                        'Branch',
                        'Department',
                        'Shift',
                        'Status',
                        'Check In',
                        'Check Out',
                        'Worked Minutes',
                        'Late Minutes',
                        'Early Leave Minutes',
                        'Overtime Minutes',
                        'Punch Count',
                        'Source',
                    ]
                );

                $reportService
                    ->query(
                        $filters
                    )
                    ->orderBy(
                        'attendance_date'
                    )
                    ->orderBy(
                        'employee_id'
                    )
                    ->chunkById(
                        500,
                        function ($records) use ($handle): void {
                            foreach (
                                $records as $record
                            ) {
                                $employee = data_get($record, 'employee');

                                $employeeName = collect([
                                    data_get($employee, 'first_name'),
                                    data_get($employee, 'middle_name'),
                                    data_get($employee, 'last_name'),
                                ])->filter()->implode(' ');

                                fputcsv(
                                    $handle,
                                    [
                                        data_get($record, 'attendance_date'),
                                        data_get($employee, 'employee_number'),
                                        $employeeName,
                                        data_get($employee, 'company.name'),
                                        data_get($employee, 'branch.name'),
                                        data_get($employee, 'department.name'),
                                        data_get($record, 'shift.name'),
                                        data_get($record, 'attendance_status'),
                                        data_get($record, 'check_in_at'),
                                        data_get($record, 'check_out_at'),
                                        data_get($record, 'worked_minutes'),
                                        data_get($record, 'late_minutes'),
                                        data_get($record, 'early_leave_minutes'),
                                        data_get($record, 'overtime_minutes'),
                                        data_get($record, 'punch_count'),
                                        data_get($record, 'source'),
                                    ]
                                );
                            }
                        }
                    );

                fclose($handle);
            },
            $filename,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]
        );
    }

    private function filters(
        Request $request,
        AttendanceReportService $reportService
    ): array {
        $defaults =
            $reportService
                ->defaultDates();

        $validated =
            $request->validate([
                'date_from' => [
                    'nullable',
                    'date',
                ],

                'date_to' => [
                    'nullable',
                    'date',
                    'after_or_equal:date_from',
                ],

                'company_id' => [
                    'nullable',
                    'integer',
                    'exists:companies,id',
                ],

                'branch_id' => [
                    'nullable',
                    'integer',
                    'exists:branches,id',
                ],

                'department_id' => [
                    'nullable',
                    'integer',
                    'exists:departments,id',
                ],

                'employee_id' => [
                    'nullable',
                    'integer',
                    'exists:employees,id',
                ],

                'attendance_status' => [
                    'nullable',
                    'string',
                    'max:50',
                ],

                'exception_type' => [
                    'nullable',
                    'in:all,late,early_leave,overtime',
                ],

                'view' => [
                    'nullable',
                    'in:daily,employee,exceptions,summary',
                ],
            ]);

        return [
            'date_from' => $validated[
                'date_from'
            ]
                ?? $defaults[
                    'date_from'
                ],

            'date_to' => $validated[
                'date_to'
            ]
                ?? $defaults[
                    'date_to'
                ],

            'company_id' => $validated[
                'company_id'
            ] ?? null,

            'branch_id' => $validated[
                'branch_id'
            ] ?? null,

            'department_id' => $validated[
                'department_id'
            ] ?? null,

            'employee_id' => $validated[
                'employee_id'
            ] ?? null,

            'attendance_status' => $validated[
                'attendance_status'
            ] ?? null,

            'exception_type' => $validated[
                'exception_type'
            ] ?? 'all',

            'view' => $validated[
                'view'
            ] ?? 'daily',
        ];
    }
}
