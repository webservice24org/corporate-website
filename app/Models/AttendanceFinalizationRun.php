<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceFinalizationRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'run_by',
        'attendance_date',
        'status',
        'employees_checked',
        'absent_created',
        'off_day_created',
        'off_day_work_updated',
        'existing_attendance_skipped',
        'manual_records_skipped',
        'no_shift_skipped',
        'result_summary',
        'notes',
    ];

    protected $casts = [
        'attendance_date' =>
            'date:Y-m-d',

        'result_summary' =>
            'array',

        'employees_checked' =>
            'integer',

        'absent_created' =>
            'integer',

        'off_day_created' =>
            'integer',

        'off_day_work_updated' =>
            'integer',

        'existing_attendance_skipped' =>
            'integer',

        'manual_records_skipped' =>
            'integer',

        'no_shift_skipped' =>
            'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(
            Company::class
        );
    }

    public function runBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'run_by'
        );
    }
}