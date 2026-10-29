<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessNotificationTemplate;
use App\Services\Notifications\BusinessMessageTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class BusinessNotificationSettingsController extends Controller
{
    private const EVENTS = ['depot.submitted', 'depot.approved', 'dealer.submitted', 'dealer.approved', 'employee.created'];

    public function edit(Request $request): Response
    {
        $templates = BusinessNotificationTemplate::query()->orderBy('event')->orderBy('audience')->orderBy('channel')
            ->get(['id', 'event', 'audience', 'channel', 'subject', 'body', 'enabled']);
        $settings = DB::table('business_notification_settings')->where('id', 1)->first(['admin_email', 'admin_phone', 'automation_enabled']);

        return Inertia::render('Admin/BusinessNotifications/Edit', [
            'templates' => $templates,
            'settings' => ['admin_email' => $settings->admin_email ?? '', 'admin_phone' => $settings->admin_phone ?? '', 'automation_enabled' => (bool) ($settings->automation_enabled ?? false)],
            'canEdit' => $request->user()?->can('mail-settings.edit') ?? false,
        ]);
    }

    public function updateTemplate(Request $request, BusinessMessageTemplates $registry): RedirectResponse
    {
        $data = $request->validate([
            'event' => ['required', 'string', Rule::in(self::EVENTS)],
            'audience' => ['required', 'string', Rule::in(['applicant', 'admin', 'employee'])],
            'channel' => ['required', 'string', Rule::in(['email', 'sms'])],
            'subject' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'body' => ['required', 'string', 'max:'.($request->input('channel') === 'sms' ? '800' : '10000'), 'regex:/\S/u'],
            'enabled' => ['required', 'boolean'],
        ]);
        if (($data['event'] === 'employee.created' && $data['audience'] !== 'employee')
            || ($data['event'] !== 'employee.created' && $data['audience'] === 'employee')) {
            return back()->withErrors(['audience' => 'This audience is not supported for the selected event.']);
        }
        if ($data['channel'] === 'email' && trim((string) ($data['subject'] ?? '')) === '') {
            return back()->withErrors(['subject' => 'An email subject is required.']);
        }
        try {
            $registry->validate((string) ($data['subject'] ?? ''));
            $registry->validate((string) $data['body']);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['body' => $exception->getMessage()]);
        }

        BusinessNotificationTemplate::query()->updateOrCreate(
            ['event' => $data['event'], 'audience' => $data['audience'], 'channel' => $data['channel']],
            ['subject' => $data['channel'] === 'email' ? trim((string) $data['subject']) : null, 'body' => trim($data['body']), 'enabled' => $data['enabled']],
        );

        return back()->with('success', 'Template saved. Changes apply to new events; already queued messages keep their saved text.');
    }

    public function updateRecipients(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'admin_email' => ['nullable', 'email', 'max:255'],
            'admin_phone' => ['nullable', 'string', 'max:32', 'regex:/\A\+?[0-9][0-9\s-]*\z/'],
            'automation_enabled' => ['sometimes', 'boolean'],
        ]);
        DB::table('business_notification_settings')->updateOrInsert(['id' => 1], [
            'admin_email' => $data['admin_email'] ?? null,
            'admin_phone' => $data['admin_phone'] ?? null,
            'updated_at' => now(),
            ...(array_key_exists('automation_enabled', $data) ? ['automation_enabled' => (bool) $data['automation_enabled']] : []),
        ]);

        return back()->with('success', 'Administrator contacts saved.');
    }
}
