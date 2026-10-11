<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depots', function (Blueprint $table) {
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

            /*
             * Do not generate DEP-0001 here.
             * Centralized document numbering will
             * own this later.
             */
            $table->string('depot_code')
                ->nullable();

            $table->string('name');

            $table->string('proprietor_name')
                ->nullable();

            $table->string('phone', 50)
                ->nullable();

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

            $table->text('address')
                ->nullable();

            $table->string('postal_code', 30)
                ->nullable();

            $table->decimal('credit_limit', 15, 2)
                ->default(0);

            $table->unsignedInteger('payment_terms_days')
                ->default(0);

            $table->foreignId('manager_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->boolean('is_active')
                ->default(true);

            $table->text('notes')
                ->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['company_id', 'depot_code'],
                'depots_company_code_unique'
            );

            $table->index(
                ['company_id', 'district_id', 'is_active'],
                'depots_company_district_active_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depots');
    }
};