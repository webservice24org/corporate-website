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
use App\Models\SalesDocumentEvent;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesReturnItem;
use App\Models\SalesStockReservation;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
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

final class SalesInvoiceManagementTest extends TestCase
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

    private function acceptedReturn(SalesDelivery $delivery, string $quantity): void
    {
        $source = SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->firstOrFail();
        $service = app(SalesReturnService::class);
        $row = $service->save($this->creator, ['company_id' => $this->company->getKey(), 'sales_delivery_id' => $delivery->getKey(), 'document_date' => now('Asia/Dhaka')->toDateString(), 'reason' => 'Customer returned goods', 'items' => [['sales_delivery_item_id' => $source->getKey(), 'quantity' => $quantity]]]);
        $item = SalesReturnItem::query()->where('sales_return_id', $row->getKey())->firstOrFail();
        $row = $service->inspect($this->creator, (int) $row->getKey(), ['revision' => $row->getAttribute('revision'), 'reason' => 'Accepted in good condition', 'items' => [['id' => $item->getKey(), 'accepted_quantity' => $quantity, 'restocked_quantity' => $quantity]]]);
        $service->postReturn($this->creator, (int) $row->getKey(), (int) $row->getAttribute('revision'), (string) Str::uuid());
    }

    public function test_draft_copies_frozen_terms_and_does_not_change_billing_or_stock(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $invoice = $this->bill($order, $delivery, '2');
        $this->assertStringStartsWith('SI-', (string) $invoice->getAttribute('number'));
        $this->assertSame('20.00', $invoice->getAttribute('grand_total'));
        $this->assertSame('draft', $invoice->getAttribute('status'));
        $this->assertNull($invoice->getAttribute('issued_at'));
        $this->assertSame('0.0000', $this->line($order)->getAttribute('invoiced_quantity'));
        $this->assertSame('0.0000', SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->firstOrFail()->getAttribute('invoiced_quantity'));
        $this->assertSame('4.0000', $this->balance->fresh()->getAttribute('quantity_on_hand'));
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_issue_records_obligation_and_both_quantity_counters_without_stock_effect(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $invoice = $this->issueInvoice($this->bill($order, $delivery, '2'));
        $this->assertSame('issued', $invoice->getAttribute('status'));
        $this->assertSame('2.0000', $this->line($order)->getAttribute('invoiced_quantity'));
        $this->assertSame('2.0000', SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->firstOrFail()->getAttribute('invoiced_quantity'));
        $balance = app(SalesInvoiceBalanceService::class)->snapshot($invoice);
        $this->assertSame('20.00', $balance['outstanding']);
        $this->assertSame('unpaid', $balance['payment_status']);
        $this->assertFalse($balance['overdue']);
        $this->assertSame('delivered', $order->fresh()->getAttribute('status'));
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('customer_receipts', 0);
    }

    public function test_three_partial_invoices_preserve_original_rounding_cents(): void
    {
        $order = $this->order([['product_id' => $this->product->getKey(), 'quantity' => '3', 'unit_price' => '33.3333']]);
        $delivery = $this->fulfillment($order, '3');
        $amounts = [];
        for ($i = 0; $i < 3; $i++) {
            $invoice = $this->issueInvoice($this->bill($order, $delivery, '1'));
            $amounts[] = $invoice->getAttribute('grand_total');
        }
        $this->assertSame(['33.33', '33.34', '33.33'], $amounts);
        $this->assertSame('100.00', $order->getAttribute('grand_total'));
        $this->assertSame('3.0000', $this->line($order)->getAttribute('invoiced_quantity'));
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_discount_tax_components_reconcile_across_partial_invoices(): void
    {
        $order = $this->order([['product_id' => $this->product->getKey(), 'quantity' => '3', 'unit_price' => '17.1234', 'discount_percent' => '13.4567', 'tax_percent' => '7.9876']]);
        $delivery = $this->fulfillment($order, '3');
        $totals = ['subtotal' => '0.00', 'discount_total' => '0.00', 'tax_total' => '0.00', 'grand_total' => '0.00'];
        for ($i = 0; $i < 3; $i++) {
            $invoice = $this->issueInvoice($this->bill($order, $delivery, '1'));
            foreach ($totals as $field => $value) {
                $totals[$field] = bcadd($value, (string) $invoice->getAttribute($field), 2);
            }
            $this->assertSame($invoice->getAttribute('grand_total'), bcadd(bcsub((string) $invoice->getAttribute('subtotal'), (string) $invoice->getAttribute('discount_total'), 2), (string) $invoice->getAttribute('tax_total'), 2));
        }
        foreach ($totals as $field => $value) {
            $this->assertSame($order->getAttribute($field), $value);
        }
    }

    public function test_half_cent_tie_matches_cancelled_order_budget_and_credit_reader(): void
    {
        $order = $this->order([['product_id' => $this->product->getKey(), 'quantity' => '2', 'unit_price' => '0.0050']]);
        $delivery = $this->fulfillment($order, '1');
        $order = $order->fresh();
        app(SalesOrderService::class)->transition($this->creator, (int) $order->getKey(), 'cancel', (int) $order->getAttribute('revision'), 'Cancel unshipped half');
        $invoice = $this->issueInvoice($this->bill($order, $delivery, '1'));
        $this->assertSame('0.00', $invoice->getAttribute('grand_total'));
        $this->assertSame('no_charge', app(SalesInvoiceBalanceService::class)->snapshot($invoice)['payment_status']);
        // A new confirmed order runs the existing credit reader against this exact cancelled budget.
        $this->assertSame('confirmed', $this->order([['product_id' => $this->product->getKey(), 'quantity' => '1', 'unit_price' => '10']])->getAttribute('status'));
    }

    public function test_competing_rounding_draft_requires_review_and_resave_before_issue(): void
    {
        $order = $this->order([['product_id' => $this->product->getKey(), 'quantity' => '3', 'unit_price' => '33.3333']]);
        $delivery = $this->fulfillment($order, '3');
        $a = $this->bill($order, $delivery, '1');
        $b = $this->bill($order, $delivery, '1');
        $this->issueInvoice($a);
        $this->invalid(fn () => $this->issueInvoice($b));
        $source = SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->firstOrFail();
        $b = app(SalesInvoiceService::class)->save($this->creator, [...$this->invoicePayload($order, [['sales_delivery_item_id' => $source->getKey(), 'quantity' => '1']]), 'revision' => 1], (int) $b->getKey());
        $this->assertSame('33.34', $b->getAttribute('grand_total'));
        $this->issueInvoice($b);
        $this->assertSame('2.0000', $this->line($order)->getAttribute('invoiced_quantity'));
    }

    public function test_issue_retry_uses_original_revision_and_does_not_duplicate_debt_or_counters(): void
    {
        $order = $this->order();
        $invoice = $this->bill($order, $this->fulfillment($order), '2');
        $key = (string) Str::uuid();
        $first = $this->issueInvoice($invoice, $key);
        $again = $this->issueInvoice($invoice, $key);
        $this->assertSame($first->getAttribute('revision'), $again->getAttribute('revision'));
        $this->assertSame('2.0000', $this->line($order)->getAttribute('invoiced_quantity'));
        $this->assertSame(1, SalesDocumentEvent::query()->where('document_type', 'invoice')->where('document_id', $invoice->getKey())->where('action', 'issue')->count());
        $this->assertDatabaseCount('sales_invoices', 1);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_changed_issue_key_or_payload_is_rejected_after_issue(): void
    {
        $order = $this->order();
        $key = (string) Str::uuid();
        $invoice = $this->issueInvoice($this->bill($order, $this->fulfillment($order), '2'), $key);
        $this->invalid(fn () => $this->issueInvoice($invoice));
        $invoice->update(['notes' => 'Tampered']);
        $this->invalid(fn () => $this->issueInvoice($invoice, $key));
        $this->assertSame('2.0000', $this->line($order)->getAttribute('invoiced_quantity'));
    }

    public function test_key_cannot_be_reused_for_another_invoice(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $a = $this->bill($order, $delivery, '2');
        $b = $this->bill($order, $delivery, '2');
        $key = (string) Str::uuid();
        $this->issueInvoice($a, $key);
        $this->invalid(fn () => $this->issueInvoice($b, $key));
        $this->assertSame('2.0000', $this->line($order)->getAttribute('invoiced_quantity'));
    }

    public function test_quantity_capacity_is_rechecked_for_competing_drafts(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $a = $this->bill($order, $delivery, '4');
        $b = $this->bill($order, $delivery, '4');
        $this->issueInvoice($a);
        $this->invalid(fn () => $this->issueInvoice($b));
        $this->assertSame('4.0000', $this->line($order)->getAttribute('invoiced_quantity'));
    }

    public function test_returns_before_billing_reduce_available_fulfillment(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $this->acceptedReturn($delivery, '2');
        $this->invalid(fn () => $this->bill($order, $delivery, '5'));
        $this->issueInvoice($this->bill($order, $delivery, '4'));
        $this->assertSame('4.0000', $this->line($order)->getAttribute('invoiced_quantity'));
        $this->assertSame('6.0000', $this->line($order)->getAttribute('delivered_quantity'));
    }

    public function test_returns_after_draft_block_excess_issue(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $invoice = $this->bill($order, $delivery);
        $this->acceptedReturn($delivery, '1');
        $this->invalid(fn () => $this->issueInvoice($invoice));
        $this->assertSame('draft', $invoice->fresh()->getAttribute('status'));
    }

    public function test_unposted_delivery_cannot_be_invoiced(): void
    {
        $order = $this->order();
        $delivery = $this->draft($order, $this->reserve($order));
        $this->invalid(fn () => $this->bill($order, $delivery));
        $this->assertDatabaseCount('sales_invoices', 0);
    }

    public function test_broken_posted_stock_evidence_requires_review(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->update(['stock_movement_id' => null]);
        $this->invalid(fn () => $this->bill($order, $delivery));
        $this->assertDatabaseCount('sales_invoices', 0);
    }

    public function test_non_stock_completion_can_be_invoiced_without_inventory(): void
    {
        $service = $this->product('Consulting', 'service', false);
        $order = $this->order([['product_id' => $service->getKey(), 'quantity' => '2', 'unit_price' => '50']]);
        $line = $this->line($order, $service);
        $delivery = app(SalesDeliveryService::class)->save($this->creator, $this->payload($order, [['sales_order_item_id' => $line->getKey(), 'quantity' => '2']]));
        $delivery = $this->postDelivery($delivery);
        $invoice = $this->issueInvoice($this->bill($order, $delivery, '2'));
        $this->assertSame('100.00', $invoice->getAttribute('grand_total'));
        $this->assertSame('2.0000', $this->line($order, $service)->getAttribute('invoiced_quantity'));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_closed_and_partially_cancelled_orders_remain_billable_for_completed_quantities(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order, '2');
        $order = $order->fresh();
        $order = app(SalesOrderService::class)->transition($this->creator, (int) $order->getKey(), 'cancel', (int) $order->getAttribute('revision'), 'Cancel unshipped remainder');
        $order = app(SalesOrderService::class)->transition($this->creator, (int) $order->getKey(), 'close', (int) $order->getAttribute('revision'));
        $invoice = $this->issueInvoice($this->bill($order, $delivery, '2'));
        $this->assertSame('20.00', $invoice->getAttribute('grand_total'));
        $this->assertSame('closed', $order->fresh()->getAttribute('status'));
    }

    public function test_inactive_or_archived_masters_do_not_block_billing_saved_fulfillment(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $this->customer->update(['is_active' => false]);
        $this->customer->delete();
        $this->product->update(['is_active' => false]);
        $this->product->delete();
        $this->unit->update(['is_active' => false]);
        $this->warehouse->update(['is_active' => false]);
        $this->warehouse->delete();
        $invoice = $this->issueInvoice($this->bill($order, $delivery));
        $this->assertSame('60.00', $invoice->getAttribute('grand_total'));
    }

    public function test_zero_value_and_fully_discounted_invoices_keep_component_totals(): void
    {
        $order = $this->order([['product_id' => $this->product->getKey(), 'quantity' => '2', 'unit_price' => '10', 'discount_percent' => '100']]);
        $delivery = $this->fulfillment($order, '2');
        $a = $this->issueInvoice($this->bill($order, $delivery, '1'));
        $b = $this->issueInvoice($this->bill($order, $delivery, '1'));
        $this->assertSame('0.00', $a->getAttribute('grand_total'));
        $this->assertSame('10.00', $a->getAttribute('subtotal'));
        $this->assertSame('10.00', $b->getAttribute('discount_total'));
        $this->assertSame('0.00', app(SalesInvoiceBalanceService::class)->snapshot($a)['outstanding']);
    }

    public function test_issue_failure_rolls_back_counters_status_and_audit(): void
    {
        $order = $this->order();
        $invoice = $this->bill($order, $this->fulfillment($order), '2');
        $event = 'eloquent.updating: '.SalesInvoice::class;
        Event::listen($event, static function (SalesInvoice $model): void {
            if ($model->getAttribute('status') === 'issued') {
                throw ValidationException::withMessages(['issue' => 'Injected issue persistence failure.']);
            }
        });
        try {
            $this->invalid(fn () => $this->issueInvoice($invoice));
        } finally {
            Event::forget($event);
        }
        $this->assertSame('0.0000', $this->line($order)->getAttribute('invoiced_quantity'));
        $this->assertSame('0.0000', SalesDeliveryItem::query()->firstOrFail()->getAttribute('invoiced_quantity'));
        $this->assertSame('draft', $invoice->fresh()->getAttribute('status'));
        $this->assertNull($invoice->fresh()->getAttribute('idempotency_key'));
        $this->assertSame(0, SalesDocumentEvent::query()->where('document_type', 'invoice')->where('document_id', $invoice->getKey())->where('action', 'issue')->count());
    }

    public function test_draft_cancellation_is_reasoned_and_has_no_debt_or_counter_effect(): void
    {
        $order = $this->order();
        $invoice = $this->bill($order, $this->fulfillment($order));
        $this->invalid(fn () => app(SalesInvoiceService::class)->cancel($this->creator, (int) $invoice->getKey(), 1, '   '));
        $cancelled = app(SalesInvoiceService::class)->cancel($this->creator, (int) $invoice->getKey(), 1, 'Duplicate draft');
        $this->assertSame('cancelled', $cancelled->getAttribute('status'));
        $this->assertSame('0.0000', $this->line($order)->getAttribute('invoiced_quantity'));
        $this->invalid(fn () => $this->issueInvoice($cancelled));
    }

    public function test_issued_invoice_cannot_be_edited_or_cancelled(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $invoice = $this->issueInvoice($this->bill($order, $delivery, '2'));
        $source = SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->firstOrFail();
        $this->invalid(fn () => app(SalesInvoiceService::class)->save($this->creator, [...$this->invoicePayload($order, [['sales_delivery_item_id' => $source->getKey(), 'quantity' => '1']]), 'revision' => $invoice->getAttribute('revision')], (int) $invoice->getKey()));
        $this->invalid(fn () => app(SalesInvoiceService::class)->cancel($this->creator, (int) $invoice->getKey(), (int) $invoice->getAttribute('revision'), 'Cancel issued'));
    }

    public function test_draft_edit_uses_revision_and_preserves_numbered_date(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $invoice = $this->bill($order, $delivery, '2');
        $source = SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->firstOrFail();
        $payload = [...$this->invoicePayload($order, [['sales_delivery_item_id' => $source->getKey(), 'quantity' => '3']]), 'revision' => 1];
        $updated = app(SalesInvoiceService::class)->save($this->creator, $payload, (int) $invoice->getKey());
        $this->assertSame(2, $updated->getAttribute('revision'));
        $this->invalid(fn () => app(SalesInvoiceService::class)->save($this->creator, $payload, (int) $invoice->getKey()));
        $this->travel(1)->days();
        $this->invalid(fn () => app(SalesInvoiceService::class)->save($this->creator, [...$payload, 'revision' => 2, 'document_date' => now('Asia/Dhaka')->toDateString()], (int) $invoice->getKey()));
    }

    public function test_due_date_is_validated_and_overdue_uses_company_local_calendar(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $source = SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->firstOrFail();
        $payload = $this->invoicePayload($order, [['sales_delivery_item_id' => $source->getKey(), 'quantity' => '2']]);
        $this->invalid(fn () => app(SalesInvoiceService::class)->save($this->creator, [...$payload, 'due_date' => now('Asia/Dhaka')->subDay()->toDateString()]));
        $invoice = $this->issueInvoice(app(SalesInvoiceService::class)->save($this->creator, [...$payload, 'due_date' => now('Asia/Dhaka')->toDateString()]));
        $this->assertFalse(app(SalesInvoiceBalanceService::class)->snapshot($invoice)['overdue']);
        $this->travel(1)->days();
        $this->assertTrue(app(SalesInvoiceBalanceService::class)->snapshot($invoice)['overdue']);
        $this->allocation($invoice, '20.00');
        $this->assertFalse(app(SalesInvoiceBalanceService::class)->snapshot($invoice)['overdue']);
    }

    public function test_partial_and_full_receipts_are_derived_without_changing_issued_status(): void
    {
        $invoice = $this->issuedInvoice();
        $this->allocation($invoice, '20.00');
        $balance = app(SalesInvoiceBalanceService::class)->snapshot($invoice);
        $this->assertSame('40.00', $balance['outstanding']);
        $this->assertSame('partially_paid', $balance['payment_status']);
        $this->allocation($invoice, '40.00');
        $balance = app(SalesInvoiceBalanceService::class)->snapshot($invoice);
        $this->assertSame('0.00', $balance['outstanding']);
        $this->assertSame('paid', $balance['payment_status']);
        $this->assertSame('issued', $invoice->fresh()->getAttribute('status'));
    }

    public function test_allocation_reversal_restores_debt(): void
    {
        $invoice = $this->issuedInvoice();
        $allocation = $this->allocation($invoice, '20.00');
        CustomerReceiptAllocation::query()->create(['company_id' => $this->company->getKey(), 'customer_receipt_id' => $allocation->getAttribute('customer_receipt_id'), 'sales_invoice_id' => $invoice->getKey(), 'amount' => '20.00', 'reverses_allocation_id' => $allocation->getKey(), 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('b', 64), 'posted_at' => now()->toDateTimeString()]);
        $this->assertSame('60.00', app(SalesInvoiceBalanceService::class)->snapshot($invoice)['outstanding']);
    }

    public function test_credits_settle_separately_from_cash_and_do_not_fabricate_receipts(): void
    {
        $invoice = $this->issuedInvoice();
        $this->credit($invoice, '60.00');
        $balance = app(SalesInvoiceBalanceService::class)->snapshot($invoice);
        $this->assertSame('0.00', $balance['outstanding']);
        $this->assertSame('0.00', $balance['paid']);
        $this->assertSame('unpaid', $balance['payment_status']);
        $this->assertSame('fully_credited', $balance['credit_status']);
        $this->assertDatabaseCount('customer_receipts', 0);
    }

    public function test_paid_invoice_with_later_credit_shows_excess_separately(): void
    {
        $invoice = $this->issuedInvoice();
        $this->allocation($invoice, '60.00');
        $this->credit($invoice, '10.00');
        $balance = app(SalesInvoiceBalanceService::class)->snapshot($invoice);
        $this->assertSame('0.00', $balance['outstanding']);
        $this->assertSame('10.00', $balance['excess_settlement']);
        $this->assertSame('60.00', $balance['paid']);
    }

    public function test_invalid_receipt_currency_or_allocation_caps_require_review(): void
    {
        $invoice = $this->issuedInvoice();
        $allocation = $this->allocation($invoice, '20.00');
        $receipt = CustomerReceipt::query()->findOrFail($allocation->getAttribute('customer_receipt_id'));
        $receipt->update(['currency_code' => 'USD']);
        $this->invalid(fn () => app(SalesInvoiceBalanceService::class)->snapshot($invoice));
        $receipt->update(['currency_code' => 'BDT', 'amount' => '10.00']);
        $this->invalid(fn () => app(SalesInvoiceBalanceService::class)->snapshot($invoice));
    }

    public function test_foreign_credit_and_invalid_reversal_cannot_reduce_debt(): void
    {
        $invoice = $this->issuedInvoice();
        $credit = $this->credit($invoice, '10.00');
        $credit->update(['currency_code' => 'USD']);
        $this->invalid(fn () => app(SalesInvoiceBalanceService::class)->snapshot($invoice));
        $credit->update(['currency_code' => 'BDT']);
        $allocation = $this->allocation($invoice, '20.00');
        CustomerReceiptAllocation::query()->create(['company_id' => $this->company->getKey(), 'customer_receipt_id' => $allocation->getAttribute('customer_receipt_id'), 'sales_invoice_id' => $invoice->getKey(), 'amount' => '19.00', 'reverses_allocation_id' => $allocation->getKey(), 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('c', 64), 'posted_at' => now()->toDateTimeString()]);
        $this->invalid(fn () => app(SalesInvoiceBalanceService::class)->snapshot($invoice));
    }

    public function test_no_unallocated_advance_is_silently_offset_against_invoice(): void
    {
        $invoice = $this->issuedInvoice();
        CustomerReceipt::query()->create(['company_id' => $this->company->getKey(), 'customer_id' => $this->customer->getKey(), 'currency_code' => 'BDT', 'number' => 'CR-ADV', 'document_date' => now('Asia/Dhaka')->toDateString(), 'status' => 'posted', 'posted_at' => now()->toDateTimeString(), 'amount' => '100.00', 'payment_method' => 'cash']);
        $this->assertSame('60.00', app(SalesInvoiceBalanceService::class)->snapshot($invoice)['outstanding']);
    }

    public function test_existing_invoice_counter_or_money_drift_blocks_additional_billing(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $invoice = $this->issueInvoice($this->bill($order, $delivery, '2'));
        $this->line($order)->update(['invoiced_quantity' => '1.0000']);
        $this->invalid(fn () => $this->bill($order, $delivery, '1'));
        $this->line($order)->update(['invoiced_quantity' => '2.0000']);
        SalesInvoiceItem::query()->where('sales_invoice_id', $invoice->getKey())->update(['line_total' => '19.99']);
        $this->invalid(fn () => $this->bill($order, $delivery, '1'));
    }

    public function test_client_cannot_inject_prices_status_or_billing_counters(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $source = SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->firstOrFail();
        $payload = $this->invoicePayload($order, [['sales_delivery_item_id' => $source->getKey(), 'quantity' => '2', 'unit_price' => '0', 'line_total' => '0', 'product_id' => 999, 'credited_quantity' => '2']]);
        $invoice = app(SalesInvoiceService::class)->save($this->creator, [...$payload, 'status' => 'issued', 'number' => 'FAKE', 'grand_total' => '0']);
        $this->assertSame('draft', $invoice->getAttribute('status'));
        $this->assertSame('20.00', $invoice->getAttribute('grand_total'));
        $this->assertSame('0.0000', SalesInvoiceItem::query()->where('sales_invoice_id', $invoice->getKey())->firstOrFail()->getAttribute('credited_quantity'));
    }

    public function test_foreign_delivery_item_and_changed_order_source_are_rejected(): void
    {
        $a = $this->order();
        $b = $this->order();
        $da = $this->fulfillment($a, '2');
        $db = $this->fulfillment($b, '2');
        $source = SalesDeliveryItem::query()->where('sales_delivery_id', $db->getKey())->firstOrFail();
        $this->actingAs($this->creator)->post('/erp/sales/invoices', $this->invoicePayload($a, [['sales_delivery_item_id' => $source->getKey(), 'quantity' => '1']]))->assertNotFound();
        $invoice = $this->bill($a, $da, '1');
        $this->invalid(fn () => app(SalesInvoiceService::class)->save($this->creator, [...$this->invoicePayload($b, [['sales_delivery_item_id' => $source->getKey(), 'quantity' => '1']]), 'revision' => 1], (int) $invoice->getKey()));
    }

    public function test_invoice_and_receivables_pages_resolve_with_private_metadata_excluded(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $invoice = $this->bill($order, $delivery, '2');
        $this->actingAs($this->creator)->get('/erp/sales/invoices')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Sales/Invoices/Index')->has('invoices.data', 1));
        $this->get('/erp/sales/invoices/create?company_id='.$this->company->getKey().'&sales_order_id='.$order->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Sales/Invoices/Form')->has('sourceItems', 1));
        $this->get('/erp/sales/invoices/'.$invoice->getKey().'/edit')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Sales/Invoices/Form')->where('invoice.document_date', now('Asia/Dhaka')->toDateString()));
        $this->issueInvoice($invoice);
        $this->get('/erp/sales/invoices/'.$invoice->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Sales/Invoices/Show')->where('balance.outstanding', '20.00')->missing('invoice.idempotency_key')->missing('invoice.terms_snapshot.sales_order_source'));
        $this->get('/erp/sales/invoices/'.$invoice->getKey().'/print')->assertOk()->assertInertia(fn (Assert $page) => $page->where('printMode', true)->where('balance', null)->has('events', 0));
        $this->get('/erp/sales/receivables')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Sales/Receivables/Index')->has('receivables.data', 1)->where('pageTotals.BDT', '20.00'));
    }

    public function test_authentication_permissions_and_company_scope_apply_to_both_workspaces(): void
    {
        $invoice = $this->issuedInvoice();
        $this->get('/erp/sales/invoices')->assertRedirect('/login');
        $this->get('/erp/sales/receivables')->assertRedirect('/login');
        $actor = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $actor->getKey(), 'employee_number' => 'INV-STAFF', 'first_name' => 'Invoice', 'last_name' => 'Staff']);
        $this->actingAs($actor)->get('/erp/sales/invoices')->assertForbidden();
        $actor->givePermissionTo('erp.customer-receivables.view');
        $this->get('/erp/sales/receivables')->assertOk()->assertInertia(fn (Assert $page) => $page->where('receivables.data.0.url', null));
        $this->post('/erp/sales/invoices/'.$invoice->getKey().'/issue', ['revision' => 1, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $foreign = Company::query()->create(['name' => 'Foreign', 'code' => 'INV-B', 'status' => 'active', 'currency_code' => 'BDT']);
        $this->get('/erp/sales/receivables?company_id='.$foreign->getKey())->assertForbidden();
        Employee::query()->where('user_id', $actor->getKey())->update(['company_id' => $foreign->getKey()]);
        $this->get('/erp/sales/receivables?customer_id='.$this->customer->getKey())->assertNotFound();
        $actor->givePermissionTo('erp.sales-invoices.view');
        $this->get('/erp/sales/invoices/'.$invoice->getKey())->assertNotFound();
    }

    public function test_issue_permission_is_separate_from_view_and_draft_edit(): void
    {
        $order = $this->order();
        $invoice = $this->bill($order, $this->fulfillment($order));
        $actor = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $actor->getKey(), 'employee_number' => 'INV-VIEW', 'first_name' => 'View', 'last_name' => 'Only']);
        $actor->givePermissionTo('erp.sales-invoices.view');
        $this->actingAs($actor)->get('/erp/sales/invoices/'.$invoice->getKey())->assertOk();
        $this->post('/erp/sales/invoices/'.$invoice->getKey().'/issue', ['revision' => 1, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_print_preserves_customer_and_product_names_after_master_changes(): void
    {
        $invoice = $this->issuedInvoice();
        $this->customer->update(['name' => 'Changed customer']);
        $this->product->update(['name' => 'Changed product']);
        $this->actingAs($this->creator)->get('/erp/sales/invoices/'.$invoice->getKey().'/print')->assertOk()->assertInertia(fn (Assert $page) => $page->where('invoice.customer_snapshot.name', 'Delivery customer')->where('items.0.product_snapshot.name', 'Physical')->missing('items.0.unit_cost'));
    }

    public function test_http_create_edit_issue_and_receivables_flow(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $source = SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->firstOrFail();
        $payload = $this->invoicePayload($order, [['sales_delivery_item_id' => $source->getKey(), 'quantity' => '2']]);
        $this->actingAs($this->creator)->post('/erp/sales/invoices', $payload)->assertRedirect();
        $invoice = SalesInvoice::query()->firstOrFail();
        $this->put('/erp/sales/invoices/'.$invoice->getKey(), [...$payload, 'revision' => 1])->assertRedirect('/erp/sales/invoices/'.$invoice->getKey());
        $this->post('/erp/sales/invoices/'.$invoice->getKey().'/issue', ['revision' => 2, 'idempotency_key' => (string) Str::uuid()])->assertRedirect('/erp/sales/invoices/'.$invoice->getKey());
        $this->get('/erp/sales/receivables?customer_id='.$this->customer->getKey())->assertOk();
    }

    public function test_invalid_quantities_and_dates_do_not_allocate_invoice_numbers(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        foreach (['0', '-1', '1e1', '0.00001', '10000.0001', '7'] as $quantity) {
            $this->invalid(fn () => $this->bill($order, $delivery, $quantity));
        }
        $source = SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->firstOrFail();
        $payload = $this->invoicePayload($order, [['sales_delivery_item_id' => $source->getKey(), 'quantity' => '1']]);
        $this->invalid(fn () => app(SalesInvoiceService::class)->save($this->creator, [...$payload, 'document_date' => now('Asia/Dhaka')->addDay()->toDateString()]));
        $this->assertDatabaseCount('sales_invoices', 0);
        $this->assertDatabaseMissing('sales_document_sequences', ['document_type' => 'invoice']);
    }

    public function test_one_invoice_can_bill_several_deliveries_of_the_same_order_line(): void
    {
        $order = $this->order();
        $a = $this->fulfillment($order, '2');
        $b = $this->fulfillment($order, '2');
        $diA = SalesDeliveryItem::query()->where('sales_delivery_id', $a->getKey())->firstOrFail();
        $diB = SalesDeliveryItem::query()->where('sales_delivery_id', $b->getKey())->firstOrFail();
        $invoice = app(SalesInvoiceService::class)->save($this->creator, $this->invoicePayload($order, [['sales_delivery_item_id' => $diA->getKey(), 'quantity' => '1'], ['sales_delivery_item_id' => $diB->getKey(), 'quantity' => '1']]));
        $this->issueInvoice($invoice);
        $this->assertSame('20.00', $invoice->getAttribute('grand_total'));
        $this->assertSame('2.0000', $this->line($order)->getAttribute('invoiced_quantity'));
        $this->assertSame('1.0000', $diA->fresh()->getAttribute('invoiced_quantity'));
        $this->assertSame('1.0000', $diB->fresh()->getAttribute('invoiced_quantity'));
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_fractional_invoices_accumulate_subcent_quantities_without_losing_money(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order, '1');
        $a = $this->issueInvoice($this->bill($order, $delivery, '0.0001'));
        $b = $this->issueInvoice($this->bill($order, $delivery, '0.0009'));
        $this->assertSame('0.00', $a->getAttribute('grand_total'));
        $this->assertSame('0.01', $b->getAttribute('grand_total'));
        $this->assertSame('0.0010', $this->line($order)->getAttribute('invoiced_quantity'));
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_receivables_refuses_silently_missing_issuance_metadata(): void
    {
        $invoice = $this->issuedInvoice();
        $invoice->update(['issued_at' => null]);
        $this->actingAs($this->creator)->get('/erp/sales/receivables')->assertSessionHasErrors('receivable');
    }

    public function test_reversed_receipt_with_active_allocation_requires_review(): void
    {
        $invoice = $this->issuedInvoice();
        $allocation = $this->allocation($invoice, '20.00');
        CustomerReceipt::query()->create(['company_id' => $this->company->getKey(), 'customer_id' => $this->customer->getKey(), 'currency_code' => 'BDT', 'number' => 'CR-REVERSE', 'document_date' => now('Asia/Dhaka')->toDateString(), 'status' => 'posted', 'posted_at' => now()->toDateTimeString(), 'amount' => '20.00', 'payment_method' => 'cash', 'reverses_receipt_id' => $allocation->getAttribute('customer_receipt_id')]);
        $this->invalid(fn () => app(SalesInvoiceBalanceService::class)->snapshot($invoice));
    }

    public function test_invoice_cannot_predate_its_selected_delivery(): void
    {
        $order = $this->order();
        $date = now('Asia/Dhaka')->toDateString();
        $this->travel(1)->days();
        $delivery = $this->fulfillment($order);
        $source = SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->firstOrFail();
        $payload = $this->invoicePayload($order, [['sales_delivery_item_id' => $source->getKey(), 'quantity' => '1']]);
        $this->invalid(fn () => app(SalesInvoiceService::class)->save($this->creator, [...$payload, 'document_date' => $date]));
        $this->assertDatabaseCount('sales_invoices', 0);
    }

    public function test_duplicate_fulfillment_rows_are_rejected(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $source = SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->firstOrFail();
        $row = ['sales_delivery_item_id' => $source->getKey(), 'quantity' => '1'];
        $this->invalid(fn () => app(SalesInvoiceService::class)->save($this->creator, $this->invoicePayload($order, [$row, $row])));
        $this->assertDatabaseCount('sales_invoices', 0);
    }
}
