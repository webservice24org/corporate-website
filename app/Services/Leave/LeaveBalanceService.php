<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\EmployeeLeaveBalance;
use App\Models\EmployeeLeaveBalanceAdjustment;
use App\Models\EmployeeLeaveRequest;
use App\Models\LeaveType;

class LeaveBalanceService
{
    public function getOrCreate(
        Employee $employee,
        LeaveType $leaveType,
        int $year
    ): EmployeeLeaveBalance {
        return EmployeeLeaveBalance::firstOrCreate(
            [
                'employee_id' => $employee->id,

                'leave_type_id' => $leaveType->id,

                'year' => $year,
            ],
            [
                'opening_balance' => 0,

                'entitled_days' => $leaveType
                    ->annual_allowance,

                'adjustment_days' => 0,

                'used_days' => 0,

                'pending_days' => 0,
            ]
        );
    }

    public function recalculate(
        Employee $employee,
        LeaveType $leaveType,
        int $year
    ): EmployeeLeaveBalance {
        $balance =
            $this->getOrCreate(
                $employee,
                $leaveType,
                $year
            );

        /*
        |--------------------------------------------------------------------------
        | Approved leave
        |--------------------------------------------------------------------------
        */

        $used =
            EmployeeLeaveRequest::query()
                ->where(
                    'employee_id',
                    $employee->id
                )
                ->where(
                    'leave_type_id',
                    $leaveType->id
                )
                ->where(
                    'status',
                    'approved'
                )
                ->whereYear(
                    'start_date',
                    $year
                )
                ->sum(
                    'total_days'
                );

        /*
        |--------------------------------------------------------------------------
        | Pending leave
        |--------------------------------------------------------------------------
        */

        $pending =
            EmployeeLeaveRequest::query()
                ->where(
                    'employee_id',
                    $employee->id
                )
                ->where(
                    'leave_type_id',
                    $leaveType->id
                )
                ->where(
                    'status',
                    'pending'
                )
                ->whereYear(
                    'start_date',
                    $year
                )
                ->sum(
                    'total_days'
                );

        /*
        |--------------------------------------------------------------------------
        | Audited adjustments
        |--------------------------------------------------------------------------
        */

        $adjustments =
            EmployeeLeaveBalanceAdjustment::query()
                ->where(
                    'employee_id',
                    $employee->id
                )
                ->where(
                    'leave_type_id',
                    $leaveType->id
                )
                ->where(
                    'year',
                    $year
                )
                ->sum('days');

        $balance->update([
            'entitled_days' => $leaveType
                ->annual_allowance,

            'used_days' => $used,

            'pending_days' => $pending,

            'adjustment_days' => $adjustments,
        ]);

        return $balance->refresh();
    }
}
