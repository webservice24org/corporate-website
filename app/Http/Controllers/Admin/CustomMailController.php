<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessNotificationTemplate;
use App\Services\DynamicMailConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class CustomMailController extends Controller
{
    public function index(Request $request): Response
    {
        $template = BusinessNotificationTemplate::query()->where('event', 'custom.email')
            ->where('audience', 'admin')->where('channel', 'email')->first();

        return Inertia::render('Admin/CustomMail/Index', [
            'template' => ['subject' => (string) ($template?->getAttribute('subject') ?? ''), 'message' => (string) ($template?->getAttribute('body') ?? '')],
            'canEdit' => $request->user()?->can('mail-settings.edit') ?? false,
        ]);
    }

    public function saveTemplate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255', 'regex:/\S/u', 'not_regex:/[\r\n]/'],
            'message' => ['required', 'string', 'max:10000', 'regex:/\S/u'],
        ]);
        BusinessNotificationTemplate::query()->updateOrCreate(
            ['event' => 'custom.email', 'audience' => 'admin', 'channel' => 'email'],
            ['subject' => trim((string) $data['subject']), 'body' => trim((string) $data['message']), 'enabled' => false],
        );

        return back()->with('success', 'Custom email template saved.');
    }

    public function send(Request $request, DynamicMailConfig $mailConfig): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:255', 'regex:/\S/u', 'not_regex:/[\r\n]/'],
            'message' => ['required', 'string', 'max:10000', 'regex:/\S/u'],
        ]);
        try {
            $settings = $mailConfig->apply();
            if ($settings === null) {
                return back()->withInput()->withErrors(['mail' => 'No active SMTP configuration was found.']);
            }
            Mail::raw((string) $data['message'], static function ($mail) use ($data, $settings): void {
                $mail->to((string) $data['email'])->subject(trim((string) $data['subject']));
                if ($settings->reply_to_address) {
                    $mail->replyTo($settings->reply_to_address, $settings->reply_to_name);
                }
            });
        } catch (Throwable $exception) {
            // SMTP errors may contain credentials or addresses; do not return raw details.
            return back()->withInput()->withErrors(['mail' => 'Email could not be confirmed. Check SMTP status before retrying.']);
        }

        return back()->with('success', 'Email submitted to the configured SMTP server. Final delivery is not guaranteed.');
    }
}
