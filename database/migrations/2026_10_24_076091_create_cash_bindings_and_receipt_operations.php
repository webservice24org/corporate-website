<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['accounting_cash_accounts', 'accounting_receipt_operations'] as $name) {
            if (Schema::hasTable($name)) {
                throw new RuntimeException($name.' already exists; reconcile the collision before migrating.');
            }
        }
        if (Schema::hasColumn('customer_receipts', 'cash_account_id')) {
            throw new RuntimeException('customer_receipts.cash_account_id already exists.');
        }
        if (DB::table('journal_entry_lines')->whereNotNull('cash_account_id')->exists()) {
            throw new RuntimeException('Existing unbound journal cash IDs require reconciliation before creating cash bindings.');
        }
        Schema::create('accounting_cash_accounts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('role', 40);
            $t->string('name', 160);
            $t->string('kind', 10);
            $t->char('currency_code', 3);
            $t->unsignedBigInteger('account_id');
            $t->string('status', 20)->default('active');
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
            $t->unique(['id', 'company_id'], 'aca_id_company');
            $t->unique(['company_id', 'role'], 'aca_company_role');
            $t->unique(['company_id', 'account_id'], 'aca_company_gl');
            $t->foreign(['account_id', 'company_id'], 'aca_gl_company')->references(['id', 'company_id'])->on('accounts')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::table('customer_receipts', function (Blueprint $t): void {
            $t->unique(['id', 'company_id'], 'cr_id_company_finance');
            $t->unsignedBigInteger('cash_account_id')->nullable();
            $t->foreign(['cash_account_id', 'company_id'], 'cr_cash_company')->references(['id', 'company_id'])->on('accounting_cash_accounts')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::table('journal_entry_lines', function (Blueprint $t): void {
            $t->foreign(['cash_account_id', 'company_id'], 'jel_cash_company')->references(['id', 'company_id'])->on('accounting_cash_accounts')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('accounting_receipt_operations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('customer_receipt_id');
            $t->foreign(['customer_receipt_id', 'company_id'], 'aro_receipt_company')->references(['id', 'company_id'])->on('customer_receipts')->restrictOnDelete()->restrictOnUpdate();
            $t->string('kind', 20);
            $t->char('key_hash', 64);
            $t->char('request_hash', 64);
            $t->json('members');
            $t->char('payload_hash', 64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'customer_receipt_id', 'kind', 'key_hash'], 'aro_operation_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_receipt_operations');
        Schema::table('journal_entry_lines', function (Blueprint $t): void {
            $t->dropForeign('jel_cash_company');
        });
        Schema::table('customer_receipts', function (Blueprint $t): void {
            $t->dropForeign('cr_cash_company');
            $t->dropColumn('cash_account_id');
            $t->dropUnique('cr_id_company_finance');
        });
        Schema::dropIfExists('accounting_cash_accounts');
    }
};
