<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dealers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('depot_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('upazila_id')
                ->constrained()
                ->restrictOnDelete();

            /*
             * Keep nullable until centralized
             * DocumentSequence is implemented.
             */
            $table->string('dealer_code')->nullable();

            $table->string('business_name');
            $table->string('proprietor_name')->nullable();

            $table->string('phone')->nullable();
            $table->string('alternate_phone')->nullable();
            $table->string('email')->nullable();

            $table->string('trade_license_number')->nullable();
            $table->string('nid_number')->nullable();
            $table->string('tin_number')->nullable();
            $table->string('vat_number')->nullable();

            $table->text('address')->nullable();
            $table->string('postal_code')->nullable();

            $table->decimal(
                'credit_limit',
                18,
                2
            )->default(0);

            $table->unsignedInteger(
                'payment_terms_days'
            )->default(0);

            $table->foreignId('manager_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->boolean('is_active')
                ->default(true);

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique([
                'company_id',
                'dealer_code',
            ], 'dealers_company_code_unique');

            $table->index([
                'company_id',
                'depot_id',
                'upazila_id',
                'is_active',
            ], 'dealers_distribution_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dealers');
    }
};
