<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'employee_employment_histories',
            function (Blueprint $table) {
                $table->id();

                $table
                    ->foreignId('employee_id')
                    ->constrained()
                    ->cascadeOnDelete();

                /*
                 * Internal organization links.
                 *
                 * Nullable because an employee
                 * may have external previous
                 * employment.
                 */
                $table
                    ->foreignId('company_id')
                    ->nullable()
                    ->constrained()
                    ->nullOnDelete();

                $table
                    ->foreignId('branch_id')
                    ->nullable()
                    ->constrained()
                    ->nullOnDelete();

                $table
                    ->foreignId('department_id')
                    ->nullable()
                    ->constrained()
                    ->nullOnDelete();

                $table
                    ->foreignId('designation_id')
                    ->nullable()
                    ->constrained()
                    ->nullOnDelete();

                $table
                    ->string(
                        'record_type',
                        20
                    )
                    ->default('internal');

                /*
                 * Historical snapshots.
                 *
                 * These remain even if the
                 * linked organization record
                 * later changes or is deleted.
                 */
                $table
                    ->string('employer_name')
                    ->nullable();

                $table
                    ->string('branch_name')
                    ->nullable();

                $table
                    ->string('department_name')
                    ->nullable();

                $table
                    ->string('designation_name')
                    ->nullable();

                $table
                    ->string(
                        'employment_type',
                        30
                    )
                    ->nullable();

                $table->date(
                    'start_date'
                );

                $table
                    ->date('end_date')
                    ->nullable();

                $table
                    ->boolean('is_current')
                    ->default(false);

                $table
                    ->string(
                        'reason_for_leaving'
                    )
                    ->nullable();

                $table
                    ->text('notes')
                    ->nullable();

                $table->timestamps();

                $table->index([
                    'employee_id',
                    'start_date',
                ]);

                $table->index([
                    'employee_id',
                    'is_current',
                ]);

                $table->index([
                    'employee_id',
                    'record_type',
                ]);
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'employee_employment_histories'
        );
    }
};
