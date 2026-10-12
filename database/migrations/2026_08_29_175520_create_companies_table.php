<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('legal_name')->nullable();

            $table->string('code', 50)->unique();

            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('website')->nullable();

            $table->string('registration_number')->nullable();
            $table->string('tax_number')->nullable();

            $table->text('address')->nullable();

            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code', 30)->nullable();
            $table->string('country')->nullable();

            $table->string('currency_code', 10)
                ->default('BDT');

            $table->string('timezone')
                ->default('Asia/Dhaka');

            $table->string('status', 20)
                ->default('active');

            $table->boolean('is_default')
                ->default(false);

            $table->timestamps();

            $table->index('status');
            $table->index('is_default');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
