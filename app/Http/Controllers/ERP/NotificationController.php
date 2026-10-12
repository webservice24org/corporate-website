<?php

namespace App\Http\Controllers\ERP;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(
        Request $request
    ): Response {
        $user = $request->user();

        $notifications = $user
            ->notifications()
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $notifications->through(
            fn ($notification) => [
                'id' => $notification->id,

                'type' => $notification->data['type']
                    ?? 'notification',

                'title' => $notification->data['title']
                    ?? 'Notification',

                'message' => $notification->data['message']
                    ?? null,

                'url' => $notification->data['url']
                    ?? null,

                'lead_id' => $notification->data['lead_id']
                    ?? null,

                'lead_number' => $notification->data['lead_number']
                    ?? null,

                'read_at' => $notification->read_at
                    ?->toISOString(),

                'created_at' => $notification->created_at
                    ?->toISOString(),
            ]
        );

        return Inertia::render(
            'ERP/Notifications/Index',
            [
                'notifications' => $notifications,
            ]
        );
    }

    public function markRead(
        Request $request,
        string $notification
    ): RedirectResponse {
        $item = $request
            ->user()
            ->notifications()
            ->whereKey(
                $notification
            )
            ->firstOrFail();

        if (! $item->read_at) {
            $item->markAsRead();
        }

        return back();
    }

    public function markAllRead(
        Request $request
    ): RedirectResponse {
        $request
            ->user()
            ->unreadNotifications
            ->markAsRead();

        return back()
            ->with(
                'success',
                'All notifications marked as read.'
            );
    }
}
