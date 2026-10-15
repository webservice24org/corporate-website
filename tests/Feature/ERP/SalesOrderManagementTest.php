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
use App\Models\SalesCreditNote;
use App\Models\SalesDelivery;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\SalesOrder;
use App\Models\SalesQuotation;
use App\Models\SalesStockReservation;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ERP\Sales\SalesOrderService;
use App\Services\ERP\Sales\SalesQuotationService;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class SalesOrderManagementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private Product $product;

    private User $creator;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SalesPermissionSeeder::class);
        $this->company = Company::query()->create(['name' => 'Sales A', 'code' => 'SO-A', 'status' => 'active', 'currency_code' => 'BDT']);
        $this->customer = Customer::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Order customer', 'phone' => '01700000000', 'credit_limit' => '1000.00', 'is_active' => true]);
        $unit = Unit::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Piece', 'short_name' => 'pc', 'is_active' => true]);
        $this->product = Product::query()->create(['company_id' => $this->company->getKey(), 'unit_id' => $unit->getKey(), 'name' => 'Order product', 'selling_price' => '100.00', 'product_type' => 'physical', 'track_inventory' => true, 'has_variants' => false, 'is_active' => true]);
        $this->creator = $this->admin();
        $this->approver = $this->admin();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        return $user;
    }

    private function today(): string
    {
        return now('Asia/Dhaka')->toDateString();
    }

    private function quote(bool $accepted = true, string $quantity = '2', string $unitPrice = '100', string $discount = '0'): SalesQuotation
    {
        $service = app(SalesQuotationService::class);
        $quote = $service->save($this->creator, ['company_id' => $this->company->getKey(), 'customer_id' => $this->customer->getKey(), 'document_date' => $this->today(), 'valid_until' => now('Asia/Dhaka')->addDays(30)->toDateString(), 'notes' => 'Quoted terms', 'items' => [['product_id' => $this->product->getKey(), 'quantity' => $quantity, 'unit_price' => $unitPrice, 'discount_percent' => $discount, 'tax_percent' => '0']]]);
        if ($accepted) {
            foreach ([['submit', $this->creator], ['approve', $this->approver], ['send', $this->creator], ['accept', $this->creator]] as [$action, $actor]) {
                $quote = $service->transition($actor, (int) $quote->getKey(), $action, (int) $quote->getAttribute('revision'));
            }
        }

        return $quote;
    }

    /** @return array<string, mixed> */
    private function payload(SalesQuotation $quote): array
    {
        return ['company_id' => $this->company->getKey(), 'sales_quotation_id' => $quote->getKey(), 'document_date' => $this->today(), 'expected_delivery_date' => null];
    }

    private function draft(): SalesOrder
    {
        return app(SalesOrderService::class)->create($this->creator, $this->payload($this->quote()));
    }

    private function approved(): SalesOrder
    {
        $service = app(SalesOrderService::class);
        $order = $this->draft();
        $order = $service->transition($this->creator, (int) $order->getKey(), 'submit', 1);

        return $service->transition($this->approver, (int) $order->getKey(), 'approve', (int) $order->getAttribute('revision'));
    }

    private function confirmed(): SalesOrder
    {
        $order = $this->approved();

        return app(SalesOrderService::class)->transition($this->creator, (int) $order->getKey(), 'confirm', (int) $order->getAttribute('revision'));
    }

    /** Financial fixtures emulate later atomic invoice/counter posting; no posting routes exist here. */
    private function invoice(SalesOrder $order, string $currency = 'BDT'): SalesInvoice
    {
        $order->items()->firstOrFail()->update(['delivered_quantity' => '1.0000', 'invoiced_quantity' => '1.0000']);
        $order->update(['status' => 'partially_delivered']);
        $delivery = SalesDelivery::query()->create(['company_id' => $this->company->getKey(), 'customer_id' => $this->customer->getKey(), 'sales_order_id' => $order->getKey(), 'number' => 'SD-FIX-'.$order->getKey(), 'document_date' => $this->today(), 'currency_code' => $order->getAttribute('currency_code'), 'status' => 'posted', 'posted_at' => now()->toDateTimeString()]);
        $source = $order->items()->firstOrFail();
        $deliveryLine = $delivery->items()->create(['sales_order_item_id' => $source->getKey(), 'line_number' => 1, 'product_id' => $source->getAttribute('product_id'), 'product_variant_id' => $source->getAttribute('product_variant_id'), 'unit_id' => $source->getAttribute('unit_id'), 'quantity' => '1.0000', 'invoiced_quantity' => '1.0000']);
        $invoice = SalesInvoice::query()->create(['company_id' => $this->company->getKey(), 'customer_id' => $this->customer->getKey(), 'sales_order_id' => $order->getKey(), 'number' => 'SI-FIX-'.$order->getKey(), 'document_date' => $this->today(), 'due_date' => $this->today(), 'currency_code' => $currency, 'status' => 'issued', 'issued_at' => now()->toDateTimeString(), 'subtotal' => '100.00', 'grand_total' => '100.00']);
        SalesInvoiceItem::query()->create(['sales_invoice_id' => $invoice->getKey(), 'sales_delivery_item_id' => $deliveryLine->getKey(), 'line_number' => 1, 'product_id' => $source->getAttribute('product_id'), 'product_variant_id' => $source->getAttribute('product_variant_id'), 'unit_id' => $source->getAttribute('unit_id'), 'quantity' => '1.0000', 'unit_price' => '100.0000', 'subtotal' => '100.00', 'line_total' => '100.00']);

        return $invoice;
    }

    private function allocation(SalesInvoice $invoice, string $amount = '40.00', ?string $receiptAmount = null): CustomerReceiptAllocation
    {
        $receipt = CustomerReceipt::query()->create(['company_id' => $this->company->getKey(), 'customer_id' => $this->customer->getKey(), 'number' => 'CR-FIX-'.$invoice->getKey(), 'document_date' => $this->today(), 'currency_code' => $invoice->getAttribute('currency_code'), 'status' => 'posted', 'posted_at' => now()->toDateTimeString(), 'payment_method' => 'manual', 'amount' => $receiptAmount ?? $amount]);

        return CustomerReceiptAllocation::query()->create(['company_id' => $this->company->getKey(), 'customer_receipt_id' => $receipt->getKey(), 'sales_invoice_id' => $invoice->getKey(), 'amount' => $amount, 'idempotency_key' => 'ALLOC-'.$invoice->getKey(), 'payload_hash' => hash('sha256', 'fixture'), 'posted_at' => now()->toDateTimeString()]);
    }

    public function test_create_edit_list_show_and_print_pages_exist_with_preloaded_sources(): void
    {
        $quote = $this->quote();
        $this->actingAs($this->creator)->get('/erp/sales/orders/create?company_id='.$this->company->getKey().'&sales_quotation_id='.$quote->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Sales/Orders/Form')->has('sources', 1));
        $order = app(SalesOrderService::class)->create($this->creator, $this->payload($quote));
        $this->get('/erp/sales/orders')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Sales/Orders/Index')->has('orders.data', 1));
        $this->get('/erp/sales/orders/'.$order->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Sales/Orders/Show')->has('items', 1)->where('printMode', false));
        $this->get('/erp/sales/orders/'.$order->getKey().'/edit')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Sales/Orders/Form')->where('order.id', $order->getKey()));
        $this->get('/erp/sales/orders/'.$order->getKey().'/print')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Sales/Orders/Show')->where('printMode', true));
        $this->get('/erp/sales/orders/create?company_id='.$this->company->getKey().'&sales_quotation_id='.$quote->getKey())->assertRedirect('/erp/sales/orders/'.$order->getKey());
    }

    public function test_complete_conversion_copies_prices_and_is_idempotent(): void
    {
        $quote = $this->quote();
        $service = app(SalesOrderService::class);
        $order = $service->create($this->creator, [...$this->payload($quote), 'grand_total' => '1', 'status' => 'confirmed', 'currency_code' => 'USD', 'items' => []]);
        $retry = $service->create($this->creator, [...$this->payload($quote), 'notes' => 'Do not apply on retry']);
        $this->assertSame($order->getKey(), $retry->getKey());
        $this->assertSame('draft', $order->getAttribute('status'));
        $this->assertSame('BDT', $order->getAttribute('currency_code'));
        $this->assertSame('200.00', $order->getAttribute('grand_total'));
        $this->assertSame('Quoted terms', $retry->getAttribute('notes'));
        $this->assertSame('100.0000', $order->items()->firstOrFail()->getAttribute('unit_price'));
        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertSame(1, DB::table('sales_document_sequences')->where('document_type', 'order')->value('last_number'));
        $this->assertSame(1, DB::table('sales_document_events')->where('document_type', 'order')->where('action', 'create')->count());
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_unaccepted_quote_cannot_create_order_or_consume_number(): void
    {
        $quote = $this->quote(false);
        try {
            app(SalesOrderService::class)->create($this->creator, $this->payload($quote));
            $this->fail('Unaccepted quotation converted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('sales_orders', 0);
            $this->assertSame(0, DB::table('sales_document_sequences')->where('document_type', 'order')->count());
        }
    }

    public function test_expired_source_cannot_create_or_confirm_a_new_commitment(): void
    {
        $order = $this->approved();
        $this->travel(40)->days();
        try {
            $this->expectException(ValidationException::class);
            app(SalesOrderService::class)->transition($this->creator, (int) $order->getKey(), 'confirm', (int) $order->getAttribute('revision'));
        } finally {
            $this->travelBack();
        }
    }

    public function test_foreign_source_quote_is_not_found_in_requested_company(): void
    {
        $quote = $this->quote();
        $foreign = Company::query()->create(['name' => 'Foreign', 'code' => 'SO-B', 'status' => 'active', 'currency_code' => 'BDT']);
        $payload = [...$this->payload($quote), 'company_id' => $foreign->getKey()];
        $this->expectException(ModelNotFoundException::class);
        app(SalesOrderService::class)->create($this->creator, $payload);
    }

    public function test_draft_edit_only_changes_delivery_and_notes_and_rejects_stale_revision(): void
    {
        $order = $this->draft();
        $service = app(SalesOrderService::class);
        $updated = $service->update($this->creator, (int) $order->getKey(), ['revision' => 1, 'notes' => 'Changed', 'unit_price' => '1', 'grand_total' => '1', 'items' => []]);
        $this->assertSame(2, $updated->getAttribute('revision'));
        $this->assertSame('200.00', $updated->getAttribute('grand_total'));
        $this->assertSame('100.0000', $updated->items()->firstOrFail()->getAttribute('unit_price'));
        $this->expectException(ValidationException::class);
        $service->update($this->creator, (int) $order->getKey(), ['revision' => 1, 'notes' => 'Stale']);
    }

    public function test_order_creator_cannot_approve_even_as_super_admin(): void
    {
        $order = $this->draft();
        $service = app(SalesOrderService::class);
        $order = $service->transition($this->creator, (int) $order->getKey(), 'submit', 1);
        $this->expectException(AuthorizationException::class);
        $service->transition($this->creator, (int) $order->getKey(), 'approve', (int) $order->getAttribute('revision'));
    }

    public function test_confirmation_checks_credit_and_retry_creates_one_event(): void
    {
        $order = $this->approved();
        $service = app(SalesOrderService::class);
        $revision = (int) $order->getAttribute('revision');
        $confirmed = $service->transition($this->creator, (int) $order->getKey(), 'confirm', $revision);
        $retry = $service->transition($this->creator, (int) $order->getKey(), 'confirm', $revision);
        $this->assertSame('confirmed', $confirmed->getAttribute('status'));
        $this->assertSame($confirmed->getAttribute('revision'), $retry->getAttribute('revision'));
        $this->assertSame('200.00', $confirmed->getAttribute('credit_check_snapshot')['projected_exposure']);
        $this->assertSame(1, DB::table('sales_document_events')->where('document_type', 'order')->where('action', 'confirm')->count());
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('sales_invoices', 0);
    }

    public function test_zero_value_order_requires_no_unsecured_credit(): void
    {
        $this->customer->update(['credit_limit' => '0.00']);
        $quote = $this->quote(true, '2', '100', '100');
        $service = app(SalesOrderService::class);
        $order = $service->create($this->creator, $this->payload($quote));
        $order = $service->transition($this->creator, (int) $order->getKey(), 'submit', 1);
        $order = $service->transition($this->approver, (int) $order->getKey(), 'approve', (int) $order->getAttribute('revision'));
        $confirmed = $service->transition($this->creator, (int) $order->getKey(), 'confirm', (int) $order->getAttribute('revision'));
        $this->assertSame('confirmed', $confirmed->getAttribute('status'));
        $this->assertSame('0.00', $confirmed->getAttribute('credit_check_snapshot')['projected_exposure']);
    }

    public function test_zero_limit_blocks_positive_unsecured_order(): void
    {
        $this->customer->update(['credit_limit' => '0.00']);
        $order = $this->approved();
        try {
            app(SalesOrderService::class)->transition($this->creator, (int) $order->getKey(), 'confirm', (int) $order->getAttribute('revision'));
            $this->fail('Zero-limit positive order confirmed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('credit', $exception->errors());
            $this->assertDatabaseHas('sales_orders', ['id' => $order->getKey(), 'status' => 'approved', 'confirmed_at' => null]);
        }
    }

    public function test_creator_cannot_override_their_own_order_credit(): void
    {
        $this->customer->update(['credit_limit' => '0.00']);
        $order = $this->approved();
        $this->expectException(AuthorizationException::class);
        app(SalesOrderService::class)->transition($this->creator, (int) $order->getKey(), 'credit-override', (int) $order->getAttribute('revision'), 'Exception');
    }

    public function test_independent_override_requires_reason_and_records_both_events(): void
    {
        $this->customer->update(['credit_limit' => '0.00']);
        $order = $this->approved();
        $service = app(SalesOrderService::class);
        $revision = (int) $order->getAttribute('revision');
        try {
            $service->transition($this->approver, (int) $order->getKey(), 'credit-override', $revision);
            $this->fail('Unreasoned override accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('sales_orders', ['id' => $order->getKey(), 'status' => 'approved']);
        }
        $confirmed = $service->transition($this->approver, (int) $order->getKey(), 'credit-override', $revision, 'Approved credit exception');
        $this->assertSame('confirmed', $confirmed->getAttribute('status'));
        $this->assertSame((int) $this->approver->getKey(), (int) $confirmed->getAttribute('credit_override_by'));
        $this->assertFalse($confirmed->getAttribute('credit_check_snapshot')['within_limit']);
        $this->assertSame(1, DB::table('sales_document_events')->where('document_type', 'order')->where('action', 'credit-override')->count());
        $this->assertSame(1, DB::table('sales_document_events')->where('document_type', 'order')->where('action', 'confirm')->count());
    }

    public function test_preview_does_not_post_and_confirmation_rechecks_changed_limit(): void
    {
        $order = $this->approved();
        $service = app(SalesOrderService::class);
        $preview = $service->creditPreview($this->creator, (int) $order->getKey());
        $this->assertTrue($preview['within_limit']);
        $this->assertDatabaseHas('sales_orders', ['id' => $order->getKey(), 'status' => 'approved', 'credit_check_snapshot' => null]);
        $this->customer->update(['credit_limit' => '100.00']);
        $this->expectException(ValidationException::class);
        $service->transition($this->creator, (int) $order->getKey(), 'confirm', (int) $order->getAttribute('revision'));
    }

    public function test_another_confirmed_order_consumes_credit_capacity(): void
    {
        $this->customer->update(['credit_limit' => '300.00']);
        $this->confirmed();
        $second = $this->approved();
        $preview = app(SalesOrderService::class)->creditPreview($this->creator, (int) $second->getKey());
        $this->assertSame('200.00', $preview['unbilled_commitments']);
        $this->assertSame('400.00', $preview['projected_exposure']);
        $this->assertFalse($preview['within_limit']);
        $this->expectException(ValidationException::class);
        app(SalesOrderService::class)->transition($this->creator, (int) $second->getKey(), 'confirm', (int) $second->getAttribute('revision'));
    }

    public function test_invoice_and_allocation_reduce_exposure_without_double_counting_billed_quantity(): void
    {
        $old = $this->confirmed();
        $invoice = $this->invoice($old);
        $this->allocation($invoice);
        $candidate = $this->approved();
        $preview = app(SalesOrderService::class)->creditPreview($this->creator, (int) $candidate->getKey());
        $this->assertSame('60.00', $preview['open_receivable']);
        $this->assertSame('100.00', $preview['unbilled_commitments']);
        $this->assertSame('360.00', $preview['projected_exposure']);
    }

    public function test_fractional_cancellation_and_billing_keep_the_last_cent_of_commitment(): void
    {
        $quote = $this->quote(true, '3', '33.3333');
        $service = app(SalesOrderService::class);
        $old = $service->create($this->creator, $this->payload($quote));
        foreach ([['submit', $this->creator], ['approve', $this->approver], ['confirm', $this->creator]] as [$action, $actor]) {
            $old = $service->transition($actor, (int) $old->getKey(), $action, (int) $old->getAttribute('revision'));
        }
        $invoice = $this->invoice($old);
        $invoice->update(['subtotal' => '33.33', 'grand_total' => '33.33']);
        $invoice->items()->firstOrFail()->update(['unit_price' => '33.3333', 'subtotal' => '33.33', 'line_total' => '33.33']);
        // Valid future partial-quantity fixture; header cancellation currently cancels all remainder.
        $old->items()->firstOrFail()->update(['cancelled_quantity' => '1.0000']);
        $candidate = $this->approved();
        $preview = $service->creditPreview($this->creator, (int) $candidate->getKey());
        $this->assertSame('33.33', $preview['open_receivable']);
        $this->assertSame('33.34', $preview['unbilled_commitments']);
        $this->assertSame('266.67', $preview['projected_exposure']);
    }

    public function test_allocation_reversal_restores_receivable_and_releases_unallocated_cash(): void
    {
        $invoice = $this->invoice($this->confirmed());
        $allocation = $this->allocation($invoice);
        CustomerReceiptAllocation::query()->create(['company_id' => $this->company->getKey(), 'customer_receipt_id' => $allocation->getAttribute('customer_receipt_id'), 'sales_invoice_id' => $invoice->getKey(), 'amount' => '40.00', 'reverses_allocation_id' => $allocation->getKey(), 'idempotency_key' => 'REV-FIX', 'payload_hash' => hash('sha256', 'reverse'), 'posted_at' => now()->toDateTimeString()]);
        $candidate = $this->approved();
        $preview = app(SalesOrderService::class)->creditPreview($this->creator, (int) $candidate->getKey());
        $this->assertSame('100.00', $preview['open_receivable']);
        $this->assertSame('400.00', $preview['gross_exposure']);
        $this->assertSame('360.00', $preview['projected_exposure']);
        $this->assertSame('40.00', $preview['advance_offset']);
    }

    public function test_issued_credit_does_not_create_negative_receivable_or_fake_cash_paid(): void
    {
        $invoice = $this->invoice($this->confirmed());
        $this->allocation($invoice, '100.00');
        SalesCreditNote::query()->create(['company_id' => $this->company->getKey(), 'customer_id' => $this->customer->getKey(), 'sales_invoice_id' => $invoice->getKey(), 'number' => 'SCN-FIX', 'document_date' => $this->today(), 'currency_code' => 'BDT', 'status' => 'issued', 'issued_at' => now()->toDateTimeString(), 'reason' => 'Correction', 'subtotal' => '25.00', 'grand_total' => '25.00']);
        $candidate = $this->approved();
        $preview = app(SalesOrderService::class)->creditPreview($this->creator, (int) $candidate->getKey());
        $this->assertSame('0.00', $preview['open_receivable']);
        $this->assertSame('300.00', $preview['projected_exposure']);
        $this->assertDatabaseCount('customer_receipts', 1);
    }

    public function test_invalid_receipt_allocation_cannot_be_bypassed_by_credit_override(): void
    {
        $invoice = $this->invoice($this->confirmed());
        $this->allocation($invoice, '40.00', '20.00');
        $candidate = $this->approved();
        $this->expectException(ValidationException::class);
        app(SalesOrderService::class)->transition($this->approver, (int) $candidate->getKey(), 'credit-override', (int) $candidate->getAttribute('revision'), 'Cannot mask corrupt data');
    }

    public function test_foreign_outstanding_currency_requires_review(): void
    {
        $this->invoice($this->confirmed(), 'USD');
        $candidate = $this->approved();
        $this->expectException(ValidationException::class);
        app(SalesOrderService::class)->creditPreview($this->creator, (int) $candidate->getKey());
    }

    public function test_changed_order_prices_cannot_reach_confirmation(): void
    {
        $order = $this->approved();
        $order->items()->firstOrFail()->update(['unit_price' => '90.0000']);
        $this->expectException(ValidationException::class);
        app(SalesOrderService::class)->transition($this->creator, (int) $order->getKey(), 'confirm', (int) $order->getAttribute('revision'));
    }

    public function test_changed_source_quote_is_detected_by_fingerprint(): void
    {
        $order = $this->approved();
        $quote = SalesQuotation::query()->findOrFail($order->getAttribute('sales_quotation_id'));
        $quote->update(['notes' => 'Changed after acceptance']);
        $this->expectException(ValidationException::class);
        app(SalesOrderService::class)->transition($this->creator, (int) $order->getKey(), 'confirm', (int) $order->getAttribute('revision'));
    }

    public function test_catalog_repricing_keeps_the_accepted_order_price(): void
    {
        $order = $this->approved();
        $this->product->update(['selling_price' => '900.00']);
        $confirmed = app(SalesOrderService::class)->transition($this->creator, (int) $order->getKey(), 'confirm', (int) $order->getAttribute('revision'));
        $this->assertSame('200.00', $confirmed->getAttribute('grand_total'));
        $this->assertSame('100.0000', $confirmed->items()->firstOrFail()->getAttribute('unit_price'));
    }

    public function test_changed_inventory_tracking_configuration_blocks_confirmation(): void
    {
        $order = $this->approved();
        $this->product->update(['track_inventory' => false]);
        $this->expectException(ValidationException::class);
        app(SalesOrderService::class)->transition($this->creator, (int) $order->getKey(), 'confirm', (int) $order->getAttribute('revision'));
    }

    public function test_inactive_customer_blocks_confirmation(): void
    {
        $order = $this->approved();
        $this->customer->update(['is_active' => false]);
        $this->expectException(ValidationException::class);
        app(SalesOrderService::class)->transition($this->creator, (int) $order->getKey(), 'confirm', (int) $order->getAttribute('revision'));
    }

    public function test_confirmed_order_is_immutable(): void
    {
        $order = $this->confirmed();
        $this->expectException(ValidationException::class);
        app(SalesOrderService::class)->update($this->creator, (int) $order->getKey(), ['revision' => $order->getAttribute('revision'), 'notes' => 'Do not change']);
    }

    public function test_rejection_and_cancellation_require_reasons_and_rollback_counters(): void
    {
        $order = $this->draft();
        try {
            app(SalesOrderService::class)->transition($this->creator, (int) $order->getKey(), 'cancel', 1);
            $this->fail('Cancellation without reason succeeded.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('sales_orders', ['id' => $order->getKey(), 'status' => 'draft']);
            $this->assertSame('0.0000', $order->items()->firstOrFail()->getAttribute('cancelled_quantity'));
        }
    }

    public function test_partial_remainder_cancellation_preserves_gross_delivery_and_pending_billing(): void
    {
        $order = $this->confirmed();
        // Future delivery fixture; this milestone does not expose fulfillment posting.
        $order->items()->firstOrFail()->update(['delivered_quantity' => '1.0000']);
        $order->update(['status' => 'partially_delivered']);
        $service = app(SalesOrderService::class);
        $cancelled = $service->transition($this->creator, (int) $order->getKey(), 'cancel', (int) $order->getAttribute('revision'), 'Cancel undelivered remainder');
        $line = $cancelled->items()->firstOrFail();
        $this->assertSame('1.0000', $line->getAttribute('delivered_quantity'));
        $this->assertSame('1.0000', $line->getAttribute('cancelled_quantity'));
        $this->assertSame('200.00', $cancelled->getAttribute('grand_total'));
        $closed = $service->transition($this->creator, (int) $order->getKey(), 'close', (int) $cancelled->getAttribute('revision'));
        $this->assertSame('closed', $closed->getAttribute('status'));
        $candidate = $this->approved();
        $this->assertSame('100.00', $service->creditPreview($this->creator, (int) $candidate->getKey())['unbilled_commitments']);
    }

    public function test_cancel_releases_owned_reservations_without_physical_stock_movement(): void
    {
        $order = $this->confirmed();
        $line = $order->items()->firstOrFail();
        $warehouse = Warehouse::query()->create(['company_id' => $this->company->getKey(), 'code' => 'SO-WH', 'name' => 'Order warehouse', 'is_active' => true]);
        $balance = InventoryBalance::query()->create(['company_id' => $this->company->getKey(), 'warehouse_id' => $warehouse->getKey(), 'product_id' => $this->product->getKey(), 'variant_key' => 0, 'quantity_on_hand' => '10.0000', 'quantity_reserved' => '1.0000', 'average_cost' => '5.0000']);
        $reservation = SalesStockReservation::query()->create(['company_id' => $this->company->getKey(), 'sales_order_item_id' => $line->getKey(), 'warehouse_id' => $warehouse->getKey(), 'product_id' => $this->product->getKey(), 'variant_key' => 0, 'allocated_quantity' => '1.0000']);
        $cancelled = app(SalesOrderService::class)->transition($this->creator, (int) $order->getKey(), 'cancel', (int) $order->getAttribute('revision'), 'Reserved');
        $this->assertSame('cancelled', $cancelled->getAttribute('status'));
        $this->assertSame('0.0000', $balance->fresh()->getAttribute('quantity_reserved'));
        $this->assertSame('10.0000', $balance->fresh()->getAttribute('quantity_on_hand'));
        $this->assertSame('1.0000', $reservation->fresh()->getAttribute('released_quantity'));
        $this->assertDatabaseHas('sales_reservation_events', ['sales_stock_reservation_id' => $reservation->getKey(), 'action' => 'release', 'reason' => 'Reserved']);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_unfulfilled_or_fully_cancelled_order_cannot_be_closed(): void
    {
        $order = $this->confirmed();
        $service = app(SalesOrderService::class);
        $cancelled = $service->transition($this->creator, (int) $order->getKey(), 'cancel', (int) $order->getAttribute('revision'), 'Cancel all');
        $this->expectException(ValidationException::class);
        $service->transition($this->creator, (int) $order->getKey(), 'close', (int) $cancelled->getAttribute('revision'));
    }

    public function test_staff_without_action_permission_and_foreign_ids_are_protected(): void
    {
        $order = $this->draft();
        $actor = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $actor->getKey(), 'employee_number' => 'SO-STAFF', 'first_name' => 'Sales', 'last_name' => 'Staff']);
        $actor->givePermissionTo('erp.sales-orders.view');
        $this->actingAs($actor)->post('/erp/sales/orders/'.$order->getKey().'/submit', ['revision' => 1])->assertForbidden();
        $foreign = Company::query()->create(['name' => 'Foreign', 'code' => 'SO-B', 'currency_code' => 'BDT']);
        Employee::query()->where('user_id', $actor->getKey())->update(['company_id' => $foreign->getKey()]);
        $this->get('/erp/sales/orders/'.$order->getKey())->assertNotFound();
    }

    public function test_order_date_cannot_break_source_validity_or_number_year(): void
    {
        $quote = $this->quote();
        $this->expectException(ValidationException::class);
        app(SalesOrderService::class)->create($this->creator, [...$this->payload($quote), 'document_date' => now('Asia/Dhaka')->addDays(40)->toDateString()]);
    }

    public function test_credit_override_requires_confirm_permission_too(): void
    {
        $order = $this->approved();
        $actor = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $actor->getKey(), 'employee_number' => 'SO-OVERRIDE', 'first_name' => 'Sales', 'last_name' => 'Approver']);
        $actor->givePermissionTo('erp.sales-orders.credit-override');
        $this->expectException(AuthorizationException::class);
        app(SalesOrderService::class)->transition($actor, (int) $order->getKey(), 'credit-override', (int) $order->getAttribute('revision'), 'No confirm permission');
    }
}
