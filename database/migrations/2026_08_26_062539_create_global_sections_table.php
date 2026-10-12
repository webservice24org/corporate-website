<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('global_sections', function (Blueprint $table) {
            $table->id();

            $table->string('type')->unique();
            // footer, header

            $table->string('variant');
            // footer-01, header-01

            $table->json('settings')->nullable();

            $table->boolean('is_active')
                ->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_sections');
    }
};
