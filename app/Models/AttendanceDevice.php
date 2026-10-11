<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceDevice extends Model
{
    protected $fillable = [
        'company_id',
        'branch_id',

        'name',
        'code',

        'provider',
        'model',
        'serial_number',

        'integration_mode',

        'ip_address',
        'port',
        'base_url',

        'connection_config',

        'timezone',

        'last_seen_at',
        'last_sync_at',

        'status',
        'notes',
    ];

    protected $hidden = [
        'connection_config',
    ];

    protected function casts(): array
    {
        return [
            'port' =>
                'integer',

            'connection_config' =>
                'encrypted:array',

            'last_seen_at' =>
                'datetime',

            'last_sync_at' =>
                'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(
            Company::class
        );
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(
            Branch::class
        );
    }

    public function users(): HasMany
    {
        return $this->hasMany(
            AttendanceDeviceUser::class,
            'device_id'
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

    public function rawPunches(): HasMany
    {
        return $this->hasMany(
            AttendanceRawPunch::class,
            'device_id'
        );
    }




}