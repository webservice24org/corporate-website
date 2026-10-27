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

        foreach (['accounting_expense_finance_plans', 'accounting_expense_finance_events', 'accounting_expense_finance_operations', 'accounting_expense_finance_claims'] as $table) {
            if (Schema::hasTable($table)) {
                $issues[] = $table.' exists';
            }
        }

        foreach (['accounting_expenses' => ['id', 'company_id', 'attachment_revision', 'approved_hash'], 'accounting_source_effects' => ['id', 'company_id'], 'accounts' => ['id', 'company_id'], 'accounting_cash_accounts' => ['id', 'company_id'], 'users' => ['id']] as $table => $fields) {
            if (! Schema::hasTable($table)) {
                $issues[] = $table.' missing';

                continue;
            }

            foreach ($fields as $field) {
                if (! Schema::hasColumn($table, $field)) {
                    $issues[] = $table.'.'.$field.' missing';
                }
            }
        }

        if ($issues === []) {
            $owned = false;
            foreach (Schema::getIndexes('accounting_expenses') as $index) {
                if ($index['unique'] && $index['columns'] === ['id', 'company_id']) {
                    $owned = true;
                }
            }
            if (! $owned) {
                $issues[] = 'expense owned key missing';
            }

            $tables = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
                ? Schema::getTableListing(DB::connection()->getDatabaseName(), false)
                : Schema::getTableListing();
            foreach ($tables as $table) {
                foreach (Schema::getForeignKeys($table) as $foreign) {
                    if (str_starts_with(strtolower((string) ($foreign['name'] ?? '')), 'aef_')) {
                        $issues[] = 'reserved expense finance FK on '.$table;
                    }
                }
            }
        }

        if ($issues !== []) {
            throw new RuntimeException('Expense finance preflight: '.implode('; ', $issues).'. No DDL was attempted.');
        }

        Schema::create('accounting_expense_finance_plans', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('expense_id');
            $t->unsignedBigInteger('active_recognition_id')->nullable();
            $t->string('kind', 12);
            $t->string('status', 12);
            $t->unsignedInteger('revision');

            $t->date('document_date');
            $t->char('currency_code', 3);
            $t->string('amount', 22);
            $t->json('snapshot');
            $t->char('intent_hash', 64);
            $t->char('payment_claim_hash', 64)->nullable();
            $t->char('request_key_hash', 64);
            $t->char('request_hash', 64);

            $t->unsignedBigInteger('created_by');
            $t->dateTime('created_at');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->unsignedBigInteger('posted_by')->nullable();
            $t->dateTime('posted_at')->nullable();
            $t->char('posting_key_hash', 64)->nullable();

            $t->unsignedBigInteger('history_head_id')->nullable();
            $t->char('history_hash', 64)->nullable();

            $t->unique(['id', 'company_id'], 'aef_plan_owned');
            $t->unique(['company_id', 'request_key_hash'], 'aef_plan_key');
            $t->unique(['company_id', 'payment_claim_hash'], 'aef_payment_reference');
            $t->unique('active_recognition_id', 'aef_one_recognition');

            $t->foreign(['expense_id', 'company_id'], 'aef_plan_expense')->references(['id', 'company_id'])->on('accounting_expenses')->restrictOnDelete();

            $t->foreign(['active_recognition_id', 'company_id'], 'aef_plan_active')->references(['id', 'company_id'])->on('accounting_expenses')->restrictOnDelete();

            foreach (['created_by', 'approved_by', 'posted_by'] as $actor) {
                $t->foreign($actor, 'aef_plan_'.$actor)->references('id')->on('users')->restrictOnDelete();
            }
        });

        Schema::create('accounting_expense_finance_events', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('plan_id');
            $t->unsignedInteger('revision');
            $t->string('action', 12);
            $t->unsignedBigInteger('actor_id');
            $t->text('reason');
            $t->dateTime('occurred_at');
            $t->json('payload');
            $t->char('previous_hash', 64)->nullable();
            $t->char('payload_hash', 64);

            $t->unique(['id', 'company_id', 'plan_id'], 'aef_event_owned');
            $t->unique(['plan_id', 'revision'], 'aef_event_revision');

            $t->foreign(['plan_id', 'company_id'], 'aef_event_plan')->references(['id', 'company_id'])->on('accounting_expense_finance_plans')->restrictOnDelete();
            $t->foreign('actor_id', 'aef_event_actor')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('accounting_expense_finance_plans', function (Blueprint $t): void {
            $t->foreign(['history_head_id', 'company_id', 'id'], 'aef_plan_head')->references(['id', 'company_id', 'plan_id'])->on('accounting_expense_finance_events')->restrictOnDelete();
        });

        Schema::create('accounting_expense_finance_operations', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('plan_id');
            $t->unsignedBigInteger('expense_id');
            $t->unsignedBigInteger('recognition_expense_id')->nullable();
            $t->unsignedBigInteger('effect_id');
            $t->json('payload');
            $t->char('payload_hash', 64);

            $t->unique('plan_id', 'aef_operation_plan');
            $t->unique('effect_id', 'aef_operation_effect');
            $t->unique('recognition_expense_id', 'aef_operation_recognition');

            $t->foreign(['plan_id', 'company_id'], 'aef_operation_plan_fk')->references(['id', 'company_id'])->on('accounting_expense_finance_plans')->restrictOnDelete();

            $t->foreign(['expense_id', 'company_id'], 'aef_operation_expense')->references(['id', 'company_id'])->on('accounting_expenses')->restrictOnDelete();

            $t->foreign('effect_id', 'aef_operation_effect_fk')->references('id')->on('accounting_source_effects')->restrictOnDelete();
        });

        Schema::create('accounting_expense_finance_claims', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('expense_id');
            $t->unsignedBigInteger('plan_id');
            $t->char('claim_hash', 64);
            $t->char('intent_hash', 64);

            $t->unique(['company_id', 'claim_hash'], 'aef_claim_identity');
            $t->unique('expense_id', 'aef_claim_expense');

            $t->foreign(['expense_id', 'company_id'], 'aef_claim_expense_fk')->references(['id', 'company_id'])->on('accounting_expenses')->restrictOnDelete();
            $t->foreign(['plan_id', 'company_id'], 'aef_claim_plan')->references(['id', 'company_id'])->on('accounting_expense_finance_plans')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_expense_finance_claims');
        Schema::dropIfExists('accounting_expense_finance_operations');

        Schema::table('accounting_expense_finance_plans', function (Blueprint $t): void {
            $t->dropForeign('aef_plan_head');
        });

        Schema::dropIfExists('accounting_expense_finance_events');
        Schema::dropIfExists('accounting_expense_finance_plans');
    }
};
