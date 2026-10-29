<?php

declare(strict_types=1);

return [
    'enabled' => env('SMS_BD_ENABLED', false),
    'api_key' => env('SMS_NET_BD_API_KEY'),
    'sender_id' => env('SMS_BD_SENDER_ID'),
];
