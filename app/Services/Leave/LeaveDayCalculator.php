<?php

namespace App\Services\Leave;

use App\Models\AttendanceHoliday;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\LeaveType;
use Carbon\CarbonImmutable;

class LeaveDayCalculator
{
    public function calculate(
        Employee $employee,
        LeaveType $leaveType,
        string $startDate,
        string $endDate,
        string $durationType = 'full_day'
    ): float {
        if (
            $durationType === 'half_day'
        ) {
            return 0.5;
        }

        $start =
            CarbonImmutable::parse(
                $startDate
            );

        $end =
            CarbonImmutable::parse(
                $endDate
            );

        $days = 0.0;

        for (
            $date = $start;
            $date->lessThanOrEqualTo($end);
            $date = $date->addDay()
        ) {
            if (
                $leaveType->exclude_holidays &&
                $this->isHoliday(
                    $employee,
                    $date->toDateString()
                )
            ) {
                continue;
            }

            if (
                $leaveType->exclude_weekly_off &&
                !$this->isWorkingDay(
                    $employee,
                    $date
                )
            ) {
                continue;
            }

            $days += 1;
        }

        return $days;
    }

    private function isHoliday(
        Employee $employee,
        string $date
    ): bool {
        return AttendanceHoliday::query()
            ->where(
                'company_id',
                $employee->company_id
            )
            ->whereDate(
                'holiday_date',
                $date
            )
            ->where(
                'status',
                'active'
            )
            ->where(
                function ($query) use (
                    $employee
                ) {
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
            ->exists();
    }

    private function isWorkingDay(
        Employee $employee,
        CarbonImmutable $date
    ): bool {
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
                    $date->toDateString()
                )
                ->where(
                    function ($query) use (
                        $date
                    ) {
                        $query
                            ->whereNull(
                                'effective_to'
                            )
                            ->orWhereDate(
                                'effective_to',
                                '>=',
                                $date->toDateString()
                            );
                    }
                )
                ->orderByDesc(
                    'effective_from'
                )
                ->first();

        if (
            !$assignment?->shift
        ) {
            return true;
        }

        $workingDays =
            $assignment
                ->shift
                ->working_days;

        if (empty($workingDays)) {
            return true;
        }

        if (
            is_string(
                $workingDays
            )
        ) {
            $workingDays =
                json_decode(
                    $workingDays,
                    true
                ) ?: [];
        }

        $normalized =
            collect($workingDays)
                ->map(
                    fn ($day) =>
                        strtolower(
                            trim(
                                (string) $day
                            )
                        )
                );

        if (
            $normalized->contains(
                strtolower(
                    $date->format('l')
                )
            )
        ) {
            return true;
        }

        if (
            $normalized->contains(
                strtolower(
                    $date->format('D')
                )
            )
        ) {
            return true;
        }

        if (
            $normalized->contains(
                (string)
                $date->dayOfWeekIso
            )
        ) {
            return true;
        }

        return
            $date->isSunday() &&
            $normalized->contains('0');
    }
}