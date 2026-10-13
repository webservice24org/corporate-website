<?php

namespace App\Http\Middleware;

use App\Models\GlobalSection;
use App\Models\User;
use App\Notifications\InventoryLowStockNotification;
use App\Services\Inventory\LowStockCompanyAccess;
use Illuminate\Http\Request;
use Inertia\Middleware;

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

                        'email_verified_at' => $request->user()->email_verified_at,

                        /*
                         * Spatie Roles
                         */
                        'roles' => $request->user()
                            ->getRoleNames()
                            ->values()
                            ->all(),

                        /*
                         * Effective Permissions
                         *
                         * Includes permissions received
                         * through assigned roles.
                         */
                        'permissions' => $request->user()
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
                        'is_super_admin' => $request->user()
                            ->hasRole(
                                'super-admin'
                            ),

                        /*
                         * Profile
                         */
                        'profile_photo' => $request->user()
                            ->profile
                            ?->getAttribute('profile_photo'),

                        'designation' => $request->user()
                            ->profile
                            ?->getAttribute('designation'),

                        'department' => $request->user()
                            ->profile
                            ?->getAttribute('department'),
                    ]
                    : null,
            ],

            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],

            'globalFooter' => fn () => GlobalSection::query()
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

            'globalFloatingContact' => fn () => GlobalSection::query()
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
            'erpNotifications' => function () use ($request): array {
                /** @var User|null $user */
                $user = $request->user();

                if ($user === null) {
                    return [
                        'unread_count' => 0,
                        'items' => [],
                    ];
                }

                // Match NotificationController::visibleNotifications() exactly:
                // keep CRM notifications, but recheck company access for inventory.
                $allowedCompanyIds = app(LowStockCompanyAccess::class)
                    ->allowedCompanyIds($user);

                $visible = static function () use ($user, $allowedCompanyIds) {
                    return $user->notifications()
                        ->where(function ($query) use ($allowedCompanyIds): void {
                            $query->where('type', '!=', InventoryLowStockNotification::class);

                            if ($allowedCompanyIds !== []) {
                                $query->orWhere(function ($inventory) use ($allowedCompanyIds): void {
                                    $inventory
                                        ->where('type', InventoryLowStockNotification::class)
                                        ->whereIn('data->company_id', $allowedCompanyIds);
                                });
                            }
                        });
                };

                return [
                    'unread_count' => $visible()
                        ->whereNull('read_at')
                        ->count(),

                    'items' => $visible()
                        ->latest()
                        ->limit(8)
                        ->get()
                        ->map(static function ($notification): array {
                            /** @var array<string, mixed> $data */
                            $data = $notification->data;
                            $isInventory = $notification->type === InventoryLowStockNotification::class;
                            $severity = (string) ($data['severity'] ?? 'low_stock');
                            $event = (string) ($data['event'] ?? 'created');

                            return [
                                'id' => $notification->id,
                                'type' => $isInventory
                                    ? 'inventory.low_stock'
                                    : ($data['type'] ?? 'notification'),
                                'title' => $isInventory
                                    ? ($event === 'reopened' ? 'Low-stock alert reopened' : 'Low-stock alert')
                                    : ($data['title'] ?? 'Notification'),
                                'message' => $isInventory
                                    ? 'Inventory alert ('.str_replace('_', ' ', $severity).') for product #'.(string) ($data['product_id'] ?? '').'.'
                                    : ($data['message'] ?? null),
                                'url' => $isInventory
                                    ? '/erp/inventory/low-stock?company_id='.(int) ($data['company_id'] ?? 0)
                                    : ($data['url'] ?? null),
                                'lead_id' => $data['lead_id'] ?? null,
                                'lead_number' => $data['lead_number'] ?? null,
                                'company_id' => $isInventory ? ($data['company_id'] ?? null) : null,
                                'alert_id' => $isInventory ? ($data['alert_id'] ?? null) : null,
                                'severity' => $isInventory ? $severity : null,
                                'read_at' => $notification->read_at?->toISOString(),
                                'created_at' => $notification->created_at?->toISOString(),
                            ];
                        })
                        ->values()
                        ->all(),
                ];
            },
        ];
    }
}
