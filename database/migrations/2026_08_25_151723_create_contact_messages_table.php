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
        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email');

            $table->string('target')->nullable();
            $table->string('target_label')->nullable();

            $table->string('subject');
            $table->text('message');

            $table->enum('status', [
                'unread',
                'read',
                'replied',
                'archived',
            ])->default('unread');

            $table->timestamp('read_at')->nullable();
            $table->timestamp('replied_at')->nullable();

            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
