<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depot_applications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('division_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('district_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('depot_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('business_name');

            $table->string('proprietor_name');

            $table->string('phone', 50);

            $table->string('alternate_phone', 50)
                ->nullable();

            $table->string('email')
                ->nullable();

            $table->string('trade_license_number')
                ->nullable();

            $table->string('nid_number')
                ->nullable();

            $table->string('tin_number')
                ->nullable();

            $table->string('vat_number')
                ->nullable();

            $table->text('business_address');

            $table->string('postal_code', 30)
                ->nullable();

            $table->unsignedInteger('years_in_business')
                ->nullable();

            $table->decimal('requested_credit_limit', 15, 2)
                ->nullable();

            $table->text('business_description')
                ->nullable();

            $table->enum('status', [
                'pending',
                'under_review',
                'approved',
                'rejected',
            ])->default('pending');

            $table->foreignId('reviewed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('reviewed_at')
                ->nullable();

            $table->foreignId('approved_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('approved_at')
                ->nullable();

            $table->foreignId('rejected_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('rejected_at')
                ->nullable();

            $table->text('admin_notes')
                ->nullable();

            $table->text('rejection_reason')
                ->nullable();

            $table->timestamps();

            $table->index(
                ['company_id', 'status'],
                'depot_apps_company_status_idx'
            );

            $table->index(
                ['district_id', 'status'],
                'depot_apps_district_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depot_applications');
    }
};
