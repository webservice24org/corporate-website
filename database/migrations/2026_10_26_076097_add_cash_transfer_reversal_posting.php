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
        if (Schema::hasTable('accounting_cash_transfer_reversal_operations')) {
            $issues[] = 'reversal operation table already exists';
        }
        foreach (['accounting_cash_transfer_reversal_requests' => ['id', 'company_id', 'original_transfer_id', 'original_journal_id', 'active_transfer_id'], 'accounting_cash_transfers' => ['id', 'company_id'], 'accounting_source_effects' => ['id', 'company_id'], 'journal_entries' => ['id', 'company_id'], 'users' => ['id']] as $table => $columns) {
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
        foreach (['posted_by', 'posted_at', 'posting_revision', 'posting_key_hash'] as $column) {
            if (Schema::hasColumn('accounting_cash_transfer_reversal_requests', $column)) {
                $issues[] = 'posting column already exists: '.$column;
            }
        }
        if ($issues === []) {
            foreach (['accounting_cash_transfer_reversal_requests', 'accounting_cash_transfers', 'accounting_source_effects', 'journal_entries'] as $table) {
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
            $reserved = ['actrr_post_actor', 'actro_request', 'actro_original', 'actro_original_journal', 'actro_effect', 'actro_journal', 'actro_actor'];
            $tables = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
                ? Schema::getTableListing(DB::connection()->getDatabaseName(), false)
                : Schema::getTableListing();
            foreach ($tables as $table) {
                foreach (Schema::getForeignKeys($table) as $foreign) {
                    if (in_array(strtolower((string) ($foreign['name'] ?? '')), $reserved, true)) {
                        $issues[] = 'reserved reversal posting constraint on '.$table;
                    }
                }
            }
        }
        if ($issues !== []) {
            throw new RuntimeException('Transfer reversal posting preflight: '.implode('; ', $issues).'. No DDL was attempted.');
        }
        Schema::table('accounting_cash_transfer_reversal_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->unsignedInteger('posting_revision')->nullable();
            $table->char('posting_key_hash', 64)->nullable();
            $table->foreign('posted_by', 'actrr_post_actor')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::create('accounting_cash_transfer_reversal_operations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('reversal_request_id');
            $table->unsignedBigInteger('original_transfer_id');
            $table->unsignedBigInteger('original_journal_id');
            $table->unsignedBigInteger('source_effect_id');
            $table->unsignedBigInteger('journal_entry_id');
            $table->unsignedBigInteger('actor_id');
            $table->char('key_hash', 64);
            $table->unsignedInteger('request_revision');
            $table->string('contract_version', 40);
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->dateTime('occurred_at');
            $table->unique(['id', 'company_id'], 'actro_id_company');
            $table->unique(['company_id', 'reversal_request_id'], 'actro_one_request');
            $table->unique(['company_id', 'original_transfer_id'], 'actro_one_original');
            $table->unique('source_effect_id', 'actro_one_effect');
            $table->unique('journal_entry_id', 'actro_one_journal');
            foreach (['reversal_request_id' => ['accounting_cash_transfer_reversal_requests', 'actro_request'], 'original_transfer_id' => ['accounting_cash_transfers', 'actro_original'], 'original_journal_id' => ['journal_entries', 'actro_original_journal'], 'source_effect_id' => ['accounting_source_effects', 'actro_effect'], 'journal_entry_id' => ['journal_entries', 'actro_journal']] as $field => [$parent, $name]) {
                $table->foreign([$field, 'company_id'], $name)->references(['id', 'company_id'])->on($parent)->restrictOnDelete()->restrictOnUpdate();
            }
            $table->foreign('actor_id', 'actro_actor')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_cash_transfer_reversal_operations');
        Schema::table('accounting_cash_transfer_reversal_requests', function (Blueprint $table): void {
            $table->dropForeign('actrr_post_actor');
            $table->dropColumn(['posted_by', 'posted_at', 'posting_revision', 'posting_key_hash']);
        });
    }
};
