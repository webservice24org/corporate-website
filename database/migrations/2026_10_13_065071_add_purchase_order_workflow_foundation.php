<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->unsignedInteger('revision')->default(1);
            $table->unique('supplier_quotation_id', 'purchase_order_one_per_quotation');
        });
        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->foreignId('supplier_quotation_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('product_name', 200)->nullable();
            $table->string('variant_name', 180)->nullable();
            $table->unique(['purchase_order_id', 'supplier_quotation_item_id'], 'purchase_order_source_line_unique');
        });
        Schema::create('purchase_order_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 20);
            $table->json('old_values')->nullable();
            $table->json('new_values');
            $table->text('comment')->nullable();
            $table->timestamp('created_at');
            $table->index(['company_id', 'purchase_order_id'], 'purchase_order_audit_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_audit_logs');
        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->dropUnique('purchase_order_source_line_unique');
            $table->dropForeign(['supplier_quotation_item_id']);
            $table->dropColumn(['supplier_quotation_item_id', 'product_name', 'variant_name']);
        });
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropUnique('purchase_order_one_per_quotation');
            $table->dropColumn('revision');
        });
    }
};
