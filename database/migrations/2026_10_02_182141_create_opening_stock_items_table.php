<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'opening_stock_items',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId(
                    'opening_stock_id'
                )
                    ->constrained(
                        'opening_stocks'
                    )
                    ->cascadeOnDelete();

                $table->foreignId(
                    'product_id'
                )
                    ->constrained(
                        'products'
                    )
                    ->restrictOnDelete();

                $table->foreignId(
                    'product_variant_id'
                )
                    ->nullable()
                    ->constrained(
                        'product_variants'
                    )
                    ->restrictOnDelete();

                $table->decimal(
                    'quantity',
                    18,
                    4
                );

                $table->decimal(
                    'unit_cost',
                    18,
                    4
                );

                $table->decimal(
                    'total_cost',
                    18,
                    4
                );

                $table->timestamps();

                $table->index(
                    'product_id',
                    'osi_product_idx'
                );

                $table->index(
                    'product_variant_id',
                    'osi_variant_idx'
                );

                $table->index(
                    [
                        'opening_stock_id',
                        'product_id',
                    ],
                    'osi_stock_product_idx'
                );

                $table->index(
                    [
                        'opening_stock_id',
                        'product_id',
                        'product_variant_id',
                    ],
                    'osi_stock_product_variant_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'opening_stock_items'
        );
    }
};
