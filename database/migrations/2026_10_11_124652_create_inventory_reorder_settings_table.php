<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_reorder_settings', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->restrictOnDelete();

            $table->foreignId('warehouse_id')
                ->constrained('warehouses')
                ->restrictOnDelete();

            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();

            $table->foreignId('product_variant_id')
                ->nullable()
                ->constrained('product_variants')
                ->restrictOnDelete();

            // 0 for products without variants;
            // otherwise the product variant ID.
            $table->unsignedBigInteger('variant_key')
                ->default(0);

            $table->decimal('reorder_level', 18, 4);
            $table->decimal('minimum_stock', 18, 4)
                ->default(0);

            $table->timestamps();

            $table->unique(
                ['warehouse_id', 'product_id', 'variant_key'],
                'inventory_reorder_settings_unique'
            );

            $table->index(
                ['company_id', 'warehouse_id'],
                'inventory_reorder_settings_company_warehouse_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_reorder_settings');
    }
};
