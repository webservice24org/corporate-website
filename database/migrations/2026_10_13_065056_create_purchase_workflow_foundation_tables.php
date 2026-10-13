<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_document_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('document_type', 20);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'document_type', 'year'], 'purchase_sequence_scope_unique');
        });

        Schema::create('purchase_workflow_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('document_type', 20);
            $table->unsignedBigInteger('document_id');
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->text('comment')->nullable();
            $table->timestamp('created_at');
            $table->index(['company_id', 'document_type', 'document_id'], 'purchase_event_document_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_workflow_events');
        Schema::dropIfExists('purchase_document_sequences');
    }
};
