<?php

namespace App\Services\Attendance;

use App\Models\AttendanceHoliday;
use App\Models\AttendanceRawPunch;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\AttendanceShift;
use App\Models\Company;
use App\Models\AttendanceDevice;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AttendanceProcessor
{
    /*
    |--------------------------------------------------------------------------
    | Process pending punches
    |--------------------------------------------------------------------------
    */

    public function processPending(
        int $limit = 500
    ): array {
        /** @var \Illuminate\Database\Eloquent\Collection<int, AttendanceRawPunch> $punches */
        $punches =
            AttendanceRawPunch::query()
                ->where(
                    'processing_status',
                    'pending'
                )
                ->whereNotNull(
                    'employee_id'
                )
                ->with([
                    'company',
                    'device',
                    'employee',
                ])
                ->orderBy('punched_at')
                ->limit($limit)
                ->get();

        $days = [];

        foreach ($punches as $punch) {
            $timezone =
                $this->resolveTimezone(
                    $punch
                );

            $localPunch =
                $this->rawUtcToLocal(
                    $punch,
                    $timezone
                );

            /*
            |--------------------------------------------------------------------------
            | Process both local date and previous date
            |--------------------------------------------------------------------------
            |
            | Previous date is important for overnight shifts.
            |
            */

            $dates = [
                $localPunch
                    ->toDateString(),

                $localPunch
                    ->subDay()
                    ->toDateString(),
            ];

            foreach ($dates as $date) {
                $key =
                    $punch->employee_id .
                    '|' .
                    $date;

                $days[$key] = [
                    'employee_id' => $punch->employee_id,

                    'date' => $date,
                ];
            }
        }

        $processedRecords = 0;
        $failedDays = 0;

        foreach ($days as $day) {
            try {
                $record =
                    $this->processEmployeeDay(
                        employeeId: $day['employee_id'],

                        attendanceDate: $day['date']
                    );

                if ($record) {
                    $processedRecords++;
                }
            } catch (\Throwable $exception) {
                report($exception);

                $failedDays++;
            }
        }

        return [
            'pending_punches' => $punches->count(),

            'candidate_days' => count($days),

            'processed_records' => $processedRecords,

            'failed_days' => $failedDays,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Reprocess one existing attendance record
    |--------------------------------------------------------------------------
    */

    public function reprocessRecord(
        AttendanceRecord $record
    ): ?AttendanceRecord {
        $attendanceDate =
            (string) 
            $record->getRawOriginal(
                'attendance_date'
            );

        return $this->processEmployeeDay(
            $record->employee_id,
            $attendanceDate
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Process one employee + attendance date
    |--------------------------------------------------------------------------
    */

    public function processEmployeeDay(
        int $employeeId,
        string $attendanceDate
    ): ?AttendanceRecord {
        $employee =
            Employee::query()
                ->with([
                    'company',
                    'branch',
                    'department',
                    'designation',
                ])
                ->findOrFail(
                    $employeeId
                );

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
            return null;
        }

        $shift = $assignment->shift;

        if (!$shift instanceof AttendanceShift) {
            return null;
        }

        $company = $employee->company;
        $timezone = $company instanceof Company && $company->timezone
            ? $company->timezone
            : 'Asia/Dhaka';

        $setting =
            AttendanceSetting::query()
                ->where(
                    'company_id',
                    $employee->company_id
                )
                ->where(
                    'is_active',
                    true
                )
                ->first();

        if (
            $setting?->timezone
        ) {
            $timezone =
                $setting->timezone;
        }

        /*
        |--------------------------------------------------------------------------
        | Shift boundary
        |--------------------------------------------------------------------------
        */

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

        /*
        |--------------------------------------------------------------------------
        | Punch search window
        |--------------------------------------------------------------------------
        |
        | We support several possible field names so this stays compatible
        | with the Shift model we already built.
        |
        */

        $checkInBefore =
            $this->integerSetting(
                $shift,
                [
                    'check_in_window_before_minutes',
                ],
                180
            );

        $checkOutAfter =
            $this->integerSetting(
                $shift,
                [
                    'check_out_window_after_minutes',
                ],
                360
            );

        $windowStartLocal =
            $scheduledStartLocal
                ->subMinutes(
                    $checkInBefore
                );

        $windowEndLocal =
            $scheduledEndLocal
                ->addMinutes(
                    $checkOutAfter
                );

        $windowStartUtc =
            $windowStartLocal->utc();

        $windowEndUtc =
            $windowEndLocal->utc();

        /*
        |--------------------------------------------------------------------------
        | Existing attendance record + owned raw punch evidence
        |--------------------------------------------------------------------------
        |
        | Reprocessing may reuse raw punches already linked to THIS attendance
        | record. It must never steal processed/pending punches that belong to
        | another attendance record.
        |
        */

        $existingRecord =
            AttendanceRecord::query()
                ->where(
                    'employee_id',
                    $employee->id
                )
                ->whereDate(
                    'attendance_date',
                    $attendanceDate
                )
                ->first();

        $existingRawPunchIds =
            $existingRecord
            ? $existingRecord
                ->rawPunches()
                ->pluck(
                    'attendance_raw_punches.id'
                )
                ->map(
                    fn($id) => (int) $id
                )
                ->all()
            : [];

        /*
        |--------------------------------------------------------------------------
        | Protect manual attendance
        |--------------------------------------------------------------------------
        |
        | A manually maintained attendance record must never be silently replaced
        | by automatic biometric processing.
        |
        */

        if (
            $existingRecord?->is_manual
        ) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Detect finalizer-generated placeholder
        |--------------------------------------------------------------------------
        */

        $existingCalculationDetails = $existingRecord->calculation_details ?? [];

        if (is_string($existingCalculationDetails)) {
            $decodedDetails = json_decode($existingCalculationDetails, true);
            $existingCalculationDetails = is_array($decodedDetails) ? $decodedDetails : [];
        }

        $isFinalizedPlaceholder =
            $existingRecord &&
            $existingRecord->source
            === 'finalizer' &&
            in_array(
                $existingRecord
                    ->attendance_status,
                [
                    'absent',
                    'off_day',
                ],
                true
            ) &&
            (int) 
            $existingRecord
                ->punch_count === 0;

        /*
        |--------------------------------------------------------------------------
        | Fetch raw punches
        |--------------------------------------------------------------------------
        |
        | Eligible punches are:
        |
        | 1. New pending punches that are not linked to ANY attendance record.
        | 2. Existing punches already linked to THIS attendance record.
        |
        | Processed punches belonging to another attendance record are excluded.
        |
        */

        /** @var \Illuminate\Database\Eloquent\Collection<int, AttendanceRawPunch> $rawPunches */
        $rawPunches =
            AttendanceRawPunch::query()
                ->where(
                    'employee_id',
                    $employee->id
                )
                ->where(
                    'company_id',
                    $employee->company_id
                )
                ->whereBetween(
                    'punched_at',
                    [
                        $windowStartUtc
                            ->format(
                                'Y-m-d H:i:s'
                            ),

                        $windowEndUtc
                            ->format(
                                'Y-m-d H:i:s'
                            ),
                    ]
                )
                ->where(
                    function ($query) use ($existingRawPunchIds) {
                        $query->where(
                            function ($pendingQuery) {
                                $pendingQuery
                                    ->where(
                                        'processing_status',
                                        'pending'
                                    )
                                    ->whereDoesntHave(
                                        'attendanceRecords'
                                    );
                            }
                        );

                        if (
                            count(
                                $existingRawPunchIds
                            ) > 0
                        ) {
                            $query->orWhereIn(
                                'id',
                                $existingRawPunchIds
                            );
                        }
                    }
                )
                ->orderBy(
                    'punched_at'
                )
                ->orderBy('id')
                ->get();

        if ($rawPunches->isEmpty()) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Business duplicate removal
        |--------------------------------------------------------------------------
        */

        $duplicateWindowSeconds =
            (int) (
                ($setting instanceof AttendanceSetting ? $setting->duplicate_punch_window_seconds : null)
                ?? 10
            );

        $effectivePunches =
            $this->removeBusinessDuplicates(
                $rawPunches,
                $duplicateWindowSeconds
            );

        if ($effectivePunches->isEmpty()) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | First and last effective punch
        |--------------------------------------------------------------------------
        */

        $firstPunch =
            $effectivePunches->first();

        $lastPunch =
            $effectivePunches->last();

        $checkInLocal =
            $this->rawUtcToLocal(
                $firstPunch,
                $timezone
            );

        $checkOutLocal =
            $effectivePunches->count() >= 2
            ? $this->rawUtcToLocal(
                $lastPunch,
                $timezone
            )
            : null;

        /*
        |--------------------------------------------------------------------------
        | Attendance rule values
        |--------------------------------------------------------------------------
        */

        $graceMinutes =
            $this->integerSetting(
                $shift,
                [
                    'grace_minutes',
                    'late_grace_minutes',
                ],
                (int) (
                    ($setting instanceof AttendanceSetting ? $setting->grace_minutes : null)
                    ?? 0
                )
            );

        $earlyLeaveGraceMinutes =
            $this->integerSetting(
                $shift,
                [
                    'early_leave_grace_minutes',
                ],
                (int) (
                    ($setting instanceof AttendanceSetting ? $setting->early_leave_grace_minutes : null)
                    ?? 0
                )
            );

        $halfDayMinutes =
            $this->integerSetting(
                $shift,
                [
                    'half_day_minutes',
                ],
                (int) (
                    ($setting instanceof AttendanceSetting ? $setting->half_day_minutes : null)
                    ?? 240
                )
            );

        $minimumFullDayMinutes =
            $this->integerSetting(
                $shift,
                [
                    'minimum_work_minutes',
                ],
                (int) (
                    ($setting instanceof AttendanceSetting ? $setting->minimum_full_day_minutes : null)
                    ?? 480
                )
            );

        $overtimeAfterMinutes =
            $this->integerSetting(
                $shift,
                [
                    'overtime_after_minutes',
                ],
                (int) (
                    ($setting instanceof AttendanceSetting ? $setting->overtime_after_minutes : null)
                    ?? 0
                )
            );

        $breakMinutes =
            $this->integerSetting(
                $shift,
                [
                    'break_minutes',
                ],
                0
            );

        $paidBreak =
            $this->booleanSetting(
                $shift,
                [
                    'is_break_paid',
                    'paid_break',
                    'break_is_paid',
                ],
                false
            );

        /*
        |--------------------------------------------------------------------------
        | Worked minutes
        |--------------------------------------------------------------------------
        */

        $workedMinutes = 0;

        if ($checkOutLocal) {
            $workedMinutes =
                max(
                    0,
                    $checkInLocal
                        ->diffInMinutes(
                            $checkOutLocal
                        )
                );

            if (
                !$paidBreak &&
                $breakMinutes > 0
            ) {
                $workedMinutes =
                    max(
                        0,
                        $workedMinutes -
                        $breakMinutes
                    );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Late
        |--------------------------------------------------------------------------
        */

        $lateThreshold =
            $scheduledStartLocal
                ->addMinutes(
                    $graceMinutes
                );

        $lateMinutes =
            $checkInLocal
                ->greaterThan(
                    $lateThreshold
                )
            ? $scheduledStartLocal
                ->diffInMinutes(
                    $checkInLocal
                )
            : 0;

        /*
        |--------------------------------------------------------------------------
        | Early leave
        |--------------------------------------------------------------------------
        */

        $earlyLeaveMinutes = 0;

        if ($checkOutLocal) {
            $earlyThreshold =
                $scheduledEndLocal
                    ->subMinutes(
                        $earlyLeaveGraceMinutes
                    );

            if (
                $checkOutLocal
                    ->lessThan(
                        $earlyThreshold
                    )
            ) {
                $earlyLeaveMinutes =
                    $checkOutLocal
                        ->diffInMinutes(
                            $scheduledEndLocal
                        );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Overtime
        |--------------------------------------------------------------------------
        */

        $overtimeMinutes = 0;

        if ($checkOutLocal) {
            $overtimeThreshold =
                $scheduledEndLocal
                    ->addMinutes(
                        $overtimeAfterMinutes
                    );

            if (
                $checkOutLocal
                    ->greaterThan(
                        $overtimeThreshold
                    )
            ) {
                $overtimeMinutes =
                    $scheduledEndLocal
                        ->diffInMinutes(
                            $checkOutLocal
                        );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Working day?
        |--------------------------------------------------------------------------
        */

        $workingDay =
            $this->isWorkingDay(
                $shift->working_days,
                $scheduledStartLocal
            );

        /*
        |--------------------------------------------------------------------------
        | Holiday?
        |--------------------------------------------------------------------------
        |
        | Branch-specific holidays take priority over company-wide holidays.
        |
        */

        $holiday =
            AttendanceHoliday::query()
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
                        $query->whereNull(
                            'branch_id'
                        );

                        if (
                            $employee->branch_id
                        ) {
                            $query->orWhere(
                                'branch_id',
                                $employee->branch_id
                            );
                        }
                    }
                )
                ->orderByRaw(
                    'CASE
                        WHEN branch_id IS NULL
                        THEN 1
                        ELSE 0
                    END'
                )
                ->first();

        /*
        |--------------------------------------------------------------------------
        | Final attendance status
        |--------------------------------------------------------------------------
        */

        if ($holiday) {
            $attendanceStatus =
                'off_day_work';
        } elseif (!$workingDay) {
            $attendanceStatus =
                'off_day_work';
        } elseif (
            !$checkOutLocal
        ) {
            $attendanceStatus =
                'incomplete';
        } elseif (
            $workedMinutes >=
            $minimumFullDayMinutes
        ) {
            $attendanceStatus =
                'present';
        } elseif (
            $workedMinutes >=
            $halfDayMinutes
        ) {
            $attendanceStatus =
                'half_day';
        } else {
            $attendanceStatus =
                'incomplete';
        }

        /*
        |--------------------------------------------------------------------------
        | Preserve previous finalization information
        |--------------------------------------------------------------------------
        */

        $finalizationHistory = [];

        if ($isFinalizedPlaceholder) {
            $finalizationHistory[] = [
                'previous_status' => $existingRecord
                    ->attendance_status,

                'previous_source' => $existingRecord
                    ->source,

                'previous_processed_at' => $existingRecord->processed_at
                    ? CarbonImmutable::parse($existingRecord->processed_at)->toIso8601String()
                    : null,

                'finalized_at' => $existingCalculationDetails[
                    'finalized_at'
                ] ?? null,

                'finalization_reason' => $existingCalculationDetails[
                    'finalization_reason'
                ] ?? null,

                'reconciled_at' => now()->toIso8601String(),

                'reason' => 'late_biometric_punch',
            ];
        }

        $previousFinalizationHistory = $existingCalculationDetails['finalization_history'] ?? [];
        $previousFinalizationHistory = is_array($previousFinalizationHistory)
            ? $previousFinalizationHistory
            : [];

        $finalizationHistory =
            array_merge(
                $previousFinalizationHistory,
                $finalizationHistory
            );

        /*
        |--------------------------------------------------------------------------
        | Persist
        |--------------------------------------------------------------------------
        */

        return DB::transaction(
            function () use ($employee, $shift, $attendanceDate, $scheduledStartLocal, $scheduledEndLocal, $checkInLocal, $checkOutLocal, $workedMinutes, $breakMinutes, $lateMinutes, $earlyLeaveMinutes, $overtimeMinutes, $attendanceStatus, $rawPunches, $effectivePunches, $duplicateWindowSeconds, $timezone, $workingDay, $holiday, $existingRawPunchIds, $isFinalizedPlaceholder, $finalizationHistory): AttendanceRecord {
                $record =
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

                                'check_in_at' => $checkInLocal
                                    ->utc()
                                    ->format(
                                        'Y-m-d H:i:s'
                                    ),

                                'check_out_at' => $checkOutLocal
                                        ?->utc()
                                    ->format(
                                        'Y-m-d H:i:s'
                                    ),

                                'worked_minutes' => $workedMinutes,

                                'break_minutes' => $breakMinutes,

                                'late_minutes' => $lateMinutes,

                                'early_leave_minutes' => $earlyLeaveMinutes,

                                'overtime_minutes' => $overtimeMinutes,

                                'punch_count' => $rawPunches->count(),

                                'attendance_status' => $attendanceStatus,

                                'source' => 'automatic',

                                'is_manual' => false,

                                'calculation_details' => [
                                    'timezone' => $timezone,

                                    'working_day' => $workingDay,

                                    'holiday' => $holiday
                                        ? [
                                            'id' => $holiday->id,

                                            'name' => $holiday->name,

                                            'type' => $holiday
                                                ->holiday_type,

                                            'is_paid' => $holiday->is_paid,
                                        ]
                                        : null,

                                    'raw_punch_count' => $rawPunches
                                        ->count(),

                                    'effective_punch_count' => $effectivePunches
                                        ->count(),

                                    'duplicate_punch_count' => $rawPunches
                                        ->count() -
                                        $effectivePunches
                                            ->count(),

                                    'duplicate_window_seconds' => $duplicateWindowSeconds,

                                    'effective_raw_punch_ids' => $effectivePunches
                                        ->pluck('id')
                                        ->values()
                                        ->all(),

                                    'all_raw_punch_ids' => $rawPunches
                                        ->pluck('id')
                                        ->values()
                                        ->all(),

                                    'late_punch_reconciliation' => $isFinalizedPlaceholder,

                                    'finalization_history' => $finalizationHistory,

                                    'leave_reconciliation_pending' => false,
                                ],

                                'processed_at' => now(),
                            ]
                        );

                /*
                |--------------------------------------------------------------------------
                | Source audit links
                |--------------------------------------------------------------------------
                */

                $rawPunchIds =
                    $rawPunches
                        ->pluck('id')
                        ->map(
                            fn($id) => (int) $id
                        )
                        ->values()
                        ->all();

                $linkedRawPunchIds =
                    collect(
                        $existingRawPunchIds
                    )
                        ->merge(
                            $rawPunchIds
                        )
                        ->map(
                            fn($id) => (int) $id
                        )
                        ->unique()
                        ->values()
                        ->all();

                $record
                    ->rawPunches()
                    ->sync(
                        $linkedRawPunchIds
                    );

                /*
                |--------------------------------------------------------------------------
                | Mark raw punches processed
                |--------------------------------------------------------------------------
                |
                | Only punches participating in the current calculation window
                | are marked processed here. Preserved historical audit links
                | outside this calculation set are not rewritten.
                |
                */

                AttendanceRawPunch::query()
                    ->whereIn(
                        'id',
                        $rawPunchIds
                    )
                    ->update([
                        'processing_status' => 'processed',

                        'processing_error' => null,

                        'processed_at' => now(),

                        'updated_at' => now(),
                    ]);

                return $record;
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Remove business duplicates
    |--------------------------------------------------------------------------
    */

    private function removeBusinessDuplicates(
        Collection $punches,
        int $windowSeconds
    ): Collection {
        $result =
            collect();

        $previousTimestamp =
            null;

        foreach ($punches as $punch) {
            $timestamp =
                CarbonImmutable::parse(
                    $punch->getRawOriginal(
                        'punched_at'
                    ),
                    'UTC'
                );

            if ($previousTimestamp) {
                $seconds =
                    abs(
                        $previousTimestamp
                            ->diffInSeconds(
                                $timestamp
                            )
                    );

                if (
                    $seconds <=
                    $windowSeconds
                ) {
                    continue;
                }
            }

            $result->push(
                $punch
            );

            $previousTimestamp =
                $timestamp;
        }

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | Raw UTC -> local
    |--------------------------------------------------------------------------
    */

    private function rawUtcToLocal(
        AttendanceRawPunch $punch,
        string $timezone
    ): CarbonImmutable {
        return CarbonImmutable::parse(
            $punch->getRawOriginal(
                'punched_at'
            ),
            'UTC'
        )->setTimezone(
                $timezone
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve timezone
    |--------------------------------------------------------------------------
    */

    private function resolveTimezone(
        AttendanceRawPunch $punch
    ): string {
        if (
            $punch->source_timezone
        ) {
            return $punch->source_timezone;
        }

        $device = $punch->device;
        if ($device instanceof AttendanceDevice && $device->timezone) {
            return $device->timezone;
        }

        $company = $punch->company;
        if ($company instanceof Company && $company->timezone) {
            return $company->timezone;
        }

        return 'Asia/Dhaka';
    }

    /*
    |--------------------------------------------------------------------------
    | Integer shift setting with fallback
    |--------------------------------------------------------------------------
    */

    private function integerSetting(
        object $model,
        array $attributes,
        int $default
    ): int {
        foreach ($attributes as $attribute) {
            $value =
                $model->getAttribute(
                    $attribute
                );

            if (
                $value !== null &&
                $value !== ''
            ) {
                return (int) $value;
            }
        }

        return $default;
    }

    /*
    |--------------------------------------------------------------------------
    | Boolean shift setting with fallback
    |--------------------------------------------------------------------------
    */

    private function booleanSetting(
        object $model,
        array $attributes,
        bool $default
    ): bool {
        foreach ($attributes as $attribute) {
            $value =
                $model->getAttribute(
                    $attribute
                );

            if ($value !== null) {
                return (bool) $value;
            }
        }

        return $default;
    }

    /*
    |--------------------------------------------------------------------------
    | Working day detection
    |--------------------------------------------------------------------------
    */

    private function isWorkingDay(
        mixed $workingDays,
        CarbonImmutable $date
    ): bool {
        if (
            empty($workingDays)
        ) {
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
                json_last_error() ===
                JSON_ERROR_NONE
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
            $normalized->contains(
                $fullName
            ) ||
            $normalized->contains(
                $shortName
            )
        ) {
            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Numeric values
        |--------------------------------------------------------------------------
        |
        | ISO:
        | Monday = 1 ... Sunday = 7
        |
        */

        $isoDay =
            (string) 
            $date->dayOfWeekIso;

        if (
            $normalized->contains(
                $isoDay
            )
        ) {
            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Also support Sunday = 0 format
        |--------------------------------------------------------------------------
        */

        if (
            $date->isSunday() &&
            $normalized->contains('0')
        ) {
            return true;
        }

        return false;
    }
}
