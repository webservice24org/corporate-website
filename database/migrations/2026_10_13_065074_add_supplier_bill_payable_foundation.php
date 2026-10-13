<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_bills', function (Blueprint $table): void {
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
        });
        Schema::table('supplier_bill_items', function (Blueprint $table): void {
            $table->unique(['supplier_bill_id', 'goods_receipt_item_id'], 'supplier_bill_source_unique');
        });
        Schema::create('supplier_payables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('supplier_bill_id')->unique()->constrained('supplier_bills')->restrictOnDelete();
            $table->string('currency_code', 3);
            $table->decimal('amount', 18, 2);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->date('due_date')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->index(['company_id', 'supplier_id', 'currency_code']);
        });
        Schema::create('supplier_bill_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('supplier_bill_id')->constrained('supplier_bills')->restrictOnDelete();
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
        Schema::dropIfExists('supplier_bill_audit_logs');
        Schema::dropIfExists('supplier_payables');
        Schema::table('supplier_bill_items', function (Blueprint $table): void {
            $table->dropUnique('supplier_bill_source_unique');
        });
        Schema::table('supplier_bills', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['revision', 'approved_at', 'cancelled_at']);
        });
    }
};
