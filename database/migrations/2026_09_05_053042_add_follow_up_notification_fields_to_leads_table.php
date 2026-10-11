<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dateTime('follow_up_notification_sent_at')
                ->nullable()
                ->after('next_follow_up_at');

            $table->index(
                [
                    'is_active',
                    'next_follow_up_at',
                    'follow_up_notification_sent_at',
                ],
                'lead_followup_notification_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(
                'lead_followup_notification_idx'
            );

            $table->dropColumn(
                'follow_up_notification_sent_at'
            );
        });
    }
};