<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accounting_manifest_versions')) {
            throw new RuntimeException('accounting_manifest_versions already exists; reconcile the collision before migrating.');
        }
        Schema::create('accounting_manifest_versions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('cutover_id');
            $t->unsignedInteger('cutover_revision');
            $t->unsignedBigInteger('opening_journal_entry_id');
            $t->json('payload');
            $t->char('payload_hash', 64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['cutover_id', 'cutover_revision'], 'amv_cutover_revision');
            $t->foreign(['cutover_id', 'company_id'], 'amv_cutover')->references(['id', 'company_id'])->on('accounting_cutovers')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['opening_journal_entry_id', 'company_id'], 'amv_opening')->references(['id', 'company_id'])->on('journal_entries')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_manifest_versions');
    }
};
