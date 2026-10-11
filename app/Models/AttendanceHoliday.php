<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceHoliday extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'branch_id',
        'name',
        'holiday_date',
        'holiday_type',
        'is_paid',
        'status',
        'notes',
    ];

    protected $casts = [
        'holiday_date' => 'date:Y-m-d',
        'is_paid' => 'boolean',
    ];

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

    public function scopeActive($query)
    {
        return $query->where(
            'status',
            'active'
        );
    }
}