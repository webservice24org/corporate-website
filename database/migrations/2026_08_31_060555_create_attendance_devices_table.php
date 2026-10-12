<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_devices', function (Blueprint $table) {
            $table->id();

            $table
                ->foreignId('company_id')
                ->constrained()
                ->restrictOnDelete();

            $table
                ->foreignId('branch_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Device Identity
            |--------------------------------------------------------------------------
            */

            $table->string('name');

            $table->string(
                'code',
                50
            );

            $table
                ->string('provider', 50)
                ->default('generic');

            $table
                ->string('model')
                ->nullable();

            $table
                ->string('serial_number')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Integration
            |--------------------------------------------------------------------------
            |
            | push_adms
            | wdms_api
            | pull_sdk
            | manual_import
            | other
            |
            */

            $table
                ->string(
                    'integration_mode',
                    50
                )
                ->default('manual_import');

            /*
            |--------------------------------------------------------------------------
            | Connection
            |--------------------------------------------------------------------------
            */

            $table
                ->string('ip_address', 100)
                ->nullable();

            $table
                ->unsignedInteger('port')
                ->nullable();

            $table
                ->string('base_url', 2048)
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Secure provider-specific configuration
            |--------------------------------------------------------------------------
            |
            | Stored as encrypted TEXT through Laravel encrypted cast.
            | Examples later:
            |
            | API username/password
            | API token
            | communication key
            | provider configuration
            |
            */

            $table
                ->text('connection_config')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Timezone
            |--------------------------------------------------------------------------
            */

            $table
                ->string('timezone')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Communication State
            |--------------------------------------------------------------------------
            */

            $table
                ->timestamp('last_seen_at')
                ->nullable();

            $table
                ->timestamp('last_sync_at')
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

            $table->unique(
                [
                    'company_id',
                    'code',
                ],
                'att_device_company_code_uq'
            );

            $table->index(
                [
                    'company_id',
                    'status',
                ],
                'att_device_company_status_idx'
            );

            $table->index(
                [
                    'branch_id',
                    'status',
                ],
                'att_device_branch_status_idx'
            );

            $table->index(
                'serial_number',
                'att_device_serial_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'attendance_devices'
        );
    }
};
