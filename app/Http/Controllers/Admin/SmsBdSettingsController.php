<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SmsBdSetting;
use App\Services\Notifications\SmsBdSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

final class SmsBdSettingsController extends Controller
{
    public function edit(Request $request, SmsBdSettings $settings): Response
    {
        $current = $settings->current();

        return Inertia::render('Admin/SmsBdSettings/Edit', [
            'settings' => ['enabled' => $current['enabled'], 'sender_id' => $current['sender_id'] ?? '',
                'has_key' => is_string($current['api_key']) && trim($current['api_key']) !== '', 'source' => $current['source']],
            'canEdit' => $request->user()?->can('mail-settings.edit') ?? false,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'api_key' => ['nullable', 'string', 'max:1000', 'regex:/\S/u'],
            'sender_id' => ['nullable', 'string', 'max:80', 'not_regex:/[\r\n]/'],
            'enabled' => ['required', 'boolean'],
        ]);
        if ($validator->fails()) {
            // Never flash submitted credentials into old input on validation failure.
            return back()->withErrors($validator);
        }
        $data = $validator->validated();

        return DB::transaction(function () use ($data): RedirectResponse {
            $row = SmsBdSetting::query()->lockForUpdate()->find(1) ?? new SmsBdSetting;
            $row->setAttribute('id', 1);
            $newKey = trim((string) ($data['api_key'] ?? ''));
            if ($newKey !== '') {
                $row->setAttribute('api_key', $newKey);
            }
            $key = $row->getAttribute('api_key');
            if ((bool) $data['enabled'] && (! is_string($key) || trim($key) === '')) {
                return back()->withErrors(['api_key' => 'Enter an API key before enabling SMS.']);
            }
            $sender = trim((string) ($data['sender_id'] ?? ''));
            $row->setAttribute('sender_id', $sender === '' ? null : $sender);
            $row->setAttribute('enabled', (bool) $data['enabled']);
            $row->save();

            return back()->with('success', 'SMS.BD settings saved.');
        });
    }
}
