<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['accounting_supplier_credits', 'accounting_supplier_credit_lines'] as $name) {
            if (Schema::hasTable($name)) {
                throw new RuntimeException($name.' already exists.');
            }
        }
        if (Schema::hasColumn('supplier_payables', 'credited_amount')) {
            throw new RuntimeException('supplier_payables.credited_amount already exists.');
        }
        Schema::create('accounting_supplier_credits', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignId('supplier_bill_id')->constrained('supplier_bills')->restrictOnDelete();
            $t->foreignId('purchase_return_id')->constrained('purchase_returns')->restrictOnDelete();
            $t->string('number', 60)->nullable();
            $t->string('status', 20);
            $t->unsignedInteger('revision')->default(1);
            $t->date('document_date');
            $t->char('currency_code', 3);
            $t->decimal('amount', 20, 2);
            $t->text('reason');
            $t->char('payload_hash', 64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->dateTime('approved_at')->nullable();
            $t->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->dateTime('issued_at')->nullable();
            $t->timestamps();
            $t->unique(['id', 'company_id'], 'asc_id_company');
            $t->unique(['company_id', 'number'], 'asc_number_company');
        });
        Schema::create('accounting_supplier_credit_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('supplier_credit_id');
            $t->foreign(['supplier_credit_id', 'company_id'], 'ascl_credit_company')->references(['id', 'company_id'])->on('accounting_supplier_credits')->restrictOnDelete()->restrictOnUpdate();
            $t->foreignId('supplier_bill_item_id')->constrained('supplier_bill_items')->restrictOnDelete();
            $t->foreignId('purchase_return_item_id')->constrained('purchase_return_items')->restrictOnDelete();
            $t->decimal('quantity', 18, 4);
            $t->decimal('net', 20, 2);
            $t->decimal('tax', 20, 2);
            $t->decimal('clearing_amount', 20, 2);
            $t->boolean('is_stock_item');
            $t->timestamps();
            $t->unique(['supplier_credit_id', 'supplier_bill_item_id', 'purchase_return_item_id'], 'ascl_source_pair');
        });
        Schema::table('supplier_payables', function (Blueprint $t): void {
            $t->decimal('credited_amount', 20, 2)->default('0.00');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payables', function (Blueprint $t): void {
            $t->dropColumn('credited_amount');
        });
        Schema::dropIfExists('accounting_supplier_credit_lines');
        Schema::dropIfExists('accounting_supplier_credits');
    }
};
