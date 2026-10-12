<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRecord;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AttendanceReportService
{
    public function query(array $filters): Builder
    {
        return AttendanceRecord::query()
            ->with([
                'employee:id,employee_number,first_name,middle_name,last_name,company_id,branch_id,department_id,designation_id',
                'employee.company:id,name',
                'employee.branch:id,name',
                'employee.department:id,name',
                'employee.designation:id,name',
                'shift:id,name,code',
            ])
            ->when(
                $filters['company_id'] ?? null,
                fn (Builder $query, $companyId) => $query->where(
                    'company_id',
                    $companyId
                )
            )
            ->when(
                $filters['branch_id'] ?? null,
                fn (Builder $query, $branchId) => $query->where(
                    'branch_id',
                    $branchId
                )
            )
            ->when(
                $filters['department_id'] ?? null,
                fn (Builder $query, $departmentId) => $query->where(
                    'department_id',
                    $departmentId
                )
            )
            ->when(
                $filters['employee_id'] ?? null,
                fn (Builder $query, $employeeId) => $query->where(
                    'employee_id',
                    $employeeId
                )
            )
            ->when(
                $filters['attendance_status'] ?? null,
                fn (Builder $query, $status) => $query->where(
                    'attendance_status',
                    $status
                )
            )
            ->when(
                $filters['date_from'] ?? null,
                fn (Builder $query, $date) => $query->whereDate(
                    'attendance_date',
                    '>=',
                    $date
                )
            )
            ->when(
                $filters['date_to'] ?? null,
                fn (Builder $query, $date) => $query->whereDate(
                    'attendance_date',
                    '<=',
                    $date
                )
            );
    }

    public function paginate(
        array $filters,
        int $perPage = 25
    ): LengthAwarePaginator {
        $query =
            ($filters['view'] ?? 'daily') ===
            'exceptions'
                ? $this->exceptionQuery(
                    $filters
                )
                : $this->query(
                    $filters
                );

        return $query
            ->orderByDesc(
                'attendance_date'
            )
            ->orderBy(
                'employee_id'
            )
            ->paginate($perPage)
            ->withQueryString();
    }

    public function summary(
        array $filters
    ): array {
        $query = $this->query($filters)
            ->without([
                'employee',
                'shift',
            ]);

        $total =
            (clone $query)->count();

        $present =
            (clone $query)
                ->where(
                    'attendance_status',
                    'present'
                )
                ->count();

        $absent =
            (clone $query)
                ->where(
                    'attendance_status',
                    'absent'
                )
                ->count();

        $leave =
            (clone $query)
                ->where(
                    'attendance_status',
                    'leave'
                )
                ->count();

        $offDay =
            (clone $query)
                ->where(
                    'attendance_status',
                    'off_day'
                )
                ->count();

        $offDayWork =
            (clone $query)
                ->where(
                    'attendance_status',
                    'off_day_work'
                )
                ->count();

        $late =
            (clone $query)
                ->where(
                    'late_minutes',
                    '>',
                    0
                )
                ->count();

        $earlyLeave =
            (clone $query)
                ->where(
                    'early_leave_minutes',
                    '>',
                    0
                )
                ->count();

        $overtime =
            (clone $query)
                ->where(
                    'overtime_minutes',
                    '>',
                    0
                )
                ->count();

        return [
            'total_records' => $total,

            'present' => $present,

            'absent' => $absent,

            'leave' => $leave,

            'off_day' => $offDay,

            'off_day_work' => $offDayWork,

            'late' => $late,

            'early_leave' => $earlyLeave,

            'overtime' => $overtime,

            'late_minutes' => (int)
                (clone $query)
                    ->sum(
                        'late_minutes'
                    ),

            'early_leave_minutes' => (int)
                (clone $query)
                    ->sum(
                        'early_leave_minutes'
                    ),

            'overtime_minutes' => (int)
                (clone $query)
                    ->sum(
                        'overtime_minutes'
                    ),

            'worked_minutes' => (int)
                (clone $query)
                    ->sum(
                        'worked_minutes'
                    ),
        ];
    }

    public function statusSummary(
        array $filters
    ): Collection {
        return $this->query($filters)
            ->without([
                'employee',
                'shift',
            ])
            ->selectRaw(
                '
                attendance_status,
                COUNT(*) as total,
                SUM(worked_minutes) as worked_minutes,
                SUM(late_minutes) as late_minutes,
                SUM(early_leave_minutes) as early_leave_minutes,
                SUM(overtime_minutes) as overtime_minutes
                '
            )
            ->groupBy(
                'attendance_status'
            )
            ->orderBy(
                'attendance_status'
            )
            ->get();
    }

    public function employeeSummary(
        array $filters
    ): Collection {
        return $this->query($filters)
            ->without([
                'employee',
                'shift',
            ])
            ->selectRaw(
                '
                employee_id,
                COUNT(*) as total_days,

                SUM(
                    CASE
                        WHEN attendance_status = "present"
                        THEN 1
                        ELSE 0
                    END
                ) as present_days,

                SUM(
                    CASE
                        WHEN attendance_status = "absent"
                        THEN 1
                        ELSE 0
                    END
                ) as absent_days,

                SUM(
                    CASE
                        WHEN attendance_status = "leave"
                        THEN 1
                        ELSE 0
                    END
                ) as leave_days,

                SUM(
                    CASE
                        WHEN attendance_status = "off_day"
                        THEN 1
                        ELSE 0
                    END
                ) as off_days,

                SUM(
                    CASE
                        WHEN attendance_status = "off_day_work"
                        THEN 1
                        ELSE 0
                    END
                ) as off_day_work_days,

                SUM(
                    CASE
                        WHEN late_minutes > 0
                        THEN 1
                        ELSE 0
                    END
                ) as late_days,

                SUM(
                    CASE
                        WHEN early_leave_minutes > 0
                        THEN 1
                        ELSE 0
                    END
                ) as early_leave_days,

                SUM(late_minutes)
                    as late_minutes,

                SUM(early_leave_minutes)
                    as early_leave_minutes,

                SUM(overtime_minutes)
                    as overtime_minutes,

                SUM(worked_minutes)
                    as worked_minutes
                '
            )
            ->groupBy(
                'employee_id'
            )
            ->with([
                'employee:id,employee_number,first_name,middle_name,last_name,company_id,branch_id,department_id',
                'employee.company:id,name',
                'employee.branch:id,name',
                'employee.department:id,name',
            ])
            ->orderBy(
                'employee_id'
            )
            ->get();
    }

    public function exceptionQuery(
        array $filters
    ): Builder {
        $type =
            $filters['exception_type']
                ?? 'all';

        $query =
            $this->query(
                $filters
            );

        return match ($type) {
            'late' => $query->where(
                'late_minutes',
                '>',
                0
            ),

            'early_leave' => $query->where(
                'early_leave_minutes',
                '>',
                0
            ),

            'overtime' => $query->where(
                'overtime_minutes',
                '>',
                0
            ),

            default => $query->where(
                function (
                    Builder $query
                ) {
                    $query
                        ->where(
                            'late_minutes',
                            '>',
                            0
                        )
                        ->orWhere(
                            'early_leave_minutes',
                            '>',
                            0
                        )
                        ->orWhere(
                            'overtime_minutes',
                            '>',
                            0
                        );
                }
            ),
        };
    }

    public function defaultDates(): array
    {
        $today =
            CarbonImmutable::today();

        return [
            'date_from' => $today
                ->startOfMonth()
                ->toDateString(),

            'date_to' => $today
                ->toDateString(),
        ];
    }
}
