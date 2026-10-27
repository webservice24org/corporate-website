<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accounting_year_end_events') || Schema::hasColumn('fiscal_years', 'year_end_head_id') || Schema::hasColumn('fiscal_years', 'year_end_hash')) {
            throw new RuntimeException('Year-end schema collision. Inspect retained evidence before migrating.');
        }
        Schema::create('accounting_year_end_events', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('fiscal_year_id');
            $t->unsignedInteger('sequence');
            $t->string('event_kind', 12);
            $t->unsignedBigInteger('actor_id');
            $t->unsignedBigInteger('journal_entry_id')->nullable()->unique('aye_journal');
            $t->char('key_hash', 64);
            $t->char('request_hash', 64);
            $t->json('payload');
            $t->char('previous_hash', 64)->nullable();
            $t->char('payload_hash', 64);
            $t->dateTime('occurred_at');
            $t->unique(['company_id', 'fiscal_year_id', 'sequence'], 'aye_sequence');
            $t->unique(['company_id', 'key_hash'], 'aye_key');
            $t->foreign('company_id', 'aye_company')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('actor_id', 'aye_actor')->references('id')->on('users')->restrictOnDelete();
            $t->foreign(['fiscal_year_id', 'company_id'], 'aye_year')->references(['id', 'company_id'])->on('fiscal_years')->restrictOnDelete();
            $t->foreign(['journal_entry_id', 'company_id'], 'aye_journal_fk')->references(['id', 'company_id'])->on('journal_entries')->restrictOnDelete();
        });
        Schema::table('fiscal_years', function (Blueprint $t): void {
            $t->unsignedBigInteger('year_end_head_id')->nullable();
            $t->char('year_end_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_years', function (Blueprint $t): void {
            $t->dropColumn(['year_end_head_id', 'year_end_hash']);
        });
        Schema::dropIfExists('accounting_year_end_events');
    }
};
