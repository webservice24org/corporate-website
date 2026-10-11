<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeShiftAssignment extends Model
{
    protected $fillable = [
        'employee_id',
        'shift_id',
        'assigned_by',

        'effective_from',
        'effective_to',

        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' =>
                'date:Y-m-d',

            'effective_to' =>
                'date:Y-m-d',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(
            Employee::class
        );
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(
            AttendanceShift::class,
            'shift_id'
        );
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'assigned_by'
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

    public function scopeEffectiveOn(
        Builder $query,
        string $date
    ): Builder {
        return $query
            ->where(
                'status',
                'active'
            )
            ->whereDate(
                'effective_from',
                '<=',
                $date
            )
            ->where(
                function (
                    Builder $query
                ) use ($date) {
                    $query
                        ->whereNull(
                            'effective_to'
                        )
                        ->orWhereDate(
                            'effective_to',
                            '>=',
                            $date
                        );
                }
            );
    }
}