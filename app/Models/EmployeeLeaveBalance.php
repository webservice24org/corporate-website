<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeLeaveBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'year',
        'opening_balance',
        'entitled_days',
        'adjustment_days',
        'used_days',
        'pending_days',
    ];

    protected $casts = [
        'year' => 'integer',
        'opening_balance' => 'decimal:2',
        'entitled_days' => 'decimal:2',
        'adjustment_days' => 'decimal:2',
        'used_days' => 'decimal:2',
        'pending_days' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(
            Employee::class
        );
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(
            LeaveType::class
        );
    }

    public function getAvailableDaysAttribute(): float
    {
        return
            (float) $this->opening_balance +
            (float) $this->entitled_days +
            (float) $this->adjustment_days -
            (float) $this->used_days -
            (float) $this->pending_days;
    }
}
