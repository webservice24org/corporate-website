<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'attendance_finalization_runs',
            function (Blueprint $table) {
                $table->id();

                $table
                    ->foreignId('company_id')
                    ->constrained()
                    ->restrictOnDelete();

                $table
                    ->foreignId('run_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->date(
                    'attendance_date'
                );

                $table
                    ->string('status', 20)
                    ->default('completed');

                $table
                    ->unsignedInteger(
                        'employees_checked'
                    )
                    ->default(0);

                $table
                    ->unsignedInteger(
                        'absent_created'
                    )
                    ->default(0);

                $table
                    ->unsignedInteger(
                        'off_day_created'
                    )
                    ->default(0);

                $table
                    ->unsignedInteger(
                        'off_day_work_updated'
                    )
                    ->default(0);

                $table
                    ->unsignedInteger(
                        'existing_attendance_skipped'
                    )
                    ->default(0);

                $table
                    ->unsignedInteger(
                        'manual_records_skipped'
                    )
                    ->default(0);

                $table
                    ->unsignedInteger(
                        'no_shift_skipped'
                    )
                    ->default(0);

                $table->json(
                    'result_summary'
                )->nullable();

                $table->text(
                    'notes'
                )->nullable();

                $table->timestamps();

                $table->index(
                    [
                        'company_id',
                        'attendance_date',
                    ],
                    'att_finalize_company_date_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'attendance_finalization_runs'
        );
    }
};