<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalaryComponent extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'code',
        'component_type',
        'calculation_type',
        'percentage_of_component_id',
        'default_amount',
        'default_percentage',
        'is_taxable',
        'affects_gross_salary',
        'is_recurring',
        'status',
        'sort_order',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'default_amount' =>
                'decimal:2',

            'default_percentage' =>
                'decimal:4',

            'is_taxable' =>
                'boolean',

            'affects_gross_salary' =>
                'boolean',

            'is_recurring' =>
                'boolean',

            'status' =>
                'boolean',

            'sort_order' =>
                'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(
            Company::class
        );
    }

    public function percentageOfComponent(): BelongsTo
    {
        return $this->belongsTo(
            self::class,
            'percentage_of_component_id'
        );
    }

    public function dependentComponents(): HasMany
    {
        return $this->hasMany(
            self::class,
            'percentage_of_component_id'
        );
    }

    public function scopeActive(
        Builder $query
    ): Builder {
        return $query->where(
            'status',
            true
        );
    }

    public function scopeEarnings(
        Builder $query
    ): Builder {
        return $query->where(
            'component_type',
            'earning'
        );
    }

    public function scopeDeductions(
        Builder $query
    ): Builder {
        return $query->where(
            'component_type',
            'deduction'
        );
    }
    public function salaryStructureItems(): HasMany
    {
        return $this->hasMany(
            EmployeeSalaryStructureItem::class
        );
    }





}