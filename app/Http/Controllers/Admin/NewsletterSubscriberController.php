<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NewsletterSubscriber;
use Illuminate\Http\Request;

class NewsletterSubscriberController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => [
                'nullable',
                'in:active,unsubscribed',
            ],
        ]);

        $status = $validated['status'] ?? null;

        $subscribers = NewsletterSubscriber::query()
            ->when(
                $status,
                fn ($query) =>
                    $query->where('status', $status)
            )
            ->latest('subscribed_at')
            ->paginate(20)
            ->withQueryString();

        $counts = [
            'all' =>
                NewsletterSubscriber::count(),

            'active' =>
                NewsletterSubscriber::where(
                    'status',
                    'active'
                )->count(),

            'unsubscribed' =>
                NewsletterSubscriber::where(
                    'status',
                    'unsubscribed'
                )->count(),
        ];

        return inertia(
            'Admin/NewsletterSubscribers/Index',
            [
                'subscribers' =>
                    $subscribers,

                'filters' => [
                    'status' =>
                        $status,
                ],

                'counts' =>
                    $counts,
            ]
        );
    }

    public function unsubscribe(
        NewsletterSubscriber $newsletterSubscriber
    ) {
        $newsletterSubscriber->update([
            'status' =>
                'unsubscribed',

            'unsubscribed_at' =>
                now(),
        ]);

        return back()->with(
            'success',
            'Subscriber unsubscribed successfully.'
        );
    }

    public function reactivate(
        NewsletterSubscriber $newsletterSubscriber
    ) {
        $newsletterSubscriber->update([
            'status' =>
                'active',

            'subscribed_at' =>
                now(),

            'unsubscribed_at' =>
                null,
        ]);

        return back()->with(
            'success',
            'Subscriber reactivated successfully.'
        );
    }

    public function destroy(
        NewsletterSubscriber $newsletterSubscriber
    ) {
        $newsletterSubscriber->delete();

        return back()->with(
            'success',
            'Subscriber deleted successfully.'
        );
    }
}