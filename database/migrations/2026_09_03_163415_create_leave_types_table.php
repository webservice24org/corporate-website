<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();

            $table
                ->foreignId('company_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('name');

            $table->string(
                'code',
                50
            );

            $table
                ->decimal(
                    'annual_allowance',
                    8,
                    2
                )
                ->default(0);

            $table
                ->boolean('is_paid')
                ->default(true);

            $table
                ->boolean('allow_half_day')
                ->default(true);

            $table
                ->boolean('carry_forward')
                ->default(false);

            $table
                ->decimal(
                    'max_carry_forward',
                    8,
                    2
                )
                ->default(0);

            $table
                ->boolean('requires_attachment')
                ->default(false);

            $table
                ->boolean('exclude_weekly_off')
                ->default(true);

            $table
                ->boolean('exclude_holidays')
                ->default(true);

            $table
                ->string('status', 20)
                ->default('active');

            $table->text('description')
                ->nullable();

            $table->timestamps();

            $table->unique(
                [
                    'company_id',
                    'code',
                ]
            );

            $table->index(
                [
                    'company_id',
                    'status',
                ],
                'leave_type_company_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'leave_types'
        );
    }
};
