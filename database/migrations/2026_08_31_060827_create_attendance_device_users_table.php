<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'attendance_device_users',
            function (Blueprint $table) {
                $table->id();

                $table
                    ->foreignId('device_id')
                    ->constrained(
                        'attendance_devices'
                    )
                    ->restrictOnDelete();

                $table
                    ->foreignId('employee_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table
                    ->foreignId('mapped_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                /*
                |--------------------------------------------------------------------------
                | External Identity
                |--------------------------------------------------------------------------
                |
                | This is the ID/PIN/user identifier
                | known by the biometric device.
                |
                */

                $table
                    ->string(
                        'external_user_id',
                        100
                    );

                $table
                    ->string(
                        'card_number',
                        100
                    )
                    ->nullable();

                /*
                |--------------------------------------------------------------------------
                | Sync state
                |--------------------------------------------------------------------------
                */

                $table
                    ->string(
                        'sync_status',
                        30
                    )
                    ->default('pending');

                $table
                    ->timestamp('last_synced_at')
                    ->nullable();

                $table
                    ->text('sync_error')
                    ->nullable();

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

                /*
                |--------------------------------------------------------------------------
                | One external user ID per device
                |--------------------------------------------------------------------------
                */

                $table->unique(
                    [
                        'device_id',
                        'external_user_id',
                    ],
                    'att_dev_user_external_uq'
                );

                /*
                |--------------------------------------------------------------------------
                | An employee maps once to a particular device
                |--------------------------------------------------------------------------
                */

                $table->unique(
                    [
                        'device_id',
                        'employee_id',
                    ],
                    'att_dev_user_employee_uq'
                );

                $table->index(
                    [
                        'employee_id',
                        'status',
                    ],
                    'att_dev_user_emp_status_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'attendance_device_users'
        );
    }
};