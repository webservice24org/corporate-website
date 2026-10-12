<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'employee_shift_assignments',
            function (Blueprint $table) {
                $table->id();

                $table
                    ->foreignId('employee_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table
                    ->foreignId('shift_id')
                    ->constrained('attendance_shifts')
                    ->restrictOnDelete();

                $table
                    ->foreignId('assigned_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                /*
                 |--------------------------------------------------------------------------
                 | Effective period
                 |--------------------------------------------------------------------------
                 |
                 | effective_to = null means:
                 | "continues until changed"
                 |
                 */

                $table->date(
                    'effective_from'
                );

                $table
                    ->date('effective_to')
                    ->nullable();

                /*
                 |--------------------------------------------------------------------------
                 | Status
                 |--------------------------------------------------------------------------
                 */

                $table
                    ->string(
                        'status',
                        20
                    )
                    ->default('active');

                $table
                    ->text('notes')
                    ->nullable();

                $table->timestamps();

                $table->index(
                    [
                        'employee_id',
                        'status',
                    ],
                    'emp_shift_employee_status_idx'
                );

                $table->index(
                    [
                        'shift_id',
                        'status',
                    ],
                    'emp_shift_shift_status_idx'
                );

                $table->index(
                    [
                        'employee_id',
                        'effective_from',
                        'effective_to',
                    ],
                    'emp_shift_effective_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'employee_shift_assignments'
        );
    }
};
