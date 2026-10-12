<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_transfer_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            // NULL is normalized to 0 to make duplicate non-variant lines enforceable.
            $table->unsignedBigInteger('variant_key')->default(0);
            $table->decimal('quantity', 18, 4);
            // Captured at posting from source inventory valuation; not user-entered for drafts.
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->decimal('total_cost', 20, 4)->nullable();
            $table->timestamps();

            $table->unique(['stock_transfer_id', 'product_id', 'variant_key'], 'stock_transfer_items_identity_unique');
            $table->index(['product_id', 'product_variant_id'], 'stock_transfer_items_product_variant_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
    }
};
