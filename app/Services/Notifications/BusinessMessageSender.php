<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\BusinessNotificationDelivery;
use App\Services\DynamicMailConfig;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class BusinessMessageSender
{
    public function __construct(private readonly SmsBdGateway $sms, private readonly SmsBdSettings $smsSettings, private readonly DynamicMailConfig $mailConfig) {}

    /** @return array{status: string, reason: string|null, provider_id: string|null} */
    public function send(BusinessNotificationDelivery $delivery): array
    {
        $recipient = (string) $delivery->getAttribute('recipient');
        $body = (string) $delivery->getAttribute('body');
        if ($delivery->getAttribute('channel') === 'sms') {
            try {
                $settings = $this->smsSettings->current();
            } catch (Throwable $exception) {
                return $this->result('blocked', 'sms_configuration_unreadable');
            }
            if (! $settings['enabled'] || ! is_string($settings['api_key']) || trim($settings['api_key']) === '') {
                return $this->result('blocked', 'sms_not_configured_or_disabled');
            }
            try {
                $sent = $this->sms->send($recipient, $body);

                return ['status' => 'submitted', 'reason' => null, 'provider_id' => mb_substr($sent['request_id'], 0, 100)];
            } catch (InvalidArgumentException $exception) {
                return $this->result('failed', 'invalid_sms_input');
            } catch (RuntimeException $exception) {
                // The current adapter does not expose structured provider error codes.
                // Conservatively avoid enabling retry for any provider/network error.
                return $this->result('uncertain', 'sms_result_unknown');
            } catch (Throwable $exception) {
                return $this->result('uncertain', 'sms_result_unknown');
            }
        }
        if ($delivery->getAttribute('channel') !== 'email') {
            return $this->result('failed', 'unsupported_channel');
        }
        try {
            $settings = $this->mailConfig->apply();
        } catch (Throwable $exception) {
            return $this->result('blocked', 'smtp_configuration_unreadable');
        }
        if ($settings === null) {
            return $this->result('blocked', 'smtp_not_configured');
        }
        config(['mail.mailers.smtp.timeout' => 20]);
        try {
            Mail::raw($body, static function (Message $message) use ($recipient, $delivery, $settings): void {
                $message->to($recipient)->subject((string) $delivery->getAttribute('subject'));
                if ($settings->reply_to_address) {
                    $message->replyTo($settings->reply_to_address, $settings->reply_to_name);
                }
            });
        } catch (Throwable $exception) {
            return $this->result('uncertain', 'smtp_result_unknown');
        }

        return $this->result('submitted', null);
    }

    /** @return array{status: string, reason: string|null, provider_id: string|null} */
    private function result(string $status, ?string $reason): array
    {
        return ['status' => $status, 'reason' => $reason, 'provider_id' => null];
    }
}
