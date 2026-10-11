<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollSetting extends Model
{
    protected $fillable = [
        'company_id',
        'pay_frequency',
        'salary_proration_basis',
        'default_currency',
        'default_pay_day',
        'attendance_integration_enabled',
        'require_finalized_attendance',
        'attendance_deduction_enabled',
        'unpaid_leave_deduction_enabled',
        'overtime_enabled',
        'overtime_rate_multiplier',
        'auto_include_recurring_components',
        'rounding_method',
        'rounding_precision',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'default_pay_day' => 'integer',

            'attendance_integration_enabled' => 'boolean',
            'require_finalized_attendance' => 'boolean',
            'attendance_deduction_enabled' => 'boolean',
            'unpaid_leave_deduction_enabled' => 'boolean',

            'overtime_enabled' => 'boolean',

            'overtime_rate_multiplier' =>
                'decimal:4',

            'auto_include_recurring_components' => 'boolean',

            'rounding_precision' => 'integer',

            'status' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(
            Company::class
        );
    }
}