<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accounting_period_close_events')) {
            throw new RuntimeException('Period close evidence table already exists. Inspect the migration collision before proceeding.');
        }
        if (Schema::hasColumn('accounting_periods', 'close_evidence_head_id') || Schema::hasColumn('accounting_periods', 'close_evidence_hash')) {
            throw new RuntimeException('Period close anchor columns already exist. Inspect the migration collision.');
        }
        Schema::create('accounting_period_close_events', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('period_id');
            $t->unsignedInteger('sequence');
            $t->string('event_kind', 20);
            $t->unsignedBigInteger('actor_id');
            $t->json('payload');
            $t->char('previous_hash', 64)->nullable();
            $t->char('payload_hash', 64);
            $t->dateTime('occurred_at');
            $t->unique(['company_id', 'period_id', 'sequence'], 'apce_sequence');
            $t->foreign('company_id', 'apce_company')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('actor_id', 'apce_actor')->references('id')->on('users')->restrictOnDelete();
            $t->foreign(['period_id', 'company_id'], 'apce_period')->references(['id', 'company_id'])->on('accounting_periods')->restrictOnDelete();
        });
        Schema::table('accounting_periods', function (Blueprint $t): void {
            $t->unsignedBigInteger('close_evidence_head_id')->nullable();
            $t->char('close_evidence_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('accounting_periods', function (Blueprint $t): void {
            $t->dropColumn(['close_evidence_head_id', 'close_evidence_hash']);
        });
        Schema::dropIfExists('accounting_period_close_events');
    }
};
