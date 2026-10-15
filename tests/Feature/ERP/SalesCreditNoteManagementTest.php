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
use App\Models\SalesCreditNoteItem;
use App\Models\SalesDelivery;
use App\Models\SalesDeliveryItem;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\SalesStockReservation;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ERP\Sales\CustomerCreditAllocationService;
use App\Services\ERP\Sales\CustomerReceiptService;
use App\Services\ERP\Sales\CustomerStatementService;
use App\Services\ERP\Sales\SalesCreditExposureService;
use App\Services\ERP\Sales\SalesCreditNoteService;
use App\Services\ERP\Sales\SalesDeliveryService;
use App\Services\ERP\Sales\SalesInvoiceBalanceService;
use App\Services\ERP\Sales\SalesInvoiceService;
use App\Services\ERP\Sales\SalesOrderService;
use App\Services\ERP\Sales\SalesQuotationService;
use App\Services\ERP\Sales\SalesReservationService;
use App\Services\ERP\Sales\SalesReturnService;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class SalesCreditNoteManagementTest extends TestCase
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

    public function test_draft_does_not_reduce_invoice_or_move_stock(): void
    {
        $invoice = $this->issuedInvoice();
        $note = $this->creditDraft($invoice);
        $this->assertSame('draft', $note->getAttribute('status'));
        $this->assertSame('20.00', $note->getAttribute('grand_total'));
        $this->assertSame('60.00', $this->balanceOf($invoice)['outstanding']);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_quantity_credit_uses_original_price_and_updates_separate_counters(): void
    {
        $invoice = $this->issuedInvoice();
        $note = $this->issueCredit($this->creditDraft($invoice));
        $line = SalesInvoiceItem::query()->where('sales_invoice_id', $invoice->getKey())->firstOrFail();
        $this->assertSame('20.00', $note->getAttribute('grand_total'));
        $this->assertSame('2.0000', $line->getAttribute('credited_quantity'));
        $this->assertSame('20.00', $line->getAttribute('credited_amount'));
        $this->assertSame('40.00', $this->balanceOf($invoice)['outstanding']);
        $this->assertSame('issued', $invoice->fresh()->getAttribute('status'));
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_creator_cannot_approve_and_issue_own_credit(): void
    {
        $note = $this->creditDraft($this->issuedInvoice());
        $this->expectException(AuthorizationException::class);
        app(SalesCreditNoteService::class)->issue($this->creator, (int) $note->getKey(), 1, (string) Str::uuid());
    }

    public function test_value_correction_does_not_claim_physical_quantity(): void
    {
        $invoice = $this->issuedInvoice();
        $p = $this->creditPayload($invoice);
        $p['items'][0] = [...$p['items'][0], 'credit_type' => 'value', 'amount' => '15'];
        $note = $this->issueCredit(app(SalesCreditNoteService::class)->save($this->creator, $p));
        $this->assertSame('15.00', $note->getAttribute('grand_total'));
        $this->assertSame('0.0000', SalesInvoiceItem::query()->where('sales_invoice_id', $invoice->getKey())->firstOrFail()->getAttribute('credited_quantity'));
        $this->assertSame('45.00', $this->balanceOf($invoice)['outstanding']);
    }

    public function test_credit_quantity_cannot_exceed_original_invoice(): void
    {
        $invoice = $this->issuedInvoice();
        $this->invalid(fn () => $this->creditDraft($invoice, '6.0001'));
    }

    public function test_credit_amount_cannot_exceed_remaining_original_value(): void
    {
        $invoice = $this->issuedInvoice();
        $this->issueCredit($this->creditDraft($invoice, '4'));
        $p = $this->creditPayload($invoice);
        $p['items'][0] = [...$p['items'][0], 'credit_type' => 'value', 'amount' => '20.01'];
        $this->invalid(fn () => app(SalesCreditNoteService::class)->save($this->creator, $p));
    }

    public function test_partial_credits_can_exhaust_original_quantity_and_value(): void
    {
        $invoice = $this->issuedInvoice();
        foreach (['1', '2', '3'] as $q) {
            $this->issueCredit($this->creditDraft($invoice, $q));
        }
        $this->assertSame('0.00', $this->balanceOf($invoice)['outstanding']);
        $this->assertSame('60.00', $this->balanceOf($invoice)['native_credits']);
        $this->invalid(fn () => $this->creditDraft($invoice, '0.0001'));
    }

    public function test_draft_invoice_cannot_be_credited(): void
    {
        $order = $this->order();
        $invoice = $this->bill($order, $this->fulfillment($order));
        $this->invalid(fn () => $this->creditDraft($invoice));
    }

    public function test_issue_retry_is_exactly_once(): void
    {
        $invoice = $this->issuedInvoice();
        $note = $this->creditDraft($invoice);
        $key = (string) Str::uuid();
        $this->issueCredit($note, $key);
        $this->issueCredit($note, $key);
        $this->assertDatabaseCount('sales_credit_notes', 1);
        $this->assertSame('20.00', $this->balanceOf($invoice)['native_credits']);
    }

    public function test_issue_retry_rejects_a_different_key(): void
    {
        $note = $this->issueCredit($this->creditDraft($this->issuedInvoice()));
        $this->invalid(fn () => $this->issueCredit($note));
    }

    public function test_saved_drafts_do_not_reserve_credit_budget(): void
    {
        $invoice = $this->issuedInvoice();
        $a = $this->creditDraft($invoice, '4');
        $b = $this->creditDraft($invoice, '4');
        $this->issueCredit($a);
        $this->invalid(fn () => $this->issueCredit($b));
        $this->assertSame('draft', $b->fresh()->getAttribute('status'));
    }

    public function test_issue_sequence_handles_drafts_issued_out_of_creation_order(): void
    {
        $invoice = $this->issuedInvoice();
        $a = $this->creditDraft($invoice, '1');
        $b = $this->creditDraft($invoice, '1');
        $this->issueCredit($b);
        $this->issueCredit($a);
        $this->assertSame('40.00', $this->balanceOf($invoice)['outstanding']);
        $this->assertSame(2, (int) $a->fresh()->getAttribute('issue_sequence'));
    }

    public function test_billed_accepted_return_can_be_credited_once(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $invoice = $this->issueInvoice($this->bill($order, $delivery));
        $return = $this->postCustomerReturn($this->inspectReturn($this->returnDraft($delivery)));
        $this->issueCredit($this->creditDraft($invoice, '2', $return));
        $this->invalid(fn () => $this->creditDraft($invoice, '1', $return));
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_unbilled_return_does_not_create_a_financial_credit(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $return = $this->postCustomerReturn($this->inspectReturn($this->returnDraft($delivery)));
        $invoice = $this->issueInvoice($this->bill($order, $delivery, '4'));
        $this->invalid(fn () => $this->creditDraft($invoice, '1', $return));
    }

    public function test_unposted_return_cannot_back_a_credit(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $invoice = $this->issueInvoice($this->bill($order, $delivery));
        $return = $this->inspectReturn($this->returnDraft($delivery));
        $this->expectException(ModelNotFoundException::class);
        $this->creditDraft($invoice, '1', $return);
    }

    public function test_paid_invoice_credit_becomes_available_customer_credit(): void
    {
        $invoice = $this->issuedInvoice();
        $this->payInvoice($invoice);
        $this->issueCredit($this->creditDraft($invoice));
        $b = $this->balanceOf($invoice);
        $this->assertSame('0.00', $b['outstanding']);
        $this->assertSame('20.00', $b['available_customer_credit']);
        $this->assertSame('60.00', $b['paid']);
        $this->assertDatabaseCount('customer_receipt_allocations', 1);
    }

    public function test_partial_payment_credit_only_releases_excess_as_available(): void
    {
        $invoice = $this->issuedInvoice();
        $this->payInvoice($invoice, '50');
        $this->issueCredit($this->creditDraft($invoice));
        $this->assertSame('10.00', $this->balanceOf($invoice)['available_customer_credit']);
    }

    public function test_unpaid_credit_is_not_unapplied_cash_or_extra_customer_credit(): void
    {
        $invoice = $this->issuedInvoice();
        $this->issueCredit($this->creditDraft($invoice));
        $this->assertSame('0.00', $this->balanceOf($invoice)['available_customer_credit']);
        $this->assertDatabaseCount('customer_receipts', 0);
    }

    public function test_available_credit_can_settle_another_invoice(): void
    {
        [$source, $target] = $this->twoInvoices();
        $this->payInvoice($source, '30');
        $this->issueCredit($this->creditDraft($source));
        app(CustomerCreditAllocationService::class)->allocate($this->creator, $this->transferPayload($source, $target));
        $this->assertSame('10.00', $this->balanceOf($target)['outstanding']);
        $this->assertSame('0.00', $this->balanceOf($source)['available_customer_credit']);
        $this->assertSame('20.00', $this->balanceOf($target)['allocated_credit']);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_transfer_cannot_exceed_source_available_credit(): void
    {
        [$source, $target] = $this->twoInvoices();
        $this->payInvoice($source, '30');
        $this->issueCredit($this->creditDraft($source));
        $this->invalid(fn () => app(CustomerCreditAllocationService::class)->allocate($this->creator, $this->transferPayload($source, $target, '20.01')));
    }

    public function test_transfer_cannot_exceed_target_outstanding(): void
    {
        [$source, $target] = $this->twoInvoices();
        $this->payInvoice($source, '30');
        $this->issueCredit($this->creditDraft($source));
        $this->payInvoice($target, '25');
        $this->invalid(fn () => app(CustomerCreditAllocationService::class)->allocate($this->creator, $this->transferPayload($source, $target, '6')));
    }

    public function test_credit_allocation_retry_is_exactly_once(): void
    {
        [$source, $target] = $this->twoInvoices();
        $this->payInvoice($source, '30');
        $this->issueCredit($this->creditDraft($source));
        $p = $this->transferPayload($source, $target);
        $s = app(CustomerCreditAllocationService::class);
        $a = $s->allocate($this->creator, $p);
        $b = $s->allocate($this->creator, $p);
        $this->assertSame($a->getKey(), $b->getKey());
        $this->assertDatabaseCount('customer_credit_allocations', 1);
    }

    public function test_credit_allocation_key_cannot_change_amount(): void
    {
        [$source, $target] = $this->twoInvoices();
        $this->payInvoice($source, '30');
        $this->issueCredit($this->creditDraft($source));
        $p = $this->transferPayload($source, $target);
        $s = app(CustomerCreditAllocationService::class);
        $s->allocate($this->creator, $p);
        $this->invalid(fn () => $s->allocate($this->creator, [...$p, 'amount' => '10']));
    }

    public function test_credit_allocation_reversal_appends_compensation(): void
    {
        [$source, $target] = $this->twoInvoices();
        $this->payInvoice($source, '30');
        $this->issueCredit($this->creditDraft($source));
        $s = app(CustomerCreditAllocationService::class);
        $a = $s->allocate($this->creator, $this->transferPayload($source, $target));
        $p = ['idempotency_key' => (string) Str::uuid(), 'reason' => 'Wrong invoice'];
        $s->reverse($this->creator, (int) $a->getKey(), $p);
        $s->reverse($this->creator, (int) $a->getKey(), $p);
        $this->assertSame('30.00', $this->balanceOf($target)['outstanding']);
        $this->assertSame('20.00', $this->balanceOf($source)['available_customer_credit']);
        $this->assertDatabaseCount('customer_credit_allocations', 2);
    }

    public function test_cash_reversal_is_blocked_while_derived_credit_is_spent(): void
    {
        [$source, $target] = $this->twoInvoices();
        $receipt = $this->payInvoice($source, '30');
        $this->issueCredit($this->creditDraft($source));
        app(CustomerCreditAllocationService::class)->allocate($this->creator, $this->transferPayload($source, $target));
        $this->invalid(fn () => app(CustomerReceiptService::class)->reverseReceipt($this->creator, (int) $receipt->getKey(), ['revision' => $receipt->getAttribute('revision'), 'idempotency_key' => (string) Str::uuid(), 'reason' => 'Correct cash']));
        $this->assertDatabaseCount('customer_receipts', 1);
        $this->assertDatabaseCount('customer_receipt_allocations', 1);
    }

    public function test_credit_reversal_allows_subsequent_cash_correction(): void
    {
        [$source, $target] = $this->twoInvoices();
        $receipt = $this->payInvoice($source, '30');
        $this->issueCredit($this->creditDraft($source));
        $s = app(CustomerCreditAllocationService::class);
        $a = $s->allocate($this->creator, $this->transferPayload($source, $target));
        $s->reverse($this->creator, (int) $a->getKey(), ['idempotency_key' => (string) Str::uuid(), 'reason' => 'Release coverage']);
        app(CustomerReceiptService::class)->reverseReceipt($this->creator, (int) $receipt->getKey(), ['revision' => $receipt->getAttribute('revision'), 'idempotency_key' => (string) Str::uuid(), 'reason' => 'Correct cash']);
        $this->assertSame('10.00', $this->balanceOf($source)['outstanding']);
    }

    public function test_new_credit_cannot_overlap_credit_already_received(): void
    {
        [$source, $target] = $this->twoInvoices();
        $this->payInvoice($source, '30');
        $this->issueCredit($this->creditDraft($source));
        app(CustomerCreditAllocationService::class)->allocate($this->creator, $this->transferPayload($source, $target));
        $note = $this->creditDraft($target, '2');
        $this->invalid(fn () => $this->issueCredit($note));
        $this->assertSame('draft', $note->fresh()->getAttribute('status'));
        $this->assertSame('0.0000', SalesInvoiceItem::query()->where('sales_invoice_id', $target->getKey())->firstOrFail()->getAttribute('credited_quantity'));
    }

    public function test_issued_credit_cannot_be_cancelled(): void
    {
        $note = $this->issueCredit($this->creditDraft($this->issuedInvoice()));
        $this->invalid(fn () => app(SalesCreditNoteService::class)->cancel($this->creator, (int) $note->getKey(), (int) $note->getAttribute('revision'), 'Cancel'));
    }

    public function test_cancelled_draft_has_no_balance_effect(): void
    {
        $invoice = $this->issuedInvoice();
        $note = $this->creditDraft($invoice);
        app(SalesCreditNoteService::class)->cancel($this->creator, (int) $note->getKey(), 1, 'Duplicate');
        $this->assertSame('60.00', $this->balanceOf($invoice)['outstanding']);
    }

    public function test_credit_allocation_model_is_append_only(): void
    {
        [$source, $target] = $this->twoInvoices();
        $this->payInvoice($source, '30');
        $this->issueCredit($this->creditDraft($source));
        $a = app(CustomerCreditAllocationService::class)->allocate($this->creator, $this->transferPayload($source, $target));
        $this->expectException(\LogicException::class);
        $a->forceFill(['amount' => '1'])->save();
    }

    public function test_statement_does_not_count_credit_transfers_again(): void
    {
        [$source, $target] = $this->twoInvoices();
        $this->payInvoice($source, '30');
        $this->issueCredit($this->creditDraft($source));
        app(CustomerCreditAllocationService::class)->allocate($this->creator, $this->transferPayload($source, $target));
        $p = app(CustomerStatementService::class)->read($this->creator, ['company_id' => $this->company->getKey(), 'customer_id' => $this->customer->getKey(), 'from' => now('Asia/Dhaka')->startOfMonth()->toDateString(), 'to' => now('Asia/Dhaka')->toDateString()]);
        $this->assertSame('10.00', $p['groups'][0]['closing']);
        $this->assertCount(4, $p['groups'][0]['rows']);
        $this->assertSame('0.00', $p['currentBalances']['BDT']['available_credit']);
    }

    public function test_blank_reasons_and_invalid_value_amounts_fail(): void
    {
        $invoice = $this->issuedInvoice();
        $p = $this->creditPayload($invoice);
        $this->invalid(fn () => app(SalesCreditNoteService::class)->save($this->creator, [...$p, 'reason' => ' ']));
        foreach (['0', '-1', '1.001', '1e2'] as $amount) {
            $bad = $p;
            $bad['items'][0] = [...$p['items'][0], 'credit_type' => 'value', 'amount' => $amount];
            $this->invalid(fn () => app(SalesCreditNoteService::class)->save($this->creator, $bad));
        }
    }

    public function test_credit_pages_and_allocation_register_resolve(): void
    {
        $invoice = $this->issuedInvoice();
        $note = $this->creditDraft($invoice);
        foreach (['/erp/sales/credit-notes' => 'Index', '/erp/sales/credit-notes/create?company_id='.$this->company->getKey().'&sales_invoice_id='.$invoice->getKey() => 'Form', '/erp/sales/credit-notes/'.$note->getKey().'/edit' => 'Form', '/erp/sales/credit-notes/'.$note->getKey() => 'Show', '/erp/sales/credit-notes/'.$note->getKey().'/print' => 'Show', '/erp/sales/customer-credits?company_id='.$this->company->getKey().'&customer_id='.$this->customer->getKey() => 'Allocations'] as $url => $page) {
            $this->actingAs($this->creator)->get($url)->assertOk()->assertInertia(fn (Assert $view) => $view->component('ERP/Sales/CreditNotes/'.$page)->missing('note.payload_hash')->missing('note.idempotency_key')->missing('note.customer_snapshot.nid_number'));
        }
    }

    public function test_workspace_requires_authentication_and_explicit_permissions(): void
    {
        $this->get('/erp/sales/credit-notes')->assertRedirect('/login');
        $actor = User::factory()->create();
        $this->actingAs($actor)->get('/erp/sales/credit-notes')->assertForbidden();
        $this->get('/erp/sales/customer-credits')->assertForbidden();
        $this->post('/erp/sales/customer-credits/allocate', [])->assertForbidden();
    }

    public function test_fractional_credits_preserve_original_tax_discount_and_last_cent(): void
    {
        $order = $this->order([['product_id' => $this->product->getKey(), 'quantity' => '3', 'unit_price' => '0.05', 'discount_percent' => '10', 'tax_percent' => '5']]);
        $delivery = $this->fulfillment($order, '3');
        $invoice = $this->issueInvoice($this->bill($order, $delivery, '3'));
        foreach (['1', '1', '1'] as $qty) {
            $this->issueCredit($this->creditDraft($invoice, $qty));
        }
        foreach (['subtotal' => 'subtotal', 'discount_total' => 'discount_amount', 'tax_total' => 'tax_amount', 'grand_total' => 'line_total'] as $header => $field) {
            $sum = '0.00';
            foreach (SalesCreditNoteItem::query()->get() as $item) {
                $sum = bcadd($sum, (string) $item->getAttribute($field), 2);
            } $this->assertSame($invoice->getAttribute($header), $sum);
        }
        $this->assertSame('0.00', $this->balanceOf($invoice)['outstanding']);
    }

    public function test_other_customer_cannot_receive_credit_transfer(): void
    {
        [$source, $target] = $this->twoInvoices();
        $this->payInvoice($source, '30');
        $this->issueCredit($this->creditDraft($source));
        $other = Customer::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Other', 'phone' => '01700000001', 'is_active' => true]);
        $target->forceFill(['customer_id' => $other->getKey()])->save();
        $this->invalid(fn () => app(CustomerCreditAllocationService::class)->allocate($this->creator, $this->transferPayload($source, $target)));
    }

    public function test_other_currency_cannot_receive_credit_transfer(): void
    {
        [$source, $target] = $this->twoInvoices();
        $this->payInvoice($source, '30');
        $this->issueCredit($this->creditDraft($source));
        $target->forceFill(['currency_code' => 'USD'])->save();
        $this->invalid(fn () => app(CustomerCreditAllocationService::class)->allocate($this->creator, $this->transferPayload($source, $target)));
    }

    public function test_other_company_invoice_is_not_a_credit_source(): void
    {
        $invoice = $this->issuedInvoice();
        $other = Company::query()->create(['name' => 'Other', 'code' => 'CN-B', 'status' => 'active', 'currency_code' => 'BDT']);
        $this->actingAs($this->creator)->post('/erp/sales/credit-notes', [...$this->creditPayload($invoice), 'company_id' => $other->getKey()])->assertNotFound();
    }

    public function test_ordinary_viewer_cannot_issue_or_access_other_company(): void
    {
        $note = $this->creditDraft($this->issuedInvoice());
        $actor = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $actor->getKey(), 'employee_number' => 'CN-VIEW', 'first_name' => 'Credit', 'last_name' => 'Viewer']);
        $actor->givePermissionTo('erp.sales-credit-notes.view');
        $this->actingAs($actor)->get('/erp/sales/credit-notes/'.$note->getKey())->assertOk()->assertInertia(fn (Assert $p) => $p->where('invoiceUrl', null)->where('creditUrl', null)->where('canIssue', false));
        $this->post('/erp/sales/credit-notes/'.$note->getKey().'/issue', [])->assertForbidden();
        $other = Company::query()->create(['name' => 'Other', 'code' => 'CN-C', 'status' => 'active', 'currency_code' => 'BDT']);
        $this->get('/erp/sales/credit-notes?company_id='.$other->getKey())->assertForbidden();
        Employee::query()->where('user_id', $actor->getKey())->update(['company_id' => $other->getKey()]);
        $this->get('/erp/sales/credit-notes/'.$note->getKey())->assertNotFound();
    }

    public function test_failed_credit_issue_rolls_back_line_counters(): void
    {
        $invoice = $this->issuedInvoice();
        $note = $this->creditDraft($invoice);
        $event = 'eloquent.updating: '.SalesCreditNote::class;
        Event::listen($event, static function (SalesCreditNote $n): void {
            if ($n->getAttribute('status') === 'issued') {
                throw ValidationException::withMessages(['credit' => 'Simulated write failure']);
            }
        });
        try {
            $this->invalid(fn () => $this->issueCredit($note));
        } finally {
            Event::forget($event);
        }
        $this->assertSame('0.0000', SalesInvoiceItem::query()->where('sales_invoice_id', $invoice->getKey())->firstOrFail()->getAttribute('credited_quantity'));
        $this->assertSame('draft', $note->fresh()->getAttribute('status'));
        $this->assertSame('60.00', $this->balanceOf($invoice)['outstanding']);
    }

    public function test_unbilled_returns_release_order_commitments_without_changing_billed_debt(): void
    {
        $order = $this->order();
        $delivery = $this->fulfillment($order);
        $this->postCustomerReturn($this->inspectReturn($this->returnDraft($delivery, '6'), '6', '6'));
        $candidate = $this->order(confirmed: false);
        $assessment = DB::transaction(fn () => app(SalesCreditExposureService::class)->assess($this->customer, $candidate));
        $this->assertSame('0.00', $assessment['unbilled_commitments']);
        $this->assertSame('60.00', $assessment['projected_exposure']);
    }

    public function test_credit_allocations_reduce_credit_exposure_without_fake_cash(): void
    {
        [$source, $target] = $this->twoInvoices();
        $this->payInvoice($source, '30');
        $this->issueCredit($this->creditDraft($source));
        app(CustomerCreditAllocationService::class)->allocate($this->creator, $this->transferPayload($source, $target));
        $candidate = $this->order(confirmed: false);
        $assessment = DB::transaction(fn () => app(SalesCreditExposureService::class)->assess($this->customer, $candidate));
        $this->assertSame('10.00', $assessment['open_receivable']);
        $this->assertSame('0.00', $assessment['available_advance']);
    }

    public function test_credit_transfer_reversal_requires_a_reason(): void
    {
        [$source, $target] = $this->twoInvoices();
        $this->payInvoice($source, '30');
        $this->issueCredit($this->creditDraft($source));
        $s = app(CustomerCreditAllocationService::class);
        $a = $s->allocate($this->creator, $this->transferPayload($source, $target));
        $this->invalid(fn () => $s->reverse($this->creator, (int) $a->getKey(), ['reason' => ' ', 'idempotency_key' => (string) Str::uuid()]));
    }

    public function test_cash_allocation_uses_outstanding_after_credit_transfer(): void
    {
        [$source, $target] = $this->twoInvoices();
        $this->payInvoice($source, '30');
        $this->issueCredit($this->creditDraft($source));
        app(CustomerCreditAllocationService::class)->allocate($this->creator, $this->transferPayload($source, $target));
        $this->invalid(fn () => $this->payInvoice($target, '10.01'));
        $this->payInvoice($target, '10');
        $this->assertSame('0.00', $this->balanceOf($target)['outstanding']);
    }

    public function test_issued_credit_header_is_immutable(): void
    {
        $note = $this->issueCredit($this->creditDraft($this->issuedInvoice()));
        $this->expectException(\LogicException::class);
        $note->forceFill(['reason' => 'Changed after approval'])->save();
    }

    public function test_issued_credit_lines_are_immutable(): void
    {
        $note = $this->issueCredit($this->creditDraft($this->issuedInvoice()));
        $line = SalesCreditNoteItem::query()->where('sales_credit_note_id', $note->getKey())->firstOrFail();
        $this->expectException(\LogicException::class);
        $line->delete();
    }

    public function test_last_editor_cannot_approve_their_own_changes(): void
    {
        $invoice = $this->issuedInvoice();
        $note = $this->creditDraft($invoice);
        app(SalesCreditNoteService::class)->save($this->approver, [...$this->creditPayload($invoice), 'revision' => 1], (int) $note->getKey());
        $this->expectException(AuthorizationException::class);
        $this->issueCredit($note->fresh());
    }
}
