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

        foreach (['accounting_expense_attachments', 'accounting_expense_attachment_events', 'accounting_expense_attachment_downloads'] as $table) {
            if (Schema::hasTable($table)) {
                $issues[] = $table.' already exists';
            }
        }

        foreach (['accounting_expenses' => ['id', 'company_id', 'revision', 'status', 'history_head_id', 'history_hash'], 'companies' => ['id'], 'users' => ['id']] as $table => $fields) {
            if (! Schema::hasTable($table)) {
                $issues[] = $table.' is missing';

                continue;
            }

            foreach ($fields as $field) {
                if (! Schema::hasColumn($table, $field)) {
                    $issues[] = $table.'.'.$field.' is missing';
                }
            }
        }

        if (Schema::hasTable('accounting_expenses')) {
            foreach (['attachment_revision', 'attachment_head_id', 'attachment_hash'] as $field) {
                if (Schema::hasColumn('accounting_expenses', $field)) {
                    $issues[] = $field.' already exists';
                }
            }

            $owned = false;

            foreach (Schema::getIndexes('accounting_expenses') as $index) {
                if ($index['unique'] && $index['columns'] === ['id', 'company_id']) {
                    $owned = true;
                }
            }

            if (! $owned) {
                $issues[] = 'expense owned unique key is missing';
            }
        }

        if ($issues === []) {
            $tables = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
                ? Schema::getTableListing(DB::connection()->getDatabaseName(), false)
                : Schema::getTableListing();
            foreach ($tables as $table) {
                foreach (Schema::getForeignKeys($table) as $foreign) {
                    if (str_starts_with(strtolower((string) ($foreign['name'] ?? '')), 'aea_')) {
                        $issues[] = 'reserved attachment constraint on '.$table;
                    }
                }
            }
        }

        if ($issues !== []) {
            throw new RuntimeException('Expense attachment preflight: '.implode('; ', $issues).'. No DDL was attempted.');
        }

        Schema::create('accounting_expense_attachments', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('expense_id');

            $t->string('original_name', 160);
            $t->string('extension', 4);
            $t->string('mime_type', 40);
            $t->unsignedInteger('size_bytes');
            $t->char('sha256', 64);
            $t->string('storage_key', 180);

            $t->unsignedBigInteger('uploaded_by');
            $t->dateTime('uploaded_at');
            $t->char('request_key_hash', 64);
            $t->char('request_hash', 64);

            $t->unique(['id', 'company_id', 'expense_id'], 'aea_file_owned');
            $t->unique(['expense_id', 'request_key_hash'], 'aea_file_request');
            $t->unique('storage_key', 'aea_file_storage');

            $t->foreign(['expense_id', 'company_id'], 'aea_file_expense')->references(['id', 'company_id'])->on('accounting_expenses')->restrictOnDelete()->restrictOnUpdate();

            $t->foreign('uploaded_by', 'aea_file_actor')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('accounting_expense_attachment_events', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('expense_id');
            $t->unsignedBigInteger('attachment_id');
            $t->unsignedInteger('sequence');

            $t->string('action', 12);
            $t->unsignedBigInteger('actor_id');
            $t->text('reason');
            $t->dateTime('occurred_at');
            $t->char('previous_hash', 64)->nullable();
            $t->json('payload');
            $t->char('payload_hash', 64);

            $t->unique(['id', 'company_id', 'expense_id'], 'aea_event_owned');
            $t->unique(['expense_id', 'sequence'], 'aea_event_sequence');

            $t->foreign(['attachment_id', 'company_id', 'expense_id'], 'aea_event_file')->references(['id', 'company_id', 'expense_id'])->on('accounting_expense_attachments')->restrictOnDelete();

            $t->foreign('actor_id', 'aea_event_actor')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('accounting_expense_attachment_downloads', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('expense_id');
            $t->unsignedBigInteger('attachment_id');
            $t->unsignedBigInteger('actor_id');

            $t->unsignedInteger('attachment_revision');
            $t->char('attachment_hash', 64);
            $t->char('sha256', 64);
            $t->unsignedInteger('size_bytes');
            $t->dateTime('occurred_at');

            $t->foreign(['attachment_id', 'company_id', 'expense_id'], 'aea_download_file')->references(['id', 'company_id', 'expense_id'])->on('accounting_expense_attachments')->restrictOnDelete();

            $t->foreign('actor_id', 'aea_download_actor')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('accounting_expenses', function (Blueprint $t): void {
            $t->unsignedInteger('attachment_revision')->default(0);
            $t->unsignedBigInteger('attachment_head_id')->nullable();
            $t->char('attachment_hash', 64)->nullable();

            $t->foreign(['attachment_head_id', 'company_id', 'id'], 'aea_expense_head')->references(['id', 'company_id', 'expense_id'])->on('accounting_expense_attachment_events')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accounting_expenses', function (Blueprint $t): void {
            $t->dropForeign('aea_expense_head');
            $t->dropColumn(['attachment_revision', 'attachment_head_id', 'attachment_hash']);
        });

        Schema::dropIfExists('accounting_expense_attachment_downloads');
        Schema::dropIfExists('accounting_expense_attachment_events');
        Schema::dropIfExists('accounting_expense_attachments');
    }
};
