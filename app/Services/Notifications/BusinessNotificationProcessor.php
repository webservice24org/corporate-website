<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\BusinessNotificationDelivery;
use App\Models\BusinessNotificationTemplate;
use App\Models\DealerApplication;
use App\Models\DepotApplication;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

final class BusinessNotificationProcessor
{
    public function __construct(private readonly BusinessMessageSender $sender, private readonly BusinessNotificationRecorder $recorder) {}

    public function process(int $limit = 25): int
    {
        // A terminated worker may have sent a request. Never silently resend it.
        BusinessNotificationDelivery::query()->where('status', 'processing')->where('claimed_at', '<', now()->subMinutes(10))
            ->update(['status' => 'uncertain', 'reason' => 'worker_interrupted', 'updated_at' => now()]);
        $count = 0;
        for ($i = 0; $i < max(1, min($limit, 200)); $i++) {
            $delivery = DB::transaction(function (): ?BusinessNotificationDelivery {
                if (! (bool) DB::table('business_notification_settings')->where('id', 1)->value('automation_enabled')) {
                    return null;
                }
                $row = BusinessNotificationDelivery::query()->where('status', 'pending')->orderBy('id')->lockForUpdate()->first();
                if ($row === null) {
                    return null;
                }
                $row->fill(['status' => 'processing', 'claimed_at' => now(), 'reason' => null, 'attempts' => (int) $row->getAttribute('attempts') + 1])->save();

                return $row;
            });
            if ($delivery === null) {
                break;
            }
            $count++;
            try {
                $result = $this->preflight($delivery);
                if ($result === null) {
                    $result = $this->sender->send($delivery);
                }
            } catch (Throwable $exception) {
                // Do not include payloads, contacts, SMTP credentials or provider text in logs.
                $result = ['status' => 'uncertain', 'reason' => 'processing_result_unknown', 'provider_id' => null];
            }
            BusinessNotificationDelivery::query()->whereKey($delivery->getKey())->where('status', 'processing')->update([
                ...$result, 'submitted_at' => $result['status'] === 'submitted' ? now() : null, 'updated_at' => now(),
            ]);
        }

        return $count;
    }

    /** @return array{status: string, reason: string, provider_id: null}|null */
    private function preflight(BusinessNotificationDelivery $delivery): ?array
    {
        if (! (bool) DB::table('business_notification_settings')->where('id', 1)->value('automation_enabled')) {
            return ['status' => 'blocked', 'reason' => 'automation_paused', 'provider_id' => null];
        }
        $template = BusinessNotificationTemplate::query()->find($delivery->getAttribute('template_id'));
        if ($template === null || ! (bool) $template->getAttribute('enabled')) {
            return ['status' => 'blocked', 'reason' => 'template_now_disabled', 'provider_id' => null];
        }
        $class = match ($delivery->getAttribute('source_type')) {
            'depot' => DepotApplication::class, 'dealer' => DealerApplication::class, 'employee' => Employee::class, default => null,
        };
        /** @var Model|null $source */
        $source = $class === null ? null : $class::query()->where('company_id', $delivery->getAttribute('company_id'))->find($delivery->getAttribute('source_id'));
        if ($source === null || (str_ends_with((string) $delivery->getAttribute('event'), '.approved') && $source->getAttribute('status') !== 'approved')) {
            return ['status' => 'cancelled', 'reason' => 'source_no_longer_valid', 'provider_id' => null];
        }
        if (str_ends_with((string) $delivery->getAttribute('event'), '.submitted') && ! in_array($source->getAttribute('status'), ['pending', 'under_review'], true)) {
            return ['status' => 'cancelled', 'reason' => 'application_already_reviewed', 'provider_id' => null];
        }
        $current = $this->recorder->contact($source, (string) $delivery->getAttribute('audience'), (string) $delivery->getAttribute('channel'));
        if ($current === null || $current !== $delivery->getAttribute('recipient')) {
            return ['status' => 'cancelled', 'reason' => 'recipient_changed', 'provider_id' => null];
        }

        return null;
    }
}
