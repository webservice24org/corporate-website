<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesDelivery;
use App\Models\SalesDeliveryItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesReservationEvent;
use App\Models\SalesStockReservation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ERP\Sales\SalesOrderService;
use App\Services\ERP\Sales\SalesQuotationService;
use App\Services\ERP\Sales\SalesReservationService;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\StockTransferService;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class SalesReservationManagementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private Product $product;

    private Warehouse $warehouse;

    private InventoryBalance $balance;

    private User $creator;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SalesPermissionSeeder::class);
        $this->company = Company::query()->create(['name' => 'Reserve company', 'code' => 'RES-A', 'status' => 'active', 'currency_code' => 'BDT']);
        $this->customer = Customer::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Reserve customer', 'phone' => '01700000000', 'is_active' => true, 'credit_limit' => '100000.00']);
        $unit = Unit::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Piece', 'short_name' => 'pc', 'is_active' => true]);
        $this->product = Product::query()->create(['company_id' => $this->company->getKey(), 'unit_id' => $unit->getKey(), 'name' => 'Reserve product', 'product_type' => 'physical', 'track_inventory' => true, 'has_variants' => false, 'is_active' => true, 'selling_price' => '10.00']);
        $this->warehouse = $this->warehouse('RES-W1');
        $this->balance = $this->stock($this->warehouse, $this->product);
        $this->creator = $this->admin();
        $this->approver = $this->admin();
    }

    private function admin(): User
    {
        $actor = User::factory()->create();
        $actor->assignRole('super-admin');

        return $actor;
    }

    private function warehouse(string $code): Warehouse
    {
        return Warehouse::query()->create(['company_id' => $this->company->getKey(), 'name' => $code, 'code' => $code, 'is_active' => true]);
    }

    private function stock(Warehouse $warehouse, Product $product, ?ProductVariant $variant = null, string $quantity = '10.0000'): InventoryBalance
    {
        return InventoryBalance::query()->create(['company_id' => $warehouse->getAttribute('company_id'), 'warehouse_id' => $warehouse->getKey(), 'product_id' => $product->getKey(), 'product_variant_id' => $variant?->getKey(), 'variant_key' => $variant?->getKey() ?? 0, 'quantity_on_hand' => $quantity, 'quantity_reserved' => '0.0000', 'average_cost' => '5.0000']);
    }

    private function order(string $quantity = '6', bool $confirmed = true, ?ProductVariant $variant = null): SalesOrder
    {
        $today = now('Asia/Dhaka')->toDateString();
        $quotes = app(SalesQuotationService::class);
        $quote = $quotes->save($this->creator, ['company_id' => $this->company->getKey(), 'customer_id' => $this->customer->getKey(), 'document_date' => $today, 'valid_until' => now('Asia/Dhaka')->addDays(30)->toDateString(), 'items' => [['product_id' => $this->product->getKey(), 'product_variant_id' => $variant?->getKey(), 'quantity' => $quantity, 'unit_price' => '10', 'discount_percent' => '0', 'tax_percent' => '0']]]);
        foreach ([['submit', $this->creator], ['approve', $this->approver], ['send', $this->creator], ['accept', $this->creator]] as [$action, $actor]) {
            $quote = $quotes->transition($actor, (int) $quote->getKey(), $action, (int) $quote->getAttribute('revision'));
        }
        $orders = app(SalesOrderService::class);
        $order = $orders->create($this->creator, ['company_id' => $this->company->getKey(), 'sales_quotation_id' => $quote->getKey(), 'document_date' => $today]);
        if ($confirmed) {
            foreach ([['submit', $this->creator], ['approve', $this->approver], ['confirm', $this->creator]] as [$action, $actor]) {
                $order = $orders->transition($actor, (int) $order->getKey(), $action, (int) $order->getAttribute('revision'));
            }
        }

        return $order;
    }

    private function line(SalesOrder $order): SalesOrderItem
    {
        return SalesOrderItem::query()->where('sales_order_id', $order->getKey())->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function allocation(SalesOrder $order, string $quantity = '6', ?Warehouse $warehouse = null): array
    {
        return ['sales_order_item_id' => $this->line($order)->getKey(), 'warehouse_id' => ($warehouse ?? $this->warehouse)->getKey(), 'quantity' => $quantity, 'revision' => $order->fresh()->getAttribute('revision'), 'idempotency_key' => (string) Str::uuid()];
    }

    private function reserve(SalesOrder $order, string $quantity = '6', ?Warehouse $warehouse = null): SalesStockReservation
    {
        app(SalesReservationService::class)->allocate($this->creator, (int) $order->getKey(), $this->allocation($order, $quantity, $warehouse));

        return SalesStockReservation::query()->where('sales_order_item_id', $this->line($order)->getKey())->where('warehouse_id', ($warehouse ?? $this->warehouse)->getKey())->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function releasePayload(SalesOrder $order, SalesStockReservation $row, string $quantity = '2'): array
    {
        return ['reservation_id' => $row->getKey(), 'quantity' => $quantity, 'reason' => 'Reallocate stock', 'revision' => $order->fresh()->getAttribute('revision'), 'idempotency_key' => (string) Str::uuid()];
    }

    private function invalid(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected validation rejection.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    /** Isolated draft source for testing the internal 5.6 consumption contract. */
    private function deliveryItem(SalesOrder $order, SalesStockReservation $reservation, string $quantity = '6'): SalesDeliveryItem
    {
        $delivery = SalesDelivery::query()->create(['company_id' => $this->company->getKey(), 'customer_id' => $this->customer->getKey(), 'sales_order_id' => $order->getKey(), 'number' => 'SD-TEST-'.$reservation->getKey(), 'currency_code' => 'BDT', 'status' => 'draft', 'document_date' => now('Asia/Dhaka')->toDateString(), 'created_by' => $this->creator->getKey()]);
        $line = $this->line($order);

        return SalesDeliveryItem::query()->create(['sales_delivery_id' => $delivery->getKey(), 'line_number' => 1, 'sales_order_item_id' => $line->getKey(), 'product_id' => $line->getAttribute('product_id'), 'product_variant_id' => $line->getAttribute('product_variant_id'), 'unit_id' => $line->getAttribute('unit_id'), 'product_snapshot' => $line->getAttribute('product_snapshot'), 'quantity' => $quantity, 'warehouse_id' => $reservation->getAttribute('warehouse_id'), 'sales_stock_reservation_id' => $reservation->getKey(), 'fulfillment_type' => 'physical']);
    }

    public function test_allocation_holds_stock_without_changing_on_hand_cost_or_ledger(): void
    {
        $order = $this->order();
        $before = (int) $order->getAttribute('revision');
        $row = $this->reserve($order);
        $this->assertSame('6.0000', $row->getAttribute('allocated_quantity'));
        $this->assertSame('6.0000', $this->balance->fresh()->getAttribute('quantity_reserved'));
        $this->assertSame('10.0000', $this->balance->fresh()->getAttribute('quantity_on_hand'));
        $this->assertSame('5.0000', $this->balance->fresh()->getAttribute('average_cost'));
        $this->assertSame($before + 1, $order->fresh()->getAttribute('revision'));
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('sales_reservation_events', 1);
    }

    public function test_partial_allocations_across_warehouses_keep_shortage_visible(): void
    {
        $order = $this->order();
        $second = $this->warehouse('RES-W2');
        $this->stock($second, $this->product, quantity: '2.0000');
        $this->reserve($order, '3');
        $this->reserve($order, '2', $second);
        $this->actingAs($this->creator)->get('/erp/sales/reservations/'.$order->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Sales/Reservations/Show')->where('items.0.reserved', '5.0000')->where('items.0.unreserved', '1.0000')->has('reservations', 2));
    }

    public function test_duplicate_allocation_key_is_exactly_once_even_with_stale_revision(): void
    {
        $order = $this->order();
        $payload = $this->allocation($order, '2');
        $service = app(SalesReservationService::class);
        $first = $service->allocate($this->creator, (int) $order->getKey(), $payload);
        $second = $service->allocate($this->creator, (int) $order->getKey(), $payload);
        $this->assertSame($first->getAttribute('revision'), $second->getAttribute('revision'));
        $this->assertSame('2.0000', $this->balance->fresh()->getAttribute('quantity_reserved'));
        $this->assertDatabaseCount('sales_reservation_events', 1);
    }

    public function test_reused_operation_key_with_different_payload_is_rejected(): void
    {
        $order = $this->order();
        $payload = $this->allocation($order, '2');
        app(SalesReservationService::class)->allocate($this->creator, (int) $order->getKey(), $payload);
        $this->invalid(fn () => app(SalesReservationService::class)->allocate($this->creator, (int) $order->getKey(), [...$payload, 'quantity' => '3']));
        $this->assertDatabaseCount('sales_reservation_events', 1);
        $this->assertSame('2.0000', $this->balance->fresh()->getAttribute('quantity_reserved'));
    }

    public function test_competing_orders_cannot_over_allocate_available_stock(): void
    {
        $a = $this->order();
        $b = $this->order();
        $this->reserve($a);
        $this->invalid(fn () => $this->reserve($b, '5'));
        $this->reserve($b, '4');
        $this->assertSame('10.0000', $this->balance->fresh()->getAttribute('quantity_reserved'));
    }

    public function test_order_remainder_caps_total_reservations_across_warehouses(): void
    {
        $order = $this->order();
        $second = $this->warehouse('RES-W2');
        $this->stock($second, $this->product);
        $this->reserve($order, '4');
        $this->invalid(fn () => $this->reserve($order, '3', $second));
        $this->assertDatabaseCount('sales_stock_reservations', 1);
    }

    public function test_fractional_quantities_are_exact_and_invalid_quantities_do_not_write(): void
    {
        $order = $this->order();
        $this->reserve($order, '0.0001');
        $this->reserve($order, '0.1234');
        $this->assertSame('0.1235', $this->balance->fresh()->getAttribute('quantity_reserved'));
        foreach (['0', '-1', '0.00001', '1e1', '10000.0001'] as $quantity) {
            $this->invalid(fn () => $this->reserve($order, $quantity));
        }
        $this->assertDatabaseCount('sales_reservation_events', 2);
    }

    public function test_draft_and_cancelled_orders_cannot_allocate(): void
    {
        $draft = $this->order(confirmed: false);
        $this->invalid(fn () => $this->reserve($draft));
        $order = $this->order();
        app(SalesOrderService::class)->transition($this->creator, (int) $order->getKey(), 'cancel', (int) $order->getAttribute('revision'), 'Not needed');
        $this->invalid(fn () => $this->reserve($order));
        $this->assertDatabaseCount('sales_stock_reservations', 0);
    }

    public function test_inactive_warehouse_product_customer_and_unit_block_new_allocations(): void
    {
        $order = $this->order();
        foreach ([$this->warehouse, $this->product, $this->customer, Unit::query()->findOrFail($this->product->getAttribute('unit_id'))] as $model) {
            $model->setAttribute('is_active', false)->save();
            $this->invalid(fn () => $this->reserve($order));
            $model->setAttribute('is_active', true)->save();
        }
        $this->assertDatabaseCount('sales_stock_reservations', 0);
    }

    public function test_product_configuration_changes_and_non_stock_items_are_rejected(): void
    {
        $order = $this->order();
        $this->product->update(['track_inventory' => false]);
        $this->invalid(fn () => $this->reserve($order));
        $this->assertDatabaseCount('sales_stock_reservations', 0);
    }

    public function test_exact_variant_balances_are_required_and_other_variants_are_untouched(): void
    {
        $this->product->update(['has_variants' => true]);
        $a = ProductVariant::query()->create(['product_id' => $this->product->getKey(), 'name' => 'A', 'is_active' => true]);
        $b = ProductVariant::query()->create(['product_id' => $this->product->getKey(), 'name' => 'B', 'is_active' => true]);
        $balanceA = $this->stock($this->warehouse, $this->product, $a, '2.0000');
        $balanceB = $this->stock($this->warehouse, $this->product, $b);
        $order = $this->order(variant: $a);
        $this->invalid(fn () => $this->reserve($order, '3'));
        $row = $this->reserve($order, '2');
        $this->assertSame((int) $a->getKey(), $row->getAttribute('variant_key'));
        $this->assertSame('2.0000', $balanceA->fresh()->getAttribute('quantity_reserved'));
        $this->assertSame('0.0000', $balanceB->fresh()->getAttribute('quantity_reserved'));
        $a->update(['is_active' => false]);
        $this->invalid(fn () => $this->reserve($order, '1'));
    }

    public function test_missing_balance_and_negative_stock_policy_cannot_create_availability(): void
    {
        $order = $this->order();
        $empty = $this->warehouse('RES-EMPTY');
        $this->invalid(fn () => $this->reserve($order, '1', $empty));
        $this->product->update(['allow_negative_stock' => true]);
        $this->balance->update(['quantity_on_hand' => '0.0000']);
        $this->invalid(fn () => $this->reserve($order, '1'));
        $this->assertDatabaseCount('sales_stock_reservations', 0);
    }

    public function test_stale_revision_is_rejected_for_new_operation_keys(): void
    {
        $order = $this->order();
        $payload = $this->allocation($order, '2');
        $this->reserve($order, '1');
        $this->invalid(fn () => app(SalesReservationService::class)->allocate($this->creator, (int) $order->getKey(), $payload));
        $this->assertSame('1.0000', $this->balance->fresh()->getAttribute('quantity_reserved'));
    }

    public function test_release_is_reasoned_partial_and_idempotent(): void
    {
        $order = $this->order();
        $row = $this->reserve($order);
        $payload = $this->releasePayload($order, $row);
        $service = app(SalesReservationService::class);
        $service->release($this->creator, (int) $order->getKey(), $payload);
        $service->release($this->creator, (int) $order->getKey(), $payload);
        $this->assertSame('2.0000', $row->fresh()->getAttribute('released_quantity'));
        $this->assertSame('4.0000', $this->balance->fresh()->getAttribute('quantity_reserved'));
        $this->assertDatabaseCount('sales_reservation_events', 2);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_release_requires_reason_and_cannot_exceed_owned_active_quantity(): void
    {
        $order = $this->order();
        $row = $this->reserve($order, '2');
        $payload = $this->releasePayload($order, $row);
        $this->invalid(fn () => app(SalesReservationService::class)->release($this->creator, (int) $order->getKey(), [...$payload, 'reason' => '   ']));
        $this->invalid(fn () => app(SalesReservationService::class)->release($this->creator, (int) $order->getKey(), [...$payload, 'quantity' => '3']));
        $this->assertSame('2.0000', $this->balance->fresh()->getAttribute('quantity_reserved'));
    }

    public function test_release_allows_archived_warehouse_and_product_cleanup(): void
    {
        $order = $this->order();
        $row = $this->reserve($order);
        $this->warehouse->update(['is_active' => false]);
        $this->warehouse->delete();
        $this->product->update(['is_active' => false]);
        $this->product->delete();
        app(SalesReservationService::class)->release($this->creator, (int) $order->getKey(), $this->releasePayload($order, $row, '6'));
        $this->assertSame('0.0000', $this->balance->fresh()->getAttribute('quantity_reserved'));
    }

    public function test_cancel_releases_only_its_owned_stock_and_preserves_other_orders(): void
    {
        $a = $this->order();
        $b = $this->order('2');
        $rowA = $this->reserve($a);
        $rowB = $this->reserve($b, '2');
        $a = $a->fresh();
        app(SalesOrderService::class)->transition($this->creator, (int) $a->getKey(), 'cancel', (int) $a->getAttribute('revision'), 'Customer cancelled');
        $this->assertSame('6.0000', $rowA->fresh()->getAttribute('released_quantity'));
        $this->assertSame('0.0000', $rowB->fresh()->getAttribute('released_quantity'));
        $this->assertSame('2.0000', $this->balance->fresh()->getAttribute('quantity_reserved'));
        $this->assertSame('10.0000', $this->balance->fresh()->getAttribute('quantity_on_hand'));
        $this->assertSame('6.0000', $this->line($a)->getAttribute('cancelled_quantity'));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_cancel_second_warehouse_failure_rolls_back_releases_and_order(): void
    {
        $order = $this->order();
        $second = $this->warehouse('RES-W2');
        $balance = $this->stock($second, $this->product);
        $a = $this->reserve($order, '3');
        $b = $this->reserve($order, '3', $second);
        $balance->update(['quantity_reserved' => '2.0000']);
        $order = $order->fresh();
        $this->invalid(fn () => app(SalesOrderService::class)->transition($this->creator, (int) $order->getKey(), 'cancel', (int) $order->getAttribute('revision'), 'Cancel all'));
        $this->assertSame('0.0000', $a->fresh()->getAttribute('released_quantity'));
        $this->assertSame('0.0000', $b->fresh()->getAttribute('released_quantity'));
        $this->assertSame('3.0000', $this->balance->fresh()->getAttribute('quantity_reserved'));
        $this->assertSame('confirmed', $order->fresh()->getAttribute('status'));
        $this->assertDatabaseCount('sales_reservation_events', 2);
    }

    public function test_drift_in_reserved_balance_blocks_allocation_and_release(): void
    {
        $order = $this->order();
        $row = $this->reserve($order, '2');
        $this->balance->update(['quantity_reserved' => '1.0000']);
        $this->invalid(fn () => $this->reserve($order, '1'));
        $this->invalid(fn () => app(SalesReservationService::class)->release($this->creator, (int) $order->getKey(), $this->releasePayload($order, $row, '1')));
        $this->assertDatabaseCount('sales_reservation_events', 1);
    }

    public function test_ordinary_stock_out_cannot_spend_reserved_stock(): void
    {
        $order = $this->order();
        $this->reserve($order);
        $this->invalid(fn () => app(InventoryService::class)->stockOut($this->warehouse, $this->product, '5', StockMovement::TYPE_ADJUSTMENT_OUT));
        $this->assertSame('10.0000', $this->balance->fresh()->getAttribute('quantity_on_hand'));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_stock_transfer_cannot_reduce_on_hand_below_reservations(): void
    {
        $order = $this->order();
        $this->reserve($order);
        $destination = $this->warehouse('RES-DEST');
        $transfer = StockTransfer::query()->create(['company_id' => $this->company->getKey(), 'source_warehouse_id' => $this->warehouse->getKey(), 'destination_warehouse_id' => $destination->getKey(), 'document_number' => 'RES-TR-1', 'transfer_date' => now('Asia/Dhaka')->toDateString(), 'status' => 'draft', 'created_by' => $this->creator->getKey()]);
        $transfer->items()->create(['product_id' => $this->product->getKey(), 'quantity' => '5.0000', 'unit_cost' => '5.0000']);
        $this->invalid(fn () => app(StockTransferService::class)->post($transfer));
        $this->assertSame('10.0000', $this->balance->fresh()->getAttribute('quantity_on_hand'));
        $this->assertSame('6.0000', $this->balance->fresh()->getAttribute('quantity_reserved'));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_internal_consumption_and_stock_out_preserve_other_orders_and_retry_once(): void
    {
        $a = $this->order();
        $b = $this->order('2');
        $rowA = $this->reserve($a);
        $rowB = $this->reserve($b, '2');
        $item = $this->deliveryItem($a, $rowA);
        DB::transaction(function () use ($a, $item): void {
            $service = app(SalesReservationService::class);
            $first = $service->consumeForDelivery($this->creator, (int) $item->getKey());
            $retry = $service->consumeForDelivery($this->creator, (int) $item->getKey());
            $this->assertSame($first->getKey(), $retry->getKey());
            $movement = app(InventoryService::class)->stockOut($this->warehouse, $this->product, '6', StockMovement::TYPE_SALE, unitCost: '5', referenceType: SalesDelivery::class, referenceId: (int) $item->getAttribute('sales_delivery_id'), createdBy: (int) $this->creator->getKey());
            $item->update(['stock_movement_id' => $movement->getKey()]);
            $this->line($a)->update(['delivered_quantity' => '6.0000']);
            SalesDelivery::query()->findOrFail($item->getAttribute('sales_delivery_id'))->update(['status' => 'posted', 'posted_at' => now()->toDateTimeString()]);
            $a->update(['status' => 'delivered']);
        });
        $this->assertSame('6.0000', $rowA->fresh()->getAttribute('consumed_quantity'));
        $this->assertSame('0.0000', $rowB->fresh()->getAttribute('consumed_quantity'));
        $this->assertSame('4.0000', $this->balance->fresh()->getAttribute('quantity_on_hand'));
        $this->assertSame('2.0000', $this->balance->fresh()->getAttribute('quantity_reserved'));
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('sales_reservation_events', 3);
    }

    public function test_stock_out_failure_rolls_back_consumption_and_event(): void
    {
        $order = $this->order();
        $row = $this->reserve($order);
        $item = $this->deliveryItem($order, $row);
        $this->invalid(fn () => DB::transaction(function () use ($item): void {
            app(SalesReservationService::class)->consumeForDelivery($this->creator, (int) $item->getKey());
            app(InventoryService::class)->stockOut($this->warehouse, $this->product, '11', StockMovement::TYPE_SALE);
        }));
        $this->assertSame('0.0000', $row->fresh()->getAttribute('consumed_quantity'));
        $this->assertSame('6.0000', $this->balance->fresh()->getAttribute('quantity_reserved'));
        $this->assertSame('10.0000', $this->balance->fresh()->getAttribute('quantity_on_hand'));
        $this->assertDatabaseCount('sales_reservation_events', 1);
    }

    public function test_consumption_rejects_wrong_warehouse_and_foreign_owned_reservation(): void
    {
        $a = $this->order();
        $b = $this->order('2');
        $rowA = $this->reserve($a);
        $rowB = $this->reserve($b, '2');
        $item = $this->deliveryItem($a, $rowA);
        $item->update(['warehouse_id' => $this->warehouse('RES-W2')->getKey()]);
        $this->invalid(fn () => DB::transaction(fn () => app(SalesReservationService::class)->consumeForDelivery($this->creator, (int) $item->getKey())));
        $item->update(['warehouse_id' => $this->warehouse->getKey(), 'sales_stock_reservation_id' => $rowB->getKey()]);
        $this->expectException(ModelNotFoundException::class);
        DB::transaction(fn () => app(SalesReservationService::class)->consumeForDelivery($this->creator, (int) $item->getKey()));
    }

    public function test_internal_consumption_requires_caller_transaction(): void
    {
        $order = $this->order();
        $item = $this->deliveryItem($order, $this->reserve($order));
        // RefreshDatabase wraps tests in a transaction; force the guard's level read to zero.
        DB::shouldReceive('transactionLevel')->once()->andReturn(0);
        $this->expectException(\LogicException::class);
        app(SalesReservationService::class)->consumeForDelivery($this->creator, (int) $item->getKey());
    }

    public function test_reservation_pages_require_authentication_and_view_permission(): void
    {
        $order = $this->order();
        $this->get('/erp/sales/reservations')->assertRedirect('/login');
        $actor = User::factory()->create();
        $this->actingAs($actor)->get('/erp/sales/reservations')->assertForbidden();
        $this->actingAs($this->creator)->get('/erp/sales/reservations')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Sales/Reservations/Index')->has('orders.data', 1));
        $this->get('/erp/sales/reservations/'.$order->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Sales/Reservations/Show')->has('items', 1)->has('warehouses', 1)->missing('order.customer_snapshot.nid_number'));
    }

    public function test_staff_view_does_not_grant_allocation_and_cross_company_access_is_scoped(): void
    {
        $order = $this->order();
        $actor = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $actor->getKey(), 'employee_number' => 'RES-STAFF', 'first_name' => 'Reserve', 'last_name' => 'Staff']);
        $actor->givePermissionTo('erp.sales-reservations.view');
        $this->actingAs($actor)->get('/erp/sales/reservations/'.$order->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->where('orderUrl', null));
        $this->post('/erp/sales/reservations/'.$order->getKey().'/allocate', $this->allocation($order))->assertForbidden();
        $foreign = Company::query()->create(['name' => 'Foreign', 'code' => 'RES-B', 'status' => 'active', 'currency_code' => 'BDT']);
        $this->get('/erp/sales/reservations?company_id='.$foreign->getKey())->assertForbidden();
        Employee::query()->where('user_id', $actor->getKey())->update(['company_id' => $foreign->getKey()]);
        $this->get('/erp/sales/reservations/'.$order->getKey())->assertNotFound();
    }

    public function test_foreign_warehouse_and_wrong_order_item_are_not_found(): void
    {
        $order = $this->order();
        $other = $this->order();
        $foreign = Company::query()->create(['name' => 'Foreign', 'code' => 'RES-B', 'status' => 'active', 'currency_code' => 'BDT']);
        $warehouse = Warehouse::query()->create(['company_id' => $foreign->getKey(), 'name' => 'Foreign warehouse', 'code' => 'RES-F', 'is_active' => true]);
        $this->actingAs($this->creator)->post('/erp/sales/reservations/'.$order->getKey().'/allocate', $this->allocation($order, '1', $warehouse))->assertNotFound();
        $this->post('/erp/sales/reservations/'.$order->getKey().'/allocate', [...$this->allocation($order, '1'), 'sales_order_item_id' => $this->line($other)->getKey()])->assertNotFound();
    }

    public function test_event_update_and_delete_are_rejected(): void
    {
        $this->reserve($this->order());
        $event = SalesReservationEvent::query()->firstOrFail();
        try {
            $event->update(['reason' => 'Changed']);
            $this->fail('Event was editable.');
        } catch (\LogicException) {
            $this->assertNull($event->fresh()->getAttribute('reason'));
        }
        $this->expectException(\LogicException::class);
        $event->delete();
    }

    public function test_cancel_permission_includes_automatic_release_without_manual_release_permission(): void
    {
        $order = $this->order();
        $row = $this->reserve($order);
        $actor = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $actor->getKey(), 'employee_number' => 'RES-CANCEL', 'first_name' => 'Cancel', 'last_name' => 'Staff']);
        $actor->givePermissionTo('erp.sales-orders.cancel');
        $order = $order->fresh();
        app(SalesOrderService::class)->transition($actor, (int) $order->getKey(), 'cancel', (int) $order->getAttribute('revision'), 'Cancel remainder');
        $this->assertFalse($actor->can('erp.sales-reservations.release'));
        $this->assertSame('6.0000', $row->fresh()->getAttribute('released_quantity'));
        $this->assertDatabaseHas('sales_reservation_events', ['sales_stock_reservation_id' => $row->getKey(), 'action' => 'release', 'actor_id' => $actor->getKey()]);
    }

    public function test_allocation_retry_after_cancellation_does_not_recreate_reservation(): void
    {
        $order = $this->order();
        $payload = $this->allocation($order);
        $order = app(SalesReservationService::class)->allocate($this->creator, (int) $order->getKey(), $payload);
        app(SalesOrderService::class)->transition($this->creator, (int) $order->getKey(), 'cancel', (int) $order->getAttribute('revision'), 'Cancelled');
        $retried = app(SalesReservationService::class)->allocate($this->creator, (int) $order->getKey(), $payload);
        $this->assertSame('cancelled', $retried->getAttribute('status'));
        $this->assertSame('0.0000', $this->balance->fresh()->getAttribute('quantity_reserved'));
        $this->assertDatabaseCount('sales_reservation_events', 2);
    }

    public function test_consumption_cannot_exceed_its_owned_reservation(): void
    {
        $order = $this->order();
        $row = $this->reserve($order, '2');
        $item = $this->deliveryItem($order, $row, '3');
        $this->invalid(fn () => DB::transaction(fn () => app(SalesReservationService::class)->consumeForDelivery($this->creator, (int) $item->getKey())));
        $this->assertSame('0.0000', $row->fresh()->getAttribute('consumed_quantity'));
        $this->assertSame('2.0000', $this->balance->fresh()->getAttribute('quantity_reserved'));
        $this->assertDatabaseCount('sales_reservation_events', 1);
    }

    public function test_changed_unit_configuration_is_rejected(): void
    {
        $order = $this->order();
        $unit = Unit::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Box', 'short_name' => 'box', 'is_active' => true]);
        $this->product->update(['unit_id' => $unit->getKey()]);
        $this->invalid(fn () => $this->reserve($order));
        $this->assertDatabaseCount('sales_stock_reservations', 0);
    }

    public function test_cumulative_reallocation_counters_can_exceed_original_line_quantity(): void
    {
        $this->balance->update(['quantity_on_hand' => '10000.0000']);
        $order = $this->order('10000');
        $row = $this->reserve($order, '10000');
        app(SalesReservationService::class)->release($this->creator, (int) $order->getKey(), $this->releasePayload($order, $row, '10000'));
        $row = $this->reserve($order, '10000');
        $this->assertSame('20000.0000', $row->getAttribute('allocated_quantity'));
        $order = $order->fresh();
        app(SalesOrderService::class)->transition($this->creator, (int) $order->getKey(), 'cancel', (int) $order->getAttribute('revision'), 'Cancel reallocated order');
        $this->assertSame('20000.0000', $row->fresh()->getAttribute('released_quantity'));
        $this->assertSame('0.0000', $this->balance->fresh()->getAttribute('quantity_reserved'));
        $this->assertSame('10000.0000', $this->balance->fresh()->getAttribute('quantity_on_hand'));
    }
}
