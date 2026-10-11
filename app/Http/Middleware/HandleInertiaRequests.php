<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use App\Models\GlobalSection;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => fn () => $request->user()
                    ? [
                        'id' => $request->user()->id,

                        'name' => $request->user()->name,

                        'email' => $request->user()->email,

                        'email_verified_at' =>
                            $request->user()->email_verified_at,

                        /*
                        * Spatie Roles
                        */
                        'roles' =>
                            $request->user()
                                ->getRoleNames()
                                ->values()
                                ->all(),

                        /*
                        * Effective Permissions
                        *
                        * Includes permissions received
                        * through assigned roles.
                        */
                        'permissions' =>
                            $request->user()
                                ->getAllPermissions()
                                ->pluck('name')
                                ->values()
                                ->all(),

                        /*
                        * Super Admin shortcut for React.
                        *
                        * Backend security still comes
                        * from Gate::before().
                        */
                        'is_super_admin' =>
                            $request->user()
                                ->hasRole(
                                    'super-admin'
                                ),

                        /*
                        * Profile
                        */
                        'profile_photo' =>
                            $request->user()
                                ->profile
                                ?->profile_photo,

                        'designation' =>
                            $request->user()
                                ->profile
                                ?->designation,

                        'department' =>
                            $request->user()
                                ->profile
                                ?->department,
                    ]
                    : null,
            ],
            
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],

            'globalFooter' => fn () =>
                GlobalSection::query()
                    ->where('type', 'footer')
                    ->where('is_active', true)
                    ->first([
                        'id',
                        'type',
                        'variant',
                        'settings',
                        'is_active',
                    ]),

            'globalHeader' => fn () => GlobalSection::query()
            ->where('type', 'header')
            ->where('is_active', true)
            ->first(),

            'globalFloatingContact' => fn () =>
                GlobalSection::query()
                    ->where(
                        'type',
                        'floating-contact'
                    )
                    ->where(
                        'is_active',
                        true
                    )
                    ->first([
                        'id',
                        'type',
                        'variant',
                        'settings',
                        'is_active',
                    ]),
            'erpNotifications' => function () use ($request) {
                $user = $request->user();

                if (!$user) {
                    return [
                        'unread_count' => 0,
                        'items' => [],
                    ];
                }

                return [
                    'unread_count' =>
                        $user
                            ->unreadNotifications()
                            ->count(),

                    'items' =>
                        $user
                            ->notifications()
                            ->latest()
                            ->limit(8)
                            ->get()
                            ->map(
                                fn ($notification) => [
                                    'id' =>
                                        $notification->id,

                                    'type' =>
                                        $notification
                                            ->data['type']
                                        ?? 'notification',

                                    'title' =>
                                        $notification
                                            ->data['title']
                                        ?? 'Notification',

                                    'message' =>
                                        $notification
                                            ->data['message']
                                        ?? null,

                                    'url' =>
                                        $notification
                                            ->data['url']
                                        ?? null,

                                    'lead_id' =>
                                        $notification
                                            ->data['lead_id']
                                        ?? null,

                                    'lead_number' =>
                                        $notification
                                            ->data['lead_number']
                                        ?? null,

                                    'read_at' =>
                                        $notification
                                            ->read_at
                                            ?->toISOString(),

                                    'created_at' =>
                                        $notification
                                            ->created_at
                                            ?->toISOString(),
                                ]
                            )
                            ->values(),
                ];
            },
        ];
    }
}
