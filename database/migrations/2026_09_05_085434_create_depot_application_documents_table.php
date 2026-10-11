<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'depot_application_documents',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId(
                    'depot_application_id'
                )
                    ->constrained()
                    ->cascadeOnDelete();

                $table->enum('document_type', [
                    'bank_check',
                    'trade_license',
                    'nid',
                    'tin',
                    'vat',
                    'bank_statement',
                    'photo',
                    'other',
                ]);

                /*
                 * Sensitive documents remain
                 * on the private local disk.
                 */
                $table->string('disk')
                    ->default('local');

                $table->string('path');

                $table->string('original_name');

                $table->string('mime_type')
                    ->nullable();

                $table->unsignedBigInteger('file_size')
                    ->nullable();

                $table->foreignId('verified_by_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->timestamp('verified_at')
                    ->nullable();

                $table->text('notes')
                    ->nullable();

                $table->timestamps();

                $table->index(
                    [
                        'depot_application_id',
                        'document_type',
                    ],
                    'depot_app_docs_type_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'depot_application_documents'
        );
    }
};