<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'attendance_record_punches',
            function (Blueprint $table) {
                $table->id();

                $table
                    ->foreignId('attendance_record_id')
                    ->constrained('attendance_records')
                    ->cascadeOnDelete();

                $table
                    ->foreignId('raw_punch_id')
                    ->constrained('attendance_raw_punches')
                    ->cascadeOnDelete();

                $table->timestamps();

                /*
                |--------------------------------------------------------------------------
                | One raw punch can belong to only one final attendance record
                |--------------------------------------------------------------------------
                */

                $table->unique(
                    'raw_punch_id',
                    'att_record_punch_raw_uq'
                );

                $table->index(
                    'attendance_record_id',
                    'att_record_punch_record_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'attendance_record_punches'
        );
    }
};