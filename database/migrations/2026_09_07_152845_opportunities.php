<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            /*
             * Nullable until centralized DocumentSequence owns
             * opportunity numbering.
             */
            $table->string('opportunity_number', 100)
                ->nullable()
                ->unique();

            $table->foreignId('lead_id')
                ->nullable()
                ->constrained('leads')
                ->nullOnDelete();

            /*
             * Customer | Dealer | Seller.
             * Kept polymorphic because all three are valid
             * commercial parties in this ERP.
             */
            $table->string('party_type', 100);
            $table->unsignedBigInteger('party_id');

            $table->foreignId('opportunity_stage_id')
                ->constrained('opportunity_stages')
                ->restrictOnDelete();

            $table->foreignId('salesperson_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            $table->decimal('expected_value', 18, 2)->default(0);
            $table->string('currency', 10)->default('BDT');
            $table->unsignedTinyInteger('probability')->default(0);
            $table->date('expected_close_date')->nullable();

            $table->timestamp('won_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->text('lost_reason')->nullable();

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['party_type', 'party_id'],
                'opportunities_party_idx'
            );

            $table->index(
                ['company_id', 'opportunity_stage_id'],
                'opportunities_company_stage_idx'
            );

            $table->index(
                ['salesperson_user_id', 'expected_close_date'],
                'opportunities_salesperson_close_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunities');
    }
};
