<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('dealer_id')
                ->constrained()
                ->restrictOnDelete();

            /*
             * Keep nullable until the centralized
             * DocumentSequence service is implemented.
             */
            $table->string('seller_code')->nullable();

            $table->string('business_name');
            $table->string('proprietor_name');

            $table->string('seller_type')
                ->default('retailer');

            $table->string('phone');
            $table->string('alternate_phone')->nullable();
            $table->string('email')->nullable();

            $table->string('trade_license_number')->nullable();
            $table->string('nid_number')->nullable();
            $table->string('tin_number')->nullable();
            $table->string('vat_number')->nullable();

            $table->text('business_address');
            $table->string('postal_code')->nullable();

            $table->decimal('credit_limit', 18, 2)
                ->default(0);

            $table->unsignedInteger('payment_terms_days')
                ->default(0);

            $table->foreignId('manager_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->boolean('is_active')
                ->default(true);

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['company_id', 'seller_code'],
                'sellers_company_code_unique'
            );

            $table->index(
                ['company_id', 'dealer_id', 'is_active'],
                'sellers_company_dealer_active_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sellers');
    }
};