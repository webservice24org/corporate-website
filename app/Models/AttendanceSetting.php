<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceSetting extends Model
{
    protected $fillable = [
        'company_id',

        'timezone',

        'grace_minutes',
        'early_leave_grace_minutes',

        'half_day_minutes',
        'minimum_full_day_minutes',
        'overtime_after_minutes',

        'duplicate_punch_window_seconds',

        'auto_pair_punches',
        'allow_manual_attendance',
        'allow_multiple_checkins',

        'mark_absent_automatically',
        'count_weekends_as_absent',
        'count_holidays_as_absent',

        'device_attendance_enabled',
        'default_punch_source',

        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'grace_minutes' =>
                'integer',

            'early_leave_grace_minutes' =>
                'integer',

            'half_day_minutes' =>
                'integer',

            'minimum_full_day_minutes' =>
                'integer',

            'overtime_after_minutes' =>
                'integer',

            'duplicate_punch_window_seconds' =>
                'integer',

            'auto_pair_punches' =>
                'boolean',

            'allow_manual_attendance' =>
                'boolean',

            'allow_multiple_checkins' =>
                'boolean',

            'mark_absent_automatically' =>
                'boolean',

            'count_weekends_as_absent' =>
                'boolean',

            'count_holidays_as_absent' =>
                'boolean',

            'device_attendance_enabled' =>
                'boolean',

            'is_active' =>
                'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(
            Company::class
        );
    }
}