<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_holidays', function (Blueprint $table) {
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

            $table->string('name');

            $table->date('holiday_date');

            $table
                ->string('holiday_type', 30)
                ->default('company');

            $table
                ->boolean('is_paid')
                ->default(true);

            $table
                ->string('status', 20)
                ->default('active');

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(
                [
                    'company_id',
                    'holiday_date',
                ],
                'att_holiday_company_date_idx'
            );

            $table->index(
                [
                    'branch_id',
                    'holiday_date',
                ],
                'att_holiday_branch_date_idx'
            );

            $table->index(
                [
                    'company_id',
                    'status',
                ],
                'att_holiday_company_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'attendance_holidays'
        );
    }
};