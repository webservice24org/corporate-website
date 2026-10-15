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
use App\Models\SalesInvoiceItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesQuotation;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\SalesStockReservation;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ERP\Sales\CustomerCreditAllocationService;
use App\Services\ERP\Sales\CustomerReceiptService;
use App\Services\ERP\Sales\SalesCreditNoteService;
use App\Services\ERP\Sales\SalesDeliveryService;
use App\Services\ERP\Sales\SalesInvoiceBalanceService;
use App\Services\ERP\Sales\SalesInvoiceService;
use App\Services\ERP\Sales\SalesOrderService;
use App\Services\ERP\Sales\SalesQuotationService;
use App\Services\ERP\Sales\SalesReportCatalog;
use App\Services\ERP\Sales\SalesReportCsv;
use App\Services\ERP\Sales\SalesReportService;
use App\Services\ERP\Sales\SalesReservationService;
use App\Services\ERP\Sales\SalesReturnService;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class SalesReportManagementTest extends TestCase
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

    /** @return array<string, mixed> */
    private function creditPayload(SalesInvoice $invoice, string $quantity = '2', ?SalesReturn $return = null): array
    {
        $line = SalesInvoiceItem::query()->where('sales_invoice_id', $invoice->getKey())->firstOrFail();

        return ['company_id' => $this->company->getKey(), 'sales_invoice_id' => $invoice->getKey(), 'sales_return_id' => $return?->getKey(), 'document_date' => now('Asia/Dhaka')->toDateString(), 'reason' => 'Approved commercial correction', 'items' => [['sales_invoice_item_id' => $line->getKey(), 'sales_return_item_id' => $return === null ? null : $this->returnLine($return)->getKey(), 'credit_type' => 'quantity', 'quantity' => $quantity]]];
    }

    private function creditDraft(SalesInvoice $invoice, string $quantity = '2', ?SalesReturn $return = null): SalesCreditNote
    {
        return app(SalesCreditNoteService::class)->save($this->creator, $this->creditPayload($invoice, $quantity, $return));
    }

    private function issueCredit(SalesCreditNote $note, ?string $key = null): SalesCreditNote
    {
        return app(SalesCreditNoteService::class)->issue($this->approver, (int) $note->getKey(), (int) $note->getAttribute('revision'), $key ?? (string) Str::uuid());
    }

    private function payInvoice(SalesInvoice $invoice, string $amount = '60'): CustomerReceipt
    {
        $service = app(CustomerReceiptService::class);
        $row = $service->save($this->creator, ['company_id' => $this->company->getKey(), 'customer_id' => $this->customer->getKey(), 'document_date' => now('Asia/Dhaka')->toDateString(), 'amount' => $amount, 'payment_method' => 'cash']);

        return $service->postReceipt($this->creator, (int) $row->getKey(), ['revision' => 1, 'idempotency_key' => (string) Str::uuid(), 'allocations' => [['sales_invoice_id' => $invoice->getKey(), 'amount' => $amount]]]);
    }

    /** @return array<string, mixed> */
    private function balanceOf(SalesInvoice $invoice): array
    {
        return app(SalesInvoiceBalanceService::class)->snapshot($invoice);
    }

    /** @return array{SalesInvoice, SalesInvoice} */
    private function twoInvoices(): array
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);

        return [$this->issueInvoice($this->bill($order, $delivery, '3')), $this->issueInvoice($this->bill($order, $delivery, '3'))];
    }

    /** @return array<string, mixed> */
    private function transferPayload(SalesInvoice $source, SalesInvoice $target, string $amount = '20'): array
    {
        return ['company_id' => $this->company->getKey(), 'source_sales_invoice_id' => $source->getKey(), 'sales_invoice_id' => $target->getKey(), 'amount' => $amount, 'reason' => 'Apply existing customer credit', 'idempotency_key' => (string) Str::uuid()];
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function reportData(string $type, array $extra = [], ?User $actor = null): array
    {
        $service = app(SalesReportService::class);
        $filters = $service->filters(['type' => $type, 'company_id' => $this->company->getKey(), 'from' => now('Asia/Dhaka')->startOfMonth()->toDateString(), 'to' => now('Asia/Dhaka')->toDateString(), ...$extra]);

        return $service->read($actor ?? $this->creator, $filters, [$type])['reports'][$type];
    }

    private function quoteDraft(string $price = '10'): SalesQuotation
    {
        return app(SalesQuotationService::class)->save($this->creator, ['company_id' => $this->company->getKey(), 'customer_id' => $this->customer->getKey(), 'document_date' => now('Asia/Dhaka')->toDateString(), 'valid_until' => now('Asia/Dhaka')->addDays(30)->toDateString(), 'items' => [['product_id' => $this->product->getKey(), 'quantity' => '1', 'unit_price' => $price]]]);
    }

    public function test_quotation_pipeline_uses_original_amounts_and_current_status(): void
    {
        $this->quoteDraft();
        $report = $this->reportData('quotations');
        $this->assertSame(1, $report['matching']);
        $this->assertSame(0, $report['review_count']);
        $this->assertSame('10.00', $report['totals']['money'][0]['values']['amount']);
        $this->assertSame(1, $report['totals']['statuses']['draft']);
    }

    public function test_confirmed_orders_use_confirmation_period(): void
    {
        $order = $this->order();
        $order->forceFill(['confirmed_at' => now()->subDays(90)->toDateTimeString()])->save();
        $this->assertSame(0, $this->reportData('orders')['matching']);
        $this->assertSame(1, $this->reportData('orders', ['from' => now('Asia/Dhaka')->subDays(100)->toDateString()])['matching']);
    }

    public function test_unconfirmed_order_is_not_a_confirmed_commitment(): void
    {
        $this->order(confirmed: false);
        $this->assertSame(0, $this->reportData('orders')['matching']);
        $this->assertSame(0, $this->reportData('commitments')['matching']);
    }

    public function test_current_commitments_ignore_period_and_reconcile_partial_billing(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $this->issueInvoice($this->bill($order, $delivery, '2'));
        $r = $this->reportData('commitments', ['from' => '2000-01-01', 'to' => '2000-01-02']);
        $this->assertSame(0, $r['review_count']);
        $this->assertSame('40.00', $r['totals']['money'][0]['values']['amount']);
        $this->assertSame('4.0000', $r['totals']['quantities'][0]['values']['quantity']);
    }

    public function test_unbilled_returns_remove_unbilled_commitments(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $this->postCustomerReturn($this->inspectReturn($this->returnDraft($delivery)));
        $r = $this->reportData('commitments');
        $this->assertSame('40.00', $r['totals']['money'][0]['values']['amount']);
    }

    public function test_owned_reservations_reduce_shortage_without_combining_units(): void
    {
        $order = $this->order();
        $this->reserve($order, '2');
        $r = $this->reportData('shortages');
        $this->assertSame(0, $r['review_count']);
        $this->assertSame('6.0000', $r['rows'][0]['quantity']);
        $this->assertSame('2.0000', $r['rows'][0]['reserved']);
        $this->assertSame('4.0000', $r['totals']['quantities'][0]['values']['shortage']);
    }

    public function test_shortage_is_not_reported_for_nonstock_completions(): void
    {
        $product = $this->product('Service', 'service', false);
        $this->order([['product_id' => $product->getKey(), 'quantity' => '2', 'unit_price' => '10']]);
        $this->assertSame(0, $this->reportData('shortages')['matching']);
    }

    public function test_corrupt_reservation_counter_is_a_review_row(): void
    {
        $order = $this->order();
        $reservation = $this->reserve($order, '2');
        $reservation->forceFill(['allocated_quantity' => '3'])->save();
        $r = $this->reportData('shortages');
        $this->assertSame(1, $r['review_count']);
        $this->assertSame([], $r['totals']['quantities']);
    }

    public function test_delivery_register_uses_posted_quantity_and_original_stock_cost(): void
    {
        $this->shipped();
        $r = $this->reportData('deliveries');
        $this->assertSame(0, $r['review_count']);
        $this->assertSame('30.0000', $r['totals']['money'][0]['values']['amount']);
        $this->assertSame('6.0000', $r['totals']['quantities'][0]['values']['quantity']);
    }

    public function test_warehouse_filter_applies_to_physical_rows(): void
    {
        $this->shipped();
        $other = $this->warehouse('REPORT-W2');
        $this->assertSame(0, $this->reportData('deliveries', ['warehouse_id' => $other->getKey()])['matching']);
        $this->assertSame(1, $this->reportData('deliveries', ['warehouse_id' => $this->warehouse->getKey()])['matching']);
    }

    public function test_warehouse_filter_is_rejected_for_header_registers(): void
    {
        $this->invalid(fn () => app(SalesReportService::class)->filters(['type' => 'invoices', 'warehouse_id' => $this->warehouse->getKey()]));
    }

    public function test_invoice_register_excludes_drafts_and_uses_issue_time(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $invoice = $this->bill($order, $delivery);
        $this->assertSame(0, $this->reportData('invoices')['matching']);
        $this->issueInvoice($invoice);
        $r = $this->reportData('invoices');
        $this->assertSame('60.00', $r['totals']['money'][0]['values']['amount']);
    }

    public function test_current_receivables_ignore_activity_dates_and_match_balance_service(): void
    {
        $invoice = $this->issuedInvoice();
        $this->payInvoice($invoice, '20');
        $r = $this->reportData('receivables', ['from' => '2000-01-01', 'to' => '2000-01-02']);
        $this->assertSame('40.00', $r['totals']['money'][0]['values']['amount']);
        $this->assertSame($this->balanceOf($invoice)['outstanding'], $r['rows'][0]['amount']);
    }

    public function test_current_overdue_uses_company_local_today(): void
    {
        $this->travel(-15)->days();
        $invoice = $this->issuedInvoice();
        $this->travelBack();
        $r = $this->reportData('receivables', ['status' => 'overdue']);
        $this->assertSame('60.00', $r['totals']['money'][0]['values']['overdue']);
        $this->assertSame('overdue', $r['rows'][0]['status']);
    }

    public function test_receipt_reversal_is_negative_cash_activity_and_not_available_cash(): void
    {
        $invoice = $this->issuedInvoice();
        $receipt = $this->payInvoice($invoice);
        app(CustomerReceiptService::class)->reverseReceipt($this->creator, (int) $receipt->getKey(), ['revision' => $receipt->getAttribute('revision'), 'idempotency_key' => (string) Str::uuid(), 'reason' => 'Cash correction']);
        $r = $this->reportData('receipts');
        $this->assertSame(2, $r['matching']);
        $this->assertSame('0.00', $r['totals']['money'][0]['values']['amount']);
        $this->assertSame(0, $this->reportData('advances')['matching']);
    }

    public function test_receipt_allocation_compensations_are_signed_activity(): void
    {
        $invoice = $this->issuedInvoice();
        $receipt = $this->payInvoice($invoice);
        $a = CustomerReceiptAllocation::query()->firstOrFail();
        app(CustomerReceiptService::class)->reverseAllocation($this->creator, (int) $receipt->getKey(), (int) $a->getKey(), ['revision' => $receipt->getAttribute('revision'), 'idempotency_key' => (string) Str::uuid(), 'reason' => 'Allocation correction']);
        $r = $this->reportData('receipt_allocations');
        $this->assertSame(2, $r['matching']);
        $this->assertSame('0.00', $r['totals']['money'][0]['values']['amount']);
    }

    public function test_advance_report_is_current_unapplied_cash_only(): void
    {
        $invoice = $this->issuedInvoice();
        $service = app(CustomerReceiptService::class);
        $receipt = $service->save($this->creator, ['company_id' => $this->company->getKey(), 'customer_id' => $this->customer->getKey(), 'document_date' => now('Asia/Dhaka')->toDateString(), 'amount' => '100', 'payment_method' => 'cash']);
        $service->postReceipt($this->creator, (int) $receipt->getKey(), ['revision' => 1, 'idempotency_key' => (string) Str::uuid(), 'allocations' => [['sales_invoice_id' => $invoice->getKey(), 'amount' => '60']]]);
        $r = $this->reportData('advances', ['from' => '2000-01-01', 'to' => '2000-01-02']);
        $this->assertSame('40.00', $r['totals']['money'][0]['values']['amount']);
    }

    public function test_credit_register_and_current_credit_positions_stay_separate(): void
    {
        $invoice = $this->issuedInvoice();
        $this->payInvoice($invoice);
        $this->issueCredit($this->creditDraft($invoice));
        $this->assertSame('20.00', $this->reportData('credits')['totals']['money'][0]['values']['amount']);
        $this->assertSame('20.00', $this->reportData('customer_credits')['totals']['money'][0]['values']['amount']);
        $this->assertSame(0, $this->reportData('advances')['matching']);
    }

    public function test_credit_transfer_reversal_is_signed_activity(): void
    {
        [$a, $b] = $this->twoInvoices();
        $this->payInvoice($a, '30');
        $this->issueCredit($this->creditDraft($a));
        $service = app(CustomerCreditAllocationService::class);
        $allocation = $service->allocate($this->creator, $this->transferPayload($a, $b));
        $service->reverse($this->creator, (int) $allocation->getKey(), ['idempotency_key' => (string) Str::uuid(), 'reason' => 'Wrong target']);
        $r = $this->reportData('credit_allocations');
        $this->assertSame(2, $r['matching']);
        $this->assertSame('0.00', $r['totals']['money'][0]['values']['amount']);
    }

    public function test_return_register_reconciles_good_and_damaged_goods_without_financial_credit(): void
    {
        $return = $this->postCustomerReturn($this->inspectReturn($this->returnDraft($this->shipped()), '2', '1'));
        $r = $this->reportData('returns');
        $this->assertSame(0, $r['review_count']);
        $this->assertSame('2.0000', $r['totals']['quantities'][0]['values']['quantity']);
        $this->assertSame('1.0000', $r['totals']['quantities'][0]['values']['damaged']);
        $this->assertSame('5.0000', $r['totals']['money'][0]['values']['amount']);
        $this->assertSame(0, $this->reportData('credits')['matching']);
    }

    public function test_company_timezone_controls_period_boundaries(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 20:00:00', 'UTC'));
        $this->shipped();
        $this->assertSame(1, $this->reportData('deliveries', ['from' => '2026-10-16', 'to' => '2026-10-16'])['matching']);
        $this->assertSame(0, $this->reportData('deliveries', ['from' => '2026-10-15', 'to' => '2026-10-15'])['matching']);
    }

    public function test_customer_creator_currency_status_and_search_filters_apply(): void
    {
        $quote = $this->quoteDraft();
        $this->assertSame(1, $this->reportData('quotations', ['customer_id' => $this->customer->getKey(), 'created_by' => $this->creator->getKey(), 'currency' => 'BDT', 'status' => 'draft', 'search' => $quote->getAttribute('number')])['matching']);
        $this->assertSame(0, $this->reportData('quotations', ['currency' => 'USD'])['matching']);
        $this->assertSame(0, $this->reportData('quotations', ['status' => 'accepted'])['matching']);
    }

    public function test_bad_invoice_source_is_reviewed_and_excluded_from_totals(): void
    {
        $invoice = $this->issuedInvoice();
        $invoice->forceFill(['grand_total' => '61'])->save();
        $r = $this->reportData('receivables');
        $this->assertSame(1, $r['review_count']);
        $this->assertSame([], $r['totals']['money']);
        $this->assertArrayNotHasKey('amount', $r['rows'][0]);
        $this->assertNull($r['rows'][0]['url']);
    }

    public function test_bad_stock_movement_is_reviewed_in_physical_register(): void
    {
        $this->shipped();
        StockMovement::query()->firstOrFail()->forceFill(['reference_number' => 'WRONG'])->save();
        $r = $this->reportData('deliveries');
        $this->assertSame(1, $r['review_count']);
        $this->assertSame([], $r['totals']['money']);
    }

    public function test_totals_export_and_print_cover_all_rows_beyond_first_page(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->quoteDraft();
        }
        $this->actingAs($this->creator)->get('/erp/sales/reports?type=quotations')->assertOk()->assertInertia(fn (Assert $p) => $p->component('ERP/Sales/Reports/Index')->has('rows.data', 25)->where('rows.total', 30)->where('report.matching', 30)->where('report.totals.money.0.values.amount', '300.00'));
        $this->get('/erp/sales/reports?type=quotations&print=1')->assertOk()->assertInertia(fn (Assert $p) => $p->has('rows', 30)->where('printMode', true));
        $response = $this->get('/erp/sales/reports/export?type=quotations')->assertOk();
        $csv = $response->streamedContent();
        $this->assertSame(31, count(array_filter(explode("\n", trim($csv)))));
        $this->assertStringContainsString('Report date basis', $csv);
    }

    public function test_csv_escapes_spreadsheet_formulas_and_preserves_trusted_signed_numbers(): void
    {
        $csv = app(SalesReportCsv::class);
        foreach (['=1+1', '+SUM(A1)', '-CMD()', '@SUM(A1)', "\t=1", ' =1', "\r=1", "\xEF\xBB\xBF=1"] as $text) {
            $this->assertSame("'".$text, $csv->cell($text));
        } $this->assertSame('-20.00', $csv->cell('-20.00', 'money'));
        $this->assertSame('বাংলা, customer', $csv->cell('বাংলা, customer'));
    }

    public function test_export_escapes_customer_text_and_hides_private_fields(): void
    {
        $this->customer->forceFill(['name' => '=DANGER()', 'notes' => 'PRIVATE-NOTE', 'nid_number' => 'PRIVATE-IDENTITY'])->save();
        $this->quoteDraft();
        $csv = $this->actingAs($this->creator)->get('/erp/sales/reports/export?type=quotations')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=DANGER()", $csv);
        $this->assertStringNotContainsString('PRIVATE-NOTE', $csv);
        $this->assertStringNotContainsString('PRIVATE-IDENTITY', $csv);
        $this->assertStringNotContainsString('payload_hash', $csv);
    }

    public function test_reports_require_login_and_permission(): void
    {
        $this->get('/erp/sales/reports')->assertRedirect('/login');
        $actor = User::factory()->create();
        $this->actingAs($actor)->get('/erp/sales/reports')->assertForbidden();
        $this->get('/erp/sales/reports/dashboard')->assertForbidden();
        $this->get('/erp/sales/reports/export')->assertForbidden();
    }

    public function test_report_view_does_not_grant_export_or_document_permission(): void
    {
        $this->issuedInvoice();
        $actor = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $actor->getKey(), 'employee_number' => 'SALES-REPORT', 'first_name' => 'Report', 'last_name' => 'Viewer']);
        $actor->givePermissionTo('erp.sales-reports.view');
        $this->actingAs($actor)->get('/erp/sales/reports?type=invoices')->assertOk()->assertInertia(fn (Assert $p) => $p->where('rows.data.0.url', null));
        $this->get('/erp/sales/reports/export?type=invoices')->assertForbidden();
        $actor->givePermissionTo('erp.sales-reports.export');
        $this->get('/erp/sales/reports/export?type=invoices')->assertOk();
    }

    public function test_report_scope_denies_foreign_company_and_customer_filters(): void
    {
        $other = Company::query()->create(['name' => 'Other', 'code' => 'RPT-B', 'status' => 'active', 'currency_code' => 'BDT']);
        $customer = Customer::query()->create(['company_id' => $other->getKey(), 'name' => 'Other customer', 'phone' => '01700000002', 'is_active' => true]);
        $actor = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $actor->getKey(), 'employee_number' => 'RPT-SCOPE', 'first_name' => 'Report', 'last_name' => 'Scoped']);
        $actor->givePermissionTo('erp.sales-reports.view');
        $this->actingAs($actor)->get('/erp/sales/reports?company_id='.$other->getKey())->assertForbidden();
        $this->get('/erp/sales/reports?customer_id='.$customer->getKey())->assertNotFound();
    }

    public function test_invalid_ranges_types_and_statuses_are_rejected(): void
    {
        $service = app(SalesReportService::class);
        $this->invalid(fn () => $service->filters(['from' => '2026-10-20', 'to' => '2026-10-10']));
        $this->invalid(fn () => $service->filters(['type' => 'unknown']));
        $this->invalid(fn () => $service->filters(['type' => 'invoices', 'status' => 'approved']));
        $this->invalid(fn () => $service->filters(['status' => 'issued'], true));
    }

    public function test_dashboard_and_register_reconcile_the_same_filters(): void
    {
        $this->issuedInvoice();
        $filters = app(SalesReportService::class)->filters(['company_id' => $this->company->getKey()]);
        $all = app(SalesReportService::class)->read($this->creator, $filters, array_keys(app(SalesReportCatalog::class)->all()));
        $this->assertSame($this->reportData('invoices')['totals'], $all['reports']['invoices']['totals']);
        $this->actingAs($this->creator)->get('/erp/sales/reports/dashboard')->assertOk()->assertInertia(fn (Assert $p) => $p->component('ERP/Sales/Reports/Dashboard')->where('reports.invoices.totals.money.0.values.amount', '60.00')->missing('reports.invoices.rows'));
    }

    public function test_currency_totals_are_never_merged(): void
    {
        $this->quoteDraft();
        $this->company->forceFill(['currency_code' => 'USD'])->save();
        $this->quoteDraft();
        $r = $this->reportData('quotations');
        $this->assertCount(2, $r['totals']['money']);
        $this->assertSame(['BDT', 'USD'], array_column($r['totals']['money'], 'currency'));
        $this->assertSame('10.00', $r['totals']['money'][0]['values']['amount']);
        $this->assertSame('10.00', $r['totals']['money'][1]['values']['amount']);
    }

    public function test_company_totals_stay_separate_even_when_names_and_currency_match(): void
    {
        $firstId = (int) $this->company->getKey();
        $this->quoteDraft();
        $this->company = Company::query()->create(['name' => $this->company->getAttribute('name'), 'code' => 'RPT-SAME', 'timezone' => 'Asia/Dhaka', 'status' => 'active', 'currency_code' => 'BDT']);
        $this->customer = Customer::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Second customer', 'phone' => '01700000003', 'credit_limit' => '100000', 'is_active' => true]);
        $this->unit = Unit::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Piece', 'short_name' => 'pc', 'is_active' => true]);
        $this->product = $this->product('Other product');
        $this->quoteDraft();
        $r = $this->reportData('quotations', ['company_id' => null]);
        $this->assertCount(2, $r['totals']['money']);
        $this->assertSame([$firstId, (int) $this->company->getKey()], array_column($r['totals']['money'], 'company_id'));
    }

    public function test_quantity_totals_are_grouped_by_unit_identity(): void
    {
        $unit = Unit::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Box', 'short_name' => 'box', 'is_active' => true]);
        $product = $this->product('Box product');
        $product->forceFill(['unit_id' => $unit->getKey()])->save();
        $this->order([['product_id' => $this->product->getKey(), 'quantity' => '2', 'unit_price' => '10'], ['product_id' => $product->getKey(), 'quantity' => '1', 'unit_price' => '20']]);
        $r = $this->reportData('commitments');
        $this->assertSame(0, $r['review_count']);
        $this->assertCount(2, $r['totals']['quantities']);
        $this->assertSame('40.00', $r['totals']['money'][0]['values']['amount']);
        $this->assertEqualsCanonicalizing([(int) $this->unit->getKey(), (int) $unit->getKey()], array_column($r['totals']['quantities'], 'unit_id'));
    }

    public function test_fully_reserved_coverage_is_distinct_from_shortage(): void
    {
        $order = $this->order();
        $this->reserve($order);
        $this->assertSame(0, $this->reportData('shortages', ['status' => 'shortage'])['matching']);
        $r = $this->reportData('shortages', ['status' => 'covered']);
        $this->assertSame(1, $r['matching']);
        $this->assertSame('0.0000', $r['totals']['quantities'][0]['values']['shortage']);
    }

    public function test_current_reports_do_not_claim_historical_aging(): void
    {
        $this->issuedInvoice();
        $r = $this->reportData('receivables', ['from' => '2000-01-01', 'to' => '2000-01-02']);
        $this->assertTrue($r['current']);
        $this->assertStringContainsString('date range ignored', $r['basis']);
        $this->assertArrayNotHasKey('historical_aging', $r);
        $this->assertSame('60.00', $r['totals']['money'][0]['values']['amount']);
    }

    public function test_report_read_does_not_change_stock_audit_or_financial_history(): void
    {
        $invoice = $this->issuedInvoice();
        $this->payInvoice($invoice, '20');
        $tables = ['stock_movements', 'sales_document_events', 'customer_receipts', 'customer_receipt_allocations', 'sales_invoices', 'sales_credit_notes'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->count();
        }
        $service = app(SalesReportService::class);
        $service->read($this->creator, $service->filters([]), array_keys(app(SalesReportCatalog::class)->all()));
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->count());
        } $this->assertSame('4.0000', $this->balance->fresh()->getAttribute('quantity_on_hand'));
    }

    public function test_incomplete_legacy_invoice_proof_is_reviewed(): void
    {
        $invoice = $this->issuedInvoice();
        $invoice->forceFill(['payload_hash' => null])->save();
        $r = $this->reportData('invoices');
        $this->assertSame(1, $r['review_count']);
        $this->assertSame([], $r['totals']['money']);
    }

    public function test_single_company_default_range_uses_its_local_calendar(): void
    {
        $this->travelTo(Carbon::parse('2026-10-31 20:00:00', 'UTC'));
        $this->quoteDraft();
        $service = app(SalesReportService::class);
        $data = $service->read($this->creator, $service->filters(['company_id' => $this->company->getKey()]), ['quotations']);
        $this->assertSame('2026-11-01', $data['filters']['from']);
        $this->assertSame('2026-11-01', $data['filters']['to']);
        $this->assertSame(1, $data['reports']['quotations']['matching']);
    }
}
