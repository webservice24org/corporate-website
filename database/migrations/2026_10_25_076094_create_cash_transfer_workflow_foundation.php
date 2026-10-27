<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['accounting_cash_transfer_sequences', 'accounting_cash_transfers', 'accounting_cash_transfer_audit_events'];

    public function up(): void
    {
        $issues = [];
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                $issues[] = $table.' already exists';
            }
        }
        foreach (['companies' => ['id', 'currency_code', 'timezone'], 'users' => ['id'], 'accounting_cash_accounts' => ['id', 'company_id', 'role', 'kind', 'currency_code', 'account_id', 'status'], 'accounting_mapping_versions' => ['id', 'company_id', 'payload_hash']] as $table => $columns) {
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
            foreach (['accounting_cash_accounts', 'accounting_mapping_versions'] as $table) {
                $owned = false;
                foreach (Schema::getIndexes($table) as $index) {
                    if ($index['columns'] === ['id', 'company_id'] && $index['unique']) {
                        $owned = true;
                    }
                }
                if (! $owned) {
                    $issues[] = $table.' needs its existing unique id/company key';
                }
            }
            $reserved = ['acts_company', 'act_company', 'act_source_owner', 'act_destination_owner', 'act_mapping_owner', 'act_created', 'act_updated', 'act_editor', 'act_submitted', 'act_approved', 'act_cancelled', 'acta_company', 'acta_owner', 'acta_actor'];
            $tables = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
                ? Schema::getTableListing(DB::connection()->getDatabaseName(), false)
                : Schema::getTableListing();
            foreach ($tables as $table) {
                foreach (Schema::getForeignKeys($table) as $foreign) {
                    if (in_array(strtolower((string) ($foreign['name'] ?? '')), $reserved, true)) {
                        $issues[] = 'reserved transfer foreign-key name exists on '.$table;
                    }
                }
            }
        }
        if ($issues !== []) {
            throw new RuntimeException('Cash-transfer foundation preflight: '.implode('; ', $issues).'. No DDL was attempted.');
        }

        Schema::create('accounting_cash_transfer_sequences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'year'], 'acts_company_year');
            $table->foreign('company_id', 'acts_company')->references('id')->on('companies')->restrictOnDelete();
        });
        Schema::create('accounting_cash_transfers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('number', 40);
            $table->date('document_date');
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('source_cash_account_id');
            $table->unsignedBigInteger('destination_cash_account_id');
            $table->decimal('amount', 20, 2);
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->text('purpose');
            $table->text('notes')->nullable();
            $table->json('source_snapshot');
            $table->json('destination_snapshot');
            $table->unsignedBigInteger('mapping_version_id');
            $table->char('mapping_hash', 64);
            $table->char('creation_key_hash', 64);
            $table->char('creation_request_hash', 64);
            $table->char('submitted_payload_hash', 64)->nullable();
            $table->char('approved_payload_hash', 64)->nullable();
            foreach (['created_by' => 'act_created', 'updated_by' => 'act_updated', 'last_edited_by' => 'act_editor'] as $field => $name) {
                $table->unsignedBigInteger($field);
                $table->foreign($field, $name)->references('id')->on('users')->restrictOnDelete();
            }
            foreach (['submitted' => 'act_submitted', 'approved' => 'act_approved', 'cancelled' => 'act_cancelled'] as $action => $name) {
                $table->unsignedBigInteger($action.'_by')->nullable();
                $table->dateTime($action.'_at')->nullable();
                $table->foreign($action.'_by', $name)->references('id')->on('users')->restrictOnDelete();
            }
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->unique(['id', 'company_id'], 'act_id_company');
            $table->unique(['company_id', 'number'], 'act_company_number');
            $table->unique(['company_id', 'creation_key_hash'], 'act_company_creation_key');
            $table->index(['company_id', 'status', 'document_date'], 'act_register');
            $table->foreign('company_id', 'act_company')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['source_cash_account_id', 'company_id'], 'act_source_owner')->references(['id', 'company_id'])->on('accounting_cash_accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['destination_cash_account_id', 'company_id'], 'act_destination_owner')->references(['id', 'company_id'])->on('accounting_cash_accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['mapping_version_id', 'company_id'], 'act_mapping_owner')->references(['id', 'company_id'])->on('accounting_mapping_versions')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('accounting_cash_transfer_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('cash_transfer_id');
            $table->unsignedBigInteger('actor_id');
            $table->string('action', 30);
            $table->text('reason')->nullable();
            $table->json('before_payload')->nullable();
            $table->json('after_payload');
            $table->dateTime('occurred_at');
            $table->foreign('company_id', 'acta_company')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['cash_transfer_id', 'company_id'], 'acta_owner')->references(['id', 'company_id'])->on('accounting_cash_transfers')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('actor_id', 'acta_actor')->references('id')->on('users')->restrictOnDelete();
            $table->index(['company_id', 'cash_transfer_id', 'id'], 'acta_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_cash_transfer_audit_events');
        Schema::dropIfExists('accounting_cash_transfers');
        Schema::dropIfExists('accounting_cash_transfer_sequences');
    }
};
