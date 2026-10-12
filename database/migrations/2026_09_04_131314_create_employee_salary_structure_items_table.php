<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'employee_salary_structure_items',
            function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger(
                    'employee_salary_structure_id'
                );

                $table->unsignedBigInteger(
                    'salary_component_id'
                );

                $table->string(
                    'component_name'
                );

                $table->string(
                    'component_code',
                    50
                );

                $table->string(
                    'component_type',
                    20
                );

                $table->string(
                    'calculation_type',
                    20
                );

                $table->unsignedBigInteger(
                    'percentage_of_component_id'
                )->nullable();

                $table->decimal(
                    'amount',
                    15,
                    2
                )->default(0);

                $table->decimal(
                    'percentage',
                    8,
                    4
                )->default(0);

                $table->decimal(
                    'calculated_amount',
                    15,
                    2
                )->default(0);

                $table->boolean(
                    'is_taxable'
                )->default(false);

                $table->boolean(
                    'affects_gross_salary'
                )->default(true);

                $table->boolean(
                    'is_recurring'
                )->default(true);

                $table->unsignedInteger(
                    'sort_order'
                )->default(0);

                $table->timestamps();

                $table->foreign(
                    'employee_salary_structure_id',
                    'emp_salary_item_structure_fk'
                )
                    ->references('id')
                    ->on(
                        'employee_salary_structures'
                    )
                    ->cascadeOnDelete();

                $table->foreign(
                    'salary_component_id',
                    'emp_salary_item_component_fk'
                )
                    ->references('id')
                    ->on(
                        'salary_components'
                    )
                    ->restrictOnDelete();

                $table->foreign(
                    'percentage_of_component_id',
                    'emp_salary_item_percent_base_fk'
                )
                    ->references('id')
                    ->on(
                        'salary_components'
                    )
                    ->restrictOnDelete();

                $table->unique(
                    [
                        'employee_salary_structure_id',
                        'salary_component_id',
                    ],
                    'emp_salary_item_component_unique'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'employee_salary_structure_items'
        );
    }
};
