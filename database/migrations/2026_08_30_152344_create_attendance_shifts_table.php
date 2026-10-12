<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'attendance_shifts',
            function (Blueprint $table) {
                $table->id();

                $table
                    ->foreignId('company_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table
                    ->string('name');

                $table
                    ->string('code', 50);

                /*
                 |--------------------------------------------------------------------------
                 | Shift time
                 |--------------------------------------------------------------------------
                 */

                $table->time(
                    'start_time'
                );

                $table->time(
                    'end_time'
                );

                $table
                    ->boolean('crosses_midnight')
                    ->default(false);

                /*
                 |--------------------------------------------------------------------------
                 | Attendance windows
                 |--------------------------------------------------------------------------
                 |
                 | Useful both for manual attendance and ZKTeco punches.
                 |
                 | Example:
                 | shift 09:00
                 | check-in window before = 180 mins
                 | earliest valid check-in = 06:00
                 |
                 */

                $table
                    ->unsignedInteger(
                        'check_in_window_before_minutes'
                    )
                    ->default(180);

                $table
                    ->unsignedInteger(
                        'check_in_window_after_minutes'
                    )
                    ->default(240);

                $table
                    ->unsignedInteger(
                        'check_out_window_before_minutes'
                    )
                    ->default(240);

                $table
                    ->unsignedInteger(
                        'check_out_window_after_minutes'
                    )
                    ->default(360);

                /*
                 |--------------------------------------------------------------------------
                 | Shift-specific rules
                 |--------------------------------------------------------------------------
                 */

                $table
                    ->unsignedInteger(
                        'grace_minutes'
                    )
                    ->default(0);

                $table
                    ->unsignedInteger(
                        'early_leave_grace_minutes'
                    )
                    ->default(0);

                $table
                    ->unsignedInteger(
                        'minimum_work_minutes'
                    )
                    ->default(0);

                $table
                    ->unsignedInteger(
                        'half_day_minutes'
                    )
                    ->default(0);

                $table
                    ->unsignedInteger(
                        'overtime_after_minutes'
                    )
                    ->default(0);

                /*
                 |--------------------------------------------------------------------------
                 | Break
                 |--------------------------------------------------------------------------
                 */

                $table
                    ->unsignedInteger(
                        'break_minutes'
                    )
                    ->default(0);

                $table
                    ->boolean(
                        'break_is_paid'
                    )
                    ->default(false);

                /*
                 |--------------------------------------------------------------------------
                 | Working week
                 |--------------------------------------------------------------------------
                 |
                 | JSON example:
                 | [0,1,2,3,4]
                 |
                 | Laravel Carbon:
                 | 0 = Sunday
                 | 1 = Monday
                 | ...
                 | 6 = Saturday
                 |
                 */

                $table
                    ->json('working_days');

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

                $table->unique([
                    'company_id',
                    'code',
                ]);

                $table->index([
                    'company_id',
                    'status',
                ]);
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'attendance_shifts'
        );
    }
};
