<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'departments',
            function (Blueprint $table) {
                $table->id();

                $table
                    ->foreignId('company_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table
                    ->foreignId('branch_id')
                    ->nullable()
                    ->constrained()
                    ->nullOnDelete();

                $table->string('name');

                $table->string(
                    'code',
                    50
                );

                $table
                    ->text('description')
                    ->nullable();

                $table
                    ->string(
                        'status',
                        20
                    )
                    ->default('active');

                $table->timestamps();

                /*
                 * Department code is unique
                 * within a company.
                 */
                $table->unique([
                    'company_id',
                    'code',
                ]);

                $table->index([
                    'company_id',
                    'status',
                ]);

                $table->index([
                    'branch_id',
                    'status',
                ]);
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'departments'
        );
    }
};
