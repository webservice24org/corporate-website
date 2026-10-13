<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Q.4C.3B — Inventory Database Constraint Regression Tests
|--------------------------------------------------------------------------
|
| These tests intentionally bypass Eloquent validation and application
| services. They verify that the database rejects invalid INSERT and
| UPDATE statements.
|
| RefreshDatabase is configured globally in tests/Pest.php.
|
*/

function constraintFixture(): array
{
    $company = Company::create([
        'name' => 'Constraint Test Company',
        'code' => 'CT-'.Str::upper(Str::random(12)),
        'status' => 'active',
    ]);

    $unitId = DB::table('units')->insertGetId([
        'company_id' => $company->id,
        'name' => 'Constraint Test Pieces',
        'short_name' => 'pcs',
        'decimal_places' => 4,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $source = Warehouse::create([
        'company_id' => $company->id,
        'code' => 'CS-'.Str::upper(Str::random(12)),
        'name' => 'Constraint Source Warehouse',
        'is_active' => true,
    ]);

    $destination = Warehouse::create([
        'company_id' => $company->id,
        'code' => 'CD-'.Str::upper(Str::random(12)),
        'name' => 'Constraint Destination Warehouse',
        'is_active' => true,
    ]);

    $product = Product::create([
        'company_id' => $company->id,
        'unit_id' => $unitId,
        'name' => 'Constraint Test Product',
        'product_type' => 'physical',
        'track_inventory' => true,
        'allow_negative_stock' => false,
        'has_variants' => false,
        'is_active' => true,
    ]);

    return compact(
        'company',
        'source',
        'destination',
        'product'
    );
}

function validConstraintBalance(array $fixture): array
{
    return [
        'company_id' => $fixture['company']->id,
        'warehouse_id' => $fixture['source']->id,
        'product_id' => $fixture['product']->id,
        'product_variant_id' => null,
        'variant_key' => 0,
        'quantity_on_hand' => '10.0000',
        'quantity_reserved' => '2.0000',
        'average_cost' => '25.0000',
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

function validConstraintTransfer(array $fixture): array
{
    return [
        'company_id' => $fixture['company']->id,
        'document_number' => 'CT-ST-'.Str::upper(Str::random(16)),
        'transfer_date' => now()->toDateString(),
        'source_warehouse_id' => $fixture['source']->id,
        'destination_warehouse_id' => $fixture['destination']->id,
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

function validConstraintMovement(array $fixture): array
{
    return [
        'company_id' => $fixture['company']->id,
        'warehouse_id' => $fixture['source']->id,
        'product_id' => $fixture['product']->id,
        'product_variant_id' => null,
        'movement_type' => 'transfer_out',
        'direction' => 'out',
        'quantity' => '1.0000',
        'unit_cost' => '25.0000',
        'total_cost' => '25.0000',
        'balance_before' => '10.0000',
        'balance_after' => '9.0000',
        'occurred_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

/**
 * Assert that an invalid database operation fails.
 *
 * Only QueryException is accepted. An unrelated PHP exception
 * must not count as a successful constraint test.
 */
function expectDatabaseConstraintViolation(
    callable $operation
): void {
    $rejected = false;

    try {
        $operation();
    } catch (QueryException $exception) {
        $rejected = true;
    }

    expect($rejected)->toBeTrue(
        'The database accepted an invalid inventory record.'
    );
}

/**
 * Verify that both INSERT and UPDATE are rejected, and that the
 * previously valid row remains unchanged.
 */
function verifyInventoryConstraint(
    string $table,
    array $validData,
    string $column,
    mixed $invalidValue
): void {
    $invalidData = $validData;
    $invalidData[$column] = $invalidValue;

    $beforeCount = DB::table($table)->count();

    expectDatabaseConstraintViolation(
        static fn () => DB::table($table)->insert($invalidData)
    );

    expect(DB::table($table)->count())
        ->toBe($beforeCount);

    $id = DB::table($table)->insertGetId($validData);

    expectDatabaseConstraintViolation(
        static fn () => DB::table($table)
            ->where('id', $id)
            ->update([$column => $invalidValue])
    );

    $stored = DB::table($table)
        ->where('id', $id)
        ->value($column);

    expect(number_format((float) $stored, 4, '.', ''))
        ->toBe(
            number_format(
                (float) $validData[$column],
                4,
                '.',
                ''
            )
        );
}

/*
|--------------------------------------------------------------------------
| 1. Reserved inventory cannot be negative
|--------------------------------------------------------------------------
*/

it('rejects negative reserved inventory on insert and update', function () {
    $fixture = constraintFixture();

    verifyInventoryConstraint(
        'inventory_balances',
        validConstraintBalance($fixture),
        'quantity_reserved',
        '-1.0000'
    );
});

/*
|--------------------------------------------------------------------------
| 2. Average inventory cost cannot be negative
|--------------------------------------------------------------------------
*/

it('rejects negative inventory cost on insert and update', function () {
    $fixture = constraintFixture();

    verifyInventoryConstraint(
        'inventory_balances',
        validConstraintBalance($fixture),
        'average_cost',
        '-5.0000'
    );
});

/*
|--------------------------------------------------------------------------
| 3. Variant key must match product variant identity
|--------------------------------------------------------------------------
*/

it('rejects inconsistent inventory variant keys', function () {
    $fixture = constraintFixture();

    verifyInventoryConstraint(
        'inventory_balances',
        validConstraintBalance($fixture),
        'variant_key',
        999
    );
});

/*
|--------------------------------------------------------------------------
| 4. Stock transfer item quantity must be positive
|--------------------------------------------------------------------------
*/

it('rejects zero and negative stock transfer quantities', function () {
    $fixture = constraintFixture();

    $transferId = DB::table('stock_transfers')
        ->insertGetId(validConstraintTransfer($fixture));

    $item = [
        'stock_transfer_id' => $transferId,
        'product_id' => $fixture['product']->id,
        'product_variant_id' => null,
        'variant_key' => 0,
        'quantity' => '3.0000',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    foreach (['0.0000', '-1.0000'] as $invalidQuantity) {
        $invalidItem = $item;
        $invalidItem['quantity'] = $invalidQuantity;

        expectDatabaseConstraintViolation(
            static fn () => DB::table('stock_transfer_items')
                ->insert($invalidItem)
        );
    }

    $itemId = DB::table('stock_transfer_items')
        ->insertGetId($item);

    foreach (['0.0000', '-1.0000'] as $invalidQuantity) {
        expectDatabaseConstraintViolation(
            static fn () => DB::table('stock_transfer_items')
                ->where('id', $itemId)
                ->update(['quantity' => $invalidQuantity])
        );
    }

    expect((string) DB::table('stock_transfer_items')
        ->where('id', $itemId)
        ->value('quantity'))
        ->toBe('3');
});

/*
|--------------------------------------------------------------------------
| 5. Transfer source and destination must differ
|--------------------------------------------------------------------------
*/

it('rejects transfers between the same warehouse', function () {
    $fixture = constraintFixture();

    $transfer = validConstraintTransfer($fixture);

    verifyInventoryConstraint(
        'stock_transfers',
        $transfer,
        'destination_warehouse_id',
        $fixture['source']->id
    );
});

/*
|--------------------------------------------------------------------------
| 6. Stock movement quantity must be positive
|--------------------------------------------------------------------------
*/

it('rejects zero and negative stock movement quantities', function () {
    $fixture = constraintFixture();

    $movement = validConstraintMovement($fixture);

    foreach (['0.0000', '-1.0000'] as $invalidQuantity) {
        $invalidMovement = $movement;
        $invalidMovement['quantity'] = $invalidQuantity;

        expectDatabaseConstraintViolation(
            static fn () => DB::table('stock_movements')
                ->insert($invalidMovement)
        );
    }

    $movementId = DB::table('stock_movements')
        ->insertGetId($movement);

    foreach (['0.0000', '-1.0000'] as $invalidQuantity) {
        expectDatabaseConstraintViolation(
            static fn () => DB::table('stock_movements')
                ->where('id', $movementId)
                ->update(['quantity' => $invalidQuantity])
        );
    }

    expect((string) DB::table('stock_movements')
        ->where('id', $movementId)
        ->value('quantity'))
        ->toBe('1');
});
