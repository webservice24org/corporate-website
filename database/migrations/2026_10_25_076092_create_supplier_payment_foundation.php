<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const TABLES = ['supplier_payments', 'supplier_payment_targets', 'supplier_payment_allocations', 'supplier_payment_audit_logs', 'accounting_supplier_payment_operations'];

    private const OWNED_INDEXES = ['suppliers' => ['spay_supplier_company', ['id', 'company_id']], 'supplier_bills' => ['spay_bill_company', ['id', 'company_id']], 'supplier_payables' => ['spay_payable_bill_company', ['id', 'supplier_bill_id', 'company_id']]];

    public function up(): void
    {
        // Collect every structural collision before the first DDL; MySQL DDL is not transactional.
        $issues = [];
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                $issues[] = $table . ' already exists';
            }
        }
        foreach (['users' => ['id'], 'companies' => ['id', 'currency_code'], 'suppliers' => ['id', 'company_id', 'currency_code'], 'supplier_bills' => ['id', 'company_id', 'supplier_id', 'currency_code'], 'supplier_payables' => ['id', 'company_id', 'supplier_bill_id', 'supplier_id', 'currency_code', 'paid_amount', 'credited_amount'], 'accounting_cash_accounts' => ['id', 'company_id', 'currency_code', 'kind'], 'purchase_document_sequences' => ['company_id', 'document_type', 'year', 'last_number']] as $table => $columns) {
            if (!Schema::hasTable($table)) {
                $issues[] = $table . ' is missing';

                continue;
            }
            foreach ($columns as $column) {
                if (!Schema::hasColumn($table, $column)) {
                    $issues[] = $table . '.' . $column . ' is missing';
                }
            }
        }
        if ($issues === []) {
            foreach (self::OWNED_INDEXES as $table => $spec) {
                foreach (Schema::getIndexes($table) as $index) {
                    if (strtolower((string) $index['name']) === $spec[0]) {
                        $issues[] = $table . '.' . $spec[0] . ' already exists';
                    }
                }
            }
            $foreignNames = ['sp_supplier_owner', 'sp_cash_owner', 'sp_reversal_owner', 'spt_payment_owner', 'spt_bill_owner', 'spt_payable_owner', 'spa_payment_owner', 'spa_bill_owner', 'spa_payable_owner', 'spa_exact_original', 'spal_payment_owner', 'aspo_payment_owner'];
            foreach (self::TABLES as $table) {
                $foreignNames[] = $table . '_company_id_foreign';
            }
            foreach (['created_by', 'updated_by', 'last_edited_by', 'submitted_by', 'approved_by', 'posted_by', 'cancelled_by'] as $actor) {
                $foreignNames[] = 'supplier_payments_' . $actor . '_foreign';
            }
            $foreignNames = [...$foreignNames, 'supplier_payment_allocations_posted_by_foreign', 'supplier_payment_audit_logs_actor_id_foreign', 'accounting_supplier_payment_operations_created_by_foreign'];
            $tables = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
                ? Schema::getTableListing(DB::connection()->getDatabaseName(), false)
                : Schema::getTableListing();
            foreach ($tables as $table) {
                foreach (Schema::getForeignKeys($table) as $foreign) {
                    if (in_array(strtolower((string) ($foreign['name'] ?? '')), $foreignNames, true)) {
                        $issues[] = 'reserved foreign-key name already exists on ' . $table;
                    }
                }
            }
            $cashKey = false;
            foreach (Schema::getIndexes('accounting_cash_accounts') as $index) {
                if ($index['columns'] === ['id', 'company_id'] && $index['unique']) {
                    $cashKey = true;
                }
            }
            if (!$cashKey) {
                $issues[] = 'accounting_cash_accounts needs its existing unique id/company finance key';
            }
            if (DB::table('supplier_payables')->where('paid_amount', '<>', 0)->exists()) {
                $issues[] = 'nonzero paid summaries require an explicit legacy/import decision';
            }
            if (DB::table('purchase_document_sequences')->where('document_type', 'supplier_payment')->exists()) {
                $issues[] = 'supplier_payment numbering namespace already exists';
            }
        }
        if ($issues !== []) {
            throw new RuntimeException('Supplier-payment foundation preflight: ' . implode('; ', $issues) . '. No DDL was attempted.');
        }
        foreach (self::OWNED_INDEXES as $table => $spec) {
            Schema::table($table, static function (Blueprint $t) use ($spec): void {
                $t->unique($spec[1], $spec[0]);
            });
        }

        Schema::create('supplier_payments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('supplier_id');
            $t->string('number', 80);
            $t->date('document_date');
            $t->char('currency_code', 3);
            $t->string('status', 20)->default('draft');
            $t->unsignedInteger('revision')->default(1);
            $t->unsignedBigInteger('cash_account_id');
            $t->string('payment_method', 20);
            $t->decimal('amount', 20, 2);
            $t->decimal('advance_amount', 20, 2)->default('0.00');
            $t->string('payment_reference', 255)->nullable();
            $t->text('notes')->nullable();
            $t->text('reason')->nullable();
            $t->json('supplier_snapshot')->nullable();
            $t->json('issuer_snapshot')->nullable();
            $t->string('idempotency_key', 64)->nullable();
            $t->char('submitted_payload_hash', 64)->nullable();
            $t->char('approved_payload_hash', 64)->nullable();
            $t->char('payload_hash', 64)->nullable();
            $t->unsignedBigInteger('reverses_payment_id')->nullable();
            foreach (['created_by', 'updated_by', 'last_edited_by'] as $actor) {
                $t->foreignId($actor)->constrained('users')->restrictOnDelete();
            }
            foreach (['submitted', 'approved', 'posted', 'cancelled'] as $action) {
                $t->foreignId($action . '_by')->nullable()->constrained('users')->restrictOnDelete();
                $t->dateTime($action . '_at')->nullable();
            }
            $t->timestamps();
            $t->unique(['id', 'company_id'], 'sp_id_company');
            $t->unique(['company_id', 'number'], 'sp_company_number');
            $t->unique(['company_id', 'idempotency_key'], 'sp_company_key');
            $t->unique('reverses_payment_id', 'sp_one_reversal');
            $t->index(['company_id', 'supplier_id', 'document_date'], 'sp_supplier_date');
            $this->owned($t, ['supplier_id', 'company_id'], 'suppliers', ['id', 'company_id'], 'sp_supplier_owner');
            $this->owned($t, ['cash_account_id', 'company_id'], 'accounting_cash_accounts', ['id', 'company_id'], 'sp_cash_owner');
            $this->owned($t, ['reverses_payment_id', 'company_id'], 'supplier_payments', ['id', 'company_id'], 'sp_reversal_owner');
        });
        Schema::create('supplier_payment_targets', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('supplier_payment_id');
            $t->unsignedBigInteger('supplier_bill_id');
            $t->unsignedBigInteger('supplier_payable_id');
            $t->decimal('amount', 20, 2);
            $t->timestamps();
            $t->unique(['supplier_payment_id', 'supplier_bill_id'], 'spt_payment_bill');
            $this->lineOwners($t, 'spt');
        });
        Schema::create('supplier_payment_allocations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('supplier_payment_id');
            $t->unsignedBigInteger('supplier_bill_id');
            $t->unsignedBigInteger('supplier_payable_id');
            $t->decimal('amount', 20, 2);
            $t->unsignedBigInteger('reverses_allocation_id')->nullable();
            $t->string('idempotency_key', 120);
            $t->char('payload_hash', 64);
            $t->text('reason')->nullable();
            $t->foreignId('posted_by')->constrained('users')->restrictOnDelete();
            $t->dateTime('posted_at');
            $t->timestamps();
            $t->unique(['company_id', 'idempotency_key'], 'spa_company_key');
            $t->unique('reverses_allocation_id', 'spa_one_compensation');
            $t->index(['company_id', 'supplier_bill_id', 'posted_at'], 'spa_bill_date');
            $identity = ['id', 'supplier_payment_id', 'supplier_bill_id', 'supplier_payable_id', 'company_id', 'amount'];
            $t->unique($identity, 'spa_exact_identity');
            $this->lineOwners($t, 'spa');
            $this->owned($t, ['reverses_allocation_id', 'supplier_payment_id', 'supplier_bill_id', 'supplier_payable_id', 'company_id', 'amount'], 'supplier_payment_allocations', $identity, 'spa_exact_original');
        });
        Schema::create('supplier_payment_audit_logs', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('supplier_payment_id');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('action', 40);
            $t->text('reason')->nullable();
            $t->json('old_values')->nullable();
            $t->json('new_values');
            $t->dateTime('created_at');
            $this->owned($t, ['supplier_payment_id', 'company_id'], 'supplier_payments', ['id', 'company_id'], 'spal_payment_owner');
        });
        Schema::create('accounting_supplier_payment_operations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('supplier_payment_id');
            $t->string('kind', 20);
            $t->string('contract_version', 40);
            $t->char('key_hash', 64);
            $t->char('request_hash', 64);
            $t->json('members');
            $t->char('payload_hash', 64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'supplier_payment_id', 'kind', 'key_hash'], 'aspo_operation');
            $this->owned($t, ['supplier_payment_id', 'company_id'], 'supplier_payments', ['id', 'company_id'], 'aspo_payment_owner');
        });
    }

    private function lineOwners(Blueprint $t, string $prefix): void
    {
        $this->owned($t, ['supplier_payment_id', 'company_id'], 'supplier_payments', ['id', 'company_id'], $prefix . '_payment_owner');
        $this->owned($t, ['supplier_bill_id', 'company_id'], 'supplier_bills', ['id', 'company_id'], $prefix . '_bill_owner');
        $this->owned($t, ['supplier_payable_id', 'supplier_bill_id', 'company_id'], 'supplier_payables', ['id', 'supplier_bill_id', 'company_id'], $prefix . '_payable_owner');
    }

    /** @param list<string> $columns @param list<string> $references */
    private function owned(Blueprint $t, array $columns, string $table, array $references, string $name): void
    {
        $t->foreign($columns, $name)->references($references)->on($table)->restrictOnDelete()->restrictOnUpdate();
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
        foreach (array_reverse(self::OWNED_INDEXES, true) as $table => $spec) {
            Schema::table($table, static function (Blueprint $t) use ($spec): void {
                $t->dropUnique($spec[0]);
            });
        }
    }
};
