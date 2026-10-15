<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\CustomerReceiptAllocation;
use App\Models\Employee;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesCreditNote;
use App\Models\SalesDelivery;
use App\Models\SalesDeliveryItem;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\SalesStockReservation;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ERP\Sales\CustomerReceiptService;
use App\Services\ERP\Sales\SalesDeliveryService;
use App\Services\ERP\Sales\SalesInvoiceBalanceService;
use App\Services\ERP\Sales\SalesInvoiceService;
use App\Services\ERP\Sales\SalesOrderService;
use App\Services\ERP\Sales\SalesQuotationService;
use App\Services\ERP\Sales\SalesReservationService;
use App\Services\ERP\Sales\SalesReturnService;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class SalesReturnManagementTest extends TestCase
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
        $this->company = Company::query()->create(['name' => 'Delivery company', 'code' => 'DEL-A', 'timezone' => 'Asia/Dhaka', 'status' => 'active', 'currency_code' => 'BDT']);
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

    private function fulfillment(SalesOrder $order, string $quantity = '6'): SalesDelivery
    {
        return $this->postDelivery($this->draft($order, $this->reserve($order, $quantity), $quantity));
    }

    /** @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function invoicePayload(SalesOrder $order, array $items): array
    {
        return ['company_id' => $this->company->getKey(), 'sales_order_id' => $order->getKey(), 'document_date' => now('Asia/Dhaka')->toDateString(), 'due_date' => now('Asia/Dhaka')->addDays(14)->toDateString(), 'notes' => 'Invoice terms', 'items' => $items];
    }

    private function bill(SalesOrder $order, SalesDelivery $delivery, string $quantity = '6'): SalesInvoice
    {
        $source = SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->firstOrFail();

        return app(SalesInvoiceService::class)->save($this->creator, $this->invoicePayload($order, [['sales_delivery_item_id' => $source->getKey(), 'quantity' => $quantity]]));
    }

    private function issueInvoice(SalesInvoice $invoice, ?string $key = null): SalesInvoice
    {
        return app(SalesInvoiceService::class)->issue($this->creator, (int) $invoice->getKey(), (int) $invoice->getAttribute('revision'), $key ?? (string) Str::uuid());
    }

    private function issuedInvoice(): SalesInvoice
    {
        $order = $this->order();

        return $this->issueInvoice($this->bill($order, $this->fulfillment($order)));
    }

    /** Fixture for the future 5.8 receipt writer; no payment route is implemented here. */
    private function allocation(SalesInvoice $invoice, string $amount): CustomerReceiptAllocation
    {
        $receipt = CustomerReceipt::query()->create(['company_id' => $this->company->getKey(), 'customer_id' => $invoice->getAttribute('customer_id'), 'currency_code' => $invoice->getAttribute('currency_code'), 'number' => 'CR-FIXTURE-'.Str::uuid(), 'document_date' => now('Asia/Dhaka')->toDateString(), 'status' => 'posted', 'posted_at' => now()->toDateTimeString(), 'amount' => $amount, 'payment_method' => 'cash']);

        return CustomerReceiptAllocation::query()->create(['company_id' => $this->company->getKey(), 'customer_receipt_id' => $receipt->getKey(), 'sales_invoice_id' => $invoice->getKey(), 'amount' => $amount, 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'posted_at' => now()->toDateTimeString()]);
    }

    private function credit(SalesInvoice $invoice, string $amount): SalesCreditNote
    {
        return SalesCreditNote::query()->create(['company_id' => $this->company->getKey(), 'customer_id' => $invoice->getAttribute('customer_id'), 'currency_code' => $invoice->getAttribute('currency_code'), 'sales_invoice_id' => $invoice->getKey(), 'number' => 'SCN-FIXTURE-'.Str::uuid(), 'document_date' => now('Asia/Dhaka')->toDateString(), 'status' => 'issued', 'issued_at' => now()->toDateTimeString(), 'grand_total' => $amount, 'reason' => 'Fixture credit']);
    }

    private function shipped(): SalesDelivery
    {
        return $this->fulfillment($this->order());
    }

    /** @return array<string, mixed> */
    private function returnPayload(SalesDelivery $delivery, string $quantity = '2'): array
    {
        $di = SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->firstOrFail();

        return ['company_id' => $this->company->getKey(), 'sales_delivery_id' => $delivery->getKey(), 'document_date' => now('Asia/Dhaka')->toDateString(), 'reason' => 'Customer returned goods', 'items' => [['sales_delivery_item_id' => $di->getKey(), 'quantity' => $quantity]]];
    }

    private function returnDraft(SalesDelivery $delivery, string $quantity = '2'): SalesReturn
    {
        return app(SalesReturnService::class)->save($this->creator, $this->returnPayload($delivery, $quantity));
    }

    private function inspectReturn(SalesReturn $row, string $accepted = '2', string $restock = '2'): SalesReturn
    {
        $item = SalesReturnItem::query()->where('sales_return_id', $row->getKey())->firstOrFail();

        return app(SalesReturnService::class)->inspect($this->creator, (int) $row->getKey(), ['revision' => $row->fresh()->getAttribute('revision'), 'reason' => 'Checked condition', 'items' => [['id' => $item->getKey(), 'accepted_quantity' => $accepted, 'restocked_quantity' => $restock]]]);
    }

    private function postCustomerReturn(SalesReturn $row, ?string $key = null): SalesReturn
    {
        return app(SalesReturnService::class)->postReturn($this->creator, (int) $row->getKey(), (int) $row->getAttribute('revision'), $key ?? (string) Str::uuid());
    }

    private function returnLine(SalesReturn $row): SalesReturnItem
    {
        return SalesReturnItem::query()->where('sales_return_id', $row->getKey())->firstOrFail();
    }

    public function test_draft_has_no_inventory_or_return_counter_effect(): void
    {
        $delivery = $this->shipped();
        $row = $this->returnDraft($delivery);
        $this->assertSame('draft', $row->getAttribute('status'));
        $this->assertStringStartsWith('SR-', (string) $row->getAttribute('number'));
        $this->assertSame('4.0000', $this->balance->fresh()->getAttribute('quantity_on_hand'));
        $this->assertSame('0.0000', SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->firstOrFail()->getAttribute('returned_quantity'));
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_inspection_does_not_move_stock(): void
    {
        $row = $this->inspectReturn($this->returnDraft($this->shipped()), '2', '1');
        $this->assertSame('inspected', $row->getAttribute('status'));
        $this->assertNotNull($row->getAttribute('inspected_at'));
        $this->assertSame('mixed', $this->returnLine($row)->getAttribute('disposition'));
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_post_restock_uses_original_shipping_cost_and_warehouse(): void
    {
        $delivery = $this->shipped();
        // Current stock cost changed after shipment; a return must retain the dispatch cost 5.
        $this->balance->fresh()->forceFill(['average_cost' => '9.0000'])->save();
        $row = $this->postCustomerReturn($this->inspectReturn($this->returnDraft($delivery)));
        $item = $this->returnLine($row);
        $movement = StockMovement::query()->findOrFail($item->getAttribute('stock_movement_id'));
        $this->assertSame('5.0000', $movement->getAttribute('unit_cost'));
        $this->assertSame('2.0000', $movement->getAttribute('quantity'));
        $this->assertSame($this->warehouse->getKey(), $movement->getAttribute('warehouse_id'));
        $this->assertSame(StockMovement::TYPE_SALE_RETURN, $movement->getAttribute('movement_type'));
        $this->assertSame(SalesReturn::class, $movement->getAttribute('reference_type'));
        $this->assertSame('6.0000', $this->balance->fresh()->getAttribute('quantity_on_hand'));
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_damaged_goods_are_accepted_without_saleable_stock(): void
    {
        $row = $this->postCustomerReturn($this->inspectReturn($this->returnDraft($this->shipped()), '2', '0'));
        $this->assertSame('damaged', $this->returnLine($row)->getAttribute('disposition'));
        $this->assertNull($this->returnLine($row)->getAttribute('stock_movement_id'));
        $this->assertSame('4.0000', $this->balance->fresh()->getAttribute('quantity_on_hand'));
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_mixed_condition_restock_only_good_quantity(): void
    {
        $row = $this->postCustomerReturn($this->inspectReturn($this->returnDraft($this->shipped()), '2', '1'));
        $this->assertSame('1.0000', $this->returnLine($row)->getAttribute('restocked_quantity'));
        $this->assertSame('5.0000', $this->balance->fresh()->getAttribute('quantity_on_hand'));
    }

    public function test_rejected_return_does_not_consume_returnable_quantity(): void
    {
        $delivery = $this->shipped();
        $row = $this->postCustomerReturn($this->inspectReturn($this->returnDraft($delivery), '0', '0'));
        $this->assertSame('rejected', $this->returnLine($row)->getAttribute('disposition'));
        $this->assertSame('0.0000', app(SalesReturnService::class)->sourceProgress($this->creator, (int) $delivery->getKey())[(int) $this->returnLine($row)->getAttribute('sales_delivery_item_id')]['accepted']);
        $this->returnDraft($delivery, '6');
    }

    public function test_post_before_inspection_is_rejected(): void
    {
        $this->invalid(fn () => $this->postCustomerReturn($this->returnDraft($this->shipped())));
    }

    public function test_inspection_cannot_accept_more_than_requested(): void
    {
        $this->invalid(fn () => $this->inspectReturn($this->returnDraft($this->shipped()), '3', '2'));
    }

    public function test_inspection_cannot_restock_more_than_accepted(): void
    {
        $this->invalid(fn () => $this->inspectReturn($this->returnDraft($this->shipped()), '1', '2'));
    }

    public function test_request_cannot_exceed_delivered_quantity(): void
    {
        $delivery = $this->shipped();
        $this->invalid(fn () => $this->returnDraft($delivery, '6.0001'));
    }

    public function test_multiple_accepted_returns_are_capped_cumulatively(): void
    {
        $delivery = $this->shipped();
        $a = $this->inspectReturn($this->returnDraft($delivery, '4'), '4', '4');
        $b = $this->inspectReturn($this->returnDraft($delivery, '4'), '4', '4');
        $this->postCustomerReturn($a);
        $this->invalid(fn () => $this->postCustomerReturn($b));
        $this->assertSame('inspected', $b->fresh()->getAttribute('status'));
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_two_partial_returns_can_exhaust_delivery(): void
    {
        $delivery = $this->shipped();
        foreach (['2', '4'] as $qty) {
            $this->postCustomerReturn($this->inspectReturn($this->returnDraft($delivery, $qty), $qty, $qty));
        }
        $this->assertSame('10.0000', $this->balance->fresh()->getAttribute('quantity_on_hand'));
        $this->invalid(fn () => $this->returnDraft($delivery, '0.0001'));
    }

    public function test_post_retry_is_exactly_once(): void
    {
        $row = $this->inspectReturn($this->returnDraft($this->shipped()));
        $key = (string) Str::uuid();
        $this->postCustomerReturn($row, $key);
        $this->postCustomerReturn($row, $key);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertSame('6.0000', $this->balance->fresh()->getAttribute('quantity_on_hand'));
    }

    public function test_post_retry_with_different_key_fails(): void
    {
        $row = $this->postCustomerReturn($this->inspectReturn($this->returnDraft($this->shipped())));
        $this->invalid(fn () => $this->postCustomerReturn($row));
    }

    public function test_stale_inspection_revision_fails(): void
    {
        $row = $this->returnDraft($this->shipped());
        $old = (int) $row->getAttribute('revision');
        $row = $this->inspectReturn($row);
        $item = $this->returnLine($row);
        $this->invalid(fn () => app(SalesReturnService::class)->inspect($this->creator, (int) $row->getKey(), ['revision' => $old, 'reason' => 'Stale', 'items' => [['id' => $item->getKey(), 'accepted_quantity' => '1', 'restocked_quantity' => '1']]]));
    }

    public function test_inspected_document_can_be_reinspected_before_post(): void
    {
        $row = $this->inspectReturn($this->returnDraft($this->shipped()), '2', '2');
        $row = $this->inspectReturn($row, '1', '0');
        $this->postCustomerReturn($row);
        $this->assertSame('1.0000', $this->returnLine($row)->getAttribute('accepted_quantity'));
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_posted_return_cannot_be_reinspected_or_cancelled(): void
    {
        $row = $this->postCustomerReturn($this->inspectReturn($this->returnDraft($this->shipped())));
        $this->invalid(fn () => $this->inspectReturn($row));
        $this->invalid(fn () => app(SalesReturnService::class)->cancel($this->creator, (int) $row->getKey(), (int) $row->getAttribute('revision'), 'Wrong return'));
    }

    public function test_cancelled_return_has_no_stock_effect(): void
    {
        $row = $this->inspectReturn($this->returnDraft($this->shipped()));
        app(SalesReturnService::class)->cancel($this->creator, (int) $row->getKey(), (int) $row->getAttribute('revision'), 'Duplicate');
        $this->assertSame('cancelled', $row->fresh()->getAttribute('status'));
        $this->assertDatabaseCount('stock_movements', 1);
        $this->invalid(fn () => $this->postCustomerReturn($row->fresh()));
    }

    public function test_unbilled_return_reduces_billable_fulfillment(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $row = $this->postCustomerReturn($this->inspectReturn($this->returnDraft($delivery)));
        $this->assertSame('2.0000', $this->returnLine($row)->getAttribute('unbilled_quantity'));
        $this->assertSame('0.0000', $this->returnLine($row)->getAttribute('credit_review_quantity'));
        $this->invalid(fn () => $this->bill($order, $delivery, '5'));
        $this->issueInvoice($this->bill($order, $delivery, '4'));
        $this->assertSame('6.0000', $this->line($order)->getAttribute('delivered_quantity'));
        $this->assertSame('4.0000', $this->line($order)->getAttribute('invoiced_quantity'));
    }

    public function test_billed_return_flags_credit_review_without_rewriting_invoice(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $invoice = $this->issueInvoice($this->bill($order, $delivery));
        $row = $this->postCustomerReturn($this->inspectReturn($this->returnDraft($delivery)));
        $this->assertSame('0.0000', $this->returnLine($row)->getAttribute('unbilled_quantity'));
        $this->assertSame('2.0000', $this->returnLine($row)->getAttribute('credit_review_quantity'));
        $this->assertSame('60.00', app(SalesInvoiceBalanceService::class)->snapshot($invoice)['outstanding']);
        $this->assertSame('6.0000', $this->line($order)->getAttribute('invoiced_quantity'));
        $this->assertDatabaseCount('sales_credit_notes', 0);
    }

    public function test_return_split_prefers_unbilled_goods_then_credit_review(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $this->issueInvoice($this->bill($order, $delivery, '4'));
        $row = $this->postCustomerReturn($this->inspectReturn($this->returnDraft($delivery, '3'), '3', '3'));
        $this->assertSame('2.0000', $this->returnLine($row)->getAttribute('unbilled_quantity'));
        $this->assertSame('1.0000', $this->returnLine($row)->getAttribute('credit_review_quantity'));
    }

    public function test_billed_returns_do_not_double_reduce_remaining_unbilled_goods(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $this->issueInvoice($this->bill($order, $delivery, '4'));
        $this->postCustomerReturn($this->inspectReturn($this->returnDraft($delivery, '3'), '3', '3'));
        $this->invalid(fn () => $this->bill($order, $delivery, '0.0001'));
        $this->assertSame('4.0000', $this->line($order)->getAttribute('invoiced_quantity'));
    }

    public function test_post_failure_rolls_back_stock_and_counters(): void
    {
        $delivery = $this->shipped();
        $row = $this->inspectReturn($this->returnDraft($delivery));
        $event = 'eloquent.updating: '.SalesReturn::class;
        Event::listen($event, static function (SalesReturn $document): void {
            if ($document->getAttribute('status') === 'posted') {
                throw ValidationException::withMessages(['return' => 'Simulated write failure']);
            }
        });
        try {
            $this->invalid(fn () => $this->postCustomerReturn($row));
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame('4.0000', $this->balance->fresh()->getAttribute('quantity_on_hand'));
        $this->assertNull($this->returnLine($row)->getAttribute('stock_movement_id'));
        $this->assertSame('inspected', $row->fresh()->getAttribute('status'));
    }

    public function test_inactive_original_warehouse_blocks_restock(): void
    {
        $row = $this->inspectReturn($this->returnDraft($this->shipped()));
        $this->warehouse->forceFill(['is_active' => false])->save();
        $this->actingAs($this->creator)->post('/erp/sales/returns/'.$row->getKey().'/post', ['revision' => $row->getAttribute('revision'), 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_damaged_only_return_does_not_require_active_restock_warehouse(): void
    {
        $row = $this->inspectReturn($this->returnDraft($this->shipped()), '2', '0');
        $this->warehouse->forceFill(['is_active' => false])->save();
        $this->postCustomerReturn($row);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_source_cost_tampering_blocks_post(): void
    {
        $row = $this->inspectReturn($this->returnDraft($this->shipped()));
        $this->returnLine($row)->forceFill(['unit_cost' => '1'])->save();
        $this->invalid(fn () => $this->postCustomerReturn($row));
    }

    public function test_invalid_quantities_and_blank_reason_fail(): void
    {
        $delivery = $this->shipped();
        foreach (['0', '-1', '1.00001', '1e2'] as $qty) {
            $this->invalid(fn () => $this->returnDraft($delivery, $qty));
        }
        $this->invalid(fn () => app(SalesReturnService::class)->save($this->creator, [...$this->returnPayload($delivery), 'reason' => ' ']));
    }

    public function test_future_return_date_fails(): void
    {
        $delivery = $this->shipped();
        $this->invalid(fn () => app(SalesReturnService::class)->save($this->creator, [...$this->returnPayload($delivery), 'document_date' => now('Asia/Dhaka')->addDay()->toDateString()]));
    }

    public function test_workspace_requires_login_and_permissions(): void
    {
        $this->get('/erp/sales/returns')->assertRedirect('/login');
        $actor = User::factory()->create();
        $this->actingAs($actor)->get('/erp/sales/returns')->assertForbidden();
        $this->actingAs($actor)->post('/erp/sales/returns', [])->assertForbidden();
    }

    public function test_pages_resolve_and_internal_keys_are_hidden(): void
    {
        $delivery = $this->shipped();
        $row = $this->returnDraft($delivery);
        foreach (['/erp/sales/returns' => 'Index', '/erp/sales/returns/create?company_id='.$this->company->getKey().'&sales_delivery_id='.$delivery->getKey() => 'Form', '/erp/sales/returns/'.$row->getKey().'/edit' => 'Form', '/erp/sales/returns/'.$row->getKey() => 'Show', '/erp/sales/returns/'.$row->getKey().'/print' => 'Show'] as $url => $page) {
            $this->actingAs($this->creator)->get($url)->assertOk()->assertInertia(fn (Assert $view) => $view->component('ERP/Sales/Returns/'.$page)->missing('returnDocument.idempotency_key')->missing('returnDocument.payload_hash')->missing('returnDocument.customer_snapshot.nid_number'));
        }
    }

    public function test_cross_company_delivery_cannot_be_selected(): void
    {
        $delivery = $this->shipped();
        $other = Company::query()->create(['name' => 'Other', 'code' => 'RET-B', 'status' => 'active', 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka']);
        $this->actingAs($this->creator)->post('/erp/sales/returns', [...$this->returnPayload($delivery), 'company_id' => $other->getKey()])->assertNotFound();
        $this->assertDatabaseCount('sales_returns', 0);
    }

    public function test_unreconciled_return_counter_blocks_invoice_writer(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->update(['returned_quantity' => '1']);
        $this->invalid(fn () => $this->bill($order, $delivery, '1'));
    }

    public function test_inactive_company_blocks_new_return_writes(): void
    {
        $delivery = $this->shipped();
        $this->company->forceFill(['status' => 'inactive'])->save();
        $this->invalid(fn () => $this->returnDraft($delivery));
    }

    public function test_other_delivery_line_is_not_a_return_source(): void
    {
        $order = $this->order();
        $reservation = $this->reserve($order);
        $a = $this->postDelivery($this->draft($order, $reservation, '3'));
        $b = $this->postDelivery($this->draft($order, $reservation, '3'));
        $foreign = SalesDeliveryItem::query()->where('sales_delivery_id', $b->getKey())->firstOrFail();
        $this->actingAs($this->creator)->post('/erp/sales/returns', [...$this->returnPayload($a), 'items' => [['sales_delivery_item_id' => $foreign->getKey(), 'quantity' => '1']]])->assertNotFound();
    }

    public function test_exact_original_variant_is_restocked(): void
    {
        $this->product->forceFill(['has_variants' => true])->save();
        $variant = ProductVariant::query()->create(['product_id' => $this->product->getKey(), 'name' => 'Blue', 'sku' => 'RETURN-BLUE', 'is_active' => true]);
        $stock = $this->stock($this->product, $this->warehouse, $variant);
        $order = $this->order([['product_id' => $this->product->getKey(), 'product_variant_id' => $variant->getKey(), 'quantity' => '6', 'unit_price' => '10', 'discount_percent' => '0', 'tax_percent' => '0']]);
        $delivery = $this->fulfillment($order);
        $row = $this->postCustomerReturn($this->inspectReturn($this->returnDraft($delivery)));
        $movement = StockMovement::query()->findOrFail($this->returnLine($row)->getAttribute('stock_movement_id'));
        $this->assertSame($variant->getKey(), $movement->getAttribute('product_variant_id'));
        $this->assertSame('6.0000', $stock->fresh()->getAttribute('quantity_on_hand'));
        $this->assertSame('10.0000', $this->balance->fresh()->getAttribute('quantity_on_hand'));
    }

    public function test_cancel_requires_reason_and_rolls_back_status(): void
    {
        $row = $this->returnDraft($this->shipped());
        $this->invalid(fn () => app(SalesReturnService::class)->cancel($this->creator, (int) $row->getKey(), 1, ' '));
        $this->assertSame('draft', $row->fresh()->getAttribute('status'));
    }

    public function test_edit_preserves_number_and_date(): void
    {
        $delivery = $this->shipped();
        $row = $this->returnDraft($delivery);
        $updated = app(SalesReturnService::class)->save($this->creator, [...$this->returnPayload($delivery, '1'), 'revision' => 1], (int) $row->getKey());
        $this->assertSame($row->getAttribute('number'), $updated->getAttribute('number'));
        $this->assertSame('1.0000', $this->returnLine($row)->getAttribute('quantity'));
        $this->invalid(fn () => app(SalesReturnService::class)->save($this->creator, [...$this->returnPayload($delivery), 'document_date' => now('Asia/Dhaka')->subDay()->toDateString(), 'revision' => 2], (int) $row->getKey()));
    }

    public function test_return_after_full_payment_preserves_receipt_history(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $invoice = $this->issueInvoice($this->bill($order, $delivery));
        $receiptService = app(CustomerReceiptService::class);
        $receipt = $receiptService->save($this->creator, ['company_id' => $this->company->getKey(), 'customer_id' => $this->customer->getKey(), 'document_date' => now('Asia/Dhaka')->toDateString(), 'amount' => '60', 'payment_method' => 'cash']);
        $receiptService->postReceipt($this->creator, (int) $receipt->getKey(), ['revision' => 1, 'idempotency_key' => (string) Str::uuid(), 'allocations' => [['sales_invoice_id' => $invoice->getKey(), 'amount' => '60']]]);
        $row = $this->postCustomerReturn($this->inspectReturn($this->returnDraft($delivery)));
        $this->assertSame('2.0000', $this->returnLine($row)->getAttribute('credit_review_quantity'));
        $this->assertSame('0.00', app(SalesInvoiceBalanceService::class)->snapshot($invoice)['outstanding']);
        $this->assertDatabaseCount('customer_receipts', 1);
        $this->assertDatabaseCount('customer_receipt_allocations', 1);
        $this->assertDatabaseCount('sales_credit_notes', 0);
    }

    public function test_http_inspect_and_post_workflow(): void
    {
        $row = $this->returnDraft($this->shipped());
        $item = $this->returnLine($row);
        $this->actingAs($this->creator)->post('/erp/sales/returns/'.$row->getKey().'/inspect', ['revision' => 1, 'reason' => 'Good condition', 'items' => [['id' => $item->getKey(), 'accepted_quantity' => '2', 'restocked_quantity' => '2']]])->assertRedirect('/erp/sales/returns/'.$row->getKey());
        $this->actingAs($this->creator)->post('/erp/sales/returns/'.$row->getKey().'/post', ['revision' => $row->fresh()->getAttribute('revision'), 'idempotency_key' => (string) Str::uuid()])->assertRedirect('/erp/sales/returns/'.$row->getKey());
        $this->assertSame('posted', $row->fresh()->getAttribute('status'));
    }

    public function test_new_return_invalidates_an_existing_invoice_draft(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $invoice = $this->bill($order, $delivery);
        $this->postCustomerReturn($this->inspectReturn($this->returnDraft($delivery)));
        $this->invalid(fn () => $this->issueInvoice($invoice));
        $this->assertSame('draft', $invoice->fresh()->getAttribute('status'));
    }

    public function test_ordinary_viewer_is_company_scoped_and_cannot_inspect_or_post(): void
    {
        $row = $this->returnDraft($this->shipped());
        $actor = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $actor->getKey(), 'employee_number' => 'RETURN-VIEW', 'first_name' => 'Return', 'last_name' => 'Viewer']);
        $actor->givePermissionTo('erp.sales-returns.view');
        $this->actingAs($actor)->get('/erp/sales/returns/'.$row->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->where('deliveryUrl', null));
        $this->post('/erp/sales/returns/'.$row->getKey().'/inspect', [])->assertForbidden();
        $this->post('/erp/sales/returns/'.$row->getKey().'/post', [])->assertForbidden();
        $foreign = Company::query()->create(['name' => 'Foreign', 'code' => 'RET-C', 'status' => 'active', 'currency_code' => 'BDT']);
        $this->get('/erp/sales/returns?company_id='.$foreign->getKey())->assertForbidden();
        Employee::query()->where('user_id', $actor->getKey())->update(['company_id' => $foreign->getKey()]);
        $this->get('/erp/sales/returns/'.$row->getKey())->assertNotFound();
    }

    public function test_nonstock_completion_cannot_be_physically_returned(): void
    {
        $serviceProduct = $this->product('Service', 'service', false);
        $order = $this->order([['product_id' => $serviceProduct->getKey(), 'quantity' => '2', 'unit_price' => '10']]);
        $delivery = app(SalesDeliveryService::class)->save($this->creator, $this->payload($order, [['sales_order_item_id' => $this->line($order, $serviceProduct)->getKey(), 'quantity' => '2']]));
        $delivery = $this->postDelivery($delivery);
        $this->invalid(fn () => $this->returnDraft($delivery, '1'));
    }
}
