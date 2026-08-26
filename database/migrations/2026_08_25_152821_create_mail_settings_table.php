<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mail_settings', function (Blueprint $table) {
            $table->id();

            $table->string('mailer')->default('smtp');

            $table->string('host')->nullable();
            $table->unsignedInteger('port')->default(587);

            $table->string('username')->nullable();

            $table->text('password')->nullable();

            $table->string('encryption')->nullable();

            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();

            $table->string('reply_to_address')->nullable();
            $table->string('reply_to_name')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_settings');
    }
};
