<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'employee_documents',
            function (Blueprint $table) {
                $table->id();

                $table
                    ->foreignId('employee_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table
                    ->foreignId('uploaded_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table
                    ->string(
                        'document_type',
                        50
                    );

                $table->string(
                    'title'
                );

                $table
                    ->string(
                        'document_number',
                        100
                    )
                    ->nullable();

                $table
                    ->date('issue_date')
                    ->nullable();

                $table
                    ->date('expiry_date')
                    ->nullable();

                $table->string(
                    'file_path'
                );

                $table->string(
                    'original_name'
                );

                $table
                    ->string('mime_type')
                    ->nullable();

                $table
                    ->unsignedBigInteger(
                        'file_size'
                    )
                    ->nullable();

                $table
                    ->text('notes')
                    ->nullable();

                $table
                    ->string(
                        'status',
                        20
                    )
                    ->default('active');

                $table->timestamps();

                $table->index([
                    'employee_id',
                    'document_type',
                ]);

                $table->index([
                    'employee_id',
                    'expiry_date',
                ]);

                $table->index([
                    'employee_id',
                    'status',
                ]);
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'employee_documents'
        );
    }
};
