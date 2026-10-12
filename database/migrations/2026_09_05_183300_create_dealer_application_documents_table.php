<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'dealer_application_documents',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId(
                    'dealer_application_id'
                )
                    ->constrained()
                    ->cascadeOnDelete();

                $table->string(
                    'document_type',
                    50
                );

                $table->string('disk')
                    ->default('local');

                $table->string('path');

                $table->string(
                    'original_name'
                );

                $table->string(
                    'mime_type'
                )->nullable();

                $table->unsignedBigInteger(
                    'file_size'
                )->nullable();

                $table->foreignId(
                    'verified_by_user_id'
                )
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->timestamp(
                    'verified_at'
                )->nullable();

                $table->text(
                    'verification_notes'
                )->nullable();

                $table->timestamps();

                $table->index([
                    'dealer_application_id',
                    'document_type',
                ], 'dealer_application_doc_type_index');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'dealer_application_documents'
        );
    }
};
