<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dealer_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('dealer_id')
                ->constrained('dealers')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('source_application_document_id')
                ->nullable();

            $table->string('document_type', 100);
            $table->string('title')->nullable();

            $table->string('disk', 50)->default('local');
            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_current')->default(true);

            $table->timestamp('verified_at')->nullable();

            $table->foreignId('verified_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(
                ['dealer_id', 'document_type', 'is_current'],
                'dealer_docs_current_idx'
            );

            $table->index(
                ['dealer_id', 'document_type', 'version'],
                'dealer_docs_version_idx'
            );

            $table->index(
                'source_application_document_id',
                'dealer_docs_source_app_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dealer_documents');
    }
};
