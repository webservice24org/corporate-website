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
        Schema::table('purchase_requisitions', function (Blueprint $table): void {
            $table->unsignedInteger('revision')->default(1);
            $table->string('currency_code', 3)->nullable();
        });
        // Historical estimates did not have a currency snapshot; use their company currency as the baseline.
        DB::table('purchase_requisitions')->join('companies', 'companies.id', '=', 'purchase_requisitions.company_id')
            ->select('purchase_requisitions.id', 'companies.currency_code')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('purchase_requisitions')->where('id', $row->id)->update(['currency_code' => $row->currency_code]);
                }
            }, 'purchase_requisitions.id', 'id');

        Schema::create('purchase_requisition_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_requisition_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 20);
            $table->json('old_values')->nullable();
            $table->json('new_values');
            $table->timestamp('created_at');
            $table->index(['company_id', 'purchase_requisition_id'], 'purchase_requisition_audit_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requisition_audit_logs');
        Schema::table('purchase_requisitions', function (Blueprint $table): void {
            $table->dropColumn(['revision', 'currency_code']);
        });
    }
};
