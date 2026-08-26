<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MailSetting;
use App\Services\DynamicMailConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Throwable;

class MailSettingController extends Controller
{
    /**
     * Show the mail settings form.
     */
    public function edit()
    {
        $settings = MailSetting::query()->first();

        return inertia('Admin/MailSettings/Edit', [
            'settings' => $settings
                ? [
                    'id' => $settings->id,
                    'mailer' => $settings->mailer,
                    'host' => $settings->host,
                    'port' => $settings->port,
                    'username' => $settings->username,

                    // Never send the stored password back to React.
                    'password' => '',

                    'encryption' => $settings->encryption,
                    'from_address' => $settings->from_address,
                    'from_name' => $settings->from_name,
                    'reply_to_address' => $settings->reply_to_address,
                    'reply_to_name' => $settings->reply_to_name,
                    'is_active' => $settings->is_active,
                ]
                : null,
        ]);
    }

    /**
     * Save or update mail settings.
     */
    public function update(Request $request)
    {
        $settings = MailSetting::query()->first();

        $validated = $request->validate([
            'mailer' => [
                'required',
                'string',
                Rule::in([
                    'smtp',
                ]),
            ],

            'host' => [
                'required',
                'string',
                'max:255',
            ],

            'port' => [
                'required',
                'integer',
                'min:1',
                'max:65535',
            ],

            'username' => [
                'nullable',
                'string',
                'max:255',
            ],

            'password' => [
                $settings ? 'nullable' : 'required',
                'string',
                'max:1000',
            ],

            'encryption' => [
                'nullable',
                Rule::in([
                    'tls',
                    'ssl',
                    null,
                ]),
            ],

            'from_address' => [
                'required',
                'email',
                'max:255',
            ],

            'from_name' => [
                'required',
                'string',
                'max:255',
            ],

            'reply_to_address' => [
                'nullable',
                'email',
                'max:255',
            ],

            'reply_to_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'is_active' => [
                'required',
                'boolean',
            ],
        ]);

        /*
         * If editing existing settings and password is blank,
         * do not overwrite the saved encrypted password.
         */
        if (
            $settings &&
            blank($validated['password'] ?? null)
        ) {
            unset($validated['password']);
        }

        if ($settings) {
            $settings->update($validated);
        } else {
            MailSetting::create($validated);
        }

        return back()->with(
            'success',
            'Mail settings saved successfully.'
        );
    }

    /**
     * Send a test email using the saved SMTP configuration.
     */
    public function test(Request $request, DynamicMailConfig $mailConfig)
    {
        $validated = $request->validate([
            'email' => [
                'required',
                'email',
                'max:255',
            ],
        ]);

        try {
            $settings = $mailConfig->apply();

            if (! $settings) {
                return back()->with(
                    'error',
                    'No active mail configuration was found.'
                );
            }

            Mail::raw(
                'This is a test email from your corporate website mail configuration.',
                function ($message) use ($validated, $settings) {
                    $message
                        ->to($validated['email'])
                        ->subject('Mail Configuration Test');

                    if ($settings->reply_to_address) {
                        $message->replyTo(
                            $settings->reply_to_address,
                            $settings->reply_to_name
                        );
                    }
                }
            );

            return back()->with(
                'success',
                'Test email sent successfully.'
            );
        } catch (Throwable $exception) {
            report($exception);

            return back()->with(
                'error',
                'Test email could not be sent. Please check your SMTP settings.'
            );
        }
    }
}