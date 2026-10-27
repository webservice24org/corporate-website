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
        if (Schema::hasTable('accounting_cash_transfer_operations')) {
            $issues[] = 'operation table already exists';
        }
        foreach (['accounting_cash_transfers' => ['id', 'company_id', 'approved_payload_hash'], 'accounting_source_effects' => ['id', 'company_id'], 'journal_entries' => ['id', 'company_id'], 'users' => ['id']] as $table => $columns) {
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
        foreach (['posted_by', 'posted_at', 'posting_key_hash', 'posting_revision'] as $column) {
            if (Schema::hasColumn('accounting_cash_transfers', $column)) {
                $issues[] = 'posting column already exists: '.$column;
            }
        }
        if ($issues === []) {
            foreach (['accounting_cash_transfers', 'accounting_source_effects', 'journal_entries'] as $table) {
                $owned = false;
                foreach (Schema::getIndexes($table) as $index) {
                    if ($index['columns'] === ['id', 'company_id'] && $index['unique']) {
                        $owned = true;
                    }
                }
                if (! $owned) {
                    $issues[] = $table.' lacks unique id/company key';
                }
            }
            $tables = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
                ? Schema::getTableListing(DB::connection()->getDatabaseName(), false)
                : Schema::getTableListing();
            foreach ($tables as $table) {
                foreach (Schema::getForeignKeys($table) as $foreign) {
                    if (in_array(strtolower((string) ($foreign['name'] ?? '')), ['act_posted_actor', 'acto_transfer', 'acto_effect', 'acto_journal', 'acto_actor'], true)) {
                        $issues[] = 'reserved posting constraint on '.$table;
                    }
                }
            }
        }
        if ($issues !== []) {
            throw new RuntimeException('Transfer posting preflight: '.implode('; ', $issues).'. No DDL was attempted.');
        }
        Schema::table('accounting_cash_transfers', function (Blueprint $table): void {
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->char('posting_key_hash', 64)->nullable();
            $table->unsignedInteger('posting_revision')->nullable();
            $table->foreign('posted_by', 'act_posted_actor')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::create('accounting_cash_transfer_operations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('cash_transfer_id');
            $table->unsignedBigInteger('source_effect_id');
            $table->unsignedBigInteger('journal_entry_id');
            $table->unsignedBigInteger('actor_id');
            $table->char('key_hash', 64);
            $table->unsignedInteger('request_revision');
            $table->string('contract_version', 40);
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->dateTime('occurred_at');
            $table->unique(['company_id', 'cash_transfer_id'], 'acto_one_transfer');
            $table->unique('source_effect_id', 'acto_one_effect');
            $table->unique('journal_entry_id', 'acto_one_journal');
            $table->foreign(['cash_transfer_id', 'company_id'], 'acto_transfer')->references(['id', 'company_id'])->on('accounting_cash_transfers')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['source_effect_id', 'company_id'], 'acto_effect')->references(['id', 'company_id'])->on('accounting_source_effects')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['journal_entry_id', 'company_id'], 'acto_journal')->references(['id', 'company_id'])->on('journal_entries')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('actor_id', 'acto_actor')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_cash_transfer_operations');
        Schema::table('accounting_cash_transfers', function (Blueprint $table): void {
            $table->dropForeign('act_posted_actor');
            $table->dropColumn(['posted_by', 'posted_at', 'posting_key_hash', 'posting_revision']);
        });
    }
};
