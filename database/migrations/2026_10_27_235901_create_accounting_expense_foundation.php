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

        foreach (['accounting_expense_categories', 'accounting_expenses', 'accounting_expense_events', 'accounting_expense_sequences'] as $table) {
            if (Schema::hasTable($table)) {
                $issues[] = $table.' exists';
            }
        }

        foreach (['companies' => ['id', 'currency_code', 'timezone'], 'users' => ['id'], 'accounts' => ['id', 'company_id'], 'account_groups' => ['id', 'company_id'], 'suppliers' => ['id', 'company_id', 'status'], 'employees' => ['id', 'company_id', 'employment_status'], 'branches' => ['id', 'company_id', 'status'], 'departments' => ['id', 'company_id', 'branch_id', 'status'], 'accounting_cash_accounts' => ['id', 'company_id', 'account_id', 'currency_code', 'kind', 'status'], 'supplier_bills' => ['company_id', 'supplier_id', 'supplier_invoice_number']] as $table => $columns) {
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
            foreach (['accounts', 'accounting_cash_accounts'] as $table) {
                $owned = false;
                foreach (Schema::getIndexes($table) as $index) {
                    if ($index['unique'] && $index['columns'] === ['id', 'company_id']) {
                        $owned = true;
                    }
                }
                if (! $owned) {
                    $issues[] = $table.' lacks owned key';
                }
            }

            $tables = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
                ? Schema::getTableListing(DB::connection()->getDatabaseName(), false)
                : Schema::getTableListing();
            foreach ($tables as $table) {
                foreach (Schema::getForeignKeys($table) as $foreign) {
                    if (str_starts_with(strtolower((string) ($foreign['name'] ?? '')), 'aex_')) {
                        $issues[] = 'reserved expense constraint on '.$table;
                    }
                }
            }
        }

        if ($issues !== []) {
            throw new RuntimeException('Expense foundation preflight: '.implode('; ', $issues).'. No DDL was attempted.');
        }

        Schema::create('accounting_expense_sequences', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedSmallInteger('year');
            $t->unsignedInteger('last_number');
            $t->unique(['company_id', 'year'], 'aex_seq');
            $t->foreign('company_id', 'aex_seq_company')->references('id')->on('companies')->restrictOnDelete();
        });

        Schema::create('accounting_expense_categories', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('code', 30);
            $t->string('name', 160);
            $t->unsignedBigInteger('account_id');
            $t->string('status', 12);
            $t->unsignedInteger('revision')->default(1);

            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('updated_by');
            $t->char('creation_key_hash', 64);
            $t->char('creation_request_hash', 64);
            $t->unsignedBigInteger('history_head_id')->nullable();
            $t->char('history_hash', 64)->nullable();
            $t->timestamps();

            $t->unique(['id', 'company_id'], 'aex_cat_owned');
            $t->unique(['company_id', 'code'], 'aex_cat_code');
            $t->unique(['company_id', 'creation_key_hash'], 'aex_cat_key');

            $t->foreign('company_id', 'aex_cat_company')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign(['account_id', 'company_id'], 'aex_cat_account')->references(['id', 'company_id'])->on('accounts')->restrictOnDelete()->restrictOnUpdate();

            $t->foreign('created_by', 'aex_cat_creator')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('updated_by', 'aex_cat_editor')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('accounting_expenses', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('number', 40);
            $t->date('document_date');
            $t->date('accounting_date');
            $t->char('currency_code', 3);
            $t->string('document_reference', 100);

            $t->unsignedBigInteger('category_id');
            $t->unsignedBigInteger('expense_account_id');
            $t->json('category_snapshot');
            $t->string('payee_type', 12);
            $t->unsignedBigInteger('supplier_id')->nullable();
            $t->unsignedBigInteger('employee_id')->nullable();
            $t->json('payee_snapshot');

            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('department_id')->nullable();
            $t->string('settlement_mode', 24);
            $t->unsignedBigInteger('cash_account_id')->nullable();
            $t->json('cash_snapshot')->nullable();

            // Canonical money TEXT is intentional: native exact cents survive SQLite and MySQL without float hydration.

            $t->string('net_amount', 22);
            $t->string('tax_amount', 22);
            $t->string('total_amount', 22);
            $t->string('tax_treatment', 24);
            $t->unsignedBigInteger('tax_account_id')->nullable();
            $t->json('tax_snapshot')->nullable();
            $t->text('description');

            $t->char('claim_hash', 64);
            $t->char('active_claim_hash', 64)->nullable();
            $t->string('status', 16)->default('draft');
            $t->unsignedInteger('revision')->default(1);

            foreach (['created_by', 'updated_by', 'last_edited_by'] as $actor) {
                $t->unsignedBigInteger($actor);
            }

            foreach (['submitted', 'approved', 'cancelled'] as $action) {
                $t->unsignedBigInteger($action.'_by')->nullable();
                $t->dateTime($action.'_at')->nullable();
            }

            $t->char('submitted_hash', 64)->nullable();
            $t->char('approved_hash', 64)->nullable();
            $t->text('cancellation_reason')->nullable();
            $t->char('creation_key_hash', 64);
            $t->char('creation_request_hash', 64);
            $t->unsignedBigInteger('history_head_id')->nullable();
            $t->char('history_hash', 64)->nullable();
            $t->timestamps();

            $t->unique(['id', 'company_id'], 'aex_doc_owned');
            $t->unique(['company_id', 'number'], 'aex_doc_number');
            $t->unique(['company_id', 'active_claim_hash'], 'aex_doc_claim');
            $t->unique(['company_id', 'creation_key_hash'], 'aex_doc_key');

            $t->foreign('company_id', 'aex_doc_company')->references('id')->on('companies')->restrictOnDelete();

            foreach (['category_id' => 'accounting_expense_categories', 'expense_account_id' => 'accounts', 'tax_account_id' => 'accounts', 'cash_account_id' => 'accounting_cash_accounts'] as $field => $table) {
                $t->foreign([$field, 'company_id'], 'aex_doc_'.$field)->references(['id', 'company_id'])->on($table)->restrictOnDelete()->restrictOnUpdate();
            }

            foreach (['supplier_id' => 'suppliers', 'employee_id' => 'employees', 'branch_id' => 'branches', 'department_id' => 'departments'] as $field => $table) {
                $t->foreign($field, 'aex_doc_'.$field)->references('id')->on($table)->restrictOnDelete();
            }

            foreach (['created_by', 'updated_by', 'last_edited_by', 'submitted_by', 'approved_by', 'cancelled_by'] as $actor) {
                $t->foreign($actor, 'aex_doc_'.$actor)->references('id')->on('users')->restrictOnDelete();
            }
        });

        Schema::create('accounting_expense_events', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('expense_id')->nullable();
            $t->unsignedBigInteger('category_id')->nullable();
            $t->unsignedInteger('revision');
            $t->unsignedBigInteger('actor_id');
            $t->string('action', 16);
            $t->text('reason');
            $t->dateTime('occurred_at');
            $t->char('previous_hash', 64)->nullable();
            $t->json('payload');
            $t->char('payload_hash', 64);

            $t->unique(['id', 'company_id'], 'aex_evt_owned');
            $t->unique(['expense_id', 'revision'], 'aex_evt_doc_rev');
            $t->unique(['category_id', 'revision'], 'aex_evt_cat_rev');

            $t->foreign(['expense_id', 'company_id'], 'aex_evt_doc')->references(['id', 'company_id'])->on('accounting_expenses')->restrictOnDelete();
            $t->foreign(['category_id', 'company_id'], 'aex_evt_cat')->references(['id', 'company_id'])->on('accounting_expense_categories')->restrictOnDelete();
            $t->foreign('actor_id', 'aex_evt_actor')->references('id')->on('users')->restrictOnDelete();
        });

        foreach (['accounting_expenses' => 'aex_doc_head', 'accounting_expense_categories' => 'aex_cat_head'] as $table => $name) {
            Schema::table($table, function (Blueprint $t) use ($name): void {
                $t->foreign(['history_head_id', 'company_id'], $name)->references(['id', 'company_id'])->on('accounting_expense_events')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['accounting_expenses' => 'aex_doc_head', 'accounting_expense_categories' => 'aex_cat_head'] as $table => $name) {
            Schema::table($table, function (Blueprint $t) use ($name): void {
                $t->dropForeign($name);
            });
        }

        Schema::dropIfExists('accounting_expense_events');
        Schema::dropIfExists('accounting_expenses');
        Schema::dropIfExists('accounting_expense_categories');
        Schema::dropIfExists('accounting_expense_sequences');
    }
};
