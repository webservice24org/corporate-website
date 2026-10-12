<?php

namespace App\Services\Attendance;

use App\Models\AttendanceFinalizationRun;
use App\Models\AttendanceHoliday;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\AttendanceShift;
use App\Models\LeaveType;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeLeaveRequest;
use App\Models\EmployeeShiftAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AttendanceDayFinalizer
{
    public function finalize(
        int $companyId,
        string $attendanceDate,
        ?int $runBy = null
    ): AttendanceFinalizationRun {
        $company =
            Company::query()
                ->findOrFail(
                    $companyId
                );

        $setting =
            AttendanceSetting::query()
                ->where(
                    'company_id',
                    $company->id
                )
                ->where(
                    'is_active',
                    true
                )
                ->first();

        $timezone =
            $setting?->timezone
            ?: $company->timezone
            ?: 'Asia/Dhaka';

        $targetDate =
            CarbonImmutable::parse(
                $attendanceDate,
                $timezone
            )->startOfDay();

        $today =
            CarbonImmutable::now(
                $timezone
            )->startOfDay();

        if (
            $targetDate
                ->greaterThanOrEqualTo(
                    $today
                )
        ) {
            throw new \RuntimeException(
                'Only completed past attendance dates can be finalized.'
            );
        }

        $summary = [
            'employees_checked' => 0,
            'absent_created' => 0,
            'off_day_created' => 0,
            'leave_created' => 0,
            'off_day_work_updated' => 0,
            'existing_attendance_skipped' => 0,
            'manual_records_skipped' => 0,
            'no_shift_skipped' => 0,
        ];

        $employees =
            Employee::query()
                ->where(
                    'company_id',
                    $company->id
                )
                ->where(
                    'employment_status',
                    'active'
                )
                ->where(
                    function ($query) use ($attendanceDate) {
                        $query
                            ->whereNull(
                                'joining_date'
                            )
                            ->orWhereDate(
                                'joining_date',
                                '<=',
                                $attendanceDate
                            );
                    }
                )
                ->where(
                    function ($query) use ($attendanceDate) {
                        $query
                            ->whereNull(
                                'termination_date'
                            )
                            ->orWhereDate(
                                'termination_date',
                                '>=',
                                $attendanceDate
                            );
                    }
                )
                ->orderBy('id')
                ->get();

        foreach ($employees as $employee) {
            $summary[
                'employees_checked'
            ]++;

            DB::transaction(
                function () use ($employee, $attendanceDate, $timezone, &$summary): void {
                    $this->finalizeEmployeeDay(
                        $employee,
                        $attendanceDate,
                        $timezone,
                        $summary
                    );
                }
            );
        }

        return AttendanceFinalizationRun::create([
            'company_id' => $company->id,

            'run_by' => $runBy,

            'attendance_date' => $attendanceDate,

            'status' => 'completed',

            'employees_checked' => $summary[
                'employees_checked'
            ],

            'absent_created' => $summary[
                'absent_created'
            ],

            'off_day_created' => $summary[
                'off_day_created'
            ],

            'off_day_work_updated' => $summary[
                'off_day_work_updated'
            ],

            'existing_attendance_skipped' => $summary[
                'existing_attendance_skipped'
            ],

            'manual_records_skipped' => $summary[
                'manual_records_skipped'
            ],

            'no_shift_skipped' => $summary[
                'no_shift_skipped'
            ],

            'result_summary' => $summary,
        ]);
    }

    private function finalizeEmployeeDay(
        Employee $employee,
        string $attendanceDate,
        string $timezone,
        array &$summary
    ): void {
        $assignment =
            EmployeeShiftAssignment::query()
                ->with('shift')
                ->where(
                    'employee_id',
                    $employee->id
                )
                ->where(
                    'status',
                    'active'
                )
                ->whereDate(
                    'effective_from',
                    '<=',
                    $attendanceDate
                )
                ->where(
                    function ($query) use ($attendanceDate) {
                        $query
                            ->whereNull(
                                'effective_to'
                            )
                            ->orWhereDate(
                                'effective_to',
                                '>=',
                                $attendanceDate
                            );
                    }
                )
                ->orderByDesc(
                    'effective_from'
                )
                ->orderByDesc('id')
                ->first();

        if (
            !$assignment ||
            !$assignment->shift
        ) {
            $summary[
                'no_shift_skipped'
            ]++;

            return;
        }

        $shift = $assignment->shift;

        if (!$shift instanceof AttendanceShift) {
            $summary['no_shift_skipped']++;

            return;
        }

        $record =
            AttendanceRecord::query()
                ->where(
                    'employee_id',
                    $employee->id
                )
                ->whereDate(
                    'attendance_date',
                    $attendanceDate
                )
                ->lockForUpdate()
                ->first();

        if (
            $record?->is_manual
        ) {
            $summary[
                'manual_records_skipped'
            ]++;

            return;
        }

        $holiday =
            $this->resolveHoliday(
                $employee,
                $attendanceDate
            );

        $approvedLeave =
            EmployeeLeaveRequest::query()
                ->with(
                    'leaveType:id,name,code,is_paid'
                )
                ->where(
                    'employee_id',
                    $employee->id
                )
                ->where(
                    'status',
                    'approved'
                )
                ->whereDate(
                    'start_date',
                    '<=',
                    $attendanceDate
                )
                ->whereDate(
                    'end_date',
                    '>=',
                    $attendanceDate
                )
                ->first();

        $scheduledStartLocal =
            CarbonImmutable::parse(
                $attendanceDate .
                ' ' .
                $shift->start_time,
                $timezone
            );

        $scheduledEndLocal =
            CarbonImmutable::parse(
                $attendanceDate .
                ' ' .
                $shift->end_time,
                $timezone
            );

        if (
            $shift->crosses_midnight ||
            $scheduledEndLocal
                ->lessThanOrEqualTo(
                    $scheduledStartLocal
                )
        ) {
            $scheduledEndLocal =
                $scheduledEndLocal
                    ->addDay();
        }

        $workingDay =
            $this->isWorkingDay(
                $shift->working_days,
                $scheduledStartLocal
            );

        $hasPunchEvidence =
            $record &&
            (
                $record->punch_count > 0 ||
                $record->check_in_at !== null ||
                $record->check_out_at !== null
            );

        /*
        |--------------------------------------------------------------------------
        | Already finalized
        |--------------------------------------------------------------------------
        */

        if (
            $record &&
            $record->source === 'finalizer' &&
            in_array(
                $record->attendance_status,
                [
                    'absent',
                    'off_day',
                ],
                true
            ) &&
            (int) $record->punch_count === 0
        ) {
            $summary[
                'existing_attendance_skipped'
            ]++;

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Existing biometric attendance
        |--------------------------------------------------------------------------
        */

        if ($hasPunchEvidence) {
            if (
                $holiday &&
                $record->attendance_status
                !== 'off_day_work'
            ) {
                $details =
                    $record
                        ->calculation_details
                    ?? [];

                $details['holiday'] = [
                    'id' => $holiday->id,

                    'name' => $holiday->name,

                    'type' => $holiday
                        ->holiday_type,

                    'is_paid' => $holiday->is_paid,
                ];

                $details[
                    'finalization_reconciled'
                ] = true;

                $record->update([
                    'attendance_status' => 'off_day_work',

                    'calculation_details' => $details,
                ]);

                $summary[
                    'off_day_work_updated'
                ]++;

                return;
            }

            $summary[
                'existing_attendance_skipped'
            ]++;

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Determine no-punch final status
        |--------------------------------------------------------------------------
        */

        if ($holiday) {
            $attendanceStatus =
                'off_day';

            $summary[
                'off_day_created'
            ]++;
        } elseif (!$workingDay) {
            $attendanceStatus =
                'off_day';

            $summary[
                'off_day_created'
            ]++;
        } elseif ($approvedLeave) {
            $attendanceStatus =
                'leave';

            $summary[
                'leave_created'
            ]++;
        } else {
            $attendanceStatus =
                'absent';

            $summary[
                'absent_created'
            ]++;
        }

        $leaveType = $approvedLeave?->leaveType;
        $leaveType = $leaveType instanceof LeaveType ? $leaveType : null;

        $calculationDetails = [
            'timezone' => $timezone,

            'working_day' => $workingDay,

            'finalized' => true,

            'finalized_at' => now()->toIso8601String(),

            'finalization_reason' => $holiday
                ? 'holiday'
                : (
                    !$workingDay
                    ? 'weekly_off'
                    : (
                        $approvedLeave
                        ? 'approved_leave'
                        : 'no_attendance'
                    )
                ),

            'leave_reconciliation_pending' => false,

            'leave' => $approvedLeave
                ? [
                    'request_id' => $approvedLeave->id,

                    'leave_type_id' => $approvedLeave
                        ->leave_type_id,

                    'leave_type_name' => $leaveType?->name,

                    'leave_type_code' => $leaveType?->code,

                    'is_paid' => $leaveType?->is_paid,

                    'duration_type' => $approvedLeave
                        ->duration_type,

                    'half_day_period' => $approvedLeave
                        ->half_day_period,
                ]
                : null,
        ];

        if ($holiday) {
            $calculationDetails[
                'holiday'
            ] = [
                'id' => $holiday->id,

                'name' => $holiday->name,

                'type' => $holiday
                    ->holiday_type,

                'is_paid' => $holiday->is_paid,
            ];
        }

        AttendanceRecord::query()
            ->updateOrCreate(
                [
                    'employee_id' => $employee->id,

                    'attendance_date' => $attendanceDate,
                ],
                [
                    'company_id' => $employee->company_id,

                    'shift_id' => $shift->id,

                    'branch_id' => $employee->branch_id,

                    'department_id' => $employee->department_id,

                    'designation_id' => $employee->designation_id,

                    'scheduled_start_at' => $scheduledStartLocal
                        ->utc()
                        ->format(
                            'Y-m-d H:i:s'
                        ),

                    'scheduled_end_at' => $scheduledEndLocal
                        ->utc()
                        ->format(
                            'Y-m-d H:i:s'
                        ),

                    'check_in_at' => null,

                    'check_out_at' => null,

                    'worked_minutes' => 0,

                    'break_minutes' => 0,

                    'late_minutes' => 0,

                    'early_leave_minutes' => 0,

                    'overtime_minutes' => 0,

                    'punch_count' => 0,

                    'attendance_status' => $attendanceStatus,

                    'source' => 'finalizer',

                    'is_manual' => false,

                    'calculation_details' => $calculationDetails,

                    'processed_at' => now(),
                ]
            );
    }

    private function resolveHoliday(
        Employee $employee,
        string $attendanceDate
    ): ?AttendanceHoliday {
        return AttendanceHoliday::query()
            ->where(
                'company_id',
                $employee->company_id
            )
            ->whereDate(
                'holiday_date',
                $attendanceDate
            )
            ->where(
                'status',
                'active'
            )
            ->where(
                function ($query) use ($employee) {
                    $query
                        ->whereNull(
                            'branch_id'
                        );

                    if (
                        $employee->branch_id
                    ) {
                        $query->orWhere(
                            'branch_id',
                            $employee
                                ->branch_id
                        );
                    }
                }
            )
            ->orderByRaw(
                'CASE WHEN branch_id IS NULL THEN 1 ELSE 0 END'
            )
            ->first();
    }

    private function isWorkingDay(
        mixed $workingDays,
        CarbonImmutable $date
    ): bool {
        if (empty($workingDays)) {
            return true;
        }

        if (
            is_string(
                $workingDays
            )
        ) {
            $decoded =
                json_decode(
                    $workingDays,
                    true
                );

            if (
                json_last_error()
                === JSON_ERROR_NONE
            ) {
                $workingDays =
                    $decoded;
            } else {
                $workingDays =
                    explode(
                        ',',
                        $workingDays
                    );
            }
        }

        if (
            !is_array(
                $workingDays
            )
        ) {
            return true;
        }

        $normalized =
            collect(
                $workingDays
            )
                ->map(
                    fn($day) => strtolower(
                        trim(
                            (string) $day
                        )
                    )
                )
                ->values();

        $fullName =
            strtolower(
                $date->format('l')
            );

        $shortName =
            strtolower(
                $date->format('D')
            );

        if (
            $normalized
                ->contains(
                    $fullName
                ) ||
            $normalized
                ->contains(
                    $shortName
                )
        ) {
            return true;
        }

        $isoDay =
            (string) 
            $date->dayOfWeekIso;

        if (
            $normalized
                ->contains(
                    $isoDay
                )
        ) {
            return true;
        }

        if (
            $date->isSunday() &&
            $normalized
                ->contains('0')
        ) {
            return true;
        }

        return false;
    }
}
