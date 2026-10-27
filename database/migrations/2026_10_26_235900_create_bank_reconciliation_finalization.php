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
        if (Schema::hasTable('accounting_bank_finalization_events')) {
            $issues[] = 'finalization events already exist';
        }
        foreach (['accounting_bank_reconciliations' => ['id', 'company_id', 'cash_account_id', 'statement_id', 'revision'], 'accounting_bank_statements' => ['id', 'ends_on'], 'accounting_cash_accounts' => ['id', 'company_id'], 'companies' => ['id'], 'users' => ['id']] as $table => $columns) {
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
        foreach (['finalization_revision', 'finalization_head_id', 'finalization_hash'] as $column) {
            if (Schema::hasTable('accounting_bank_reconciliations') && Schema::hasColumn('accounting_bank_reconciliations', $column)) {
                $issues[] = 'reserved column '.$column.' exists';
            }
        }
        if ($issues === []) {
            $owned = false;
            foreach (Schema::getIndexes('accounting_bank_reconciliations') as $index) {
                if ($index['unique'] && $index['columns'] === ['id', 'company_id', 'cash_account_id']) {
                    $owned = true;
                }
            }
            if (! $owned) {
                $issues[] = 'reconciliation owned bank key missing';
            }
            $tables = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
                ? Schema::getTableListing(DB::connection()->getDatabaseName(), false)
                : Schema::getTableListing();
            foreach ($tables as $table) {
                foreach (Schema::getForeignKeys($table) as $foreign) {
                    if (in_array(strtolower((string) ($foreign['name'] ?? '')), ['abfe_root', 'abfe_actor', 'abr_final_head'], true)) {
                        $issues[] = 'reserved finalization constraint on '.$table;
                    }
                }
            }
        }
        if ($issues !== []) {
            throw new RuntimeException('Bank finalization preflight: '.implode('; ', $issues).'. No DDL was attempted.');
        }
        Schema::create('accounting_bank_finalization_events', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('cash_account_id');
            $t->unsignedBigInteger('reconciliation_id');
            $t->unsignedInteger('sequence');
            $t->string('event_kind', 12);
            $t->unsignedBigInteger('actor_id');
            $t->text('reason');
            $t->dateTime('occurred_at');
            $t->unsignedInteger('request_revision');
            $t->char('key_hash', 64);
            $t->char('request_hash', 64);
            $t->char('previous_hash', 64)->nullable();
            $t->json('payload');
            $t->char('payload_hash', 64);
            $t->unique(['id', 'company_id'], 'abfe_owned');
            $t->unique(['company_id', 'key_hash'], 'abfe_key');
            $t->unique(['reconciliation_id', 'sequence'], 'abfe_sequence');
            $t->foreign(['reconciliation_id', 'company_id', 'cash_account_id'], 'abfe_root')->references(['id', 'company_id', 'cash_account_id'])->on('accounting_bank_reconciliations')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign('actor_id', 'abfe_actor')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::table('accounting_bank_reconciliations', function (Blueprint $t): void {
            $t->unsignedInteger('finalization_revision')->default(0);
            $t->unsignedBigInteger('finalization_head_id')->nullable();
            $t->char('finalization_hash', 64)->nullable();
            $t->foreign(['finalization_head_id', 'company_id'], 'abr_final_head')->references(['id', 'company_id'])->on('accounting_bank_finalization_events')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('accounting_bank_reconciliations', function (Blueprint $t): void {
            $t->dropForeign('abr_final_head');
            $t->dropColumn(['finalization_revision', 'finalization_head_id', 'finalization_hash']);
        });
        Schema::dropIfExists('accounting_bank_finalization_events');
    }
};
