<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'code',
        'email',
        'phone',
        'address',
        'city',
        'state',
        'postal_code',
        'country',
        'timezone',
        'status',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(
            Company::class
        );
    }

    public function scopeActive($query)
    {
        return $query->where(
            'status',
            'active'
        );
    }

    public function departments(): HasMany
    {
        return $this->hasMany(
            Department::class
        );
    }

    public function employees(): HasMany
    {
        return $this->hasMany(
            Employee::class
        );
    }

    public function attendanceDevices(): HasMany
    {
        return $this->hasMany(
            AttendanceDevice::class
        );
    }

    public function attendanceHolidays(): HasMany
    {
        return $this->hasMany(
            AttendanceHoliday::class
        );
    }



}