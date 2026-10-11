<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('lead_source_id')
                ->nullable()
                ->constrained('lead_sources')
                ->nullOnDelete();

            $table->foreignId('lead_status_id')
                ->constrained('lead_statuses')
                ->restrictOnDelete();

            $table->foreignId('owner_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('lead_number', 50);

            $table->string('lead_type', 20)
                ->default('individual');
            // individual | company

            $table->string('company_name')
                ->nullable();

            $table->string('contact_name');

            $table->string('job_title')
                ->nullable();

            $table->string('email')
                ->nullable();

            $table->string('phone', 50)
                ->nullable();

            $table->string('alternate_phone', 50)
                ->nullable();

            $table->string('website')
                ->nullable();

            $table->decimal(
                'expected_value',
                15,
                2
            )->default(0);

            $table->string('currency', 3)
                ->default('BDT');

            $table->dateTime('next_follow_up_at')
                ->nullable();

            $table->string('address_line_1')
                ->nullable();

            $table->string('address_line_2')
                ->nullable();

            $table->string('city', 100)
                ->nullable();

            $table->string('state', 100)
                ->nullable();

            $table->string('postal_code', 30)
                ->nullable();

            $table->string('country', 100)
                ->nullable();

            $table->text('notes')
                ->nullable();

            $table->boolean('is_active')
                ->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                [
                    'company_id',
                    'lead_number',
                ],
                'lead_company_number_unique'
            );

            $table->index(
                [
                    'company_id',
                    'lead_status_id',
                    'is_active',
                ],
                'lead_company_status_active_idx'
            );

            $table->index(
                [
                    'company_id',
                    'owner_user_id',
                ],
                'lead_company_owner_idx'
            );

            $table->index(
                'next_follow_up_at',
                'lead_follow_up_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};