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
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class BusinessNotificationRecorder
{
    public function __construct(private readonly BusinessMessageTemplates $templates) {}

    public function record(Model $source, bool $approved): void
    {
        if (! Schema::hasTable('business_notification_deliveries') || ! Schema::hasColumn('business_notification_settings', 'automation_enabled')) {
            return;
        }
        if (! (bool) DB::table('business_notification_settings')->where('id', 1)->value('automation_enabled')) {
            return;
        }
        $type = match (true) {
            $source instanceof DepotApplication => 'depot',
            $source instanceof DealerApplication => 'dealer',
            $source instanceof Employee => 'employee',
            default => null,
        };
        if ($type === null || ($approved && $type === 'employee')) {
            return;
        }
        if ($type !== 'employee' && ! $approved && ! in_array($source->getAttribute('status'), ['pending', 'under_review'], true)) {
            return;
        }
        $event = $type.'.'.($type === 'employee' ? 'created' : ($approved ? 'approved' : 'submitted'));
        $company = (int) $source->getAttribute('company_id');
        $values = [
            'name' => $type === 'employee' ? trim((string) $source->getAttribute('first_name').' '.(string) $source->getAttribute('last_name')) : (string) $source->getAttribute('proprietor_name'),
            'business_name' => (string) $source->getAttribute('business_name'),
            'reference' => strtoupper($type).'-'.$source->getKey(),
            'status' => $type === 'employee' ? 'created' : ($approved ? 'approved' : 'awaiting review'),
            'company_name' => (string) DB::table('companies')->where('id', $company)->value('name'),
            'employee_number' => (string) $source->getAttribute('employee_number'),
        ];

        DB::transaction(function () use ($source, $type, $event, $company, $values): void {
            foreach ($this->templates->audiences($event) as $audience) {
                foreach (['email', 'sms'] as $channel) {
                    $template = BusinessNotificationTemplate::query()->where('event', $event)->where('audience', $audience)->where('channel', $channel)->first();
                    $contact = $this->contact($source, $audience, $channel);
                    $reason = null;
                    $subject = null;
                    $body = null;
                    if ($template === null || ! (bool) $template->getAttribute('enabled')) {
                        $reason = $template === null ? 'template_missing' : 'template_disabled';
                    } elseif ($contact === null) {
                        $reason = 'recipient_missing_or_invalid';
                    } else {
                        try {
                            $subject = $channel === 'email' ? preg_replace('/[\r\n]+/', ' ', $this->templates->render((string) $template->getAttribute('subject'), $values)) : null;
                            $body = $this->templates->render((string) $template->getAttribute('body'), $values);
                            if (trim($body) === '' || mb_strlen($body) > ($channel === 'sms' ? 800 : 10000)
                                || ($channel === 'email' && (trim((string) $subject) === '' || mb_strlen((string) $subject) > 255))) {
                                $reason = 'rendered_template_invalid';
                            }
                        } catch (InvalidArgumentException $exception) {
                            $reason = 'unsupported_template_placeholder';
                        }
                    }
                    $fingerprint = $template === null ? null : hash('sha256', json_encode($template->only(['event', 'audience', 'channel', 'subject', 'body', 'enabled']), JSON_THROW_ON_ERROR));
                    BusinessNotificationDelivery::query()->firstOrCreate([
                        'event_key' => hash('sha256', implode('|', ['business.v1', $type, (string) $source->getKey(), $event, $audience, $channel])),
                    ], [
                        'company_id' => $company, 'source_type' => $type, 'source_id' => (int) $source->getKey(),
                        'event' => $event, 'audience' => $audience, 'channel' => $channel,
                        'recipient' => $reason === null ? $contact : null, 'subject' => $reason === null ? $subject : null,
                        'body' => $reason === null ? $body : null, 'template_id' => $template?->getKey(), 'template_hash' => $fingerprint,
                        'status' => $reason === null ? 'pending' : 'skipped', 'reason' => $reason,
                    ]);
                }
            }
        });
    }

    public function contact(Model $source, string $audience, string $channel): ?string
    {
        if ($audience === 'admin') {
            $value = DB::table('business_notification_settings')->where('id', 1)->value($channel === 'email' ? 'admin_email' : 'admin_phone');
        } elseif ($source instanceof Employee) {
            $work = trim((string) $source->getAttribute($channel === 'email' ? 'work_email' : 'work_phone'));
            $value = $work !== '' ? $work : $source->getAttribute($channel === 'email' ? 'personal_email' : 'personal_phone');
        } else {
            $value = $source->getAttribute($channel === 'email' ? 'email' : 'phone');
        }
        $value = trim((string) $value);
        if ($channel === 'email') {
            return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower($value) : null;
        }
        $number = preg_replace('/[\s-]+/', '', $value);
        if (! is_string($number) || ! preg_match('/\A(?:01[3-9][0-9]{8}|8801[3-9][0-9]{8}|\+8801[3-9][0-9]{8})\z/D', $number)) {
            return null;
        }
        $number = ltrim($number, '+');

        return str_starts_with($number, '01') ? '88'.$number : $number;
    }
}
