<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunity_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('code', 100);
            $table->unsignedTinyInteger('probability')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_won')->default(false);
            $table->boolean('is_lost')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['company_id', 'code'],
                'opportunity_stages_company_code_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunity_stages');
    }
};
