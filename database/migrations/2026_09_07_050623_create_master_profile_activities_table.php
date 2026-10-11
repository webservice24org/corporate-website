<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_profile_activities', function (Blueprint $table) {
            $table->id();

            $table->string('subject_type', 100);
            $table->unsignedBigInteger('subject_id');

            $table->foreignId('actor_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
             * admin | partner | approval | system
             * Future profile-request workflows can reuse this column.
             */
            $table->string('source', 50)->default('admin');

            /*
             * Examples:
             * profile.updated
             * document.uploaded
             * document.verified
             * document.unverified
             * document.removed
             */
            $table->string('action', 100);

            $table->text('description')->nullable();

            /*
             * Field-by-field old/new values for profile changes.
             */
            $table->json('changes')->nullable();

            /*
             * Extra context such as document id/type/version/name.
             */
            $table->json('meta')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();

            $table->index(
                ['subject_type', 'subject_id', 'created_at'],
                'master_profile_activity_subject_idx'
            );

            $table->index(
                ['actor_id', 'created_at'],
                'master_profile_activity_actor_idx'
            );

            $table->index(
                ['action', 'created_at'],
                'master_profile_activity_action_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_profile_activities');
    }
};
