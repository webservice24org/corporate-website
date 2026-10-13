<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use App\Models\Employee;
use App\Models\InventoryLowStockAlert;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\InventoryLowStockNotification;
use App\Services\Inventory\LowStockAlertService;
use App\Services\Inventory\LowStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class LowStockScannerLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_scan_creates_preserves_resolves_and_reopens_alert_without_duplicate_notifications(): void
    {
        $company = Company::query()->create([
            'name' => 'Lifecycle Test Company',
            'code' => 'LIFECYCLE-'.uniqid(),
            'status' => 'active',
        ]);

        $unit = Unit::query()->create([
            'company_id' => $company->id,
            'name' => 'Lifecycle Piece',
            'short_name' => 'LPC',
            'decimal_places' => 0,
        ]);

        $warehouse = Warehouse::query()->create([
            'company_id' => $company->id,
            'name' => 'Lifecycle Warehouse',
            'code' => 'LIFE-WH',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'company_id' => $company->id,
            'unit_id' => $unit->id,
            'name' => 'Lifecycle Product',
            'sku' => 'LIFE-SKU',
            'product_type' => 'physical',
            'track_inventory' => true,
            'has_variants' => false,
            'is_active' => true,
            'minimum_stock' => '5.0000',
            'reorder_level' => '10.0000',
        ]);

        $user = User::factory()->create();
        Employee::query()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'employee_number' => 'LIFE-EMP-001',
            'first_name' => 'Lifecycle',
            'last_name' => 'Recipient',
        ]);

        $guard = config('auth.defaults.guard', 'web');
        Permission::findOrCreate('erp.low-stock-reports.view', (string) $guard);
        $user->givePermissionTo('erp.low-stock-reports.view');

        $row = [
            'company_id' => (int) $company->id,
            'warehouse_id' => (int) $warehouse->id,
            'product_id' => (int) $product->id,
            'product_variant_id' => null,
            'variant_key' => 0,
            'quantity_available' => '2.0000',
            'minimum_stock' => '5.0000',
            'reorder_level' => '10.0000',
            'suggested_reorder_quantity' => '8.0000',
            'status' => LowStockService::STATUS_CRITICAL,
        ];

        // Only the stock-report input is mocked; the scanner, DB, and delivery remain real.
        $snapshots = [[$row], [$row], [], [$row], [$row]];
        $reportService = Mockery::mock(LowStockService::class);
        $reportService->shouldReceive('report')
            ->times(count($snapshots))
            ->with((int) $company->id)
            ->andReturnUsing(static function () use (&$snapshots): array {
                return ['rows' => array_shift($snapshots), 'summary' => []];
            });
        $this->app->instance(LowStockService::class, $reportService);
        $scanner = $this->app->make(LowStockAlertService::class);

        $created = $scanner->syncCompany((int) $company->id);
        $this->assertSame(1, $created['created']);
        $this->assertSame(1, InventoryLowStockAlert::query()->count());
        $alert = InventoryLowStockAlert::query()->sole();
        $this->assertSame(InventoryLowStockAlert::STATUS_ACTIVE, $alert->status);
        $this->assertSame(1, (int) $alert->occurrence_count);
        $this->assertSame(1, $this->notificationCount($user));

        $unchanged = $scanner->syncCompany((int) $company->id);
        $this->assertSame(1, $unchanged['unchanged']);
        $this->assertSame(1, $this->notificationCount($user));

        $resolved = $scanner->syncCompany((int) $company->id);
        $this->assertSame(1, $resolved['resolved']);
        $this->assertSame(InventoryLowStockAlert::STATUS_RESOLVED, $alert->fresh()->status);
        $this->assertSame(1, $this->notificationCount($user));

        $reopened = $scanner->syncCompany((int) $company->id);
        $this->assertSame(1, $reopened['reopened']);
        $alert->refresh();
        $this->assertSame(InventoryLowStockAlert::STATUS_ACTIVE, $alert->status);
        $this->assertSame(2, (int) $alert->occurrence_count);
        $this->assertSame(1, InventoryLowStockAlert::query()->count());
        $this->assertSame(2, $this->notificationCount($user));

        $again = $scanner->syncCompany((int) $company->id);
        $this->assertSame(1, $again['unchanged']);
        $this->assertSame(2, $this->notificationCount($user));

        $events = DB::table('notifications')
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->where('type', InventoryLowStockNotification::class)
            ->pluck('data')
            ->map(static fn (string $json): string => (string) (json_decode($json, true, 512, JSON_THROW_ON_ERROR)['event'] ?? ''))
            ->sort()
            ->values()
            ->all();
        $this->assertSame(['created', 'reopened'], $events);
    }

    private function notificationCount(User $user): int
    {
        return DB::table('notifications')
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->where('type', InventoryLowStockNotification::class)
            ->count();
    }
}
