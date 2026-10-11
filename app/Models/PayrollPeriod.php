<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollPeriod extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'code',
        'start_date',
        'end_date',
        'payment_date',
        'status',
        'is_locked',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'start_date' =>
                'date:Y-m-d',

            'end_date' =>
                'date:Y-m-d',

            'payment_date' =>
                'date:Y-m-d',

            'is_locked' =>
                'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(
            Company::class
        );
    }

    public function scopeDraft(
        Builder $query
    ): Builder {
        return $query->where(
            'status',
            'draft'
        );
    }

    public function scopeOpen(
        Builder $query
    ): Builder {
        return $query->where(
            'status',
            'open'
        );
    }

    public function scopeClosed(
        Builder $query
    ): Builder {
        return $query->where(
            'status',
            'closed'
        );
    }
}