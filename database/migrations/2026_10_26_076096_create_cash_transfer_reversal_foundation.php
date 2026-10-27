<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['accounting_cash_transfer_reversal_requests', 'accounting_cash_transfer_reversal_audit_events'];

    public function up(): void
    {
        $issues = [];
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                $issues[] = $table.' already exists';
            }
        }
        foreach (['companies' => ['id'], 'users' => ['id'], 'accounting_cash_transfers' => ['id', 'company_id', 'status', 'posted_at'], 'accounting_cash_transfer_operations' => ['id', 'company_id', 'cash_transfer_id', 'payload_hash'], 'accounting_cash_accounts' => ['id', 'company_id'], 'journal_entries' => ['id', 'company_id']] as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $issues[] = $table.' is missing';

                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $issues[] = $table.'.'.$column.' is missing';
                }
            }
        }
        if ($issues === []) {
            foreach (['accounting_cash_transfers', 'accounting_cash_accounts', 'journal_entries'] as $table) {
                $owned = false;
                foreach (Schema::getIndexes($table) as $index) {
                    if ($index['columns'] === ['id', 'company_id'] && $index['unique']) {
                        $owned = true;
                    }
                }
                if (! $owned) {
                    $issues[] = $table.' lacks its unique id/company key';
                }
            }
            $reserved = ['actrr_company', 'actrr_original', 'actrr_slot', 'actrr_journal', 'actrr_source', 'actrr_destination', 'actrr_created', 'actrr_updated', 'actrr_editor', 'actrr_submitted', 'actrr_approved', 'actrr_cancelled', 'actra_company', 'actra_request', 'actra_actor'];
            $tables = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
                ? Schema::getTableListing(DB::connection()->getDatabaseName(), false)
                : Schema::getTableListing();
            foreach ($tables as $table) {
                foreach (Schema::getForeignKeys($table) as $foreign) {
                    if (in_array(strtolower((string) ($foreign['name'] ?? '')), $reserved, true)) {
                        $issues[] = 'reserved reversal constraint exists on '.$table;
                    }
                }
            }
        }
        if ($issues !== []) {
            throw new RuntimeException('Transfer reversal preflight: '.implode('; ', $issues).'. No DDL was attempted.');
        }
        Schema::create('accounting_cash_transfer_reversal_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('original_transfer_id');
            $table->unsignedBigInteger('active_transfer_id')->nullable();
            $table->unsignedBigInteger('original_journal_id');
            $table->unsignedBigInteger('source_cash_account_id');
            $table->unsignedBigInteger('destination_cash_account_id');
            $table->char('currency_code', 3);
            $table->decimal('amount', 20, 2);
            $table->date('document_date');
            $table->text('reason');
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->json('original_snapshot');
            $table->char('original_snapshot_hash', 64);
            $table->char('creation_key_hash', 64);
            $table->char('creation_request_hash', 64);
            $table->char('submitted_payload_hash', 64)->nullable();
            $table->char('approved_payload_hash', 64)->nullable();
            foreach (['created_by' => 'actrr_created', 'updated_by' => 'actrr_updated', 'last_edited_by' => 'actrr_editor'] as $field => $name) {
                $table->unsignedBigInteger($field);
                $table->foreign($field, $name)->references('id')->on('users')->restrictOnDelete();
            }
            foreach (['submitted' => 'actrr_submitted', 'approved' => 'actrr_approved', 'cancelled' => 'actrr_cancelled'] as $action => $name) {
                $table->unsignedBigInteger($action.'_by')->nullable();
                $table->dateTime($action.'_at')->nullable();
                $table->foreign($action.'_by', $name)->references('id')->on('users')->restrictOnDelete();
            }
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->unique(['id', 'company_id'], 'actrr_id_company');
            $table->unique(['company_id', 'creation_key_hash'], 'actrr_creation_key');
            // MySQL and SQLite both permit multiple NULL entries: cancelled requests release this slot.
            $table->unique(['company_id', 'active_transfer_id'], 'actrr_one_active');
            $table->index(['company_id', 'status', 'document_date'], 'actrr_register');
            $table->foreign('company_id', 'actrr_company')->references('id')->on('companies')->restrictOnDelete();
            foreach (['original_transfer_id' => 'actrr_original', 'active_transfer_id' => 'actrr_slot'] as $field => $name) {
                $table->foreign([$field, 'company_id'], $name)->references(['id', 'company_id'])->on('accounting_cash_transfers')->restrictOnDelete()->restrictOnUpdate();
            }
            $table->foreign(['original_journal_id', 'company_id'], 'actrr_journal')->references(['id', 'company_id'])->on('journal_entries')->restrictOnDelete()->restrictOnUpdate();
            foreach (['source_cash_account_id' => 'actrr_source', 'destination_cash_account_id' => 'actrr_destination'] as $field => $name) {
                $table->foreign([$field, 'company_id'], $name)->references(['id', 'company_id'])->on('accounting_cash_accounts')->restrictOnDelete()->restrictOnUpdate();
            }
        });
        Schema::create('accounting_cash_transfer_reversal_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('reversal_request_id');
            $table->unsignedBigInteger('actor_id');
            $table->string('action', 30);
            $table->text('reason')->nullable();
            $table->json('before_payload')->nullable();
            $table->json('after_payload');
            $table->dateTime('occurred_at');
            $table->foreign('company_id', 'actra_company')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['reversal_request_id', 'company_id'], 'actra_request')->references(['id', 'company_id'])->on('accounting_cash_transfer_reversal_requests')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('actor_id', 'actra_actor')->references('id')->on('users')->restrictOnDelete();
            $table->index(['company_id', 'reversal_request_id', 'id'], 'actra_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_cash_transfer_reversal_audit_events');
        Schema::dropIfExists('accounting_cash_transfer_reversal_requests');
    }
};
