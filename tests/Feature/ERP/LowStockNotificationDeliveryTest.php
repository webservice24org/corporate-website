<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use App\Models\InventoryLowStockAlert;
use App\Models\User;
use App\Notifications\InventoryLowStockNotification;
use App\Services\Inventory\LowStockNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LowStockNotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::query()->create([
            'name' => 'Notification Test Company',
            'code' => 'NTC-'.uniqid(),
            'status' => 'active',
        ]);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $guard = config('auth.defaults.guard', 'web');

        Permission::findOrCreate('erp.low-stock-reports.view', $guard);
        $role = Role::findOrCreate('super-admin', $guard);
        $role->givePermissionTo('erp.low-stock-reports.view');
        $user->assignRole($role);

        return $user;
    }

    /**
     * Build a snapshot without saving an inventory alert. This isolates
     * notification delivery from warehouse/product inventory fixtures.
     */
    private function alert(Company $company, int $occurrence = 1): InventoryLowStockAlert
    {
        $alert = new InventoryLowStockAlert([
            'company_id' => $company->getKey(),
            'warehouse_id' => 10,
            'product_id' => 20,
            'product_variant_id' => null,
            'variant_key' => 0,
            'status' => InventoryLowStockAlert::STATUS_ACTIVE,
            'severity' => 'low_stock',
            'quantity_available' => '2.0000',
            'minimum_stock' => '5.0000',
            'reorder_level' => '5.0000',
            'suggested_reorder_quantity' => '3.0000',
            'occurrence_count' => $occurrence,
        ]);

        $alert->setAttribute('id', 98765);

        return $alert;
    }

    private function service(): LowStockNotificationService
    {
        return app(LowStockNotificationService::class);
    }

    public function test_repeated_delivery_of_same_occurrence_is_idempotent(): void
    {
        $company = $this->company();
        $admin = $this->superAdmin();
        $alert = $this->alert($company);

        $this->assertSame(1, $this->service()->sendForOccurrence($alert, 'created'));
        $this->assertSame(0, $this->service()->sendForOccurrence($alert, 'created'));

        $this->assertSame(1, $admin->notifications()
            ->where('type', InventoryLowStockNotification::class)->count());
    }

    public function test_reopened_occurrence_creates_one_new_notification(): void
    {
        $company = $this->company();
        $admin = $this->superAdmin();
        $alert = $this->alert($company, 1);

        $this->assertSame(1, $this->service()->sendForOccurrence($alert, 'created'));

        $alert->occurrence_count = 2;
        $this->assertSame(1, $this->service()->sendForOccurrence($alert, 'reopened'));
        $this->assertSame(0, $this->service()->sendForOccurrence($alert, 'reopened'));

        $this->assertSame(2, $admin->notifications()
            ->where('type', InventoryLowStockNotification::class)->count());
    }

    public function test_resolved_alert_is_not_delivered(): void
    {
        $company = $this->company();
        $admin = $this->superAdmin();
        $alert = $this->alert($company);
        $alert->status = InventoryLowStockAlert::STATUS_RESOLVED;

        $this->assertSame(0, $this->service()->sendForOccurrence($alert, 'created'));
        $this->assertSame(0, $admin->notifications()->count());
    }

    public function test_user_without_company_access_does_not_receive_alert(): void
    {
        $company = $this->company();
        $user = User::factory()->create();

        $this->assertSame(0, $this->service()->sendForOccurrence($this->alert($company), 'created'));
        $this->assertSame(0, $user->notifications()->count());
    }

    public function test_invalid_event_is_rejected(): void
    {
        $company = $this->company();
        $alert = $this->alert($company);

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->sendForOccurrence($alert, 'resolved');
    }
}
