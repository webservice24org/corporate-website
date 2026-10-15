<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_credit_notes', function (Blueprint $table): void {
            $table->unsignedBigInteger('issue_sequence')->nullable();
            $table->unique(['sales_invoice_id', 'issue_sequence'], 'sales_credit_issue_sequence_unique');
        });
        Schema::create('customer_credit_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('source_sales_invoice_id')->constrained('sales_invoices')->restrictOnDelete();
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->foreignId('reverses_allocation_id')->nullable()->constrained('customer_credit_allocations')->restrictOnDelete();
            $table->unique('reverses_allocation_id', 'customer_credit_reversal_unique');
            $table->string('idempotency_key', 100);
            $table->char('payload_hash', 64);
            $table->unique(['company_id', 'idempotency_key'], 'customer_credit_key_unique');
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at');
            $table->text('reason');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credit_allocations');
        Schema::table('sales_credit_notes', function (Blueprint $table): void {
            $table->dropUnique('sales_credit_issue_sequence_unique');
            $table->dropColumn('issue_sequence');
        });
    }
};
