<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Notifications\SmsBdGateway;
use App\Services\Notifications\SmsBdSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

final class CustomSmsController extends Controller
{
    public function index(Request $request, SmsBdSettings $settings): Response
    {
        return Inertia::render('Admin/CustomSms/Index', [
            'enabled' => $settings->current()['enabled'],
            'canSend' => $request->user()?->can('mail-settings.edit') ?? false,
        ]);
    }

    public function send(Request $request, SmsBdGateway $gateway): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'message' => ['required', 'string', 'max:800', 'regex:/\S/u'],
        ]);
        try {
            $result = $gateway->send((string) $data['phone'], (string) $data['message']);
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['sms' => $exception->getMessage()]);
        } catch (RuntimeException $exception) {
            // Provider responses may be ambiguous. Never echo a raw provider response or credential.
            return back()->withInput()->withErrors(['sms' => $exception->getMessage()]);
        }

        return back()->with('success', 'SMS submitted to provider. Request ID: '.$result['request_id'].'. Submission does not confirm delivery.');
    }
}
