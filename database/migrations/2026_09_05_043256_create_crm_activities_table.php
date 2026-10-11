<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_activities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->restrictOnDelete();

            /*
             * Lead now.
             *
             * Later:
             * App\Models\Customer
             * App\Models\Opportunity
             */
            $table->string('activityable_type');
            $table->unsignedBigInteger('activityable_id');

            $table->string('activity_type', 30);
            // call | meeting | email | task | follow_up | note

            $table->string('subject', 255);

            $table->text('description')
                ->nullable();

            $table->string('status', 30)
                ->default('planned');
            // planned | in_progress | completed | cancelled

            $table->string('priority', 20)
                ->default('normal');
            // low | normal | high | urgent

            $table->foreignId('assigned_to_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->dateTime('scheduled_at')
                ->nullable();

            $table->dateTime('due_at')
                ->nullable();

            $table->dateTime('reminder_at')
                ->nullable();

            $table->dateTime('completed_at')
                ->nullable();

            $table->text('outcome')
                ->nullable();

            $table->timestamps();

            $table->index(
                [
                    'activityable_type',
                    'activityable_id',
                ],
                'crm_activity_subject_idx'
            );

            $table->index(
                [
                    'company_id',
                    'status',
                    'activity_type',
                ],
                'crm_activity_company_status_type_idx'
            );

            $table->index(
                [
                    'assigned_to_user_id',
                    'due_at',
                ],
                'crm_activity_assignee_due_idx'
            );

            $table->index(
                'scheduled_at',
                'crm_activity_scheduled_idx'
            );

            $table->index(
                'reminder_at',
                'crm_activity_reminder_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'crm_activities'
        );
    }
};