<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\DynamicMailConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(
        Request $request,
        DynamicMailConfig $mailConfig
    ): RedirectResponse {
        $request->validate([
            'email' => [
                'required',
                'email',
            ],
        ]);

        /*
         |--------------------------------------------------------------------------
         | Apply Dynamic SMTP Configuration
         |--------------------------------------------------------------------------
         |
         | This loads the currently active SMTP settings from the database.
         |
         | Today:
         |     Mailtrap SMTP
         |
         | Later on cPanel:
         |     Your real SMTP / business email
         |
         | No code change will be required here. You will only update the
         | Mail Settings from the Admin panel.
         |
         */

        // Automated tests use Laravel's configured mail transport and
        // notification fakes, without requiring database SMTP settings.
        if (!app()->environment('testing')) {
            $mailSettings = $mailConfig->apply();

            if (!$mailSettings) {
                throw ValidationException::withMessages([
                    'email' => [
                        'Mail configuration is not available. Please contact the administrator.',
                    ],
                ]);
            }
        }

        /*
         |--------------------------------------------------------------------------
         | Send Password Reset Link
         |--------------------------------------------------------------------------
         |
         | Since the dynamic mail configuration has already been applied,
         | Laravel's password broker will use those SMTP settings when
         | sending the reset notification.
         |
         */

        $status = Password::sendResetLink(
            $request->only('email')
        );

        /*
         |--------------------------------------------------------------------------
         | Successful Reset Link Request
         |--------------------------------------------------------------------------
         */

        if ($status === Password::RESET_LINK_SENT) {
            return back()->with(
                'status',
                __($status)
            );
        }

        /*
         |--------------------------------------------------------------------------
         | Failed Reset Link Request
         |--------------------------------------------------------------------------
         */

        throw ValidationException::withMessages([
            'email' => [
                trans($status),
            ],
        ]);
    }
}
