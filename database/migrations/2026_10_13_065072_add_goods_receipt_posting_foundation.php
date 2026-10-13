<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table): void {
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
        });
        Schema::table('goods_receipt_items', function (Blueprint $table): void {
            $table->decimal('unit_cost', 18, 4)->default(0)->change();
            $table->boolean('is_stock_item')->default(true);
            $table->unique(['goods_receipt_id', 'purchase_order_item_id'], 'goods_receipt_order_line_unique');
            $table->unique('stock_movement_id', 'goods_receipt_stock_movement_unique');
        });
        Schema::create('goods_receipt_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('goods_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 20);
            $table->json('old_values')->nullable();
            $table->json('new_values');
            $table->text('comment')->nullable();
            $table->timestamp('created_at');
            $table->index(['company_id', 'goods_receipt_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_audit_logs');
        Schema::table('goods_receipt_items', function (Blueprint $table): void {
            $table->dropUnique('goods_receipt_order_line_unique');
            $table->dropUnique('goods_receipt_stock_movement_unique');
            $table->dropColumn('is_stock_item');
            // Retain four-place cost precision on rollback to avoid truncating recorded inventory costs.
        });
        Schema::table('goods_receipts', function (Blueprint $table): void {
            $table->dropForeign(['posted_by']);
            $table->dropForeign(['cancelled_by']);
            $table->dropColumn(['revision', 'posted_by', 'cancelled_by', 'cancelled_at']);
        });
    }
};
