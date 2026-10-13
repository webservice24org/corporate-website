<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\InventoryLowStockAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

final class InventoryLowStockNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly InventoryLowStockAlert $alert,
        private readonly string $event
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, int|string|null>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'inventory.low_stock',
            'event' => $this->event,
            'alert_id' => (int) $this->alert->getKey(),
            'company_id' => (int) $this->alert->company_id,
            'warehouse_id' => (int) $this->alert->warehouse_id,
            'product_id' => (int) $this->alert->product_id,
            'product_variant_id' => $this->alert->product_variant_id !== null
                ? (int) $this->alert->product_variant_id
                : null,
            'severity' => (string) $this->alert->severity,
            'occurrence_count' => (int) $this->alert->occurrence_count,
            'url' => '/erp/inventory/low-stock?company_id='
                .(int) $this->alert->company_id,
        ];
    }
}
