<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'dealer_applications',
            function (Blueprint $table) {
                $table->id();

                $table->uuid(
                    'public_token'
                )->unique();

                $table->foreignId('company_id')
                    ->constrained()
                    ->restrictOnDelete();

                $table->foreignId('depot_id')
                    ->constrained()
                    ->restrictOnDelete();

                $table->foreignId('upazila_id')
                    ->constrained()
                    ->restrictOnDelete();

                $table->foreignId('dealer_id')
                    ->nullable()
                    ->constrained()
                    ->nullOnDelete();

                $table->string('business_name');
                $table->string('proprietor_name');

                $table->string('phone');
                $table->string('alternate_phone')->nullable();
                $table->string('email')->nullable();

                $table->string(
                    'trade_license_number'
                )->nullable();

                $table->string(
                    'nid_number'
                )->nullable();

                $table->string(
                    'tin_number'
                )->nullable();

                $table->string(
                    'vat_number'
                )->nullable();

                $table->text(
                    'business_address'
                );

                $table->string(
                    'postal_code'
                )->nullable();

                $table->unsignedInteger(
                    'years_in_business'
                )->nullable();

                $table->decimal(
                    'requested_credit_limit',
                    18,
                    2
                )->nullable();

                $table->text(
                    'business_description'
                )->nullable();

                $table->string('status', 30)
                    ->default('pending');

                $table->foreignId(
                    'reviewed_by_user_id'
                )
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->timestamp(
                    'reviewed_at'
                )->nullable();

                $table->foreignId(
                    'approved_by_user_id'
                )
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->timestamp(
                    'approved_at'
                )->nullable();

                $table->foreignId(
                    'rejected_by_user_id'
                )
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->timestamp(
                    'rejected_at'
                )->nullable();

                $table->text(
                    'admin_notes'
                )->nullable();

                $table->text(
                    'rejection_reason'
                )->nullable();

                $table->timestamps();

                $table->index([
                    'company_id',
                    'status',
                ]);

                $table->index([
                    'depot_id',
                    'upazila_id',
                    'status',
                ], 'dealer_application_distribution_index');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'dealer_applications'
        );
    }
};
