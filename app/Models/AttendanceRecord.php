<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AttendanceRecord extends Model
{
    protected $fillable = [
        'company_id',
        'employee_id',
        'shift_id',

        'branch_id',
        'department_id',
        'designation_id',

        'attendance_date',

        'scheduled_start_at',
        'scheduled_end_at',

        'check_in_at',
        'check_out_at',

        'worked_minutes',
        'break_minutes',
        'late_minutes',
        'early_leave_minutes',
        'overtime_minutes',
        'punch_count',

        'attendance_status',

        'source',
        'is_manual',

        'calculation_details',
        'notes',

        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date:Y-m-d',

            'scheduled_start_at' => 'datetime',

            'scheduled_end_at' => 'datetime',

            'check_in_at' => 'datetime',

            'check_out_at' => 'datetime',

            'worked_minutes' => 'integer',

            'break_minutes' => 'integer',

            'late_minutes' => 'integer',

            'early_leave_minutes' => 'integer',

            'overtime_minutes' => 'integer',

            'punch_count' => 'integer',

            'is_manual' => 'boolean',

            'calculation_details' => 'array',

            'processed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(
            Company::class
        );
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(
            Employee::class
        );
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(
            AttendanceShift::class,
            'shift_id'
        );
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(
            Branch::class
        );
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(
            Department::class
        );
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(
            Designation::class
        );
    }

    public function rawPunches(): BelongsToMany
    {
        return $this->belongsToMany(
            AttendanceRawPunch::class,
            'attendance_record_punches',
            'attendance_record_id',
            'raw_punch_id'
        )->withTimestamps();
    }
}
