<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AttendanceRawPunch extends Model
{
    protected $fillable = [
        'company_id',
        'device_id',
        'employee_id',

        'external_user_id',

        'device_punched_at',
        'punched_at',
        'source_timezone',

        'punch_state',
        'verification_method',

        'source',
        'source_record_id',
        'ingestion_key',

        'raw_payload',

        'mapped_at',

        'processing_status',
        'processing_error',
        'processed_at',

        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'device_punched_at' =>
                'datetime',

            'punched_at' =>
                'datetime',

            'raw_payload' =>
                'array',

            'mapped_at' =>
                'datetime',

            'processed_at' =>
                'datetime',

            'received_at' =>
                'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(
            Company::class
        );
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(
            AttendanceDevice::class,
            'device_id'
        );
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(
            Employee::class
        );
    }

    public function scopePending(
        Builder $query
    ): Builder {
        return $query->where(
            'processing_status',
            'pending'
        );
    }

    public function scopeUnmapped(
        Builder $query
    ): Builder {
        return $query->where(
            'processing_status',
            'unmapped'
        );
    }

    public function attendanceRecords(): BelongsToMany
    {
        return $this->belongsToMany(
            AttendanceRecord::class,
            'attendance_record_punches',
            'raw_punch_id',
            'attendance_record_id'
        )->withTimestamps();
    }


}