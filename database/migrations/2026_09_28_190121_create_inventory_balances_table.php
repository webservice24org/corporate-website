<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_balances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('warehouse_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('product_variant_id')
                ->nullable()
                ->constrained('product_variants')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->decimal(
                'quantity_on_hand',
                18,
                4
            )->default(0);

            $table->decimal(
                'quantity_reserved',
                18,
                4
            )->default(0);

            $table->decimal(
                'average_cost',
                18,
                4
            )->default(0);

            $table->timestamp(
                'last_movement_at'
            )->nullable();

            $table->timestamps();

            $table->index([
                'company_id',
                'warehouse_id',
            ]);

            $table->index([
                'warehouse_id',
                'product_id',
            ]);

            $table->index([
                'product_id',
                'product_variant_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'inventory_balances'
        );
    }
};
