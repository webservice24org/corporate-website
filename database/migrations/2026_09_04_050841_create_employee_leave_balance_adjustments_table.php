<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'employee_leave_balance_adjustments',
            function (Blueprint $table) {
                $table->id();

                $table
                    ->foreignId('employee_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table
                    ->foreignId('leave_type_id')
                    ->constrained()
                    ->restrictOnDelete();

                $table
                    ->unsignedSmallInteger(
                        'year'
                    );

                $table
                    ->decimal(
                        'days',
                        8,
                        2
                    );

                $table
                    ->string(
                        'adjustment_type',
                        30
                    )
                    ->default('manual');

                $table
                    ->text('reason');

                $table
                    ->foreignId(
                        'adjusted_by'
                    )
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->timestamps();

                $table->index(
                    [
                        'employee_id',
                        'leave_type_id',
                        'year',
                    ],
                    'leave_balance_adj_lookup_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'employee_leave_balance_adjustments'
        );
    }
};
