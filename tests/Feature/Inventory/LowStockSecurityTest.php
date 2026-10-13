<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Employee;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function lowStockSecurityFixture(string $label = 'A'): array
{
    $company = Company::create([
        'name' => "Low Stock Company {$label}",
        'code' => 'LS-'.Str::upper(Str::random(12)),
        'status' => 'active',
    ]);

    $unitId = DB::table('units')->insertGetId([
        'company_id' => $company->id,
        'name' => "Low Stock Unit {$label}",
        'short_name' => 'pcs',
        'decimal_places' => 4,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $warehouse = Warehouse::create([
        'company_id' => $company->id,
        'code' => 'LSW-'.Str::upper(Str::random(12)),
        'name' => "Low Stock Warehouse {$label}",
        'is_active' => true,
    ]);

    $product = Product::create([
        'company_id' => $company->id,
        'unit_id' => $unitId,
        'name' => "Low Stock Product {$label}",
        'product_type' => 'physical',
        'track_inventory' => true,
        'allow_negative_stock' => false,
        'has_variants' => false,
        'is_active' => true,
        'reorder_level' => '5.0000',
        'minimum_stock' => '2.0000',
    ]);

    return compact('company', 'warehouse', 'product');
}

function lowStockSecurityUser(?Company $company = null, array $permissions = [], bool $superAdmin = false): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    if ($permissions !== []) {
        $user->givePermissionTo($permissions);
    }

    if ($superAdmin) {
        Role::findOrCreate('super-admin', 'web');
        $user->assignRole('super-admin');
    }

    if ($company !== null) {
        Employee::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'employee_number' => 'LSE-'.Str::upper(Str::random(12)),
            'first_name' => 'Low',
            'last_name' => 'Stock',
            'joining_date' => now()->toDateString(),
            'employment_status' => 'active',
        ]);
    }

    return $user;
}

function lowStockSecurityPayload(array $fixture, array $changes = []): array
{
    return array_replace([
        'company_id' => $fixture['company']->id,
        'warehouse_id' => $fixture['warehouse']->id,
        'product_id' => $fixture['product']->id,
        'product_variant_id' => null,
        'reorder_level' => '7.2500',
        'minimum_stock' => '3.1250',
    ], $changes);
}

it('requires authentication for the report and reorder endpoint', function (): void {
    $fixture = lowStockSecurityFixture();

    $this->get(route('erp.inventory.low-stock.index'))->assertRedirect();
    $this->put(route('erp.inventory.low-stock.reorder-settings.update'), lowStockSecurityPayload($fixture))
        ->assertRedirect();

    expect(DB::table('inventory_reorder_settings')->count())->toBe(0);
});

it('requires independent report-view and reorder-manage permissions', function (): void {
    $fixture = lowStockSecurityFixture();
    $user = lowStockSecurityUser($fixture['company']);

    $this->actingAs($user)
        ->get(route('erp.inventory.low-stock.index', ['company_id' => $fixture['company']->id]))
        ->assertForbidden();

    $this->actingAs($user)
        ->put(route('erp.inventory.low-stock.reorder-settings.update'), lowStockSecurityPayload($fixture))
        ->assertForbidden();

    $user->givePermissionTo(Permission::findOrCreate('erp.low-stock-reports.view', 'web'));

    $this->actingAs($user)
        ->put(route('erp.inventory.low-stock.reorder-settings.update'), lowStockSecurityPayload($fixture))
        ->assertForbidden();

    expect(DB::table('inventory_reorder_settings')->count())->toBe(0);
});

it('prevents a company employee from viewing another company report', function (): void {
    $own = lowStockSecurityFixture('Own');
    $other = lowStockSecurityFixture('Other');
    $user = lowStockSecurityUser($own['company'], ['erp.low-stock-reports.view']);

    $this->actingAs($user)
        ->get(route('erp.inventory.low-stock.index', ['company_id' => $other['company']->id]))
        ->assertForbidden();
});

it('prevents cross-company reorder updates even with manage permission', function (): void {
    $own = lowStockSecurityFixture('Own');
    $other = lowStockSecurityFixture('Other');
    $user = lowStockSecurityUser($own['company'], ['erp.inventory-reorder-settings.manage']);

    $this->actingAs($user)
        ->put(route('erp.inventory.low-stock.reorder-settings.update'), lowStockSecurityPayload($other))
        ->assertForbidden();

    expect(DB::table('inventory_reorder_settings')->count())->toBe(0);
});

it('rejects foreign company warehouses and products', function (): void {
    $own = lowStockSecurityFixture('Own');
    $other = lowStockSecurityFixture('Other');
    $user = lowStockSecurityUser($own['company'], [
        'erp.low-stock-reports.view',
        'erp.inventory-reorder-settings.manage',
    ]);

    $this->actingAs($user)
        ->put(route('erp.inventory.low-stock.reorder-settings.update'), lowStockSecurityPayload($own, [
            'warehouse_id' => $other['warehouse']->id,
        ]))->assertSessionHasErrors('warehouse_id');

    $this->actingAs($user)
        ->put(route('erp.inventory.low-stock.reorder-settings.update'), lowStockSecurityPayload($own, [
            'product_id' => $other['product']->id,
        ]))->assertSessionHasErrors('product_id');

    $this->actingAs($user)
        ->get(route('erp.inventory.low-stock.index', [
            'company_id' => $own['company']->id,
            'warehouse_id' => $other['warehouse']->id,
        ]))->assertSessionHasErrors('warehouse_id');

    $this->actingAs($user)
        ->get(route('erp.inventory.low-stock.index', [
            'company_id' => $own['company']->id,
            'product_id' => $other['product']->id,
        ]))->assertSessionHasErrors('product_id');

    expect(DB::table('inventory_reorder_settings')->count())->toBe(0);
});

it('rejects missing, unrelated and inactive variants and variants on simple products', function (): void {
    $fixture = lowStockSecurityFixture();
    $user = lowStockSecurityUser($fixture['company'], ['erp.inventory-reorder-settings.manage']);
    $variantProduct = Product::create([
        'company_id' => $fixture['company']->id,
        'unit_id' => $fixture['product']->unit_id,
        'name' => 'Variant Product',
        'product_type' => 'physical',
        'track_inventory' => true,
        'has_variants' => true,
        'is_active' => true,
    ]);
    $validVariant = ProductVariant::create([
        'product_id' => $variantProduct->id,
        'name' => 'Active Variant',
        'is_active' => true,
    ]);
    $inactiveVariant = ProductVariant::create([
        'product_id' => $variantProduct->id,
        'name' => 'Inactive Variant',
        'is_active' => false,
    ]);

    $endpoint = route('erp.inventory.low-stock.reorder-settings.update');

    $this->actingAs($user)->put($endpoint, lowStockSecurityPayload($fixture, [
        'product_id' => $variantProduct->id,
    ]))->assertSessionHasErrors('product_variant_id');

    $this->actingAs($user)->put($endpoint, lowStockSecurityPayload($fixture, [
        'product_variant_id' => $validVariant->id,
    ]))->assertSessionHasErrors('product_variant_id');

    $this->actingAs($user)->put($endpoint, lowStockSecurityPayload($fixture, [
        'product_id' => $variantProduct->id,
        'product_variant_id' => $inactiveVariant->id,
    ]))->assertSessionHasErrors('product_variant_id');

    $this->actingAs($user)->put($endpoint, lowStockSecurityPayload($fixture, [
        'product_id' => $variantProduct->id,
        'product_variant_id' => $validVariant->id,
    ]))->assertRedirect();

    expect(DB::table('inventory_reorder_settings')->count())->toBe(1);
    $this->assertDatabaseHas('inventory_reorder_settings', [
        'product_id' => $variantProduct->id,
        'product_variant_id' => $validVariant->id,
        'variant_key' => $validVariant->id,
    ]);
});

it('validates reorder decimal precision and nonnegative values', function (): void {
    $fixture = lowStockSecurityFixture();
    $user = lowStockSecurityUser($fixture['company'], ['erp.inventory-reorder-settings.manage']);
    $endpoint = route('erp.inventory.low-stock.reorder-settings.update');

    foreach (['-1', '1.12345', 'abc'] as $invalid) {
        $this->actingAs($user)->put($endpoint, lowStockSecurityPayload($fixture, [
            'reorder_level' => $invalid,
        ]))->assertSessionHasErrors('reorder_level');

        $this->actingAs($user)->put($endpoint, lowStockSecurityPayload($fixture, [
            'minimum_stock' => $invalid,
        ]))->assertSessionHasErrors('minimum_stock');
    }

    expect(DB::table('inventory_reorder_settings')->count())->toBe(0);
});

it('atomically creates and updates one reorder row without changing inventory', function (): void {
    $fixture = lowStockSecurityFixture();
    $user = lowStockSecurityUser($fixture['company'], ['erp.inventory-reorder-settings.manage']);

    InventoryBalance::create([
        'company_id' => $fixture['company']->id,
        'warehouse_id' => $fixture['warehouse']->id,
        'product_id' => $fixture['product']->id,
        'product_variant_id' => null,
        'variant_key' => 0,
        'quantity_on_hand' => '10.0000',
        'quantity_reserved' => '2.0000',
        'average_cost' => '25.0000',
    ]);

    $endpoint = route('erp.inventory.low-stock.reorder-settings.update');

    $this->actingAs($user)->put($endpoint, lowStockSecurityPayload($fixture))
        ->assertRedirect()->assertSessionHas('success');

    $this->actingAs($user)->put($endpoint, lowStockSecurityPayload($fixture, [
        'reorder_level' => '9.5000',
        'minimum_stock' => '4.2500',
    ]))->assertRedirect()->assertSessionHas('success');

    expect(DB::table('inventory_reorder_settings')->count())->toBe(1);

    $setting = DB::table('inventory_reorder_settings')->first();
    expect(number_format((float) $setting->reorder_level, 4, '.', ''))->toBe('9.5000');
    expect(number_format((float) $setting->minimum_stock, 4, '.', ''))->toBe('4.2500');

    $balance = InventoryBalance::query()->firstOrFail();
    expect((string) $balance->quantity_on_hand)->toBe('10.0000');
    expect((string) $balance->quantity_reserved)->toBe('2.0000');
    expect(DB::table('stock_movements')->count())->toBe(0);
});

it('allows a permitted super-admin to update another company setting', function (): void {
    $fixture = lowStockSecurityFixture();
    $admin = lowStockSecurityUser(null, ['erp.inventory-reorder-settings.manage'], true);

    $this->actingAs($admin)
        ->put(route('erp.inventory.low-stock.reorder-settings.update'), lowStockSecurityPayload($fixture))
        ->assertRedirect()->assertSessionHas('success');

    $this->assertDatabaseHas('inventory_reorder_settings', [
        'company_id' => $fixture['company']->id,
        'warehouse_id' => $fixture['warehouse']->id,
        'product_id' => $fixture['product']->id,
        'variant_key' => 0,
    ]);
});
