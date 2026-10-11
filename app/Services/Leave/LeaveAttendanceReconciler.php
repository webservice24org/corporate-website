<?php

namespace App\Services\Leave;

use App\Models\AttendanceRecord;
use App\Models\EmployeeLeaveRequest;
use App\Services\Attendance\AttendanceDayFinalizer;
use Carbon\CarbonImmutable;

class LeaveAttendanceReconciler
{
    public function __construct(
        private readonly AttendanceDayFinalizer $finalizer
    ) {
    }

    public function reconcile(
        EmployeeLeaveRequest $request
    ): int {
        if (
            $request->status !== 'approved'
        ) {
            return 0;
        }

        $request->loadMissing(
            'leaveType'
        );

        $updated = 0;

        $start =
            CarbonImmutable::parse(
                $request->start_date
            );

        $end =
            CarbonImmutable::parse(
                $request->end_date
            );

        for (
            $date = $start;
            $date->lessThanOrEqualTo($end);
            $date = $date->addDay()
        ) {
            $record =
                AttendanceRecord::query()
                    ->where(
                        'employee_id',
                        $request->employee_id
                    )
                    ->whereDate(
                        'attendance_date',
                        $date->toDateString()
                    )
                    ->first();

            if (!$record) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Never replace manual or real biometric attendance
            |--------------------------------------------------------------------------
            */

            if (
                $record->is_manual ||
                $record->punch_count > 0 ||
                $record->check_in_at !== null ||
                $record->check_out_at !== null
            ) {
                continue;
            }

            if (
                $record->attendance_status !==
                'absent'
            ) {
                continue;
            }

            $details =
                $record->calculation_details
                ?? [];

            $details['leave'] = [
                'request_id' =>
                    $request->id,

                'leave_type_id' =>
                    $request->leave_type_id,

                'leave_type_name' =>
                    $request
                        ->leaveType
                        ?->name,

                'leave_type_code' =>
                    $request
                        ->leaveType
                        ?->code,

                'is_paid' =>
                    $request
                        ->leaveType
                        ?->is_paid,

                'duration_type' =>
                    $request
                        ->duration_type,

                'half_day_period' =>
                    $request
                        ->half_day_period,
            ];

            $details[
                'previous_attendance_status'
            ] =
                $record
                    ->attendance_status;

            $details[
                'leave_reconciled_at'
            ] =
                now()
                    ->toIso8601String();

            $details[
                'leave_reconciliation_pending'
            ] =
                false;

            $record->update([
                'attendance_status' =>
                    'leave',

                'calculation_details' =>
                    $details,

                'processed_at' =>
                    now(),
            ]);

            $updated++;
        }

        return $updated;
    }

    /*
    |--------------------------------------------------------------------------
    | Reverse approved leave attendance
    |--------------------------------------------------------------------------
    |
    | This is used when an approved leave request is cancelled.
    |
    | Only attendance that was generated specifically from this leave request
    | may be removed.
    |
    | Manual attendance and biometric evidence are never touched.
    |
    */

    public function reverse(
        EmployeeLeaveRequest $request,
        ?int $runBy = null
    ): int {
        $reversed = 0;

        $start =
            CarbonImmutable::parse(
                $request->start_date
            );

        $end =
            CarbonImmutable::parse(
                $request->end_date
            );

        $datesToFinalize = [];

        for (
            $date = $start;
            $date->lessThanOrEqualTo($end);
            $date = $date->addDay()
        ) {
            $attendanceDate =
                $date->toDateString();

            $record =
                AttendanceRecord::query()
                    ->where(
                        'employee_id',
                        $request->employee_id
                    )
                    ->whereDate(
                        'attendance_date',
                        $attendanceDate
                    )
                    ->first();

            if (!$record) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Never touch manual attendance
            |--------------------------------------------------------------------------
            */

            if (
                $record->is_manual
            ) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Never erase biometric evidence
            |--------------------------------------------------------------------------
            */

            if (
                $record->punch_count > 0 ||
                $record->check_in_at !== null ||
                $record->check_out_at !== null
            ) {
                continue;
            }

            if (
                $record->attendance_status !==
                'leave'
            ) {
                continue;
            }

            $details =
                $record->calculation_details
                ?? [];

            $leaveDetails =
                $details['leave']
                ?? null;

            if (
                !is_array(
                    $leaveDetails
                )
            ) {
                continue;
            }

            if (
                (int) (
                    $leaveDetails[
                        'request_id'
                    ] ?? 0
                ) !==
                $request->id
            ) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Remove leave placeholder
            |--------------------------------------------------------------------------
            |
            | We intentionally delete the generated no-punch attendance row,
            | then run the normal Day Finalizer again.
            |
            | This means the normal attendance rules decide whether the day
            | becomes absent, off_day, etc.
            |
            */

            $record->delete();

            $reversed++;

            $datesToFinalize[] =
                $attendanceDate;
        }

        /*
        |--------------------------------------------------------------------------
        | Re-finalize past dates
        |--------------------------------------------------------------------------
        */

        foreach (
            array_unique(
                $datesToFinalize
            ) as $attendanceDate
        ) {
            try {
                $this->finalizer->finalize(
                    $request->company_id,
                    $attendanceDate,
                    $runBy
                );
            } catch (\RuntimeException $exception) {
                /*
                 * Current/future dates are intentionally
                 * ignored because the finalizer only works
                 * on completed past dates.
                 */
            }
        }

        return $reversed;
    }
}