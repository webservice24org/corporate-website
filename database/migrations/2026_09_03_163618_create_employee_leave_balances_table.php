<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'employee_leave_balances',
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
                    ->unsignedSmallInteger('year');

                $table
                    ->decimal(
                        'opening_balance',
                        8,
                        2
                    )
                    ->default(0);

                $table
                    ->decimal(
                        'entitled_days',
                        8,
                        2
                    )
                    ->default(0);

                $table
                    ->decimal(
                        'adjustment_days',
                        8,
                        2
                    )
                    ->default(0);

                $table
                    ->decimal(
                        'used_days',
                        8,
                        2
                    )
                    ->default(0);

                $table
                    ->decimal(
                        'pending_days',
                        8,
                        2
                    )
                    ->default(0);

                $table->timestamps();

                $table->unique(
                    [
                        'employee_id',
                        'leave_type_id',
                        'year',
                    ],
                    'employee_leave_balance_unique'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'employee_leave_balances'
        );
    }
};