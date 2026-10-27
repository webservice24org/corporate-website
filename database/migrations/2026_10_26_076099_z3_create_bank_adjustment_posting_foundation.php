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
        foreach (['accounting_bank_adjustments', 'accounting_bank_adjustment_operations', 'accounting_bank_adjustment_audit_events'] as $table) {
            if (Schema::hasTable($table)) {
                $issues[] = $table.' already exists';
            }
        }
        foreach (['companies' => ['id'], 'users' => ['id'], 'accounting_cash_accounts' => ['id', 'company_id'], 'accounting_bank_statement_lines' => ['id', 'company_id', 'statement_id'], 'accounts' => ['id', 'company_id'], 'accounting_mapping_versions' => ['id', 'company_id'], 'accounting_source_effects' => ['id', 'company_id'], 'journal_entries' => ['id', 'company_id'], 'accounting_bank_reconciliations' => ['id', 'company_id']] as $table => $columns) {
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
            foreach (['accounting_cash_accounts', 'accounting_bank_statement_lines', 'accounts', 'accounting_mapping_versions', 'accounting_source_effects', 'journal_entries'] as $table) {
                $owned = false;
                foreach (Schema::getIndexes($table) as $index) {
                    if ($index['unique'] && $index['columns'] === ['id', 'company_id']) {
                        $owned = true;
                    }
                } if (! $owned) {
                    $issues[] = $table.' lacks owned key';
                }
            }
            $names = ['aba_company', 'aba_bank', 'aba_line', 'aba_active_line', 'aba_counter', 'aba_original', 'aba_active_original', 'aba_mapping', 'abao_adjustment', 'abao_original', 'abao_effect', 'abao_journal', 'abao_actor', 'abaa_adjustment', 'abaa_actor'];
            foreach (['created_by', 'updated_by', 'last_edited_by', 'submitted_by', 'approved_by', 'posted_by', 'cancelled_by'] as $actor) {
                $names[] = 'aba_'.$actor;
            }
            $tables = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
                ? Schema::getTableListing(DB::connection()->getDatabaseName(), false)
                : Schema::getTableListing();
            foreach ($tables as $table) {
                foreach (Schema::getForeignKeys($table) as $fk) {
                    if (in_array(strtolower((string) ($fk['name'] ?? '')), $names, true)) {
                        $issues[] = 'reserved bank adjustment constraint on '.$table;
                    }
                }
            }
        }
        if ($issues !== []) {
            throw new RuntimeException('Bank adjustment preflight: '.implode('; ', $issues).'. No DDL was attempted.');
        }
        Schema::create('accounting_bank_adjustments', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('cash_account_id');
            $t->unsignedBigInteger('statement_line_id');
            $t->unsignedBigInteger('active_statement_line_id')->nullable();
            $t->unsignedBigInteger('counter_account_id');
            $t->unsignedBigInteger('reverses_id')->nullable();
            $t->unsignedBigInteger('active_reversal_of_id')->nullable();
            $t->char('original_hash', 64)->nullable();
            $t->string('kind', 10);
            $t->string('status', 20)->default('draft');
            $t->unsignedInteger('revision')->default(1);
            $t->decimal('amount', 20, 2);
            $t->string('currency_code', 3);
            $t->date('document_date');
            $t->text('reason');
            $t->json('source_snapshot');
            $t->json('binding_snapshot');
            $t->json('counter_snapshot');
            $t->unsignedBigInteger('mapping_version_id');
            $t->char('mapping_hash', 64);
            $t->char('creation_key_hash', 64);
            $t->char('creation_request_hash', 64);
            $t->char('submitted_hash', 64)->nullable();
            $t->char('approved_hash', 64)->nullable();
            $t->char('posting_key_hash', 64)->nullable();
            $t->unsignedInteger('posting_revision')->nullable();
            foreach (['created_by', 'updated_by', 'last_edited_by', 'submitted_by', 'approved_by', 'posted_by', 'cancelled_by'] as $actor) {
                $column = $t->unsignedBigInteger($actor);
                if (in_array($actor, ['submitted_by', 'approved_by', 'posted_by', 'cancelled_by'], true)) {
                    $column->nullable();
                } $t->foreign($actor, 'aba_'.$actor)->references('id')->on('users')->restrictOnDelete();
            }
            foreach (['submitted_at', 'approved_at', 'posted_at', 'cancelled_at'] as $stamp) {
                $t->dateTime($stamp)->nullable();
            }
            $t->text('cancellation_reason')->nullable();
            $t->timestamps();
            $t->unique(['id', 'company_id'], 'aba_id_company');
            $t->unique(['company_id', 'creation_key_hash'], 'aba_creation');
            $t->unique(['company_id', 'active_statement_line_id'], 'aba_line_claim');
            $t->unique(['company_id', 'active_reversal_of_id'], 'aba_one_reversal');
            $t->foreign('company_id', 'aba_company')->references('id')->on('companies')->restrictOnDelete();
            foreach (['cash_account_id' => ['accounting_cash_accounts', 'aba_bank'], 'statement_line_id' => ['accounting_bank_statement_lines', 'aba_line'], 'active_statement_line_id' => ['accounting_bank_statement_lines', 'aba_active_line'], 'counter_account_id' => ['accounts', 'aba_counter'], 'reverses_id' => ['accounting_bank_adjustments', 'aba_original'], 'active_reversal_of_id' => ['accounting_bank_adjustments', 'aba_active_original'], 'mapping_version_id' => ['accounting_mapping_versions', 'aba_mapping']] as $field => [$parent, $name]) {
                $t->foreign([$field, 'company_id'], $name)->references(['id', 'company_id'])->on($parent)->restrictOnDelete()->restrictOnUpdate();
            }
        });
        Schema::create('accounting_bank_adjustment_operations', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('adjustment_id');
            $t->unsignedBigInteger('original_adjustment_id')->nullable();
            $t->unsignedBigInteger('source_effect_id');
            $t->unsignedBigInteger('journal_entry_id');
            $t->unsignedBigInteger('actor_id');
            $t->char('key_hash', 64);
            $t->unsignedInteger('request_revision');
            $t->string('contract_version', 40);
            $t->json('payload');
            $t->char('payload_hash', 64);
            $t->dateTime('occurred_at');
            $t->unique(['id', 'company_id'], 'abao_id_company');
            $t->unique(['company_id', 'adjustment_id'], 'abao_one_adjustment');
            $t->unique(['company_id', 'original_adjustment_id'], 'abao_one_refund');
            $t->unique('source_effect_id', 'abao_one_effect');
            $t->unique('journal_entry_id', 'abao_one_journal');
            foreach (['adjustment_id' => ['accounting_bank_adjustments', 'abao_adjustment'], 'original_adjustment_id' => ['accounting_bank_adjustments', 'abao_original'], 'source_effect_id' => ['accounting_source_effects', 'abao_effect'], 'journal_entry_id' => ['journal_entries', 'abao_journal']] as $field => [$parent, $name]) {
                $t->foreign([$field, 'company_id'], $name)->references(['id', 'company_id'])->on($parent)->restrictOnDelete()->restrictOnUpdate();
            }
            $t->foreign('actor_id', 'abao_actor')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::create('accounting_bank_adjustment_audit_events', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('adjustment_id');
            $t->unsignedBigInteger('actor_id');
            $t->string('action', 20);
            $t->text('reason');
            $t->json('before_payload')->nullable();
            $t->json('after_payload');
            $t->dateTime('occurred_at');
            $t->foreign(['adjustment_id', 'company_id'], 'abaa_adjustment')->references(['id', 'company_id'])->on('accounting_bank_adjustments')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign('actor_id', 'abaa_actor')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_bank_adjustment_audit_events');
        Schema::dropIfExists('accounting_bank_adjustment_operations');
        Schema::dropIfExists('accounting_bank_adjustments');
    }
};
