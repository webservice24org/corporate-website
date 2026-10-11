<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceDeviceUser extends Model
{
    protected $fillable = [
        'device_id',
        'employee_id',
        'mapped_by',

        'external_user_id',
        'card_number',

        'sync_status',
        'last_synced_at',
        'sync_error',

        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'last_synced_at' =>
                'datetime',
        ];
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

    public function mappedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'mapped_by'
        );
    }

    public function scopeActive(
        Builder $query
    ): Builder {
        return $query->where(
            'status',
            'active'
        );
    }

    public function rawUnmappedPunches(): HasMany
    {
        return $this->hasMany(
            AttendanceRawPunch::class,
            'device_id',
            'device_id'
        )
            ->whereColumn(
                'attendance_raw_punches.external_user_id',
                'attendance_device_users.external_user_id'
            )
            ->whereNull(
                'attendance_raw_punches.employee_id'
            )
            ->where(
                'attendance_raw_punches.processing_status',
                'unmapped'
            );
    }

}