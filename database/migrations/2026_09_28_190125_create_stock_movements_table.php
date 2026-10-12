<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'stock_movements',
            function (Blueprint $table) {
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

                $table->foreignId(
                    'product_variant_id'
                )
                    ->nullable()
                    ->constrained(
                        'product_variants'
                    )
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();

                $table->string(
                    'movement_type',
                    50
                );

                $table->string(
                    'direction',
                    10
                );

                $table->decimal(
                    'quantity',
                    18,
                    4
                );

                $table->decimal(
                    'unit_cost',
                    18,
                    4
                )->default(0);

                $table->decimal(
                    'total_cost',
                    18,
                    4
                )->default(0);

                $table->decimal(
                    'balance_before',
                    18,
                    4
                )->default(0);

                $table->decimal(
                    'balance_after',
                    18,
                    4
                )->default(0);

                $table->string(
                    'reference_type',
                    100
                )->nullable();

                $table->unsignedBigInteger(
                    'reference_id'
                )->nullable();

                $table->string(
                    'reference_number',
                    100
                )->nullable();

                $table->timestamp(
                    'occurred_at'
                );

                $table->foreignId(
                    'created_by'
                )
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->text(
                    'notes'
                )->nullable();

                $table->timestamps();

                $table->index([
                    'company_id',
                    'warehouse_id',
                    'occurred_at',
                ]);

                $table->index([
                    'warehouse_id',
                    'product_id',
                    'product_variant_id',
                ]);

                $table->index([
                    'product_id',
                    'product_variant_id',
                    'occurred_at',
                ]);

                $table->index([
                    'movement_type',
                    'direction',
                ]);

                $table->index([
                    'reference_type',
                    'reference_id',
                ]);

                $table->index(
                    'reference_number'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'stock_movements'
        );
    }
};
