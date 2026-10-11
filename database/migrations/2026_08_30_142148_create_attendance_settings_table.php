<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'attendance_settings',
            function (Blueprint $table) {
                $table->id();

                $table
                    ->foreignId('company_id')
                    ->unique()
                    ->constrained()
                    ->cascadeOnDelete();

                /*
                 |--------------------------------------------------------------------------
                 | General
                 |--------------------------------------------------------------------------
                 */

                $table
                    ->string('timezone')
                    ->default('Asia/Dhaka');

                $table
                    ->unsignedInteger('grace_minutes')
                    ->default(0);

                $table
                    ->unsignedInteger('early_leave_grace_minutes')
                    ->default(0);

                /*
                 |--------------------------------------------------------------------------
                 | Work-duration rules
                 |--------------------------------------------------------------------------
                 */

                $table
                    ->unsignedInteger('half_day_minutes')
                    ->default(240);

                $table
                    ->unsignedInteger('minimum_full_day_minutes')
                    ->default(480);

                $table
                    ->unsignedInteger('overtime_after_minutes')
                    ->default(0);

                /*
                 |--------------------------------------------------------------------------
                 | Punch processing
                 |--------------------------------------------------------------------------
                 */

                $table
                    ->unsignedInteger('duplicate_punch_window_seconds')
                    ->default(60);

                $table
                    ->boolean('auto_pair_punches')
                    ->default(true);

                $table
                    ->boolean('allow_manual_attendance')
                    ->default(true);

                $table
                    ->boolean('allow_multiple_checkins')
                    ->default(false);

                /*
                 |--------------------------------------------------------------------------
                 | Day / absence calculation
                 |--------------------------------------------------------------------------
                 */

                $table
                    ->boolean('mark_absent_automatically')
                    ->default(true);

                $table
                    ->boolean('count_weekends_as_absent')
                    ->default(false);

                $table
                    ->boolean('count_holidays_as_absent')
                    ->default(false);

                /*
                 |--------------------------------------------------------------------------
                 | Device integration defaults
                 |--------------------------------------------------------------------------
                 |
                 | These settings are generic.
                 | We are deliberately NOT putting ZKTeco-specific fields here.
                 |
                 */

                $table
                    ->boolean('device_attendance_enabled')
                    ->default(false);

                $table
                    ->string(
                        'default_punch_source',
                        30
                    )
                    ->default('manual');

                /*
                 |--------------------------------------------------------------------------
                 | Status
                 |--------------------------------------------------------------------------
                 */

                $table
                    ->boolean('is_active')
                    ->default(true);

                $table->timestamps();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'attendance_settings'
        );
    }
};