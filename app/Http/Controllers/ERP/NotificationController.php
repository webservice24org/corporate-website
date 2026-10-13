<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\InventoryLowStockNotification;
use App\Services\Inventory\LowStockCompanyAccess;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function __construct(
        private readonly LowStockCompanyAccess $companyAccess
    ) {}

    /** @return MorphMany<DatabaseNotification, User> */
    private function visibleNotifications(User $user): MorphMany
    {
        $query = $user->notifications();
        $allowedCompanyIds = $this->companyAccess->allowedCompanyIds($user);

        // All non-inventory notification types retain their existing visibility.
        // Inventory notifications are only visible for companies the user can access now.
        $query->where(function ($builder) use ($allowedCompanyIds): void {
            $builder->where('type', '!=', InventoryLowStockNotification::class);

            if ($allowedCompanyIds !== []) {
                $builder->orWhere(function ($inventory) use ($allowedCompanyIds): void {
                    $inventory
                        ->where('type', InventoryLowStockNotification::class)
                        ->whereIn('data->company_id', $allowedCompanyIds);
                });
            }
        });

        return $query;
    }

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $notifications = $this->visibleNotifications($user)
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $notifications->through(static function ($notification): array {
            /** @var array<string, mixed> $data */
            $data = $notification->data;
            $isInventory = $notification->type === InventoryLowStockNotification::class;

            $severity = (string) ($data['severity'] ?? 'low_stock');
            $event = (string) ($data['event'] ?? 'created');

            return [
                'id' => $notification->id,
                'type' => $isInventory ? 'inventory.low_stock' : ($data['type'] ?? 'notification'),
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
        });

        return Inertia::render('ERP/Notifications/Index', [
            'notifications' => $notifications,
            'unreadCount' => $this->visibleNotifications($user)->whereNull('read_at')->count(),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'unread_count' => $this->visibleNotifications($user)
                ->whereNull('read_at')
                ->count(),
        ]);
    }

    public function markRead(Request $request, string $notification): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $item = $this->visibleNotifications($user)
            ->whereKey($notification)
            ->firstOrFail();

        if ($item->read_at === null) {
            $item->markAsRead();
        }

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Bulk update only currently visible, unread notifications.
        $this->visibleNotifications($user)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'All visible notifications marked as read.');
    }
}
