<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_low_stock_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->unsignedBigInteger('variant_key')->default(0);
            $table->string('status', 20)->default('active');
            $table->string('severity', 20);
            $table->decimal('quantity_available', 18, 4)->default(0);
            $table->decimal('minimum_stock', 18, 4)->default(0);
            $table->decimal('reorder_level', 18, 4)->default(0);
            $table->decimal('suggested_reorder_quantity', 18, 4)->default(0);
            $table->timestamp('first_detected_at')->nullable();
            $table->timestamp('last_detected_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamps();

            $table->unique(
                ['company_id', 'warehouse_id', 'product_id', 'variant_key'],
                'inventory_low_stock_alerts_position_unique'
            );
            $table->index(['company_id', 'status', 'severity'], 'inventory_low_stock_alerts_company_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_low_stock_alerts');
    }
};
