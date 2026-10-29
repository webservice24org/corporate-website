<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('event', 80);
            $table->string('audience', 20);
            $table->string('channel', 10);
            $table->string('subject', 255)->nullable();
            $table->text('body');
            $table->boolean('enabled')->default(false);
            $table->timestamps();
            $table->unique(['event', 'audience', 'channel'], 'business_notification_template_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_notification_templates');
    }
};
