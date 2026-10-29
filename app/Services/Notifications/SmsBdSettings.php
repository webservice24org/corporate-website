<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\SmsBdSetting;

final class SmsBdSettings
{
    /** @return array{enabled: bool, api_key: string|null, sender_id: string|null, source: string} */
    public function current(): array
    {
        $row = SmsBdSetting::query()->find(1);
        if ($row !== null) {
            $key = $row->getAttribute('api_key');
            $sender = $row->getAttribute('sender_id');

            return ['enabled' => (bool) $row->getAttribute('enabled'), 'api_key' => is_string($key) ? $key : null,
                'sender_id' => is_string($sender) ? $sender : null, 'source' => 'admin'];
        }
        $key = config('sms-bd.api_key');
        $sender = config('sms-bd.sender_id');

        return ['enabled' => config('sms-bd.enabled') === true, 'api_key' => is_string($key) ? $key : null,
            'sender_id' => is_string($sender) ? $sender : null, 'source' => 'environment'];
    }
}
