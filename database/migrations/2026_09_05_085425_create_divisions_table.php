<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('divisions', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('name_bn')->nullable();
            $table->string('code')->nullable();

            $table->boolean('is_active')
                ->default(true);

            $table->timestamps();

            $table->unique('name');
            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('divisions');
    }
};
