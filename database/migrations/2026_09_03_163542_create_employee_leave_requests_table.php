<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'employee_leave_requests',
            function (Blueprint $table) {
                $table->id();

                $table
                    ->foreignId('employee_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table
                    ->foreignId('company_id')
                    ->constrained()
                    ->restrictOnDelete();

                $table
                    ->foreignId('leave_type_id')
                    ->constrained()
                    ->restrictOnDelete();

                $table->date('start_date');
                $table->date('end_date');

                $table
                    ->string(
                        'duration_type',
                        20
                    )
                    ->default('full_day');

                $table
                    ->string(
                        'half_day_period',
                        20
                    )
                    ->nullable();

                $table
                    ->decimal(
                        'total_days',
                        8,
                        2
                    );

                $table->text('reason')
                    ->nullable();

                $table
                    ->string(
                        'attachment'
                    )
                    ->nullable();

                $table
                    ->string(
                        'status',
                        30
                    )
                    ->default('pending');

                $table
                    ->foreignId('approved_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table
                    ->timestamp('approved_at')
                    ->nullable();

                $table
                    ->foreignId('rejected_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table
                    ->timestamp('rejected_at')
                    ->nullable();

                $table->text(
                    'rejection_reason'
                )->nullable();

                $table->timestamp(
                    'cancelled_at'
                )->nullable();

                $table->timestamps();

                $table->index(
                    [
                        'employee_id',
                        'status',
                    ],
                    'leave_req_employee_status_idx'
                );

                $table->index(
                    [
                        'company_id',
                        'status',
                    ],
                    'leave_req_company_status_idx'
                );

                $table->index(
                    [
                        'start_date',
                        'end_date',
                    ],
                    'leave_req_dates_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'employee_leave_requests'
        );
    }
};
