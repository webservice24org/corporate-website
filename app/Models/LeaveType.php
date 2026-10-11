<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'code',
        'annual_allowance',
        'is_paid',
        'allow_half_day',
        'carry_forward',
        'max_carry_forward',
        'requires_attachment',
        'exclude_weekly_off',
        'exclude_holidays',
        'status',
        'description',
    ];

    protected $casts = [
        'annual_allowance' =>
            'decimal:2',

        'is_paid' =>
            'boolean',

        'allow_half_day' =>
            'boolean',

        'carry_forward' =>
            'boolean',

        'max_carry_forward' =>
            'decimal:2',

        'requires_attachment' =>
            'boolean',

        'exclude_weekly_off' =>
            'boolean',

        'exclude_holidays' =>
            'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(
            Company::class
        );
    }

    public function requests(): HasMany
    {
        return $this->hasMany(
            EmployeeLeaveRequest::class
        );
    }

    public function balances(): HasMany
    {
        return $this->hasMany(
            EmployeeLeaveBalance::class
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

    public function balanceAdjustments(): HasMany
    {
        return $this->hasMany(
            EmployeeLeaveBalanceAdjustment::class
        );
    }


}