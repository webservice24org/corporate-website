<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'employee_salary_structures',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId('company_id')
                    ->constrained()
                    ->restrictOnDelete();

                $table->foreignId('employee_id')
                    ->constrained()
                    ->restrictOnDelete();

                $table->date('effective_from');

                $table->date('effective_to')
                    ->nullable();

                $table->string('status', 20)
                    ->default('active');

                $table->string('currency', 3)
                    ->default('BDT');

                $table->decimal(
                    'gross_salary',
                    15,
                    2
                )->default(0);

                $table->decimal(
                    'total_earnings',
                    15,
                    2
                )->default(0);

                $table->decimal(
                    'total_deductions',
                    15,
                    2
                )->default(0);

                $table->decimal(
                    'net_salary',
                    15,
                    2
                )->default(0);

                $table->text('notes')
                    ->nullable();

                $table->timestamps();

                $table->index(
                    [
                        'employee_id',
                        'effective_from',
                        'effective_to',
                    ],
                    'emp_salary_effective_idx'
                );

                $table->index(
                    [
                        'company_id',
                        'status',
                    ],
                    'emp_salary_company_status_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'employee_salary_structures'
        );
    }
};