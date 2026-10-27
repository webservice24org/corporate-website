<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accounting_opening_workspaces')) {
            throw new RuntimeException('accounting_opening_workspaces already exists; reconcile the collision before migrating.');
        }
        Schema::create('accounting_opening_workspaces', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('cutover_id')->unique();
            $t->unsignedBigInteger('journal_entry_id')->unique();
            $t->string('supporting_reference', 500);
            $t->timestamps();
            $t->foreign(['cutover_id', 'company_id'], 'aow_cutover')->references(['id', 'company_id'])->on('accounting_cutovers')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['journal_entry_id', 'company_id'], 'aow_journal')->references(['id', 'company_id'])->on('journal_entries')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_opening_workspaces');
    }
};
