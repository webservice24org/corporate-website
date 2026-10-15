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
use App\Models\SalesDocumentEvent;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesStockReservation;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ERP\Sales\SalesDeliveryService;
use App\Services\ERP\Sales\SalesOrderService;
use App\Services\ERP\Sales\SalesQuotationService;
use App\Services\ERP\Sales\SalesReservationService;
use App\Services\Inventory\InventoryService;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class SalesDeliveryManagementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private Unit $unit;

    private Product $product;

    private Warehouse $warehouse;

    private InventoryBalance $balance;

    private User $creator;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SalesPermissionSeeder::class);
        $this->company = Company::query()->create(['name' => 'Delivery company', 'code' => 'DEL-A', 'status' => 'active', 'currency_code' => 'BDT']);
        $this->customer = Customer::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Delivery customer', 'phone' => '01700000000', 'credit_limit' => '100000.00', 'is_active' => true]);
        $this->unit = Unit::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Piece', 'short_name' => 'pc', 'is_active' => true]);
        $this->product = $this->product('Physical');
        $this->warehouse = $this->warehouse('DEL-W1');
        $this->balance = $this->stock($this->product);
        $this->creator = $this->admin();
        $this->approver = $this->admin();
    }

    private function admin(): User
    {
        $actor = User::factory()->create();
        $actor->assignRole('super-admin');

        return $actor;
    }

    private function product(string $name, string $type = 'physical', bool $tracked = true): Product
    {
        return Product::query()->create(['company_id' => $this->company->getKey(), 'unit_id' => $this->unit->getKey(), 'name' => $name, 'product_type' => $type, 'track_inventory' => $tracked, 'has_variants' => false, 'is_active' => true, 'selling_price' => '10.00']);
    }

    private function warehouse(string $code): Warehouse
    {
        return Warehouse::query()->create(['company_id' => $this->company->getKey(), 'code' => $code, 'name' => $code, 'is_active' => true]);
    }

    private function stock(Product $product, ?Warehouse $warehouse = null, ?ProductVariant $variant = null): InventoryBalance
    {
        return InventoryBalance::query()->create(['company_id' => $this->company->getKey(), 'warehouse_id' => ($warehouse ?? $this->warehouse)->getKey(), 'product_id' => $product->getKey(), 'product_variant_id' => $variant?->getKey(), 'variant_key' => $variant?->getKey() ?? 0, 'quantity_on_hand' => '10.0000', 'quantity_reserved' => '0.0000', 'average_cost' => '5.0000']);
    }

    /** @param list<array<string, mixed>>|null $items */
    private function order(?array $items = null, bool $confirmed = true): SalesOrder
    {
        $today = now('Asia/Dhaka')->toDateString();
        $quotes = app(SalesQuotationService::class);
        $quote = $quotes->save($this->creator, ['company_id' => $this->company->getKey(), 'customer_id' => $this->customer->getKey(), 'document_date' => $today, 'valid_until' => now('Asia/Dhaka')->addDays(30)->toDateString(), 'notes' => 'Source quotation terms', 'items' => $items ?? [['product_id' => $this->product->getKey(), 'quantity' => '6', 'unit_price' => '10', 'discount_percent' => '0', 'tax_percent' => '0']]]);
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

    private function line(SalesOrder $order, ?Product $product = null): SalesOrderItem
    {
        return SalesOrderItem::query()->where('sales_order_id', $order->getKey())->where('product_id', ($product ?? $this->product)->getKey())->firstOrFail();
    }

    private function reserve(SalesOrder $order, string $quantity = '6', ?Product $product = null, ?Warehouse $warehouse = null): SalesStockReservation
    {
        $line = $this->line($order, $product);
        app(SalesReservationService::class)->allocate($this->creator, (int) $order->getKey(), ['sales_order_item_id' => $line->getKey(), 'warehouse_id' => ($warehouse ?? $this->warehouse)->getKey(), 'quantity' => $quantity, 'revision' => $order->fresh()->getAttribute('revision'), 'idempotency_key' => (string) Str::uuid()]);

        return SalesStockReservation::query()->where('sales_order_item_id', $line->getKey())->where('warehouse_id', ($warehouse ?? $this->warehouse)->getKey())->firstOrFail();
    }

    /** @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function payload(SalesOrder $order, array $items): array
    {
        return ['company_id' => $this->company->getKey(), 'sales_order_id' => $order->getKey(), 'document_date' => now('Asia/Dhaka')->toDateString(), 'notes' => 'Handle carefully', 'items' => $items];
    }

    private function draft(SalesOrder $order, SalesStockReservation $reservation, string $quantity = '6'): SalesDelivery
    {
        return app(SalesDeliveryService::class)->save($this->creator, $this->payload($order, [['sales_order_item_id' => $reservation->getAttribute('sales_order_item_id'), 'quantity' => $quantity, 'sales_stock_reservation_id' => $reservation->getKey()]]));
    }

    private function postDelivery(SalesDelivery $delivery, ?string $key = null): SalesDelivery
    {
        return app(SalesDeliveryService::class)->post($this->creator, (int) $delivery->getKey(), (int) $delivery->getAttribute('revision'), $key ?? (string) Str::uuid());
    }

    private function invalid(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a validation rejection.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    public function test_draft_copies_sources_without_changing_stock_reservations_or_order_counters(): void
    {
        $order = $this->order();
        $row = $this->reserve($order);
        $delivery = $this->draft($order, $row, '2');
        $this->assertStringStartsWith('SD-', (string) $delivery->getAttribute('number'));
        $this->assertSame('draft', $delivery->getAttribute('status'));
        $this->assertSame($order->getAttribute('customer_snapshot'), $delivery->getAttribute('customer_snapshot'));
        $this->assertSame('0.0000', $this->line($order)->getAttribute('delivered_quantity'));
        $this->assertSame('6.0000', $this->balance->fresh()->getAttribute('quantity_reserved'));
        $this->assertSame('10.0000', $this->balance->fresh()->getAttribute('quantity_on_hand'));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_partial_post_links_movement_cost_consumption_and_order_progress(): void
    {
        $order = $this->order();
        $row = $this->reserve($order);
        $delivery = $this->postDelivery($this->draft($order, $row, '2'));
        $item = SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->firstOrFail();
        $movement = StockMovement::query()->findOrFail($item->getAttribute('stock_movement_id'));
        $this->assertSame('posted', $delivery->getAttribute('status'));
        $this->assertSame('2.0000', $row->fresh()->getAttribute('consumed_quantity'));
        $this->assertSame('8.0000', $this->balance->fresh()->getAttribute('quantity_on_hand'));
        $this->assertSame('4.0000', $this->balance->fresh()->getAttribute('quantity_reserved'));
        $this->assertSame('2.0000', $this->line($order)->getAttribute('delivered_quantity'));
        $this->assertSame('partially_delivered', $order->fresh()->getAttribute('status'));
        $this->assertSame('5.0000', $item->getAttribute('unit_cost'));
        $this->assertSame('10.0000', $item->getAttribute('total_cost'));
        $this->assertSame('sale', $movement->getAttribute('movement_type'));
        $this->assertSame('out', $movement->getAttribute('direction'));
        $this->assertSame(SalesDelivery::class, $movement->getAttribute('reference_type'));
        $this->assertSame((int) $delivery->getKey(), (int) $movement->getAttribute('reference_id'));
        $this->assertSame($delivery->getAttribute('number'), $movement->getAttribute('reference_number'));
        $this->assertDatabaseCount('sales_invoices', 0);
    }

    public function test_two_partial_deliveries_finish_order_and_allow_explicit_close(): void
    {
        $order = $this->order();
        $row = $this->reserve($order);
        $this->postDelivery($this->draft($order, $row, '2'));
        $this->postDelivery($this->draft($order, $row->fresh(), '4'));
        $order = $order->fresh();
        $this->assertSame('delivered', $order->getAttribute('status'));
        $this->assertSame('6.0000', $this->line($order)->getAttribute('delivered_quantity'));
        $this->assertSame('4.0000', $this->balance->fresh()->getAttribute('quantity_on_hand'));
        $this->assertSame('0.0000', $this->balance->fresh()->getAttribute('quantity_reserved'));
        $closed = app(SalesOrderService::class)->transition($this->creator, (int) $order->getKey(), 'close', (int) $order->getAttribute('revision'));
        $this->assertSame('closed', $closed->getAttribute('status'));
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_post_retry_with_same_key_and_original_revision_does_not_duplicate_effects(): void
    {
        $order = $this->order();
        $delivery = $this->draft($order, $this->reserve($order));
        $key = (string) Str::uuid();
        $first = $this->postDelivery($delivery, $key);
        $again = $this->postDelivery($delivery, $key);
        $this->assertSame($first->getAttribute('revision'), $again->getAttribute('revision'));
        $this->assertSame('4.0000', $this->balance->fresh()->getAttribute('quantity_on_hand'));
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('sales_document_events', ['document_type' => 'delivery', 'document_id' => $delivery->getKey(), 'action' => 'post']);
        $this->assertSame(1, SalesDocumentEvent::query()->where('document_type', 'delivery')->where('document_id', $delivery->getKey())->where('action', 'post')->count());
    }

    public function test_posted_delivery_rejects_a_different_key_and_changed_source_payload(): void
    {
        $order = $this->order();
        $key = (string) Str::uuid();
        $delivery = $this->postDelivery($this->draft($order, $this->reserve($order)), $key);
        $this->invalid(fn () => $this->postDelivery($delivery));
        $delivery->update(['notes' => 'Tampered source']);
        $this->invalid(fn () => $this->postDelivery($delivery, $key));
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_posting_key_cannot_be_reused_on_another_delivery(): void
    {
        $order = $this->order();
        $row = $this->reserve($order);
        $a = $this->draft($order, $row, '2');
        $b = $this->draft($order, $row, '2');
        $key = (string) Str::uuid();
        $this->postDelivery($a, $key);
        $this->invalid(fn () => $this->postDelivery($b, $key));
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_current_average_cost_is_used_at_posting_without_recalculating_average(): void
    {
        $order = $this->order();
        $delivery = $this->draft($order, $this->reserve($order), '2');
        $this->balance->update(['average_cost' => '7.1234']);
        $this->postDelivery($delivery);
        $item = SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->firstOrFail();
        $this->assertSame('7.1234', $item->getAttribute('unit_cost'));
        $this->assertSame('14.2468', $item->getAttribute('total_cost'));
        $this->assertSame('7.1234', $this->balance->fresh()->getAttribute('average_cost'));
    }

    public function test_consumption_preserves_another_orders_reservations(): void
    {
        $a = $this->order();
        $rowA = $this->reserve($a);
        $b = $this->order([['product_id' => $this->product->getKey(), 'quantity' => '2', 'unit_price' => '10']]);
        $rowB = $this->reserve($b, '2');
        $this->postDelivery($this->draft($a, $rowA));
        $this->assertSame('4.0000', $this->balance->fresh()->getAttribute('quantity_on_hand'));
        $this->assertSame('2.0000', $this->balance->fresh()->getAttribute('quantity_reserved'));
        $this->assertSame('0.0000', $rowB->fresh()->getAttribute('consumed_quantity'));
    }

    public function test_service_digital_and_untracked_physical_lines_complete_without_stock(): void
    {
        $service = $this->product('Consulting', 'service', false);
        $digital = $this->product('Download', 'digital', false);
        $untracked = $this->product('Untracked goods', 'physical', false);
        $order = $this->order(array_map(fn (Product $product): array => ['product_id' => $product->getKey(), 'quantity' => '2', 'unit_price' => '10'], [$service, $digital, $untracked]));
        $items = [];
        foreach (SalesOrderItem::query()->where('sales_order_id', $order->getKey())->get() as $line) {
            $items[] = ['sales_order_item_id' => $line->getKey(), 'quantity' => '2'];
        }
        $delivery = app(SalesDeliveryService::class)->save($this->creator, $this->payload($order, $items));
        $this->postDelivery($delivery);
        $this->assertSame('delivered', $order->fresh()->getAttribute('status'));
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('sales_stock_reservations', 0);
        foreach (SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->get() as $item) {
            $this->assertSame('completion', $item->getAttribute('fulfillment_type'));
            $this->assertNull($item->getAttribute('stock_movement_id'));
            $this->assertNull($item->getAttribute('warehouse_id'));
            $this->assertSame('0.0000', $item->getAttribute('unit_cost'));
        }
    }

    public function test_mixed_physical_dispatch_and_service_completion_are_posted_together(): void
    {
        $service = $this->product('Consulting', 'service', false);
        $order = $this->order([['product_id' => $this->product->getKey(), 'quantity' => '2', 'unit_price' => '10'], ['product_id' => $service->getKey(), 'quantity' => '1', 'unit_price' => '10']]);
        $row = $this->reserve($order, '2');
        $delivery = app(SalesDeliveryService::class)->save($this->creator, $this->payload($order, [['sales_order_item_id' => $this->line($order)->getKey(), 'quantity' => '2', 'sales_stock_reservation_id' => $row->getKey()], ['sales_order_item_id' => $this->line($order, $service)->getKey(), 'quantity' => '1']]));
        $this->postDelivery($delivery);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame('delivered', $order->fresh()->getAttribute('status'));
        $this->assertSame('1.0000', $this->line($order, $service)->getAttribute('delivered_quantity'));
    }

    public function test_second_line_stock_out_failure_rolls_back_all_prior_effects(): void
    {
        $second = $this->product('Second physical');
        $secondBalance = $this->stock($second);
        $order = $this->order([['product_id' => $this->product->getKey(), 'quantity' => '2', 'unit_price' => '10'], ['product_id' => $second->getKey(), 'quantity' => '2', 'unit_price' => '10']]);
        $a = $this->reserve($order, '2');
        $b = $this->reserve($order, '2', $second);
        $delivery = app(SalesDeliveryService::class)->save($this->creator, $this->payload($order, [['sales_order_item_id' => $a->getAttribute('sales_order_item_id'), 'quantity' => '2', 'sales_stock_reservation_id' => $a->getKey()], ['sales_order_item_id' => $b->getAttribute('sales_order_item_id'), 'quantity' => '2', 'sales_stock_reservation_id' => $b->getKey()]]));
        $real = app(InventoryService::class);
        $calls = 0;
        $mock = \Mockery::mock(InventoryService::class);
        $mock->shouldReceive('stockOut')->twice()->andReturnUsing(function (...$args) use ($real, &$calls): StockMovement {
            if (++$calls === 2) {
                $this->assertDatabaseCount('stock_movements', 1);
                throw ValidationException::withMessages(['quantity' => 'Injected second-line inventory failure.']);
            }

            return $real->stockOut(...$args);
        });
        $this->app->instance(InventoryService::class, $mock);
        $this->invalid(fn () => $this->postDelivery($delivery));
        $this->assertSame(2, $calls);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame('10.0000', $this->balance->fresh()->getAttribute('quantity_on_hand'));
        $this->assertSame('10.0000', $secondBalance->fresh()->getAttribute('quantity_on_hand'));
        $this->assertSame('2.0000', $this->balance->fresh()->getAttribute('quantity_reserved'));
        $this->assertSame('0.0000', $a->fresh()->getAttribute('consumed_quantity'));
        $this->assertSame('0.0000', $b->fresh()->getAttribute('consumed_quantity'));
        $this->assertSame('0.0000', $this->line($order)->getAttribute('delivered_quantity'));
        $this->assertSame('draft', $delivery->fresh()->getAttribute('status'));
        $this->assertNull($delivery->fresh()->getAttribute('idempotency_key'));
        $this->assertSame(0, SalesDocumentEvent::query()->where('document_type', 'delivery')->where('document_id', $delivery->getKey())->where('action', 'post')->count());
        $this->assertDatabaseCount('sales_reservation_events', 2);
    }

    public function test_released_allocation_after_draft_blocks_posting(): void
    {
        $order = $this->order();
        $row = $this->reserve($order);
        $delivery = $this->draft($order, $row);
        app(SalesReservationService::class)->release($this->creator, (int) $order->getKey(), ['reservation_id' => $row->getKey(), 'quantity' => '6', 'reason' => 'Changed dispatch', 'revision' => $order->fresh()->getAttribute('revision'), 'idempotency_key' => (string) Str::uuid()]);
        $this->invalid(fn () => $this->postDelivery($delivery));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_competing_drafts_recheck_capacity_at_posting(): void
    {
        $order = $this->order();
        $row = $this->reserve($order);
        $a = $this->draft($order, $row, '4');
        $b = $this->draft($order, $row, '4');
        $this->postDelivery($a);
        $this->invalid(fn () => $this->postDelivery($b));
        $this->assertSame('4.0000', $this->line($order)->getAttribute('delivered_quantity'));
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_posting_checks_current_physical_stock_and_reserved_balance_reconciliation(): void
    {
        $order = $this->order();
        $delivery = $this->draft($order, $this->reserve($order));
        $this->balance->update(['quantity_on_hand' => '5.0000']);
        $this->invalid(fn () => $this->postDelivery($delivery));
        $this->balance->update(['quantity_on_hand' => '10.0000', 'quantity_reserved' => '5.0000']);
        $this->invalid(fn () => $this->postDelivery($delivery));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_inactive_masters_and_changed_stock_configuration_block_posting(): void
    {
        $order = $this->order();
        $delivery = $this->draft($order, $this->reserve($order));
        foreach ([$this->warehouse, $this->product, $this->customer] as $model) {
            $model->setAttribute('is_active', false)->save();
            $this->invalid(fn () => $this->postDelivery($delivery));
            $model->setAttribute('is_active', true)->save();
        }
        $this->product->update(['track_inventory' => false]);
        $this->invalid(fn () => $this->postDelivery($delivery));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_posting_cannot_convert_a_physical_line_to_non_stock_completion(): void
    {
        $order = $this->order();
        $delivery = $this->draft($order, $this->reserve($order));
        SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->update(['fulfillment_type' => 'completion', 'sales_stock_reservation_id' => null, 'warehouse_id' => null]);
        $this->invalid(fn () => $this->postDelivery($delivery));
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame('6.0000', $this->balance->fresh()->getAttribute('quantity_reserved'));
    }

    public function test_posting_rejects_tampered_customer_and_line_snapshot_sources(): void
    {
        $order = $this->order();
        $delivery = $this->draft($order, $this->reserve($order));
        $delivery->update(['customer_snapshot' => ['name' => 'Tampered']]);
        $this->invalid(fn () => $this->postDelivery($delivery));
        $delivery->update(['customer_snapshot' => $order->getAttribute('customer_snapshot')]);
        SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->update(['product_snapshot' => json_encode(['name' => 'Tampered'])]);
        $this->invalid(fn () => $this->postDelivery($delivery));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_draft_cancel_is_reasoned_and_does_not_release_order_allocations(): void
    {
        $order = $this->order();
        $delivery = $this->draft($order, $this->reserve($order));
        $this->invalid(fn () => app(SalesDeliveryService::class)->cancel($this->creator, (int) $delivery->getKey(), 1, '   '));
        $cancelled = app(SalesDeliveryService::class)->cancel($this->creator, (int) $delivery->getKey(), 1, 'Wrong dispatch draft');
        $this->assertSame('cancelled', $cancelled->getAttribute('status'));
        $this->assertSame('6.0000', $this->balance->fresh()->getAttribute('quantity_reserved'));
        $this->assertSame('10.0000', $this->balance->fresh()->getAttribute('quantity_on_hand'));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_posted_delivery_cannot_be_edited_or_cancelled(): void
    {
        $order = $this->order();
        $row = $this->reserve($order);
        $delivery = $this->postDelivery($this->draft($order, $row, '2'));
        $payload = [...$this->payload($order, [['sales_order_item_id' => $row->getAttribute('sales_order_item_id'), 'quantity' => '1', 'sales_stock_reservation_id' => $row->getKey()]]), 'revision' => $delivery->getAttribute('revision')];
        $this->invalid(fn () => app(SalesDeliveryService::class)->save($this->creator, $payload, (int) $delivery->getKey()));
        $this->invalid(fn () => app(SalesDeliveryService::class)->cancel($this->creator, (int) $delivery->getKey(), (int) $delivery->getAttribute('revision'), 'Reverse'));
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_draft_edit_increments_revision_and_rejects_stale_post(): void
    {
        $order = $this->order();
        $row = $this->reserve($order);
        $delivery = $this->draft($order, $row, '2');
        $updated = app(SalesDeliveryService::class)->save($this->creator, [...$this->payload($order, [['sales_order_item_id' => $row->getAttribute('sales_order_item_id'), 'quantity' => '3', 'sales_stock_reservation_id' => $row->getKey()]]), 'revision' => 1], (int) $delivery->getKey());
        $this->assertSame(2, $updated->getAttribute('revision'));
        $this->invalid(fn () => $this->postDelivery($delivery));
        $this->postDelivery($updated);
        $this->assertSame('3.0000', $this->line($order)->getAttribute('delivered_quantity'));
    }

    public function test_cancelling_order_after_draft_blocks_delivery_and_preserves_history(): void
    {
        $order = $this->order();
        $delivery = $this->draft($order, $this->reserve($order));
        $order = $order->fresh();
        app(SalesOrderService::class)->transition($this->creator, (int) $order->getKey(), 'cancel', (int) $order->getAttribute('revision'), 'Customer cancelled');
        $this->invalid(fn () => $this->postDelivery($delivery));
        $this->assertSame('draft', $delivery->fresh()->getAttribute('status'));
        $this->assertSame('0.0000', $this->balance->fresh()->getAttribute('quantity_reserved'));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_cancellation_after_partial_delivery_releases_only_unused_stock(): void
    {
        $order = $this->order();
        $row = $this->reserve($order);
        $this->postDelivery($this->draft($order, $row, '2'));
        $order = $order->fresh();
        app(SalesOrderService::class)->transition($this->creator, (int) $order->getKey(), 'cancel', (int) $order->getAttribute('revision'), 'Cancel remainder');
        $this->assertSame('2.0000', $row->fresh()->getAttribute('consumed_quantity'));
        $this->assertSame('4.0000', $row->fresh()->getAttribute('released_quantity'));
        $this->assertSame('2.0000', $this->line($order)->getAttribute('delivered_quantity'));
        $this->assertSame('4.0000', $this->line($order)->getAttribute('cancelled_quantity'));
        $this->assertSame('8.0000', $this->balance->fresh()->getAttribute('quantity_on_hand'));
    }

    public function test_draft_rejects_duplicate_lines_and_quantities_over_order_or_allocation(): void
    {
        $order = $this->order();
        $row = $this->reserve($order, '2');
        $input = ['sales_order_item_id' => $row->getAttribute('sales_order_item_id'), 'quantity' => '1', 'sales_stock_reservation_id' => $row->getKey()];
        $this->invalid(fn () => app(SalesDeliveryService::class)->save($this->creator, $this->payload($order, [$input, $input])));
        $this->invalid(fn () => $this->draft($order, $row, '3'));
        $this->invalid(fn () => $this->draft($order, $row, '7'));
        $this->assertDatabaseCount('sales_deliveries', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_wrong_order_reservation_is_not_found_and_source_ids_cannot_be_injected(): void
    {
        $a = $this->order();
        $b = $this->order();
        $rowA = $this->reserve($a, '2');
        $rowB = $this->reserve($b, '2');
        $this->actingAs($this->creator)->post('/erp/sales/deliveries', $this->payload($a, [['sales_order_item_id' => $this->line($a)->getKey(), 'quantity' => '1', 'sales_stock_reservation_id' => $rowB->getKey()]]))->assertNotFound();
        $payload = $this->payload($a, [['sales_order_item_id' => $this->line($a)->getKey(), 'quantity' => '1', 'sales_stock_reservation_id' => $rowA->getKey(), 'product_id' => 999, 'stock_movement_id' => 999, 'unit_cost' => '99']]);
        $delivery = app(SalesDeliveryService::class)->save($this->creator, [...$payload, 'status' => 'posted', 'posted_by' => 999, 'number' => 'FAKE']);
        $item = SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->firstOrFail();
        $this->assertSame('draft', $delivery->getAttribute('status'));
        $this->assertSame((int) $this->product->getKey(), (int) $item->getAttribute('product_id'));
        $this->assertNull($item->getAttribute('stock_movement_id'));
        $this->assertSame('0.0000', $item->getAttribute('unit_cost'));
    }

    public function test_one_delivery_cannot_dispatch_from_multiple_warehouses(): void
    {
        $second = $this->product('Second physical');
        $secondWarehouse = $this->warehouse('DEL-W2');
        $this->stock($second, $secondWarehouse);
        $order = $this->order([['product_id' => $this->product->getKey(), 'quantity' => '2', 'unit_price' => '10'], ['product_id' => $second->getKey(), 'quantity' => '2', 'unit_price' => '10']]);
        $a = $this->reserve($order, '2');
        $b = $this->reserve($order, '2', $second, $secondWarehouse);
        $this->invalid(fn () => app(SalesDeliveryService::class)->save($this->creator, $this->payload($order, [['sales_order_item_id' => $a->getAttribute('sales_order_item_id'), 'quantity' => '2', 'sales_stock_reservation_id' => $a->getKey()], ['sales_order_item_id' => $b->getAttribute('sales_order_item_id'), 'quantity' => '2', 'sales_stock_reservation_id' => $b->getKey()]])));
        $this->assertDatabaseCount('sales_deliveries', 0);
    }

    public function test_draft_order_cannot_be_fulfilled_and_date_cannot_be_future(): void
    {
        $order = $this->order(confirmed: false);
        $this->invalid(fn () => app(SalesDeliveryService::class)->save($this->creator, $this->payload($order, [['sales_order_item_id' => $this->line($order)->getKey(), 'quantity' => '1']])));
        $order = $this->order();
        $row = $this->reserve($order);
        $payload = $this->payload($order, [['sales_order_item_id' => $row->getAttribute('sales_order_item_id'), 'quantity' => '1', 'sales_stock_reservation_id' => $row->getKey()]]);
        $this->invalid(fn () => app(SalesDeliveryService::class)->save($this->creator, [...$payload, 'document_date' => now('Asia/Dhaka')->addDay()->toDateString()]));
        $this->invalid(fn () => app(SalesDeliveryService::class)->save($this->creator, [...$payload, 'document_date' => now('Asia/Dhaka')->subDay()->toDateString()]));
    }

    public function test_exact_variant_is_consumed_and_other_variant_balance_is_unchanged(): void
    {
        $this->product->update(['has_variants' => true]);
        $a = ProductVariant::query()->create(['product_id' => $this->product->getKey(), 'name' => 'A', 'is_active' => true]);
        $b = ProductVariant::query()->create(['product_id' => $this->product->getKey(), 'name' => 'B', 'is_active' => true]);
        $balanceA = $this->stock($this->product, variant: $a);
        $balanceB = $this->stock($this->product, variant: $b);
        $order = $this->order([['product_id' => $this->product->getKey(), 'product_variant_id' => $a->getKey(), 'quantity' => '2', 'unit_price' => '10']]);
        $delivery = $this->postDelivery($this->draft($order, $this->reserve($order, '2'), '2'));
        $this->assertSame('8.0000', $balanceA->fresh()->getAttribute('quantity_on_hand'));
        $this->assertSame('10.0000', $balanceB->fresh()->getAttribute('quantity_on_hand'));
        $this->assertDatabaseHas('stock_movements', ['reference_id' => $delivery->getKey(), 'product_variant_id' => $a->getKey(), 'movement_type' => 'sale']);
    }

    public function test_list_create_edit_show_and_print_pages_resolve_with_preloaded_sources(): void
    {
        $order = $this->order();
        $row = $this->reserve($order);
        $delivery = $this->draft($order, $row, '2');
        $this->actingAs($this->creator)->get('/erp/sales/deliveries')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Sales/Deliveries/Index')->has('deliveries.data', 1));
        $this->get('/erp/sales/deliveries/create?company_id='.$this->company->getKey().'&sales_order_id='.$order->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Sales/Deliveries/Form')->where('sourceOrder.id', $order->getKey())->has('sourceItems.0.reservations', 1));
        $this->get('/erp/sales/deliveries/'.$delivery->getKey().'/edit')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Sales/Deliveries/Form')->has('savedItems', 1)->where('delivery.document_date', now('Asia/Dhaka')->toDateString()));
        $this->get('/erp/sales/deliveries/'.$delivery->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Sales/Deliveries/Show')->where('printMode', false)->missing('items.0.unit_cost')->missing('delivery.idempotency_key'));
        $this->get('/erp/sales/deliveries/'.$delivery->getKey().'/print')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Sales/Deliveries/Show')->where('printMode', true)->has('events', 0));
    }

    public function test_authentication_permissions_and_foreign_company_scope_are_enforced(): void
    {
        $order = $this->order();
        $delivery = $this->draft($order, $this->reserve($order), '2');
        $this->get('/erp/sales/deliveries')->assertRedirect('/login');
        $actor = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $actor->getKey(), 'employee_number' => 'DEL-STAFF', 'first_name' => 'Delivery', 'last_name' => 'Staff']);
        $this->actingAs($actor)->get('/erp/sales/deliveries')->assertForbidden();
        $actor->givePermissionTo('erp.sales-deliveries.view');
        $this->get('/erp/sales/deliveries/'.$delivery->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->where('orderUrl', null));
        $this->post('/erp/sales/deliveries/'.$delivery->getKey().'/post', ['revision' => 1, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $foreign = Company::query()->create(['name' => 'Foreign', 'code' => 'DEL-B', 'status' => 'active', 'currency_code' => 'BDT']);
        $this->get('/erp/sales/deliveries?company_id='.$foreign->getKey())->assertForbidden();
        Employee::query()->where('user_id', $actor->getKey())->update(['company_id' => $foreign->getKey()]);
        $this->get('/erp/sales/deliveries/'.$delivery->getKey())->assertNotFound();
        $this->get('/erp/sales/deliveries?sales_order_id='.$order->getKey())->assertNotFound();
    }

    public function test_draft_edit_cannot_change_its_source_order(): void
    {
        $a = $this->order();
        $b = $this->order();
        $rowA = $this->reserve($a, '2');
        $rowB = $this->reserve($b, '2');
        $delivery = $this->draft($a, $rowA, '1');
        $this->invalid(fn () => app(SalesDeliveryService::class)->save($this->creator, [...$this->payload($b, [['sales_order_item_id' => $this->line($b)->getKey(), 'quantity' => '1', 'sales_stock_reservation_id' => $rowB->getKey()]]), 'revision' => 1], (int) $delivery->getKey()));
        $this->assertSame((int) $a->getKey(), (int) $delivery->fresh()->getAttribute('sales_order_id'));
    }

    public function test_fractional_dispatch_preserves_four_decimal_quantity(): void
    {
        $order = $this->order();
        $row = $this->reserve($order, '0.1234');
        $this->postDelivery($this->draft($order, $row, '0.0001'));
        $this->assertSame('9.9999', $this->balance->fresh()->getAttribute('quantity_on_hand'));
        $this->assertSame('0.1233', $this->balance->fresh()->getAttribute('quantity_reserved'));
        $this->assertSame('0.0001', $this->line($order)->getAttribute('delivered_quantity'));
    }

    public function test_separate_warehouse_deliveries_can_fulfill_the_same_order_item(): void
    {
        $order = $this->order();
        $second = $this->warehouse('DEL-W2');
        $secondBalance = $this->stock($this->product, $second);
        $a = $this->reserve($order, '2');
        $b = $this->reserve($order, '4', warehouse: $second);
        $this->postDelivery($this->draft($order, $a, '2'));
        $this->postDelivery($this->draft($order, $b, '4'));
        $this->assertSame('delivered', $order->fresh()->getAttribute('status'));
        $this->assertSame('8.0000', $this->balance->fresh()->getAttribute('quantity_on_hand'));
        $this->assertSame('6.0000', $secondBalance->fresh()->getAttribute('quantity_on_hand'));
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_completion_cannot_borrow_a_stock_reservation(): void
    {
        $service = $this->product('Consulting', 'service', false);
        $order = $this->order([['product_id' => $this->product->getKey(), 'quantity' => '2', 'unit_price' => '10'], ['product_id' => $service->getKey(), 'quantity' => '1', 'unit_price' => '10']]);
        $row = $this->reserve($order, '2');
        $this->invalid(fn () => app(SalesDeliveryService::class)->save($this->creator, $this->payload($order, [['sales_order_item_id' => $this->line($order, $service)->getKey(), 'quantity' => '1', 'sales_stock_reservation_id' => $row->getKey()]])));
        $this->assertDatabaseCount('sales_deliveries', 0);
    }

    public function test_inactive_unit_blocks_fulfillment_and_changed_unit_requires_review(): void
    {
        $order = $this->order();
        $delivery = $this->draft($order, $this->reserve($order));
        $this->unit->update(['is_active' => false]);
        $this->invalid(fn () => $this->postDelivery($delivery));
        $this->unit->update(['is_active' => true]);
        $unit = Unit::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Box', 'short_name' => 'box', 'is_active' => true]);
        $this->product->update(['unit_id' => $unit->getKey()]);
        $this->invalid(fn () => $this->postDelivery($delivery));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_print_uses_saved_names_and_does_not_expose_costs_or_operation_keys(): void
    {
        $order = $this->order();
        $delivery = $this->postDelivery($this->draft($order, $this->reserve($order), '2'));
        $this->customer->update(['name' => 'Renamed customer']);
        $this->product->update(['name' => 'Renamed product']);
        $this->warehouse->update(['name' => 'Renamed warehouse']);
        $this->actingAs($this->creator)->get('/erp/sales/deliveries/'.$delivery->getKey().'/print')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('delivery.customer_snapshot.name', 'Delivery customer')->where('items.0.product_snapshot.name', 'Physical')->where('delivery.terms_snapshot.delivery_warehouse.name', 'DEL-W1')
            ->missing('items.0.unit_cost')->missing('items.0.total_cost')->missing('items.0.stock_movement_id')->missing('delivery.payload_hash')->missing('delivery.idempotency_key')->missing('delivery.terms_snapshot.sales_order_source'));
    }

    public function test_post_retry_does_not_accept_missing_movement_links(): void
    {
        $order = $this->order();
        $key = (string) Str::uuid();
        $delivery = $this->postDelivery($this->draft($order, $this->reserve($order)), $key);
        SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->update(['stock_movement_id' => null]);
        $this->invalid(fn () => $this->postDelivery($delivery, $key));
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame('4.0000', $this->balance->fresh()->getAttribute('quantity_on_hand'));
    }

    public function test_invalid_dispatch_quantities_do_not_allocate_document_numbers(): void
    {
        $order = $this->order();
        $row = $this->reserve($order);
        foreach (['0', '-1', '1e1', '0.00001', '10000.0001'] as $quantity) {
            $this->invalid(fn () => $this->draft($order, $row, $quantity));
        }
        $this->assertDatabaseCount('sales_deliveries', 0);
        $this->assertDatabaseMissing('sales_document_sequences', ['company_id' => $this->company->getKey(), 'document_type' => 'delivery']);
    }

    public function test_delivery_date_cannot_change_after_number_allocation(): void
    {
        $order = $this->order();
        $row = $this->reserve($order);
        $delivery = $this->draft($order, $row, '2');
        $date = $delivery->getAttribute('document_date')->format('Y-m-d');
        $this->travel(1)->days();
        $payload = [...$this->payload($order, [['sales_order_item_id' => $row->getAttribute('sales_order_item_id'), 'quantity' => '2', 'sales_stock_reservation_id' => $row->getKey()]]), 'revision' => 1];
        $this->invalid(fn () => app(SalesDeliveryService::class)->save($this->creator, $payload, (int) $delivery->getKey()));
        $this->assertSame($date, $delivery->fresh()->getAttribute('document_date')->format('Y-m-d'));
    }

    public function test_http_create_update_post_workflow_redirects_to_readable_document(): void
    {
        $order = $this->order();
        $row = $this->reserve($order, '2');
        $payload = $this->payload($order, [['sales_order_item_id' => $row->getAttribute('sales_order_item_id'), 'quantity' => '2', 'sales_stock_reservation_id' => $row->getKey()]]);
        $this->actingAs($this->creator)->post('/erp/sales/deliveries', $payload)->assertRedirect();
        $delivery = SalesDelivery::query()->firstOrFail();
        $payload['items'][0]['quantity'] = '1';
        $this->put('/erp/sales/deliveries/'.$delivery->getKey(), [...$payload, 'revision' => 1])->assertRedirect('/erp/sales/deliveries/'.$delivery->getKey());
        $this->post('/erp/sales/deliveries/'.$delivery->getKey().'/post', ['revision' => 2, 'idempotency_key' => (string) Str::uuid()])->assertRedirect('/erp/sales/deliveries/'.$delivery->getKey());
        $this->assertSame('posted', $delivery->fresh()->getAttribute('status'));
        $this->assertSame('1.0000', $this->line($order)->getAttribute('delivered_quantity'));
        $this->assertDatabaseCount('stock_movements', 1);
    }
}
