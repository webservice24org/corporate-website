<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('pay_frequency', 20)
                ->default('monthly');
            // monthly | biweekly | weekly

            $table->string('salary_proration_basis', 30)
                ->default('calendar_days');
            // calendar_days | working_days | fixed_30_days

            $table->string('default_currency', 3)
                ->default('BDT');

            $table->unsignedTinyInteger('default_pay_day')
                ->nullable();
            // 1-31, mainly for monthly payroll

            $table->boolean('attendance_integration_enabled')
                ->default(true);

            $table->boolean('require_finalized_attendance')
                ->default(true);

            $table->boolean('attendance_deduction_enabled')
                ->default(false);

            $table->boolean('unpaid_leave_deduction_enabled')
                ->default(true);

            $table->boolean('overtime_enabled')
                ->default(true);

            $table->decimal(
                'overtime_rate_multiplier',
                8,
                4
            )->default(1.5000);

            $table->boolean('auto_include_recurring_components')
                ->default(true);

            $table->string('rounding_method', 20)
                ->default('none');
            // none | nearest | up | down

            $table->unsignedTinyInteger('rounding_precision')
                ->default(2);
            // 0 | 2

            $table->boolean('status')
                ->default(true);

            $table->text('notes')
                ->nullable();

            $table->timestamps();

            $table->unique(
                'company_id',
                'payroll_settings_company_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_settings');
    }
};
