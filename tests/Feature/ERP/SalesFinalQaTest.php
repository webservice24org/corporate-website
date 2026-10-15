<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerReceipt;
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
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ERP\Sales\CustomerCreditAllocationService;
use App\Services\ERP\Sales\CustomerReceiptService;
use App\Services\ERP\Sales\CustomerStatementService;
use App\Services\ERP\Sales\SalesCreditNoteService;
use App\Services\ERP\Sales\SalesDeliveryService;
use App\Services\ERP\Sales\SalesInvoiceBalanceService;
use App\Services\ERP\Sales\SalesInvoiceService;
use App\Services\ERP\Sales\SalesOrderService;
use App\Services\ERP\Sales\SalesQuotationService;
use App\Services\ERP\Sales\SalesReportCatalog;
use App\Services\ERP\Sales\SalesReportService;
use App\Services\ERP\Sales\SalesReservationService;
use App\Services\ERP\Sales\SalesReturnService;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class SalesFinalQaTest extends TestCase
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

    /** @param list<string> $permissions */
    private function staff(array $permissions, ?Company $company = null): User
    {
        $actor = User::factory()->create();
        Employee::query()->create(['company_id' => ($company ?? $this->company)->getKey(), 'user_id' => $actor->getKey(), 'employee_number' => 'QA-'.Str::uuid(), 'first_name' => 'Sales', 'last_name' => 'QA']);
        $actor->givePermissionTo($permissions);

        return $actor;
    }

    /** @return array<string, mixed> */
    private function statementData(): array
    {
        return app(CustomerStatementService::class)->read($this->creator, ['company_id' => $this->company->getKey(), 'customer_id' => $this->customer->getKey(), 'from' => now('Asia/Dhaka')->startOfMonth()->toDateString(), 'to' => now('Asia/Dhaka')->toDateString()]);
    }

    public function test_every_sales_route_requires_authentication_and_unique_name(): void
    {
        $names = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'erp/sales/')) {
                continue;
            }
            $this->assertContains('auth', $route->gatherMiddleware(), $route->uri());
            $name = $route->getName();
            $this->assertNotNull($name, $route->uri());
            $this->assertNotContains($name, $names, $route->uri());
            $names[] = $name;
            // These three endpoints authorize in the controller/service using the requested action.
            if (in_array($name, ['erp.sales.quotations.catalog', 'erp.sales.quotations.action', 'erp.sales.orders.action'], true)) {
                continue;
            }
            $this->assertNotEmpty(array_filter($route->gatherMiddleware(), static fn ($value): bool => is_string($value) && str_starts_with($value, 'can:erp.')), $route->uri());
        }
        $this->assertGreaterThan(70, count($names));
    }

    public function test_all_sales_get_routes_redirect_guests_before_resolving_records(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'erp/sales/') || ! in_array('GET', $route->methods(), true)) {
                continue;
            }
            $path = preg_replace('/\{[^}]+\}/', '1', $route->uri());
            $this->get('/'.$path)->assertRedirect('/login');
        }
    }

    public function test_no_permission_user_cannot_read_any_sales_get_endpoint(): void
    {
        $this->actingAs($this->staff([]));
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'erp/sales/') || ! in_array('GET', $route->methods(), true) || $route->getName() === 'erp.sales.quotations.catalog') {
                continue;
            }
            $path = preg_replace('/\{[^}]+\}/', '1', $route->uri());
            $this->get('/'.$path)->assertForbidden();
        }
        $this->get('/erp/sales/quotations/catalog?company_id='.$this->company->getKey())->assertForbidden();
    }

    public function test_read_permissions_cannot_post_financial_or_stock_documents(): void
    {
        $actor = $this->staff(['erp.sales-deliveries.view', 'erp.sales-invoices.view', 'erp.customer-receipts.view', 'erp.sales-returns.view', 'erp.sales-credit-notes.view', 'erp.customer-receivables.view', 'erp.sales-reports.view']);
        $this->actingAs($actor);
        foreach (['deliveries/1/post', 'invoices/1/issue', 'receipts/1/post', 'receipts/1/allocate', 'receipts/1/reverse', 'returns/1/inspect', 'returns/1/post', 'credit-notes/1/issue', 'customer-credits/allocate', 'customer-credits/1/reverse'] as $path) {
            $this->post('/erp/sales/'.$path, ['revision' => 1, 'reason' => 'Unauthorized', 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        }
        $this->get('/erp/sales/reports/export')->assertForbidden();
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('customer_credit_allocations', 0);
    }

    public function test_dynamic_quotation_actions_enforce_action_permission(): void
    {
        $quote = $this->quoteDraft();
        $this->actingAs($this->staff(['erp.sales-quotations.view']));
        foreach (['submit', 'approve', 'reject', 'send', 'accept', 'expire', 'cancel', 'resolve-lead'] as $action) {
            $this->post('/erp/sales/quotations/'.$quote->getKey().'/'.$action, ['revision' => 1, 'reason' => 'Unauthorized'])->assertForbidden();
        }
        $this->assertSame('draft', $quote->refresh()->getAttribute('status'));
    }

    public function test_dynamic_order_actions_enforce_action_permission(): void
    {
        $order = $this->order(confirmed: false);
        $this->actingAs($this->staff(['erp.sales-orders.view']));
        foreach (['submit', 'approve', 'reject', 'confirm', 'credit-override', 'cancel', 'close'] as $action) {
            $this->post('/erp/sales/orders/'.$order->getKey().'/'.$action, ['revision' => 1, 'reason' => 'Unauthorized'])->assertForbidden();
        }
        $this->assertSame('draft', $order->refresh()->getAttribute('status'));
    }

    public function test_permission_without_employee_does_not_grant_company_access(): void
    {
        $invoice = $this->issuedInvoice();
        $actor = User::factory()->create();
        $actor->givePermissionTo(['erp.sales-invoices.view', 'erp.sales-reports.view']);
        $this->actingAs($actor)->get('/erp/sales/invoices/'.$invoice->getKey())->assertForbidden();
        $this->get('/erp/sales/reports?company_id='.$this->company->getKey())->assertForbidden();
    }

    public function test_foreign_employee_cannot_read_invoice_print_or_reports(): void
    {
        $invoice = $this->issuedInvoice();
        $other = Company::query()->create(['name' => 'Foreign', 'code' => 'QA-B', 'timezone' => 'Asia/Dhaka', 'status' => 'active', 'currency_code' => 'BDT']);
        $this->actingAs($this->staff(['erp.sales-invoices.view', 'erp.sales-reports.view', 'erp.customer-receivables.view'], $other));
        foreach (['', '/print'] as $suffix) {
            $this->get('/erp/sales/invoices/'.$invoice->getKey().$suffix)->assertNotFound();
        }
        $this->get('/erp/sales/reports?company_id='.$this->company->getKey())->assertForbidden();
        $this->get('/erp/sales/reports/dashboard?company_id='.$this->company->getKey())->assertForbidden();
        $this->get('/erp/sales/receipts/statement?company_id='.$this->company->getKey().'&customer_id='.$this->customer->getKey())->assertForbidden();
    }

    public function test_statement_permission_does_not_grant_receipt_access(): void
    {
        $invoice = $this->issuedInvoice();
        $receipt = $this->payInvoice($invoice);
        $actor = $this->staff(['erp.customer-receivables.view']);
        $this->actingAs($actor);
        $this->get('/erp/sales/receipts/statement?company_id='.$this->company->getKey().'&customer_id='.$this->customer->getKey())->assertOk();
        $this->get('/erp/sales/receipts')->assertForbidden();
        $this->get('/erp/sales/receipts/'.$receipt->getKey())->assertForbidden();
        $this->get('/erp/sales/reports')->assertForbidden();
    }

    public function test_receipt_permission_does_not_grant_statement_access(): void
    {
        $this->actingAs($this->staff(['erp.customer-receipts.view']));
        $this->get('/erp/sales/receipts')->assertOk();
        $this->get('/erp/sales/receipts/statement')->assertForbidden();
    }

    public function test_inactive_company_blocks_writes_without_stock_effects(): void
    {
        $order = $this->order();
        $reservation = $this->reserve($order);
        $delivery = $this->draft($order, $reservation);
        $this->company->update(['status' => 'inactive']);
        $this->invalid(fn () => $this->postDelivery($delivery));
        $this->assertSame('draft', $delivery->refresh()->getAttribute('status'));
        $this->assertSame('10.0000', $this->balance->refresh()->getAttribute('quantity_on_hand'));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_credit_creator_cannot_self_issue_even_as_super_admin(): void
    {
        $note = $this->creditDraft($this->issuedInvoice());
        try {
            app(SalesCreditNoteService::class)->issue($this->creator, (int) $note->getKey(), 1, (string) Str::uuid());
            $this->fail('Expected independent approval denial.');
        } catch (AuthorizationException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $this->assertSame('draft', $note->refresh()->getAttribute('status'));
        $this->assertSame('60.00', $this->balanceOf(SalesInvoice::query()->findOrFail($note->getAttribute('sales_invoice_id')))['outstanding']);
    }

    public function test_quote_to_cash_with_billed_mixed_condition_return_reconciles_all_domains(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $invoice = $this->issueInvoice($this->bill($order, $delivery));
        $this->payInvoice($invoice, '40');
        $return = $this->postCustomerReturn($this->inspectReturn($this->returnDraft($delivery), '2', '1'));
        $this->assertSame('20.00', $this->balanceOf($invoice)['outstanding']);
        $this->issueCredit($this->creditDraft($invoice, '2', $return));
        $balance = $this->balanceOf($invoice);
        $this->assertSame('60.00', $balance['gross']);
        $this->assertSame('40.00', $balance['paid']);
        $this->assertSame('20.00', $balance['native_credits']);
        $this->assertSame('0.00', $balance['outstanding']);
        $this->assertSame('5.0000', $this->balance->refresh()->getAttribute('quantity_on_hand'));
        $this->assertSame('0.0000', $this->balance->getAttribute('quantity_reserved'));
        $this->assertSame('6.0000', $this->line($order)->getAttribute('delivered_quantity'));
        $this->assertSame('6.0000', $this->line($order)->getAttribute('invoiced_quantity'));
        $this->assertSame('0.0000', $this->returnLine($return)->getAttribute('unbilled_quantity'));
        $this->assertSame('2.0000', $this->returnLine($return)->getAttribute('credit_review_quantity'));
        $this->assertDatabaseCount('stock_movements', 2);
        $statement = $this->statementData();
        $this->assertSame('0.00', $statement['groups'][0]['closing']);
        $this->assertSame('0.00', $statement['currentBalances']['BDT']['outstanding']);
        foreach (['orders', 'deliveries', 'invoices', 'receipts', 'returns', 'credits', 'receivables'] as $type) {
            $this->assertSame(0, $this->reportData($type)['review_count'], $type);
        }
        $this->assertSame('60.00', $this->reportData('invoices')['totals']['money'][0]['values']['amount']);
        $this->assertSame('40.00', $this->reportData('receipts')['totals']['money'][0]['values']['amount']);
        $this->assertSame('20.00', $this->reportData('credits')['totals']['money'][0]['values']['amount']);
    }

    public function test_unbilled_return_reduces_billable_value_without_creating_credit(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $return = $this->postCustomerReturn($this->inspectReturn($this->returnDraft($delivery)));
        $invoice = $this->issueInvoice($this->bill($order, $delivery, '4'));
        $this->payInvoice($invoice, '40');
        $this->assertSame('40.00', $invoice->getAttribute('grand_total'));
        $this->assertSame('2.0000', $this->returnLine($return)->getAttribute('unbilled_quantity'));
        $this->assertSame('0.0000', $this->returnLine($return)->getAttribute('credit_review_quantity'));
        $this->assertSame('0.00', $this->balanceOf($invoice)['outstanding']);
        $this->assertSame('6.0000', $this->balance->refresh()->getAttribute('quantity_on_hand'));
        $this->assertDatabaseCount('sales_credit_notes', 0);
        $this->assertSame('0.00', $this->statementData()['groups'][0]['closing']);
        $this->invalid(fn () => $this->bill($order, $delivery, '0.0001'));
    }

    public function test_paid_invoice_credit_can_settle_second_invoice_without_new_cash(): void
    {
        [$source, $target] = $this->twoInvoices();
        $this->payInvoice($source, '30');
        $this->issueCredit($this->creditDraft($source));
        $service = app(CustomerCreditAllocationService::class);
        $payload = $this->transferPayload($source, $target);
        $row = $service->allocate($this->creator, $payload);
        $again = $service->allocate($this->creator, $payload);
        $this->assertSame($row->getKey(), $again->getKey());
        $this->assertSame('0.00', $this->balanceOf($source)['available_customer_credit']);
        $this->assertSame('10.00', $this->balanceOf($target)['outstanding']);
        $this->assertSame('0.00', $this->balanceOf($target)['available_customer_credit']);
        $this->assertSame('10.00', $this->statementData()['groups'][0]['closing']);
        $this->assertSame('30.00', $this->reportData('receipts')['totals']['money'][0]['values']['amount']);
        $this->assertDatabaseCount('customer_receipts', 1);
        $this->assertDatabaseCount('customer_credit_allocations', 1);
        $undo = ['reason' => 'Correct target', 'idempotency_key' => (string) Str::uuid()];
        $service->reverse($this->creator, (int) $row->getKey(), $undo);
        $service->reverse($this->creator, (int) $row->getKey(), $undo);
        $this->assertSame('20.00', $this->balanceOf($source)['available_customer_credit']);
        $this->assertSame('30.00', $this->balanceOf($target)['outstanding']);
        $this->assertSame('10.00', $this->statementData()['groups'][0]['closing']);
        $this->assertDatabaseCount('customer_credit_allocations', 2);
    }

    public function test_receipt_reversal_with_spent_credit_is_atomic_then_explicit_undo_succeeds(): void
    {
        [$source, $target] = $this->twoInvoices();
        $receipt = $this->payInvoice($source, '30');
        $this->issueCredit($this->creditDraft($source));
        $service = app(CustomerCreditAllocationService::class);
        $row = $service->allocate($this->creator, $this->transferPayload($source, $target));
        $receipts = app(CustomerReceiptService::class);
        $payload = ['revision' => $receipt->getAttribute('revision'), 'reason' => 'Cash correction', 'idempotency_key' => (string) Str::uuid()];
        $this->invalid(fn () => $receipts->reverseReceipt($this->creator, (int) $receipt->getKey(), $payload));
        $this->assertDatabaseCount('customer_receipts', 1);
        $this->assertDatabaseCount('customer_receipt_allocations', 1);
        $this->assertSame('10.00', $this->balanceOf($target)['outstanding']);
        $service->reverse($this->creator, (int) $row->getKey(), ['reason' => 'Undo credit before cash correction', 'idempotency_key' => (string) Str::uuid()]);
        $receipts->reverseReceipt($this->creator, (int) $receipt->getKey(), $payload);
        $this->assertSame('10.00', $this->balanceOf($source)['outstanding']);
        $this->assertSame('30.00', $this->balanceOf($target)['outstanding']);
        $this->assertSame('40.00', $this->statementData()['groups'][0]['closing']);
        $this->assertSame('0.00', $this->reportData('receipts')['totals']['money'][0]['values']['amount']);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_replaying_delivery_invoice_return_and_credit_does_not_duplicate_effects(): void
    {
        $order = $this->order();
        $delivery = $this->draft($order, $this->reserve($order));
        $deliveryKey = (string) Str::uuid();
        $delivery = $this->postDelivery($delivery, $deliveryKey);
        $this->postDelivery($delivery, $deliveryKey);
        $invoice = $this->bill($order, $delivery);
        $invoiceKey = (string) Str::uuid();
        $invoice = $this->issueInvoice($invoice, $invoiceKey);
        $this->issueInvoice($invoice, $invoiceKey);
        $return = $this->inspectReturn($this->returnDraft($delivery));
        $returnKey = (string) Str::uuid();
        $return = $this->postCustomerReturn($return, $returnKey);
        $this->postCustomerReturn($return, $returnKey);
        $note = $this->creditDraft($invoice, '2', $return);
        $creditKey = (string) Str::uuid();
        $note = $this->issueCredit($note, $creditKey);
        $this->issueCredit($note, $creditKey);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseCount('sales_invoices', 1);
        $this->assertDatabaseCount('sales_returns', 1);
        $this->assertDatabaseCount('sales_credit_notes', 1);
        foreach ([['delivery', $delivery, 'post'], ['invoice', $invoice, 'issue'], ['return', $return, 'post'], ['credit_note', $note, 'issue']] as [$type, $document, $action]) {
            $events = DB::table('sales_document_events')->where('document_type', $type)->where('document_id', $document->getKey())->where('action', $action)->get();
            $this->assertCount(1, $events);
            $this->assertSame((int) ($type === 'credit_note' ? $this->approver->getKey() : $this->creator->getKey()), (int) $events[0]->actor_id);
        }
        $this->assertSame('6.0000', $this->line($order)->getAttribute('invoiced_quantity'));
        $this->assertSame('20.00', $this->balanceOf($invoice)['native_credits']);
        $this->assertSame('6.0000', $this->balance->refresh()->getAttribute('quantity_on_hand'));
    }

    public function test_outer_transaction_rolls_back_stock_invoice_and_credit_together(): void
    {
        $order = $this->order();
        $reservation = $this->reserve($order);
        try {
            DB::transaction(function () use ($order, $reservation): void {
                $delivery = $this->postDelivery($this->draft($order, $reservation));
                $invoice = $this->issueInvoice($this->bill($order, $delivery));
                $this->issueCredit($this->creditDraft($invoice));
                throw new \RuntimeException('Intentional integration rollback');
            });
            $this->fail('Expected integration rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Intentional integration rollback', $exception->getMessage());
        }
        $this->assertDatabaseCount('sales_deliveries', 0);
        $this->assertDatabaseCount('sales_invoices', 0);
        $this->assertDatabaseCount('sales_credit_notes', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame('10.0000', $this->balance->refresh()->getAttribute('quantity_on_hand'));
        $this->assertSame('6.0000', $this->balance->getAttribute('quantity_reserved'));
        $this->assertSame('0.0000', $this->line($order)->getAttribute('delivered_quantity'));
    }

    public function test_stale_delivery_post_does_not_consume_reservation(): void
    {
        $order = $this->order();
        $delivery = $this->draft($order, $this->reserve($order));
        $this->invalid(fn () => app(SalesDeliveryService::class)->post($this->creator, (int) $delivery->getKey(), 999, (string) Str::uuid()));
        $this->assertSame('10.0000', $this->balance->refresh()->getAttribute('quantity_on_hand'));
        $this->assertSame('6.0000', $this->balance->getAttribute('quantity_reserved'));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_report_and_statement_reads_do_not_write_business_records(): void
    {
        $invoice = $this->issuedInvoice();
        $this->payInvoice($invoice, '40');
        $this->issueCredit($this->creditDraft($invoice));
        $tables = ['sales_document_sequences', 'sales_quotations', 'sales_quotation_items', 'sales_stock_reservations', 'sales_reservation_events', 'sales_return_items', 'sales_credit_note_items', 'sales_orders', 'sales_order_items', 'sales_deliveries', 'sales_delivery_items', 'sales_invoices', 'sales_invoice_items', 'customer_receipts', 'customer_receipt_allocations', 'sales_returns', 'sales_credit_notes', 'customer_credit_allocations', 'sales_document_events', 'stock_movements', 'inventory_balances'];
        $snapshot = static function () use ($tables): array {
            $result = [];
            foreach ($tables as $table) {
                $result[$table] = DB::table($table)->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all();
            }

            return $result;
        };
        $before = $snapshot();
        $this->statementData();
        foreach (array_keys(app(SalesReportCatalog::class)->all()) as $type) {
            $this->reportData($type);
        }
        $this->assertSame($before, $snapshot());
    }

    public function test_export_permission_alone_cannot_bypass_report_view_permission(): void
    {
        $this->actingAs($this->staff(['erp.sales-reports.export']));
        $this->get('/erp/sales/reports/export?company_id='.$this->company->getKey())->assertForbidden();
        $this->get('/erp/sales/reports/dashboard')->assertForbidden();
    }

    public function test_foreign_employee_with_post_permission_cannot_dispatch_local_delivery(): void
    {
        $order = $this->order();
        $delivery = $this->draft($order, $this->reserve($order));
        $other = Company::query()->create(['name' => 'Foreign writer', 'code' => 'QA-W', 'timezone' => 'Asia/Dhaka', 'status' => 'active', 'currency_code' => 'BDT']);
        $this->actingAs($this->staff(['erp.sales-deliveries.view', 'erp.sales-deliveries.post'], $other));
        $this->post('/erp/sales/deliveries/'.$delivery->getKey().'/post', ['revision' => $delivery->getAttribute('revision'), 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->assertSame('draft', $delivery->refresh()->getAttribute('status'));
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame('10.0000', $this->balance->refresh()->getAttribute('quantity_on_hand'));
    }

    public function test_foreign_employee_with_issue_permission_cannot_issue_local_invoice(): void
    {
        $order = $this->order();
        $invoice = $this->bill($order, $this->fulfillment($order));
        $other = Company::query()->create(['name' => 'Foreign issuer', 'code' => 'QA-I', 'timezone' => 'Asia/Dhaka', 'status' => 'active', 'currency_code' => 'BDT']);
        $this->actingAs($this->staff(['erp.sales-invoices.view', 'erp.sales-invoices.issue'], $other));
        $this->post('/erp/sales/invoices/'.$invoice->getKey().'/issue', ['revision' => $invoice->getAttribute('revision'), 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->assertSame('draft', $invoice->refresh()->getAttribute('status'));
        $this->assertSame('0.0000', $this->line($order)->getAttribute('invoiced_quantity'));
    }

    public function test_report_reader_gets_totals_without_source_document_links(): void
    {
        $invoice = $this->issuedInvoice();
        $this->payInvoice($invoice, '40');
        $this->issueCredit($this->creditDraft($invoice));
        $actor = $this->staff(['erp.sales-reports.view']);
        foreach (['invoices', 'receipts', 'credits'] as $type) {
            $report = $this->reportData($type, actor: $actor);
            $this->assertSame(0, $report['review_count']);
            $this->assertSame(1, $report['matching']);
            $this->assertNull($report['rows'][0]['url']);
        }
        $this->actingAs($actor)->get('/erp/sales/invoices/'.$invoice->getKey())->assertForbidden();
        $this->get('/erp/sales/receipts/statement')->assertForbidden();
    }
}
