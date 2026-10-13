<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfq_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rfq_id')->constrained('rfqs')->restrictOnDelete();
            $table->foreignId('purchase_requisition_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('product_name', 200);
            $table->string('variant_name', 180)->nullable();
            $table->decimal('quantity', 18, 4);
            $table->decimal('estimated_unit_price', 18, 2);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['rfq_id', 'purchase_requisition_item_id']);
        });
        Schema::table('rfqs', function (Blueprint $table): void {
            $table->string('currency_code', 3)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('awarded_quotation_id')->nullable()->constrained('supplier_quotations')->restrictOnDelete();
            $table->foreignId('awarded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('awarded_at')->nullable();
        });
        Schema::table('supplier_quotations', function (Blueprint $table): void {
            $table->unsignedInteger('revision')->default(1);
            $table->unique(['rfq_id', 'supplier_id'], 'rfq_one_quotation_per_supplier');
        });
        Schema::table('supplier_quotation_items', function (Blueprint $table): void {
            $table->foreignId('rfq_item_id')->nullable()->constrained('rfq_items')->restrictOnDelete();
            $table->decimal('line_total', 18, 2)->default(0);
            $table->unique(['supplier_quotation_id', 'rfq_item_id'], 'quotation_rfq_line_unique');
        });
        Schema::create('purchase_rfq_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('rfq_id')->constrained('rfqs')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 30);
            $table->json('old_values')->nullable();
            $table->json('new_values');
            $table->text('comment')->nullable();
            $table->timestamp('created_at');
            $table->index(['company_id', 'rfq_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_rfq_audit_logs');
        Schema::table('rfqs', function (Blueprint $table): void {
            $table->dropForeign(['awarded_quotation_id']);
            $table->dropForeign(['awarded_by']);
            $table->dropColumn(['currency_code', 'revision', 'awarded_quotation_id', 'awarded_by', 'awarded_at']);
        });
        Schema::table('supplier_quotations', function (Blueprint $table): void {
            $table->dropUnique('rfq_one_quotation_per_supplier');
            $table->dropColumn('revision');
        });
        Schema::table('supplier_quotation_items', function (Blueprint $table): void {
            $table->dropUnique('quotation_rfq_line_unique');
            $table->dropForeign(['rfq_item_id']);
            $table->dropColumn(['rfq_item_id', 'line_total']);
        });
        Schema::dropIfExists('rfq_items');
    }
};
