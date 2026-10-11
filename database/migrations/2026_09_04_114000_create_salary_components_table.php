<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_components', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('name');
            $table->string('code', 50);

            $table->string('component_type', 20);
            // earning | deduction

            $table->string('calculation_type', 20)
                ->default('fixed');
            // fixed | percentage

            $table->foreignId('percentage_of_component_id')
                ->nullable()
                ->constrained('salary_components')
                ->restrictOnDelete();

            $table->decimal(
                'default_amount',
                15,
                2
            )->default(0);

            $table->decimal(
                'default_percentage',
                8,
                4
            )->default(0);

            $table->boolean('is_taxable')
                ->default(false);

            $table->boolean('affects_gross_salary')
                ->default(true);

            $table->boolean('is_recurring')
                ->default(true);

            $table->boolean('status')
                ->default(true);

            $table->unsignedInteger('sort_order')
                ->default(0);

            $table->text('description')
                ->nullable();

            $table->timestamps();

            $table->unique(
                [
                    'company_id',
                    'code',
                ],
                'salary_components_company_code_unique'
            );

            $table->index(
                [
                    'company_id',
                    'component_type',
                    'status',
                ],
                'salary_components_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'salary_components'
        );
    }
};