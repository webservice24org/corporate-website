<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('product_category_id')
                ->nullable()
                ->constrained('product_categories')
                ->nullOnDelete();
            $table->foreignId('brand_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('unit_id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('product_type', 30)->default('physical');
            $table->string('name', 200);
            $table->string('product_code', 80)->nullable();
            $table->string('sku', 100)->nullable();
            $table->string('barcode', 100)->nullable();
            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();
            $table->decimal('purchase_price', 18, 2)->default(0);
            $table->decimal('selling_price', 18, 2)->default(0);
            $table->boolean('track_inventory')->default(true);
            $table->boolean('allow_negative_stock')->default(false);
            $table->decimal('reorder_level', 18, 4)->default(0);
            $table->decimal('minimum_stock', 18, 4)->default(0);
            $table->boolean('has_variants')->default(false);
            $table->boolean('is_active')->default(true);
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

            $table->unique(['company_id', 'product_code']);
            $table->unique(['company_id', 'sku']);
            $table->unique(['company_id', 'barcode']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};