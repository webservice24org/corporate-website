<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'attendance_raw_punches',
            function (Blueprint $table) {
                $table->id();

                /*
                |--------------------------------------------------------------------------
                | Ownership
                |--------------------------------------------------------------------------
                */

                $table
                    ->foreignId('company_id')
                    ->constrained()
                    ->restrictOnDelete();

                $table
                    ->foreignId('device_id')
                    ->nullable()
                    ->constrained('attendance_devices')
                    ->nullOnDelete();

                /*
                |--------------------------------------------------------------------------
                | Resolved employee
                |--------------------------------------------------------------------------
                |
                | Nullable because a device transaction can arrive before
                | the device user has been mapped to an ERP employee.
                |
                */

                $table
                    ->foreignId('employee_id')
                    ->nullable()
                    ->constrained()
                    ->nullOnDelete();

                /*
                |--------------------------------------------------------------------------
                | External identity
                |--------------------------------------------------------------------------
                */

                $table
                    ->string(
                        'external_user_id',
                        100
                    )
                    ->nullable();

                /*
                |--------------------------------------------------------------------------
                | Punch timestamps
                |--------------------------------------------------------------------------
                |
                | device_punched_at = clock time reported by device
                |
                | punched_at = normalized UTC timestamp
                |
                */

                $table
                    ->dateTime('device_punched_at')
                    ->nullable();

                $table->dateTime(
                    'punched_at'
                );

                $table
                    ->string(
                        'source_timezone',
                        100
                    )
                    ->nullable();

                /*
                |--------------------------------------------------------------------------
                | Device information
                |--------------------------------------------------------------------------
                |
                | Do not rely on punch_state as final IN/OUT semantics.
                |
                */

                $table
                    ->string(
                        'punch_state',
                        50
                    )
                    ->nullable();

                $table
                    ->string(
                        'verification_method',
                        50
                    )
                    ->nullable();

                /*
                |--------------------------------------------------------------------------
                | Source
                |--------------------------------------------------------------------------
                |
                | device
                | wdms
                | api
                | import
                | manual
                |
                */

                $table
                    ->string(
                        'source',
                        50
                    )
                    ->default('device');

                /*
                |--------------------------------------------------------------------------
                | Provider record identity
                |--------------------------------------------------------------------------
                */

                $table
                    ->string(
                        'source_record_id',
                        191
                    )
                    ->nullable();

                /*
                |--------------------------------------------------------------------------
                | Idempotency
                |--------------------------------------------------------------------------
                |
                | Used to prevent the exact same event from being ingested
                | repeatedly because of network retries.
                |
                */

                $table
                    ->string(
                        'ingestion_key',
                        64
                    )
                    ->nullable();

                /*
                |--------------------------------------------------------------------------
                | Original provider payload
                |--------------------------------------------------------------------------
                */

                $table
                    ->json('raw_payload')
                    ->nullable();

                /*
                |--------------------------------------------------------------------------
                | Mapping state
                |--------------------------------------------------------------------------
                */

                $table
                    ->timestamp('mapped_at')
                    ->nullable();

                /*
                |--------------------------------------------------------------------------
                | Processing state
                |--------------------------------------------------------------------------
                |
                | pending
                | unmapped
                | processed
                | ignored
                | failed
                |
                */

                $table
                    ->string(
                        'processing_status',
                        30
                    )
                    ->default('pending');

                $table
                    ->text('processing_error')
                    ->nullable();

                $table
                    ->timestamp('processed_at')
                    ->nullable();

                $table
                    ->timestamp('received_at')
                    ->useCurrent();

                $table->timestamps();

                /*
                |--------------------------------------------------------------------------
                | Indexes
                |--------------------------------------------------------------------------
                */

                $table->unique(
                    'ingestion_key',
                    'att_raw_ingestion_key_uq'
                );

                $table->index(
                    [
                        'company_id',
                        'punched_at',
                    ],
                    'att_raw_company_time_idx'
                );

                $table->index(
                    [
                        'device_id',
                        'punched_at',
                    ],
                    'att_raw_device_time_idx'
                );

                $table->index(
                    [
                        'employee_id',
                        'punched_at',
                    ],
                    'att_raw_employee_time_idx'
                );

                $table->index(
                    [
                        'device_id',
                        'external_user_id',
                    ],
                    'att_raw_device_user_idx'
                );

                $table->index(
                    [
                        'processing_status',
                        'punched_at',
                    ],
                    'att_raw_status_time_idx'
                );

                $table->index(
                    [
                        'device_id',
                        'source_record_id',
                    ],
                    'att_raw_source_record_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'attendance_raw_punches'
        );
    }
};
