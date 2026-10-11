<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depot_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('depot_id')
                ->constrained('depots')
                ->cascadeOnDelete();

            /*
             * Optional pointer to the original registration
             * application document. This preserves provenance
             * without making the application table the live
             * document store.
             */
            $table->unsignedBigInteger('source_application_document_id')
                ->nullable();

            $table->string('document_type', 100);
            $table->string('title')->nullable();

            /*
             * Private storage only. Example:
             * erp/depots/{depot_id}/documents/...
             */
            $table->string('disk', 50)->default('local');
            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            /*
             * Multiple historical versions are retained.
             * Only one version per document type should be
             * marked current by application logic.
             */
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
                ['depot_id', 'document_type', 'is_current'],
                'depot_docs_current_idx'
            );

            $table->index(
                ['depot_id', 'document_type', 'version'],
                'depot_docs_version_idx'
            );

            $table->index(
                'source_application_document_id',
                'depot_docs_source_app_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depot_documents');
    }
};
