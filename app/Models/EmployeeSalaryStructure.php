<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeSalaryStructure extends Model
{
    protected $fillable = [
        'company_id',
        'employee_id',
        'effective_from',
        'effective_to',
        'status',
        'currency',
        'gross_salary',
        'total_earnings',
        'total_deductions',
        'net_salary',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date:Y-m-d',

            'effective_to' => 'date:Y-m-d',

            'gross_salary' => 'decimal:2',

            'total_earnings' => 'decimal:2',

            'total_deductions' => 'decimal:2',

            'net_salary' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(
            Company::class
        );
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(
            Employee::class
        );
    }

    public function items(): HasMany
    {
        return $this->hasMany(
            EmployeeSalaryStructureItem::class
        )->orderBy('sort_order');
    }
}
