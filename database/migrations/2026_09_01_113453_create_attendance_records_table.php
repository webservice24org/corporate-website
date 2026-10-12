<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();

            $table
                ->foreignId('company_id')
                ->constrained()
                ->restrictOnDelete();

            $table
                ->foreignId('employee_id')
                ->constrained()
                ->cascadeOnDelete();

            $table
                ->foreignId('shift_id')
                ->nullable()
                ->constrained('attendance_shifts')
                ->restrictOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Organizational snapshot
            |--------------------------------------------------------------------------
            |
            | These are useful for reporting historical attendance by the
            | employee's organization at processing time.
            |
            */

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

            /*
            |--------------------------------------------------------------------------
            | Attendance date
            |--------------------------------------------------------------------------
            |
            | For overnight shifts this is the shift START date.
            |
            */

            $table->date('attendance_date');

            /*
            |--------------------------------------------------------------------------
            | Scheduled shift
            |--------------------------------------------------------------------------
            |
            | Stored in UTC.
            |
            */

            $table
                ->dateTime('scheduled_start_at')
                ->nullable();

            $table
                ->dateTime('scheduled_end_at')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Actual attendance
            |--------------------------------------------------------------------------
            |
            | Stored in UTC.
            |
            */

            $table
                ->dateTime('check_in_at')
                ->nullable();

            $table
                ->dateTime('check_out_at')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Calculations
            |--------------------------------------------------------------------------
            */

            $table
                ->unsignedInteger('worked_minutes')
                ->default(0);

            $table
                ->unsignedInteger('break_minutes')
                ->default(0);

            $table
                ->unsignedInteger('late_minutes')
                ->default(0);

            $table
                ->unsignedInteger('early_leave_minutes')
                ->default(0);

            $table
                ->unsignedInteger('overtime_minutes')
                ->default(0);

            $table
                ->unsignedInteger('punch_count')
                ->default(0);

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            |
            | present
            | half_day
            | incomplete
            | absent          <- generated later
            | off_day
            | off_day_work
            |
            */

            $table
                ->string('attendance_status', 30)
                ->default('incomplete');

            /*
            |--------------------------------------------------------------------------
            | Processing information
            |--------------------------------------------------------------------------
            */

            $table
                ->string('source', 30)
                ->default('automatic');

            $table
                ->boolean('is_manual')
                ->default(false);

            $table
                ->json('calculation_details')
                ->nullable();

            $table
                ->text('notes')
                ->nullable();

            $table
                ->timestamp('processed_at')
                ->nullable();

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | One record per employee + attendance date
            |--------------------------------------------------------------------------
            */

            $table->unique(
                [
                    'employee_id',
                    'attendance_date',
                ],
                'att_record_employee_date_uq'
            );

            $table->index(
                [
                    'company_id',
                    'attendance_date',
                ],
                'att_record_company_date_idx'
            );

            $table->index(
                [
                    'attendance_status',
                    'attendance_date',
                ],
                'att_record_status_date_idx'
            );

            $table->index(
                [
                    'shift_id',
                    'attendance_date',
                ],
                'att_record_shift_date_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'attendance_records'
        );
    }
};
