<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upazilas', function (Blueprint $table) {
            $table->id();

            $table->foreignId('district_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('name');
            $table->string('name_bn')->nullable();
            $table->string('code')->nullable();

            $table->boolean('is_active')
                ->default(true);

            $table->timestamps();

            $table->unique(
                ['district_id', 'name'],
                'upazilas_district_name_unique'
            );

            $table->unique('code');

            $table->index(
                ['district_id', 'is_active'],
                'upazilas_district_active_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upazilas');
    }
};
