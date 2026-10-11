<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'designations',
            function (Blueprint $table) {
                $table->id();

                $table
                    ->foreignId('company_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table
                    ->foreignId('department_id')
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
                    ->unsignedInteger(
                        'sort_order'
                    )
                    ->default(0);

                $table
                    ->string(
                        'status',
                        20
                    )
                    ->default('active');

                $table->timestamps();

                $table->unique([
                    'company_id',
                    'code',
                ]);

                $table->index([
                    'company_id',
                    'status',
                ]);

                $table->index([
                    'department_id',
                    'status',
                ]);

                $table->index(
                    'sort_order'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'designations'
        );
    }
};