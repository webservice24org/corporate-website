<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\CustomerReceiptAllocation;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesCreditNote;
use App\Models\SalesDelivery;
use App\Models\SalesDeliveryItem;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesStockReservation;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ERP\Sales\CustomerReceiptService;
use App\Services\ERP\Sales\CustomerStatementService;
use App\Services\ERP\Sales\SalesCreditExposureService;
use App\Services\ERP\Sales\SalesDeliveryService;
use App\Services\ERP\Sales\SalesInvoiceBalanceService;
use App\Services\ERP\Sales\SalesInvoiceService;
use App\Services\ERP\Sales\SalesOrderService;
use App\Services\ERP\Sales\SalesQuotationService;
use App\Services\ERP\Sales\SalesReservationService;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class SalesReceiptManagementTest extends TestCase
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

    private function receiptDraft(string $amount = '100'): CustomerReceipt
    {
        return app(CustomerReceiptService::class)->save($this->creator, ['company_id' => $this->company->getKey(), 'customer_id' => $this->customer->getKey(), 'document_date' => now('Asia/Dhaka')->toDateString(), 'amount' => $amount, 'payment_method' => 'cash', 'payment_reference' => 'MANUAL-REF']);
    }

    /** @param list<array<string, mixed>> $allocations
     * @param  list<int>  $reverse
     * @return array<string, mixed>
     */
    private function operation(CustomerReceipt $receipt, array $allocations = [], array $reverse = []): array
    {
        return ['revision' => $receipt->fresh()->getAttribute('revision'), 'idempotency_key' => (string) Str::uuid(), 'allocations' => $allocations, 'reverse_allocation_ids' => $reverse, 'reason' => 'Correct payment allocation'];
    }

    /** @param list<array<string, mixed>> $allocations */
    private function postCustomerReceipt(CustomerReceipt $receipt, array $allocations = []): CustomerReceipt
    {
        return app(CustomerReceiptService::class)->postReceipt($this->creator, (int) $receipt->getKey(), $this->operation($receipt, $allocations));
    }

    /** @return array{sales_invoice_id: int, amount: string} */
    private function target(SalesInvoice $invoice, string $amount): array
    {
        return ['sales_invoice_id' => (int) $invoice->getKey(), 'amount' => $amount];
    }

    private function outstanding(SalesInvoice $invoice): string
    {
        return app(SalesInvoiceBalanceService::class)->snapshot($invoice)['outstanding'];
    }

    public function test_draft_has_no_cash_or_stock_effect(): void
    {
        $receipt = $this->receiptDraft();
        $this->assertSame('draft', $receipt->getAttribute('status'));
        $this->assertSame('0.00', app(CustomerReceiptService::class)->snapshot($this->creator, (int) $receipt->getKey())['available']);
        $this->assertDatabaseCount('customer_receipt_allocations', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_advance_posts_without_creating_an_invoice(): void
    {
        $receipt = $this->postCustomerReceipt($this->receiptDraft());
        $this->assertSame('posted', $receipt->getAttribute('status'));
        $this->assertSame('100.00', app(CustomerReceiptService::class)->snapshot($this->creator, (int) $receipt->getKey())['available']);
        $this->assertDatabaseCount('sales_invoices', 0);
    }

    public function test_partial_allocation_leaves_unapplied_cash_and_preserves_stock(): void
    {
        $invoice = $this->issuedInvoice();
        $receipt = $this->postCustomerReceipt($this->receiptDraft('100'), [$this->target($invoice, '40')]);
        $state = app(CustomerReceiptService::class)->snapshot($this->creator, (int) $receipt->getKey());
        $this->assertSame('20.00', $this->outstanding($invoice));
        $this->assertSame('60.00', $state['available']);
        $this->assertSame('40.00', $state['allocated']);
        $this->assertSame('issued', $invoice->fresh()->getAttribute('status'));
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame('4.0000', $this->balance->fresh()->getAttribute('quantity_on_hand'));
    }

    public function test_one_receipt_can_settle_two_invoices(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $a = $this->issueInvoice($this->bill($order, $delivery, '3'));
        $b = $this->issueInvoice($this->bill($order, $delivery, '3'));
        $this->postCustomerReceipt($this->receiptDraft('60'), [$this->target($a, '30'), $this->target($b, '30')]);
        $this->assertSame('0.00', $this->outstanding($a));
        $this->assertSame('0.00', $this->outstanding($b));
    }

    public function test_two_receipts_can_settle_one_invoice(): void
    {
        $invoice = $this->issuedInvoice();
        foreach (['20', '40'] as $amount) {
            $this->postCustomerReceipt($this->receiptDraft($amount), [$this->target($invoice, $amount)]);
        }
        $this->assertSame('0.00', $this->outstanding($invoice));
    }

    public function test_posting_over_receipt_funds_rolls_back_header_and_rows(): void
    {
        $invoice = $this->issuedInvoice();
        $receipt = $this->receiptDraft('20');
        $this->invalid(fn () => $this->postCustomerReceipt($receipt, [$this->target($invoice, '30')]));
        $this->assertSame('draft', $receipt->fresh()->getAttribute('status'));
        $this->assertNull($receipt->fresh()->getAttribute('posted_at'));
        $this->assertDatabaseCount('customer_receipt_allocations', 0);
        $this->assertSame('60.00', $this->outstanding($invoice));
    }

    public function test_allocation_cannot_exceed_invoice_debt(): void
    {
        $invoice = $this->issuedInvoice();
        $this->invalid(fn () => $this->postCustomerReceipt($this->receiptDraft(), [$this->target($invoice, '60.01')]));
        $this->assertDatabaseCount('customer_receipt_allocations', 0);
    }

    public function test_credit_note_reduces_allocation_cap(): void
    {
        $invoice = $this->issuedInvoice();
        $this->credit($invoice, '20');
        $this->invalid(fn () => $this->postCustomerReceipt($this->receiptDraft(), [$this->target($invoice, '50')]));
        $this->assertSame('40.00', $this->outstanding($invoice));
    }

    public function test_draft_invoice_cannot_receive_payment(): void
    {
        $order = $this->order();
        $invoice = $this->bill($order, $this->fulfillment($order));
        $this->invalid(fn () => $this->postCustomerReceipt($this->receiptDraft(), [$this->target($invoice, '10')]));
    }

    public function test_customer_mismatch_is_rejected(): void
    {
        $invoice = $this->issuedInvoice();
        $other = Customer::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Other', 'phone' => '01700000001', 'is_active' => true]);
        $receipt = $this->receiptDraft();
        $receipt->forceFill(['customer_id' => $other->getKey()])->save();
        $this->invalid(fn () => $this->postCustomerReceipt($receipt, [$this->target($invoice, '10')]));
    }

    public function test_currency_change_requires_review_before_posting(): void
    {
        $receipt = $this->receiptDraft();
        $this->company->forceFill(['currency_code' => 'USD'])->save();
        $this->invalid(fn () => $this->postCustomerReceipt($receipt));
    }

    public function test_posting_retry_is_exactly_once(): void
    {
        $invoice = $this->issuedInvoice();
        $receipt = $this->receiptDraft();
        $input = $this->operation($receipt, [$this->target($invoice, '40')]);
        $service = app(CustomerReceiptService::class);
        $service->postReceipt($this->creator, (int) $receipt->getKey(), $input);
        $service->postReceipt($this->creator, (int) $receipt->getKey(), $input);
        $this->assertDatabaseCount('customer_receipt_allocations', 1);
        $this->assertSame('20.00', $this->outstanding($invoice));
    }

    public function test_posting_retry_cannot_change_payload_or_key(): void
    {
        $invoice = $this->issuedInvoice();
        $receipt = $this->receiptDraft();
        $input = $this->operation($receipt, [$this->target($invoice, '20')]);
        $service = app(CustomerReceiptService::class);
        $service->postReceipt($this->creator, (int) $receipt->getKey(), $input);
        $this->invalid(fn () => $service->postReceipt($this->creator, (int) $receipt->getKey(), [...$input, 'allocations' => [$this->target($invoice, '30')]]));
        $this->invalid(fn () => $service->postReceipt($this->creator, (int) $receipt->getKey(), [...$input, 'idempotency_key' => (string) Str::uuid()]));
    }

    public function test_later_allocation_uses_advance(): void
    {
        $invoice = $this->issuedInvoice();
        $receipt = $this->postCustomerReceipt($this->receiptDraft());
        app(CustomerReceiptService::class)->allocate($this->creator, (int) $receipt->getKey(), $this->operation($receipt, [$this->target($invoice, '60')]));
        $this->assertSame('0.00', $this->outstanding($invoice));
        $this->assertSame('40.00', app(CustomerReceiptService::class)->snapshot($this->creator, (int) $receipt->getKey())['available']);
    }

    public function test_allocation_reversal_appends_compensation_and_restores_debt(): void
    {
        $invoice = $this->issuedInvoice();
        $receipt = $this->postCustomerReceipt($this->receiptDraft(), [$this->target($invoice, '40')]);
        $original = CustomerReceiptAllocation::query()->firstOrFail();
        app(CustomerReceiptService::class)->reverseAllocation($this->creator, (int) $receipt->getKey(), (int) $original->getKey(), $this->operation($receipt));
        $this->assertSame('60.00', $this->outstanding($invoice));
        $this->assertDatabaseCount('customer_receipt_allocations', 2);
        $this->assertSame('40.00', $original->fresh()->getAttribute('amount'));
        $this->assertSame('100.00', app(CustomerReceiptService::class)->snapshot($this->creator, (int) $receipt->getKey())['available']);
    }

    public function test_atomic_reallocation_releases_and_reuses_same_cash(): void
    {
        $invoice = $this->issuedInvoice();
        $receipt = $this->postCustomerReceipt($this->receiptDraft('40'), [$this->target($invoice, '40')]);
        $id = (int) CustomerReceiptAllocation::query()->firstOrFail()->getKey();
        app(CustomerReceiptService::class)->allocate($this->creator, (int) $receipt->getKey(), $this->operation($receipt, [$this->target($invoice, '30')], [$id]));
        $this->assertSame('30.00', $this->outstanding($invoice));
        $this->assertDatabaseCount('customer_receipt_allocations', 3);
    }

    public function test_failed_reallocation_rolls_back_compensation(): void
    {
        $invoice = $this->issuedInvoice();
        $receipt = $this->postCustomerReceipt($this->receiptDraft('40'), [$this->target($invoice, '40')]);
        $id = (int) CustomerReceiptAllocation::query()->firstOrFail()->getKey();
        $this->invalid(fn () => app(CustomerReceiptService::class)->allocate($this->creator, (int) $receipt->getKey(), $this->operation($receipt, [$this->target($invoice, '50')], [$id])));
        $this->assertDatabaseCount('customer_receipt_allocations', 1);
        $this->assertSame('20.00', $this->outstanding($invoice));
    }

    public function test_later_allocation_retry_is_exactly_once(): void
    {
        $invoice = $this->issuedInvoice();
        $receipt = $this->postCustomerReceipt($this->receiptDraft());
        $input = $this->operation($receipt, [$this->target($invoice, '30')]);
        $service = app(CustomerReceiptService::class);
        $service->allocate($this->creator, (int) $receipt->getKey(), $input);
        $service->allocate($this->creator, (int) $receipt->getKey(), $input);
        $this->assertDatabaseCount('customer_receipt_allocations', 1);
    }

    public function test_stale_allocation_revision_is_rejected(): void
    {
        $invoice = $this->issuedInvoice();
        $receipt = $this->postCustomerReceipt($this->receiptDraft());
        $input = $this->operation($receipt, [$this->target($invoice, '20')]);
        app(CustomerReceiptService::class)->allocate($this->creator, (int) $receipt->getKey(), $input);
        $this->invalid(fn () => app(CustomerReceiptService::class)->allocate($this->creator, (int) $receipt->getKey(), [...$input, 'idempotency_key' => (string) Str::uuid()]));
    }

    public function test_reversal_requires_reason(): void
    {
        $receipt = $this->postCustomerReceipt($this->receiptDraft());
        $this->invalid(fn () => app(CustomerReceiptService::class)->reverseReceipt($this->creator, (int) $receipt->getKey(), [...$this->operation($receipt), 'reason' => ' ']));
    }

    public function test_full_reversal_creates_companion_and_restores_invoice(): void
    {
        $invoice = $this->issuedInvoice();
        $receipt = $this->postCustomerReceipt($this->receiptDraft(), [$this->target($invoice, '40')]);
        $child = app(CustomerReceiptService::class)->reverseReceipt($this->creator, (int) $receipt->getKey(), $this->operation($receipt));
        $this->assertSame($receipt->getKey(), $child->getAttribute('reverses_receipt_id'));
        $this->assertSame('100.00', $child->getAttribute('amount'));
        $this->assertSame('posted', $receipt->fresh()->getAttribute('status'));
        $this->assertSame('60.00', $this->outstanding($invoice));
        $this->assertSame('0.00', app(CustomerReceiptService::class)->snapshot($this->creator, (int) $receipt->getKey())['available']);
        $this->assertDatabaseCount('customer_receipts', 2);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_full_reversal_retry_returns_same_companion(): void
    {
        $receipt = $this->postCustomerReceipt($this->receiptDraft());
        $input = $this->operation($receipt);
        $service = app(CustomerReceiptService::class);
        $a = $service->reverseReceipt($this->creator, (int) $receipt->getKey(), $input);
        $b = $service->reverseReceipt($this->creator, (int) $receipt->getKey(), $input);
        $this->assertSame($a->getKey(), $b->getKey());
        $this->assertDatabaseCount('customer_receipts', 2);
    }

    public function test_reversal_companion_cannot_fund_invoice_or_be_reversed(): void
    {
        $invoice = $this->issuedInvoice();
        $receipt = $this->postCustomerReceipt($this->receiptDraft());
        $service = app(CustomerReceiptService::class);
        $child = $service->reverseReceipt($this->creator, (int) $receipt->getKey(), $this->operation($receipt));
        $this->invalid(fn () => $service->allocate($this->creator, (int) $child->getKey(), $this->operation($child, [$this->target($invoice, '10')])));
        $this->invalid(fn () => $service->reverseReceipt($this->creator, (int) $child->getKey(), $this->operation($child)));
    }

    public function test_posted_receipt_cannot_be_edited_or_cancelled(): void
    {
        $receipt = $this->postCustomerReceipt($this->receiptDraft());
        $this->invalid(fn () => app(CustomerReceiptService::class)->cancel($this->creator, (int) $receipt->getKey(), (int) $receipt->getAttribute('revision'), 'Cancel'));
    }

    public function test_cancelled_draft_has_no_available_cash(): void
    {
        $receipt = $this->receiptDraft();
        app(CustomerReceiptService::class)->cancel($this->creator, (int) $receipt->getKey(), 1, 'Duplicate');
        $this->assertSame('0.00', app(CustomerReceiptService::class)->snapshot($this->creator, (int) $receipt->getKey())['available']);
        $this->invalid(fn () => $this->postCustomerReceipt($receipt->fresh()));
    }

    public function test_inactive_customer_can_only_receive_fully_allocated_payment(): void
    {
        $invoice = $this->issuedInvoice();
        $this->customer->forceFill(['is_active' => false])->save();
        $this->invalid(fn () => $this->postCustomerReceipt($this->receiptDraft('10')));
        $this->postCustomerReceipt($this->receiptDraft('20'), [$this->target($invoice, '20')]);
        $this->assertSame('40.00', $this->outstanding($invoice));
    }

    public function test_zero_and_overprecision_amounts_are_rejected(): void
    {
        foreach (['0', '-1', '1.001', '1e2', '10000000000000000'] as $amount) {
            $this->invalid(fn () => $this->receiptDraft($amount));
        }
    }

    public function test_append_only_allocation_model_blocks_update(): void
    {
        $invoice = $this->issuedInvoice();
        $this->postCustomerReceipt($this->receiptDraft(), [$this->target($invoice, '20')]);
        $this->expectException(\LogicException::class);
        CustomerReceiptAllocation::query()->firstOrFail()->forceFill(['amount' => '10'])->save();
    }

    public function test_statement_counts_cash_once_even_when_allocated(): void
    {
        $invoice = $this->issuedInvoice();
        $this->postCustomerReceipt($this->receiptDraft('100'), [$this->target($invoice, '40')]);
        $statement = $this->statementData();
        $this->assertSame('-40.00', $statement['groups'][0]['closing']);
        $this->assertCount(2, $statement['groups'][0]['rows']);
        $this->assertSame('20.00', $statement['currentBalances']['BDT']['outstanding']);
        $this->assertSame('60.00', $statement['currentBalances']['BDT']['available_advance']);
    }

    /** @return array<string, mixed> */
    private function statementData(?string $from = null): array
    {
        return app(CustomerStatementService::class)->read($this->creator, ['company_id' => $this->company->getKey(), 'customer_id' => $this->customer->getKey(), 'from' => $from ?? now('Asia/Dhaka')->startOfMonth()->toDateString(), 'to' => now('Asia/Dhaka')->addDay()->toDateString()]);
    }

    public function test_statement_reversal_restores_cash_balance(): void
    {
        $this->issuedInvoice();
        $receipt = $this->postCustomerReceipt($this->receiptDraft('100'));
        app(CustomerReceiptService::class)->reverseReceipt($this->creator, (int) $receipt->getKey(), $this->operation($receipt));
        $this->assertSame('60.00', $this->statementData()['groups'][0]['closing']);
    }

    public function test_statement_opening_uses_documents_before_range(): void
    {
        $this->postCustomerReceipt($this->receiptDraft('100'));
        $statement = $this->statementData(now('Asia/Dhaka')->addDay()->toDateString());
        $this->assertSame('-100.00', $statement['groups'][0]['opening']);
        $this->assertCount(0, $statement['groups'][0]['rows']);
    }

    public function test_receipt_pages_and_statement_resolve(): void
    {
        $receipt = $this->postCustomerReceipt($this->receiptDraft());
        foreach (['/erp/sales/receipts' => 'Index', '/erp/sales/receipts/create' => 'Form', '/erp/sales/receipts/'.$receipt->getKey() => 'Show', '/erp/sales/receipts/'.$receipt->getKey().'/print' => 'Show', '/erp/sales/receipts/statement?company_id='.$this->company->getKey().'&customer_id='.$this->customer->getKey() => 'Statement'] as $url => $page) {
            $this->actingAs($this->creator)->get($url)->assertOk()->assertInertia(fn (Assert $view) => $view->component('ERP/Sales/Receipts/'.$page));
        }
    }

    public function test_workspace_requires_login(): void
    {
        $this->get('/erp/sales/receipts')->assertRedirect('/login');
    }

    public function test_unpermitted_user_cannot_read_or_write_receipts(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/erp/sales/receipts')->assertForbidden();
        $this->actingAs($user)->post('/erp/sales/receipts', [])->assertForbidden();
        $this->actingAs($user)->get('/erp/sales/receipts/statement')->assertForbidden();
    }

    public function test_cross_company_customer_cannot_be_used(): void
    {
        $other = Company::query()->create(['name' => 'Other company', 'code' => 'CR-B', 'timezone' => 'Asia/Dhaka', 'status' => 'active', 'currency_code' => 'BDT']);
        $customer = Customer::query()->create(['company_id' => $other->getKey(), 'name' => 'Foreign customer', 'phone' => '01700000002', 'is_active' => true]);
        $this->actingAs($this->creator)->post('/erp/sales/receipts', ['company_id' => $this->company->getKey(), 'customer_id' => $customer->getKey(), 'document_date' => now('Asia/Dhaka')->toDateString(), 'amount' => '10', 'payment_method' => 'cash'])->assertNotFound();
    }

    public function test_foreign_invoice_id_is_scoped_out(): void
    {
        $other = Company::query()->create(['name' => 'Other company', 'code' => 'CR-B', 'timezone' => 'Asia/Dhaka', 'status' => 'active', 'currency_code' => 'BDT']);
        $invoice = $this->issuedInvoice();
        $invoice->forceFill(['company_id' => $other->getKey()])->save();
        $receipt = $this->receiptDraft();
        $this->actingAs($this->creator)->post('/erp/sales/receipts/'.$receipt->getKey().'/post', $this->operation($receipt, [$this->target($invoice, '10')]))->assertNotFound();
        $this->assertSame('draft', $receipt->fresh()->getAttribute('status'));
    }

    public function test_receipt_payload_hides_private_customer_fields_and_keys(): void
    {
        $this->customer->forceFill(['notes' => 'PRIVATE CUSTOMER'])->save();
        $receipt = $this->postCustomerReceipt($this->receiptDraft());
        $this->actingAs($this->creator)->get('/erp/sales/receipts/'.$receipt->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Sales/Receipts/Show')->missing('receipt.idempotency_key')->missing('receipt.payload_hash')->missing('receipt.customer_snapshot.notes')->missing('receipt.customer_snapshot.nid_number'));
    }

    public function test_advance_is_counted_once_across_confirmed_orders(): void
    {
        $this->postCustomerReceipt($this->receiptDraft('100'));
        $this->customer->forceFill(['credit_limit' => '0'])->save();
        $this->order(); // 60 secured by actual advance.
        $candidate = $this->order(confirmed: false);
        $service = app(SalesOrderService::class);
        foreach ([['submit', $this->creator], ['approve', $this->approver]] as [$action, $actor]) {
            $candidate = $service->transition($actor, (int) $candidate->getKey(), $action, (int) $candidate->getAttribute('revision'));
        }
        $this->invalid(fn () => $service->transition($this->creator, (int) $candidate->getKey(), 'confirm', (int) $candidate->getAttribute('revision')));
        $this->assertSame('approved', $candidate->fresh()->getAttribute('status'));
    }

    public function test_allocating_advance_preserves_total_credit_exposure(): void
    {
        $invoice = $this->issuedInvoice();
        $receipt = $this->postCustomerReceipt($this->receiptDraft('100'));
        $candidate = $this->order(confirmed: false);
        $assess = fn () => DB::transaction(fn () => app(SalesCreditExposureService::class)->assess($this->customer, $candidate));
        $before = $assess();
        app(CustomerReceiptService::class)->allocate($this->creator, (int) $receipt->getKey(), $this->operation($receipt, [$this->target($invoice, '40')]));
        $after = $assess();
        $this->assertSame($before['projected_exposure'], $after['projected_exposure']);
        $this->assertSame('60.00', $after['available_advance']);
        $this->assertSame('20.00', $after['open_receivable']);
    }

    public function test_reversing_advance_removes_credit_coverage(): void
    {
        $receipt = $this->postCustomerReceipt($this->receiptDraft('100'));
        $candidate = $this->order(confirmed: false);
        app(CustomerReceiptService::class)->reverseReceipt($this->creator, (int) $receipt->getKey(), $this->operation($receipt));
        $exposure = DB::transaction(fn () => app(SalesCreditExposureService::class)->assess($this->customer, $candidate));
        $this->assertSame('0.00', $exposure['available_advance']);
        $this->assertSame('60.00', $exposure['projected_exposure']);
    }

    public function test_allocation_rows_cannot_be_deleted(): void
    {
        $invoice = $this->issuedInvoice();
        $this->postCustomerReceipt($this->receiptDraft(), [$this->target($invoice, '10')]);
        $this->expectException(\LogicException::class);
        CustomerReceiptAllocation::query()->firstOrFail()->delete();
    }

    public function test_max_length_operation_key_fits_compensation_columns(): void
    {
        $invoice = $this->issuedInvoice();
        $receipt = $this->postCustomerReceipt($this->receiptDraft(), [$this->target($invoice, '10')]);
        app(CustomerReceiptService::class)->reverseReceipt($this->creator, (int) $receipt->getKey(), [...$this->operation($receipt), 'idempotency_key' => str_repeat('R', 64)]);
        foreach (CustomerReceiptAllocation::query()->pluck('idempotency_key') as $key) {
            $this->assertLessThanOrEqual(100, strlen($key));
        }
    }

    public function test_failed_reversal_creation_rolls_back_allocation_compensation(): void
    {
        $invoice = $this->issuedInvoice();
        $receipt = $this->postCustomerReceipt($this->receiptDraft(), [$this->target($invoice, '40')]);
        $event = 'eloquent.creating: '.CustomerReceipt::class;
        Event::listen($event, static function (CustomerReceipt $model): void {
            if ($model->getAttribute('reverses_receipt_id') !== null) {
                throw ValidationException::withMessages(['receipt' => 'Simulated insertion failure']);
            }
        });
        try {
            $this->invalid(fn () => app(CustomerReceiptService::class)->reverseReceipt($this->creator, (int) $receipt->getKey(), $this->operation($receipt)));
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseCount('customer_receipts', 1);
        $this->assertDatabaseCount('customer_receipt_allocations', 1);
        $this->assertSame('20.00', $this->outstanding($invoice));
    }
}
