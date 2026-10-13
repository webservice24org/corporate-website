<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\InventoryLowStockAlert;
use App\Models\User;
use App\Notifications\InventoryLowStockNotification;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

final class LowStockNotificationService
{
    public function __construct(
        private readonly LowStockCompanyAccess $companyAccess
    ) {}

    public function sendForOccurrence(
        InventoryLowStockAlert $alert,
        string $event
    ): int {
        if (! in_array($event, ['created', 'reopened'], true)) {
            throw new InvalidArgumentException(
                'Unsupported low-stock notification event.'
            );
        }

        if ($alert->status !== InventoryLowStockAlert::STATUS_ACTIVE) {
            return 0;
        }

        $companyId = (int) $alert->company_id;
        $occurrence = (int) $alert->occurrence_count;
        $sent = 0;

        $notification = new InventoryLowStockNotification($alert, $event);

        /** @var iterable<User> $users */
        $users = User::query()
            ->where(function ($query) use ($companyId): void {
                $query
                    ->whereHas('employee', function ($employeeQuery) use ($companyId): void {
                        $employeeQuery->where('company_id', $companyId);
                    })
                    ->orWhereHas('roles', function ($roleQuery): void {
                        $roleQuery->where('name', 'super-admin');
                    });
            })
            ->orderBy('id')
            ->cursor();

        foreach ($users as $user) {
            if (! $this->companyAccess->canView($user, $companyId)) {
                continue;
            }

            $identifier = implode(':', [
                'inventory-low-stock',
                (string) $alert->getKey(),
                (string) $occurrence,
                (string) $user->getKey(),
            ]);

            $notificationId = Uuid::uuid5(
                Uuid::NAMESPACE_DNS,
                $identifier
            )->toString();

            $inserted = DB::table('notifications')->insertOrIgnore([
                'id' => $notificationId,
                'type' => InventoryLowStockNotification::class,
                'notifiable_type' => $user->getMorphClass(),
                'notifiable_id' => $user->getKey(),
                'data' => json_encode(
                    $notification->toDatabase($user),
                    JSON_THROW_ON_ERROR
                ),
                'read_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sent += $inserted;
        }

        return $sent;
    }
}
