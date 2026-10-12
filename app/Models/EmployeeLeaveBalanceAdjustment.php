<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeLeaveBalanceAdjustment extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'year',
        'days',
        'adjustment_type',
        'reason',
        'adjusted_by',
    ];

    protected $casts = [
        'year' => 'integer',

        'days' => 'decimal:2',
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

    public function adjustedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'adjusted_by'
        );
    }
}
