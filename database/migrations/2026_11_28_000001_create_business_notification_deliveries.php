<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('business_notification_settings') || Schema::hasColumn('business_notification_settings', 'automation_enabled')
            || Schema::hasTable('business_notification_deliveries') || Schema::hasTable('business_notification_actions')) {
            throw new RuntimeException('Notification prerequisites or table names conflict. No schema changes attempted.');
        }
        Schema::table('business_notification_settings', function (Blueprint $table): void {
            $table->boolean('automation_enabled')->default(false);
        });
        Schema::create('business_notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->char('event_key', 64)->unique();
            $table->unsignedBigInteger('company_id');
            $table->string('source_type', 30);
            $table->unsignedBigInteger('source_id');
            $table->string('event', 80);
            $table->string('audience', 20);
            $table->string('channel', 10);
            $table->text('recipient')->nullable();
            $table->text('subject')->nullable();
            $table->longText('body')->nullable();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->char('template_hash', 64)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('reason', 80)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('provider_id', 100)->nullable();
            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'id'], 'business_delivery_pending');
            $table->index(['company_id', 'created_at'], 'business_delivery_company_date');
        });
        Schema::create('business_notification_actions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('delivery_id')->index();
            $table->unsignedBigInteger('actor_id');
            $table->string('action', 20);
            $table->string('previous_status', 20);
            $table->dateTime('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_notification_actions');
        Schema::dropIfExists('business_notification_deliveries');
        Schema::table('business_notification_settings', function (Blueprint $table): void {
            $table->dropColumn('automation_enabled');
        });
    }
};
