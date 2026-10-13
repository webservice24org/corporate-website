<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            // Preserve supplier history even after permitted master deletion.
            $table->unsignedBigInteger('supplier_id');
            $table->string('record_type', 20);
            $table->unsignedBigInteger('record_id');
            $table->string('action', 20);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamp('created_at');
            $table->index(['company_id', 'supplier_id'], 'supplier_audit_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_audit_logs');
    }
};
