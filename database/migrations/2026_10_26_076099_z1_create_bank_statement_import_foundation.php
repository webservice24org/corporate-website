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
        foreach (['accounting_bank_statements', 'accounting_bank_statement_lines', 'accounting_bank_statement_evidence'] as $table) {
            if (Schema::hasTable($table)) {
                $issues[] = $table.' already exists';
            }
        }
        foreach (['companies' => ['id'], 'users' => ['id'], 'accounting_cash_accounts' => ['id', 'company_id', 'kind', 'account_id', 'currency_code']] as $table => $columns) {
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
            $owned = false;
            foreach (Schema::getIndexes('accounting_cash_accounts') as $index) {
                if ($index['columns'] === ['id', 'company_id'] && $index['unique']) {
                    $owned = true;
                }
            }
            if (! $owned) {
                $issues[] = 'bank binding needs unique id/company key';
            }
            $names = ['abst_company', 'abst_binding', 'abst_actor', 'absl_statement_bank', 'abse_statement', 'abse_actor'];
            $tables = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
                ? Schema::getTableListing(DB::connection()->getDatabaseName(), false)
                : Schema::getTableListing();
            foreach ($tables as $table) {
                foreach (Schema::getForeignKeys($table) as $foreign) {
                    if (in_array(strtolower((string) ($foreign['name'] ?? '')), $names, true)) {
                        $issues[] = 'reserved statement constraint on '.$table;
                    }
                }
            }
        }
        if ($issues !== []) {
            throw new RuntimeException('Bank statement preflight: '.implode('; ', $issues).'. No DDL was attempted.');
        }
        Schema::create('accounting_bank_statements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('cash_account_id');
            $table->string('statement_reference', 80);
            $table->string('currency_code', 3);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('opening_balance', 20, 2);
            $table->decimal('closing_balance', 20, 2);
            $table->decimal('money_in', 24, 2);
            $table->decimal('money_out', 24, 2);
            $table->unsignedInteger('row_count');
            $table->string('source_mode', 10);
            $table->string('contract_version', 40);
            $table->json('binding_snapshot');
            $table->char('payload_hash', 64);
            $table->char('source_hash', 64);
            $table->char('creation_key_hash', 64);
            $table->char('request_hash', 64);
            $table->unsignedBigInteger('created_by');
            $table->dateTime('imported_at');
            $table->unique(['id', 'company_id'], 'abst_id_company');
            $table->unique(['id', 'company_id', 'cash_account_id'], 'abst_id_company_bank');
            $table->unique(['company_id', 'creation_key_hash'], 'abst_creation_key');
            $table->unique(['company_id', 'cash_account_id', 'statement_reference'], 'abst_bank_reference');
            $table->unique(['company_id', 'cash_account_id', 'payload_hash'], 'abst_payload');
            $table->index(['company_id', 'cash_account_id', 'starts_on', 'ends_on'], 'abst_bank_dates');
            $table->index(['company_id', 'cash_account_id', 'source_hash'], 'abst_source');
            $table->foreign('company_id', 'abst_company')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['cash_account_id', 'company_id'], 'abst_binding')->references(['id', 'company_id'])->on('accounting_cash_accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'abst_actor')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::create('accounting_bank_statement_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('cash_account_id');
            $table->unsignedBigInteger('statement_id');
            $table->unsignedInteger('line_number');
            $table->date('transaction_date');
            $table->date('value_date')->nullable();
            $table->string('transaction_id', 80);
            $table->string('reference', 160);
            $table->text('description');
            $table->decimal('money_in', 20, 2);
            $table->decimal('money_out', 20, 2);
            $table->decimal('reported_balance', 20, 2)->nullable();
            $table->decimal('running_balance', 20, 2);
            $table->char('fingerprint', 64);
            $table->char('transaction_hash', 64)->nullable();
            $table->unique(['id', 'company_id'], 'absl_id_company');
            $table->unique(['statement_id', 'line_number'], 'absl_ordinal');
            $table->unique(['statement_id', 'fingerprint'], 'absl_fingerprint');
            $table->unique(['statement_id', 'transaction_hash'], 'absl_transaction');
            $table->index(['company_id', 'cash_account_id', 'fingerprint'], 'absl_bank_fingerprint');
            $table->index(['company_id', 'cash_account_id', 'transaction_hash'], 'absl_bank_transaction');
            $table->foreign(['statement_id', 'company_id', 'cash_account_id'], 'absl_statement_bank')->references(['id', 'company_id', 'cash_account_id'])->on('accounting_bank_statements')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('accounting_bank_statement_evidence', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('statement_id');
            $table->unsignedBigInteger('actor_id');
            $table->string('contract_version', 40);
            $table->string('source_mode', 10);
            $table->longText('source_text');
            $table->char('source_hash', 64);
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->dateTime('recorded_at');
            $table->unique(['id', 'company_id'], 'abse_id_company');
            $table->unique(['statement_id', 'company_id'], 'abse_one_statement');
            $table->foreign(['statement_id', 'company_id'], 'abse_statement')->references(['id', 'company_id'])->on('accounting_bank_statements')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('actor_id', 'abse_actor')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_bank_statement_evidence');
        Schema::dropIfExists('accounting_bank_statement_lines');
        Schema::dropIfExists('accounting_bank_statements');
    }
};
