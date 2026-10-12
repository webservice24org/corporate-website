<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSalaryStructureItem extends Model
{
    protected $fillable = [
        'employee_salary_structure_id',
        'salary_component_id',
        'component_name',
        'component_code',
        'component_type',
        'calculation_type',
        'percentage_of_component_id',
        'amount',
        'percentage',
        'calculated_amount',
        'is_taxable',
        'affects_gross_salary',
        'is_recurring',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',

            'percentage' => 'decimal:4',

            'calculated_amount' => 'decimal:2',

            'is_taxable' => 'boolean',

            'affects_gross_salary' => 'boolean',

            'is_recurring' => 'boolean',

            'sort_order' => 'integer',
        ];
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(
            EmployeeSalaryStructure::class,
            'employee_salary_structure_id'
        );
    }

    public function salaryComponent(): BelongsTo
    {
        return $this->belongsTo(
            SalaryComponent::class
        );
    }

    public function percentageOfComponent(): BelongsTo
    {
        return $this->belongsTo(
            SalaryComponent::class,
            'percentage_of_component_id'
        );
    }
}
