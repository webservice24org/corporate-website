<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table): void {
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
        });
        Schema::table('purchase_return_items', function (Blueprint $table): void {
            $table->decimal('unit_cost', 18, 4)->default(0)->change();
            $table->decimal('source_unit_cost', 18, 4)->nullable();
            $table->boolean('is_stock_item')->nullable();
            $table->unique(['purchase_return_id', 'goods_receipt_item_id'], 'purchase_return_source_unique');
            $table->unique('stock_movement_id', 'purchase_return_movement_unique');
        });
        Schema::create('purchase_return_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('purchase_return_id')->constrained('purchase_returns')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 30);
            $table->json('old_values')->nullable();
            $table->json('new_values');
            $table->text('comment')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_audit_logs');
        Schema::table('purchase_return_items', function (Blueprint $table): void {
            $table->dropUnique('purchase_return_source_unique');
            $table->dropUnique('purchase_return_movement_unique');
            $table->dropColumn(['source_unit_cost', 'is_stock_item']);
        });
        Schema::table('purchase_returns', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('posted_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['revision', 'approved_at', 'cancelled_at']);
        });
        // Retain four-decimal costs on rollback to avoid truncating historical valuation.
    }
};
