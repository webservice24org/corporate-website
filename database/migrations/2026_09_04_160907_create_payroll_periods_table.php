<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'payroll_periods',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId('company_id')
                    ->constrained()
                    ->restrictOnDelete();

                $table->string(
                    'name',
                    100
                );

                $table->string(
                    'code',
                    50
                );

                $table->date(
                    'start_date'
                );

                $table->date(
                    'end_date'
                );

                $table->date(
                    'payment_date'
                )->nullable();

                $table->string(
                    'status',
                    20
                )->default('draft');
                // draft | open | processing | closed

                $table->boolean(
                    'is_locked'
                )->default(false);

                $table->text(
                    'notes'
                )->nullable();

                $table->timestamps();

                $table->unique(
                    [
                        'company_id',
                        'code',
                    ],
                    'payroll_period_company_code_unique'
                );

                $table->index(
                    [
                        'company_id',
                        'start_date',
                        'end_date',
                    ],
                    'payroll_period_company_dates_idx'
                );

                $table->index(
                    [
                        'company_id',
                        'status',
                    ],
                    'payroll_period_company_status_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'payroll_periods'
        );
    }
};