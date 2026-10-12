<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            $table->string('profile_photo')->nullable();

            $table->string('phone', 50)->nullable();
            $table->string('employee_id', 100)->nullable()->unique();

            $table->string('designation')->nullable();
            $table->string('department')->nullable();

            $table->date('date_of_birth')->nullable();

            $table->string('gender', 30)->nullable();

            $table->text('address')->nullable();

            $table->string('city')->nullable();
            $table->string('country')->nullable();

            $table->text('bio')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
