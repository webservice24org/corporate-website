<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_sources', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('name', 100);

            $table->string('code', 50);

            $table->text('description')
                ->nullable();

            $table->unsignedInteger('sort_order')
                ->default(0);

            $table->boolean('status')
                ->default(true);

            $table->timestamps();

            $table->unique(
                ['company_id', 'code'],
                'lead_source_company_code_unique'
            );

            $table->index(
                ['company_id', 'status'],
                'lead_source_company_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_sources');
    }
};