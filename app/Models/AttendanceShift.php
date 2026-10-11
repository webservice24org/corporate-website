<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceShift extends Model
{
    protected $fillable = [
        'company_id',

        'name',
        'code',

        'start_time',
        'end_time',
        'crosses_midnight',

        'check_in_window_before_minutes',
        'check_in_window_after_minutes',

        'check_out_window_before_minutes',
        'check_out_window_after_minutes',

        'grace_minutes',
        'early_leave_grace_minutes',

        'minimum_work_minutes',
        'half_day_minutes',
        'overtime_after_minutes',

        'break_minutes',
        'break_is_paid',

        'working_days',

        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'crosses_midnight' =>
                'boolean',

            'working_days' =>
                'array',

            'break_is_paid' =>
                'boolean',

            'check_in_window_before_minutes' =>
                'integer',

            'check_in_window_after_minutes' =>
                'integer',

            'check_out_window_before_minutes' =>
                'integer',

            'check_out_window_after_minutes' =>
                'integer',

            'grace_minutes' =>
                'integer',

            'early_leave_grace_minutes' =>
                'integer',

            'minimum_work_minutes' =>
                'integer',

            'half_day_minutes' =>
                'integer',

            'overtime_after_minutes' =>
                'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(
            Company::class
        );
    }

    
    public function employeeAssignments(): HasMany
    {
        return $this->hasMany(
            EmployeeShiftAssignment::class,
            'shift_id'
        );
    }
        

    public function scopeActive(
        $query
    ) {
        return $query->where(
            'status',
            'active'
        );
    }
}