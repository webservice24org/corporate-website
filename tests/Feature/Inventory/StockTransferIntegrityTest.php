<?php

use App\Models\Company;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Services\Inventory\StockTransferService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function inventoryFixture(): array
{
    $company = Company::create([
        'name' => 'Inventory Test Company',
        'code' => 'ITC-' . Str::upper(Str::random(10)),
        'status' => 'active',
    ]);

    $unitId = DB::table('units')->insertGetId([
        'company_id' => $company->id,
        'name' => 'Pieces',
        'short_name' => 'pcs',
        'decimal_places' => 4,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $source = Warehouse::create([
        'company_id' => $company->id,
        'code' => 'SRC-' . Str::upper(Str::random(10)),
        'name' => 'Source Warehouse',
        'is_active' => true,
    ]);

    $destination = Warehouse::create([
        'company_id' => $company->id,
        'code' => 'DST-' . Str::upper(Str::random(10)),
        'name' => 'Destination Warehouse',
        'is_active' => true,
    ]);

    $product = Product::create([
        'company_id' => $company->id,
        'unit_id' => $unitId,
        'name' => 'Inventory Test Product',
        'product_type' => 'physical',
        'track_inventory' => true,
        'allow_negative_stock' => false,
        'has_variants' => false,
        'is_active' => true,
    ]);

    return compact('company', 'source', 'destination', 'product');
}

function inventoryBalance(
    array $fixture,
    string $quantity,
    string $reserved = '0.0000'
): InventoryBalance {
    return InventoryBalance::create([
        'company_id' => $fixture['company']->id,
        'warehouse_id' => $fixture['source']->id,
        'product_id' => $fixture['product']->id,
        'product_variant_id' => null,
        'variant_key' => 0,
        'quantity_on_hand' => $quantity,
        'quantity_reserved' => $reserved,
        'average_cost' => '25.0000',
    ]);
}

function inventoryTransfer(
    array $fixture,
    string $quantity = '3.0000'
): StockTransfer {
    $transfer = StockTransfer::create([
        'company_id' => $fixture['company']->id,
        'document_number' => 'ST-TEST-' . Str::upper(Str::random(16)),
        'transfer_date' => now()->toDateString(),
        'source_warehouse_id' => $fixture['source']->id,
        'destination_warehouse_id' => $fixture['destination']->id,
        'status' => StockTransfer::STATUS_DRAFT,
    ]);

    $transfer->items()->create([
        'product_id' => $fixture['product']->id,
        'product_variant_id' => null,
        'variant_key' => 0,
        'quantity' => $quantity,
    ]);

    return $transfer;
}

function transferBalanceQuantity(Warehouse $warehouse, Product $product): string
{
    return (string) InventoryBalance::query()
        ->where('company_id', $warehouse->company_id)
        ->where('warehouse_id', $warehouse->id)
        ->where('product_id', $product->id)
        ->where('variant_key', 0)
        ->firstOrFail()
        ->quantity_on_hand;
}

function transferMovementCount(StockTransfer $transfer): int
{
    return StockMovement::query()
        ->where('reference_type', StockTransfer::class)
        ->where('reference_id', $transfer->id)
        ->count();
}

it('posts a stock transfer and updates both warehouses', function (): void {
    $fixture = inventoryFixture();
    inventoryBalance($fixture, '10.0000');
    $transfer = inventoryTransfer($fixture, '3.0000');

    app(StockTransferService::class)->post($transfer);

    expect($transfer->fresh()->status)->toBe(StockTransfer::STATUS_POSTED);
    expect($transfer->fresh()->posted_at)->not->toBeNull();
    expect(transferBalanceQuantity($fixture['source'], $fixture['product']))->toBe('7.0000');
    expect(transferBalanceQuantity($fixture['destination'], $fixture['product']))->toBe('3.0000');
    expect(transferMovementCount($transfer))->toBe(2);

    expect(StockMovement::query()
        ->where('reference_type', StockTransfer::class)
        ->where('reference_id', $transfer->id)
        ->where('movement_type', StockMovement::TYPE_TRANSFER_OUT)
        ->count())->toBe(1);

    expect(StockMovement::query()
        ->where('reference_type', StockTransfer::class)
        ->where('reference_id', $transfer->id)
        ->where('movement_type', StockMovement::TYPE_TRANSFER_IN)
        ->count())->toBe(1);
});

it('prevents posting the same transfer twice', function (): void {
    $fixture = inventoryFixture();
    inventoryBalance($fixture, '10.0000');
    $transfer = inventoryTransfer($fixture);
    $service = app(StockTransferService::class);

    $service->post($transfer);

    try {
        $service->post($transfer);
        test()->fail('Second posting should have failed.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('status');
    }

    expect(transferMovementCount($transfer))->toBe(2);
    expect(transferBalanceQuantity($fixture['source'], $fixture['product']))->toBe('7.0000');
    expect(transferBalanceQuantity($fixture['destination'], $fixture['product']))->toBe('3.0000');
});

it('rejects a transfer with insufficient available stock', function (): void {
    $fixture = inventoryFixture();
    inventoryBalance($fixture, '2.0000');
    $transfer = inventoryTransfer($fixture, '5.0000');

    try {
        app(StockTransferService::class)->post($transfer);
        test()->fail('Insufficient stock should have been rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('items.0.quantity');
    }

    expect($transfer->fresh()->status)->toBe(StockTransfer::STATUS_DRAFT);
    expect(transferBalanceQuantity($fixture['source'], $fixture['product']))->toBe('2.0000');
    expect(transferMovementCount($transfer))->toBe(0);
});

it('rejects a transfer that would consume reserved stock', function (): void {
    $fixture = inventoryFixture();
    inventoryBalance($fixture, '10.0000', '8.0000');
    $transfer = inventoryTransfer($fixture, '3.0000');

    try {
        app(StockTransferService::class)->post($transfer);
        test()->fail('Reserved stock should be protected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('items.0.quantity');
    }

    expect(transferBalanceQuantity($fixture['source'], $fixture['product']))->toBe('10.0000');
    expect($transfer->fresh()->status)->toBe(StockTransfer::STATUS_DRAFT);
    expect(transferMovementCount($transfer))->toBe(0);
});

it('rejects warehouses belonging to different companies', function (): void {
    $fixture = inventoryFixture();

    $otherCompany = Company::create([
        'name' => 'Other Inventory Company',
        'code' => 'OTHER-' . Str::upper(Str::random(10)),
        'status' => 'active',
    ]);

    $otherWarehouse = Warehouse::create([
        'company_id' => $otherCompany->id,
        'code' => 'OTHER-WH-' . Str::upper(Str::random(10)),
        'name' => 'Other Company Warehouse',
        'is_active' => true,
    ]);

    $transfer = inventoryTransfer($fixture);
    $transfer->update(['destination_warehouse_id' => $otherWarehouse->id]);

    try {
        app(StockTransferService::class)->validateDraft($transfer);
        test()->fail('Cross-company transfer should fail.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('company_id');
    }

    expect($transfer->fresh()->status)->toBe(StockTransfer::STATUS_DRAFT);
    expect(transferMovementCount($transfer))->toBe(0);
});

it('rolls back earlier item movements when a later item fails', function (): void {
    $fixture = inventoryFixture();
    inventoryBalance($fixture, '10.0000');
    $transfer = inventoryTransfer($fixture, '3.0000');

    $secondProduct = Product::create([
        'company_id' => $fixture['company']->id,
        'unit_id' => $fixture['product']->unit_id,
        'name' => 'Second Inventory Product',
        'product_type' => 'physical',
        'track_inventory' => true,
        'allow_negative_stock' => false,
        'has_variants' => false,
        'is_active' => true,
    ]);

    $transfer->items()->create([
        'product_id' => $secondProduct->id,
        'product_variant_id' => null,
        'variant_key' => 0,
        'quantity' => '5.0000',
    ]);

    try {
        app(StockTransferService::class)->post($transfer);
        test()->fail('Transfer should roll back.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('items.1.quantity');
    }

    expect($transfer->fresh()->status)->toBe(StockTransfer::STATUS_DRAFT);
    expect($transfer->fresh()->posted_at)->toBeNull();
    expect(transferBalanceQuantity($fixture['source'], $fixture['product']))->toBe('10.0000');
    expect(InventoryBalance::query()
        ->where('warehouse_id', $fixture['destination']->id)
        ->count())->toBe(0);
    expect(transferMovementCount($transfer))->toBe(0);
});
