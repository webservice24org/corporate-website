<?php

namespace App\Http\Controllers;

use App\Models\NewsletterSubscriber;
use Illuminate\Http\Request;

class NewsletterSubscriberController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'email' => [
                'required',
                'email',
                'max:190',
            ],
        ]);

        $subscriber = NewsletterSubscriber::query()
            ->where('email', $validated['email'])
            ->first();

        if ($subscriber) {
            if ($subscriber->status === 'active') {
                return back()->with(
                    'success',
                    'You are already subscribed to our newsletter.'
                );
            }

            $subscriber->update([
                'status' => 'active',
                'subscribed_at' => now(),
                'unsubscribed_at' => null,
                'ip_address' => $request->ip(),
            ]);

            return back()->with(
                'success',
                'Your newsletter subscription has been reactivated.'
            );
        }

        NewsletterSubscriber::create([
            'email' => $validated['email'],
            'status' => 'active',
            'subscribed_at' => now(),
            'ip_address' => $request->ip(),
        ]);

        return back()->with(
            'success',
            'Thank you for subscribing to our newsletter.'
        );
    }
}