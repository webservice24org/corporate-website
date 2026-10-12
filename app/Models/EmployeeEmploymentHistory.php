<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeEmploymentHistory extends Model
{
    protected $fillable = [
        'employee_id',

        'company_id',
        'branch_id',
        'department_id',
        'designation_id',

        'record_type',

        'employer_name',
        'branch_name',
        'department_name',
        'designation_name',

        'employment_type',

        'start_date',
        'end_date',

        'is_current',

        'reason_for_leaving',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',

            'end_date' => 'date:Y-m-d',

            'is_current' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(
            Employee::class
        );
    }

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

    public function department(): BelongsTo
    {
        return $this->belongsTo(
            Department::class
        );
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(
            Designation::class
        );
    }
}
