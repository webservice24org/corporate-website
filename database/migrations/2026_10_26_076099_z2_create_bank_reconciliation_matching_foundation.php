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
        $issues = [];
        foreach (['accounting_bank_reconciliations', 'accounting_bank_reconciliation_operations', 'accounting_bank_reconciliation_legs'] as $table) {
            if (Schema::hasTable($table)) {
                $issues[] = $table.' already exists';
            }
        }
        foreach (['users' => ['id'], 'companies' => ['id'], 'accounting_cash_accounts' => ['id', 'company_id'], 'accounting_bank_statements' => ['id', 'company_id', 'cash_account_id', 'payload_hash', 'source_hash'], 'accounting_bank_statement_lines' => ['id', 'company_id', 'statement_id'], 'journal_entry_lines' => ['id', 'company_id', 'cash_account_id']] as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $issues[] = $table.' missing';

                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $issues[] = $table.'.'.$column.' missing';
                }
            }
        }
        if ($issues === []) {
            foreach (['accounting_cash_accounts' => ['id', 'company_id'], 'accounting_bank_statements' => ['id', 'company_id', 'cash_account_id'], 'accounting_bank_statement_lines' => ['id', 'company_id'], 'journal_entry_lines' => ['id', 'company_id']] as $table => $columns) {
                $found = false;
                foreach (Schema::getIndexes($table) as $index) {
                    if ($index['unique'] && $index['columns'] === $columns) {
                        $found = true;
                    }
                }
                if (! $found) {
                    $issues[] = $table.' missing owned unique key';
                }
            }
            $reserved = ['abr_company', 'abr_statement_bank', 'abr_creator', 'abr_editor', 'abr_head', 'abro_root', 'abro_actor', 'abro_target', 'abrl_operation', 'abrl_statement', 'abrl_book'];
            $tables = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
                ? Schema::getTableListing(DB::connection()->getDatabaseName(), false)
                : Schema::getTableListing();
            foreach ($tables as $table) {
                foreach (Schema::getForeignKeys($table) as $foreign) {
                    if (in_array(strtolower((string) ($foreign['name'] ?? '')), $reserved, true)) {
                        $issues[] = 'reserved matching constraint on '.$table;
                    }
                }
            }
        }
        if ($issues !== []) {
            throw new RuntimeException('Bank reconciliation preflight: '.implode('; ', $issues).'. No DDL was attempted.');
        }
        Schema::create('accounting_bank_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('cash_account_id');
            $table->unsignedBigInteger('statement_id');
            $table->string('currency_code', 3);
            $table->string('contract_version', 40);
            $table->text('reason');
            $table->json('snapshot');
            $table->char('snapshot_hash', 64);
            $table->char('creation_key_hash', 64);
            $table->char('creation_request_hash', 64);
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedBigInteger('last_operation_id')->nullable();
            $table->char('last_operation_hash', 64);
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->unique(['id', 'company_id'], 'abr_id_company');
            $table->unique(['id', 'company_id', 'cash_account_id'], 'abr_id_company_bank');
            $table->unique(['company_id', 'statement_id'], 'abr_one_statement');
            $table->unique(['company_id', 'creation_key_hash'], 'abr_creation_key');
            $table->foreign('company_id', 'abr_company')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['statement_id', 'company_id', 'cash_account_id'], 'abr_statement_bank')->references(['id', 'company_id', 'cash_account_id'])->on('accounting_bank_statements')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'abr_creator')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by', 'abr_editor')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::create('accounting_bank_reconciliation_operations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('cash_account_id');
            $table->unsignedBigInteger('reconciliation_id');
            $table->string('kind', 10);
            $table->unsignedInteger('revision_from');
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->text('reason');
            $table->unsignedBigInteger('actor_id');
            $table->dateTime('occurred_at');
            $table->unsignedBigInteger('target_match_id')->nullable();
            $table->string('direction', 3);
            $table->decimal('total', 24, 2);
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->unique(['id', 'company_id'], 'abro_id_company');
            $table->unique(['id', 'company_id', 'cash_account_id'], 'abro_id_company_bank');
            $table->unique(['company_id', 'key_hash'], 'abro_key');
            $table->unique(['reconciliation_id', 'revision_from'], 'abro_revision');
            $table->unique('target_match_id', 'abro_one_undo');
            $table->foreign(['reconciliation_id', 'company_id', 'cash_account_id'], 'abro_root')->references(['id', 'company_id', 'cash_account_id'])->on('accounting_bank_reconciliations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['target_match_id', 'company_id'], 'abro_target')->references(['id', 'company_id'])->on('accounting_bank_reconciliation_operations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('actor_id', 'abro_actor')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::create('accounting_bank_reconciliation_legs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('cash_account_id');
            $table->unsignedBigInteger('operation_id');
            $table->string('side', 10);
            $table->unsignedBigInteger('statement_line_id')->nullable();
            $table->unsignedBigInteger('journal_line_id')->nullable();
            $table->decimal('amount', 20, 2);
            $table->string('direction', 3);
            $table->char('proof_hash', 64);
            $table->unique(['id', 'company_id'], 'abrl_id_company');
            $table->unique(['operation_id', 'statement_line_id'], 'abrl_statement_unique');
            $table->unique(['operation_id', 'journal_line_id'], 'abrl_book_unique');
            $table->foreign(['operation_id', 'company_id', 'cash_account_id'], 'abrl_operation')->references(['id', 'company_id', 'cash_account_id'])->on('accounting_bank_reconciliation_operations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['statement_line_id', 'company_id'], 'abrl_statement')->references(['id', 'company_id'])->on('accounting_bank_statement_lines')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['journal_line_id', 'company_id'], 'abrl_book')->references(['id', 'company_id'])->on('journal_entry_lines')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::table('accounting_bank_reconciliations', function (Blueprint $table): void {
            $table->foreign(['last_operation_id', 'company_id'], 'abr_head')->references(['id', 'company_id'])->on('accounting_bank_reconciliation_operations')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('accounting_bank_reconciliations', function (Blueprint $table): void {
            $table->dropForeign('abr_head');
        });
        Schema::dropIfExists('accounting_bank_reconciliation_legs');
        Schema::dropIfExists('accounting_bank_reconciliation_operations');
        Schema::dropIfExists('accounting_bank_reconciliations');
    }
};
