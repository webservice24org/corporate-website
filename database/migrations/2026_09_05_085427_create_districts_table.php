<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('districts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('division_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('name');
            $table->string('name_bn')->nullable();
            $table->string('code')->nullable();

            $table->boolean('is_active')
                ->default(true);

            $table->timestamps();

            $table->unique(
                ['division_id', 'name'],
                'districts_division_name_unique'
            );

            $table->unique('code');

            $table->index(
                ['division_id', 'is_active'],
                'districts_division_active_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('districts');
    }
};
