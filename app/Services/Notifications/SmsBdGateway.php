<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/** Sends a single message. This service does not retry or persist delivery history. */
final class SmsBdGateway
{
    public function __construct(private readonly SmsBdSettings $settings) {}

    /** @return array{status: string, request_id: string} */
    public function send(string $recipient, string $message): array
    {
        $settings = $this->settings->current();
        if ($settings['enabled'] !== true) {
            throw new RuntimeException('SMS delivery is disabled.');
        }
        $key = $settings['api_key'];
        if (! is_string($key) || trim($key) === '') {
            throw new RuntimeException('SMS provider credentials are not configured.');
        }
        $number = preg_replace('/[\s-]+/', '', $recipient);
        if (! is_string($number) || ! preg_match('/\A(?:01[3-9][0-9]{8}|8801[3-9][0-9]{8}|\+8801[3-9][0-9]{8})\z/D', $number)) {
            throw new InvalidArgumentException('Enter a valid Bangladeshi mobile number.');
        }
        $message = trim($message);
        if ($message === '' || mb_strlen($message) > 800) {
            throw new InvalidArgumentException('SMS text must contain 1 to 800 characters.');
        }
        $payload = ['api_key' => $key, 'msg' => $message, 'to' => ltrim($number, '+')];
        $sender = $settings['sender_id'];
        if (is_string($sender) && trim($sender) !== '') {
            $payload['sender_id'] = trim($sender);
        }
        try {
            $response = Http::asForm()->acceptJson()->timeout(15)->post('https://api.sms.net.bd/sendsms', $payload);
        } catch (ConnectionException $exception) {
            // A timeout may occur after provider acceptance; do not retry automatically.
            throw new RuntimeException('SMS provider response is unknown; check provider records before retrying.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('SMS provider returned an unsuccessful HTTP response.');
        }
        $body = $response->json();
        if (! is_array($body) || ! array_key_exists('error', $body)) {
            throw new RuntimeException('SMS provider returned an invalid response; delivery status is unknown.');
        }
        if ($body['error'] !== 0 && $body['error'] !== '0') {
            throw new RuntimeException('SMS provider rejected the request.');
        }
        $requestId = data_get($body, 'data.request_id');
        if ((! is_string($requestId) && ! is_int($requestId)) || (string) $requestId === '') {
            throw new RuntimeException('SMS provider acceptance has no request ID; delivery status is unknown.');
        }

        return ['status' => 'submitted', 'request_id' => (string) $requestId];
    }
}
