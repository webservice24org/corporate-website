<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BusinessNotificationTemplate;
use App\Services\Notifications\BusinessMessageTemplates;
use Illuminate\Database\Seeder;

final class BusinessNotificationDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $registry = app(BusinessMessageTemplates::class);
        foreach (BusinessMessageTemplates::EVENTS as $event) {
            foreach ($registry->audiences($event) as $audience) {
                $defaults = $registry->defaults($event, $audience);
                foreach (['email', 'sms'] as $channel) {
                    BusinessNotificationTemplate::query()->firstOrCreate(
                        ['event' => $event, 'audience' => $audience, 'channel' => $channel],
                        ['subject' => $channel === 'email' ? $defaults['subject'] : null, 'body' => $defaults['body'], 'enabled' => false],
                    );
                }
            }
        }
    }
}
