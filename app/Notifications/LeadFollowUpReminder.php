<?php

namespace App\Notifications;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LeadFollowUpReminder extends Notification
{
    use Queueable;

    public function __construct(
        public Lead $lead
    ) {
    }

    public function via(
        object $notifiable
    ): array {
        return [
            'database',
        ];
    }

    public function toDatabase(
        object $notifiable
    ): array {
        return [
            'type' =>
                'lead_follow_up',

            'title' =>
                'Lead follow-up due',

            'message' =>
                sprintf(
                    'Follow up with %s (%s).',
                    $this->displayName(),
                    $this->lead->lead_number
                ),

            'lead_id' =>
                $this->lead->id,

            'lead_number' =>
                $this->lead->lead_number,

            'contact_name' =>
                $this->lead->contact_name,

            'company_name' =>
                $this->lead->company_name,

            'next_follow_up_at' =>
                $this->lead
                    ->next_follow_up_at
                    ?->toISOString(),

            'url' =>
                "/erp/crm/leads/{$this->lead->id}",
        ];
    }

    private function displayName(): string
    {
        if (
            $this->lead->lead_type ===
                'company' &&
            $this->lead->company_name
        ) {
            return $this->lead->company_name;
        }

        return $this->lead->contact_name;
    }
}