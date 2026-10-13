<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use App\Models\Employee;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierBillItem;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ERP\Purchase\PurchaseReturnService;
use App\Services\ERP\Purchase\SupplierBillService;
use App\Services\Inventory\InventoryService;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class SupplierBillManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(PurchasePermissionSeeder::class);
    }

    private function company(string $code = 'C-A'): Company
    {
        return Company::query()->create(['name' => $code, 'code' => $code, 'currency_code' => 'BDT', 'status' => 'active']);
    }

    private function admin(): User
    {
        $actor = User::factory()->create();
        $actor->assignRole('super-admin');

        return $actor;
    }

    /** @param list<string> $permissions */
    private function employee(Company $company, array $permissions): User
    {
        $actor = User::factory()->create();
        Employee::query()->create(['user_id' => $actor->id, 'company_id' => $company->id, 'employee_number' => 'EMP-'.$actor->id, 'first_name' => 'Test', 'last_name' => 'Employee']);
        $actor->givePermissionTo($permissions);

        return $actor;
    }

    /** @return array{User, Company, Warehouse, Product, PurchaseOrder, PurchaseOrderItem} */
    private function fixture(bool $stock = true, string $code = 'C-A', string $currency = 'BDT'): array
    {
        $actor = $this->admin();
        $company = $this->company($code);
        $company->update(['currency_code' => $currency]);
        $warehouse = Warehouse::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'WH-1', 'is_active' => true]);
        $unit = Unit::query()->create(['company_id' => $company->id, 'name' => 'Piece', 'short_name' => 'pc']);
        $product = Product::query()->create(['company_id' => $company->id, 'unit_id' => $unit->id, 'name' => 'বাংলা পণ্য', 'is_active' => true, 'product_type' => $stock ? 'physical' : 'service', 'track_inventory' => $stock, 'has_variants' => false]);
        $supplier = Supplier::query()->create(['company_id' => $company->id, 'code' => 'S-1', 'name' => 'Supplier', 'currency_code' => $currency, 'status' => 'active']);
        $order = PurchaseOrder::query()->create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'created_by' => $actor->id, 'number' => 'PO-TEST', 'status' => 'ordered', 'currency_code' => $currency, 'subtotal' => '100.00', 'discount_total' => '10.00', 'tax_total' => '5.00', 'grand_total' => '95.00']);
        $item = PurchaseOrderItem::query()->create(['purchase_order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => '10.0000', 'received_quantity' => '0.0000', 'unit_price' => '10.00', 'discount_amount' => '10.00', 'tax_amount' => '5.00', 'line_total' => '95.00']);
        $this->actingAs($actor);

        return [$actor, $company, $warehouse, $product, $order, $item];
    }

    /** @return array<string, mixed> */
    private function data(Company $company, Warehouse $warehouse, PurchaseOrder $order, PurchaseOrderItem $item, string $accepted = '3.0000', string $rejected = '0.0000'): array
    {
        return ['company_id' => $company->id, 'purchase_order_id' => $order->id, 'warehouse_id' => $warehouse->id, 'received_at' => now()->subMinute()->format('Y-m-d\TH:i'), 'notes' => 'Inspection notes',
            'items' => [['purchase_order_item_id' => $item->id, 'quantity_received' => $accepted, 'quantity_rejected' => $rejected]]];
    }

    private function create(Company $company, Warehouse $warehouse, PurchaseOrder $order, PurchaseOrderItem $item, string $accepted = '3.0000', string $rejected = '0.0000'): GoodsReceipt
    {
        $this->post('/erp/purchase/receipts', $this->data($company, $warehouse, $order, $item, $accepted, $rejected))->assertSessionHasNoErrors();

        return GoodsReceipt::query()->latest('id')->firstOrFail();
    }

    private function postReceipt(GoodsReceipt $receipt): void
    {
        $this->post('/erp/purchase/receipts/'.$receipt->id.'/post', ['revision' => (int) $receipt->refresh()->getAttribute('revision')])->assertSessionHasNoErrors();
        $receipt->refresh();
    }

    /** @return array{User, Company, Warehouse, Product, PurchaseOrder, PurchaseOrderItem, GoodsReceipt, GoodsReceiptItem} */
    private function received(bool $stock = true, string $accepted = '6.0000', string $rejected = '2.0000', string $code = 'C-A', string $currency = 'BDT'): array
    {
        [$actor, $company, $warehouse, $product, $order, $item] = $this->fixture($stock, $code, $currency);
        $receipt = $this->create($company, $warehouse, $order, $item, $accepted, $rejected);
        $this->postReceipt($receipt);
        $source = GoodsReceiptItem::query()->where('goods_receipt_id', $receipt->id)->firstOrFail();

        return [$actor, $company, $warehouse, $product, $order, $item, $receipt, $source];
    }

    /** @return array<string, mixed> */
    private function billData(GoodsReceipt $receipt, GoodsReceiptItem $source, string $quantity = '2.0000', string $invoice = 'INV-001'): array
    {
        return ['goods_receipt_id' => $receipt->id, 'supplier_invoice_number' => $invoice, 'invoice_date' => now()->subDays(10)->toDateString(), 'due_date' => now()->addDays(20)->toDateString(),
            'notes' => 'Supplier invoice matched to PO terms', 'items' => [['goods_receipt_item_id' => $source->id, 'quantity' => $quantity]]];
    }

    private function draft(GoodsReceipt $receipt, GoodsReceiptItem $source, string $quantity = '2.0000', string $invoice = 'INV-001'): SupplierBill
    {
        $this->post('/erp/purchase/bills', $this->billData($receipt, $source, $quantity, $invoice))->assertSessionHasNoErrors();

        return SupplierBill::query()->latest('id')->firstOrFail();
    }

    private function approveBill(SupplierBill $bill): void
    {
        $this->actingAs($this->admin())->post('/erp/purchase/bills/'.$bill->id.'/approve', ['revision' => $bill->refresh()->getAttribute('revision')])->assertSessionHasNoErrors();
        $bill->refresh();
    }

    private function returnGoods(User $actor, GoodsReceipt $receipt, GoodsReceiptItem $source, string $quantity): void
    {
        $record = app(PurchaseReturnService::class)->create($actor, ['goods_receipt_id' => $receipt->id, 'reason' => 'Return to supplier', 'items' => [['goods_receipt_item_id' => $source->id, 'quantity' => $quantity]]]);
        $record = app(PurchaseReturnService::class)->approve($this->admin(), (int) $record->id, 1);
        app(PurchaseReturnService::class)->post($actor, (int) $record->id, (int) $record->getAttribute('revision'));
    }

    public function test_guests_and_users_without_permissions_are_denied(): void
    {
        $this->get('/erp/purchase/bills')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/erp/purchase/bills')->assertForbidden();
        $this->get('/erp/purchase/bills/create')->assertForbidden();
        $this->get('/erp/purchase/bills/payables')->assertForbidden();
        $this->post('/erp/purchase/bills', [])->assertForbidden();
    }

    public function test_draft_snapshots_po_terms_and_creates_no_payable_or_stock_movement(): void
    {
        [, , $warehouse, $product, , , $receipt, $source] = $this->received();
        $bill = $this->draft($receipt, $source);
        $this->assertStringStartsWith('BILL-', $bill->number);
        $this->assertSame('draft', $bill->status);
        $this->assertSame('20.00', $bill->subtotal);
        $this->assertSame('2.00', $bill->discount_total);
        $this->assertSame('1.00', $bill->tax_total);
        $this->assertSame('19.00', $bill->grand_total);
        $line = SupplierBillItem::query()->firstOrFail();
        $this->assertSame('10.00', $line->unit_price);
        $this->assertSame('2.0000', $line->quantity);
        $this->assertDatabaseCount('supplier_payables', 0);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame('6.0000', app(InventoryService::class)->quantityOnHand($warehouse, $product));
        $this->assertDatabaseCount('supplier_bill_audit_logs', 1);
    }

    public function test_independent_approval_creates_exactly_one_payable_and_retry_is_idempotent(): void
    {
        [, , $warehouse, $product, $order, $poLine, $receipt, $source] = $this->received();
        $bill = $this->draft($receipt, $source);
        $this->post('/erp/purchase/bills/'.$bill->id.'/approve', ['revision' => 1])->assertSessionHasErrors('approval');
        $this->approveBill($bill);
        $this->assertSame('approved', $bill->status);
        $this->assertDatabaseHas('supplier_payables', ['supplier_bill_id' => $bill->id, 'company_id' => $bill->company_id, 'supplier_id' => $bill->supplier_id, 'currency_code' => 'BDT', 'amount' => 19, 'paid_amount' => 0]);
        $this->post('/erp/purchase/bills/'.$bill->id.'/approve', ['revision' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('supplier_payables', 1);
        $this->assertDatabaseCount('supplier_bill_audit_logs', 2);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame('6.0000', app(InventoryService::class)->quantityOnHand($warehouse, $product));
        $this->assertSame('6.0000', $poLine->refresh()->received_quantity);
        $this->assertSame('partially_received', $order->refresh()->status);
    }

    public function test_returns_before_billing_reduce_available_invoice_quantity(): void
    {
        [$actor, , , , , , $receipt, $source] = $this->received();
        $this->returnGoods($actor, $receipt, $source, '2.0000');
        $this->post('/erp/purchase/bills', $this->billData($receipt, $source, '4.0001'))->assertSessionHasErrors('items.0.quantity');
        $bill = $this->draft($receipt, $source, '4.0000');
        $this->approveBill($bill);
        $this->assertSame('38.00', $bill->grand_total);
        $this->get('/erp/purchase/bills/create?receipt_id='.$receipt->id)->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Bills/Form')->where('sourceLines.0.remaining', '0.0000')->where('sourceLines.0.returned', '2.0000')->where('sourceLines.0.billed', '4.0000'));
    }

    public function test_cumulative_approved_billing_cannot_exceed_accepted_quantity(): void
    {
        [, , , , , , $receipt, $source] = $this->received();
        $first = $this->draft($receipt, $source, '4.0000');
        $this->approveBill($first);
        $this->post('/erp/purchase/bills', $this->billData($receipt, $source, '2.0001', 'INV-002'))->assertSessionHasErrors('items.0.quantity');
        $second = $this->draft($receipt, $source, '2.0000', 'INV-002');
        $this->approveBill($second);
        $this->assertDatabaseCount('supplier_payables', 2);
        $this->post('/erp/purchase/bills', $this->billData($receipt, $source, '0.0001', 'INV-003'))->assertSessionHasErrors('items.0.quantity');
    }

    public function test_competing_drafts_recheck_capacity_at_approval(): void
    {
        [, , , , , , $receipt, $source] = $this->received();
        $first = $this->draft($receipt, $source, '4.0000');
        $second = $this->draft($receipt, $source, '4.0000', 'INV-002');
        $this->approveBill($first);
        $this->post('/erp/purchase/bills/'.$second->id.'/approve', ['revision' => 1])->assertSessionHasErrors('items.0.quantity');
        $this->assertSame('draft', $second->refresh()->status);
        $this->assertDatabaseCount('supplier_payables', 1);
    }

    public function test_return_after_draft_blocks_excess_approval(): void
    {
        [$actor, , , , , , $receipt, $source] = $this->received();
        $bill = $this->draft($receipt, $source, '6.0000');
        $this->returnGoods($actor, $receipt, $source, '2.0000');
        $this->actingAs($this->admin())->post('/erp/purchase/bills/'.$bill->id.'/approve', ['revision' => 1])->assertSessionHasErrors('items.0.quantity');
        $this->assertDatabaseCount('supplier_payables', 0);
    }

    public function test_return_after_approval_flags_credit_review_without_reducing_payable(): void
    {
        [$actor, , , , , , $receipt, $source] = $this->received();
        $bill = $this->draft($receipt, $source, '6.0000');
        $this->approveBill($bill);
        $this->returnGoods($actor, $receipt, $source, '2.0000');
        $this->get('/erp/purchase/bills/'.$bill->id)->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Bills/Show')->where('items.0.credit_review_quantity', '2.0000')->where('payable.outstanding', '57.00'));
        $this->assertSame('57.00', $bill->refresh()->grand_total);
        $this->post('/erp/purchase/bills/'.$bill->id.'/approve', ['revision' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('supplier_payables', 1);
    }

    public function test_invoice_identity_is_case_and_whitespace_normalized_and_never_reused(): void
    {
        [, , , , , , $receipt, $source] = $this->received();
        $bill = $this->draft($receipt, $source, '2', ' inv   001 ');
        $this->assertSame('INV 001', $bill->supplier_invoice_number);
        $this->post('/erp/purchase/bills', $this->billData($receipt, $source, '2', 'Inv 001'))->assertSessionHasErrors('supplier_invoice_number');
        $this->post('/erp/purchase/bills/'.$bill->id.'/cancel', ['revision' => 1, 'reason' => 'Incorrect invoice'])->assertSessionHasNoErrors();
        $this->post('/erp/purchase/bills', $this->billData($receipt, $source, '2', 'INV 001'))->assertSessionHasErrors('supplier_invoice_number');
        $this->assertDatabaseCount('supplier_bills', 1);
    }

    public function test_legacy_noncanonical_invoice_number_is_detected_as_duplicate(): void
    {
        [, , , , , , $receipt, $source] = $this->received();
        $bill = $this->draft($receipt, $source);
        $bill->update(['supplier_invoice_number' => ' legacy   invoice ']);
        $this->post('/erp/purchase/bills', $this->billData($receipt, $source, '2', 'LEGACY INVOICE'))->assertSessionHasErrors('supplier_invoice_number');
    }

    public function test_draft_edit_stale_revision_and_cancellation_do_not_create_liability(): void
    {
        [, , , , , , $receipt, $source] = $this->received();
        $bill = $this->draft($receipt, $source);
        $data = $this->billData($receipt, $source, '3');
        unset($data['goods_receipt_id']);
        $data['revision'] = 1;
        $this->put('/erp/purchase/bills/'.$bill->id, $data)->assertSessionHasNoErrors();
        $this->assertSame('28.50', $bill->refresh()->grand_total);
        $this->put('/erp/purchase/bills/'.$bill->id, $data)->assertSessionHasErrors('revision');
        $this->post('/erp/purchase/bills/'.$bill->id.'/cancel', ['revision' => 1, 'reason' => 'Stale'])->assertSessionHasErrors('revision');
        $this->post('/erp/purchase/bills/'.$bill->id.'/cancel', ['revision' => 2, 'reason' => 'Invoice withdrawn'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $bill->refresh()->status);
        $this->actingAs($this->admin())->post('/erp/purchase/bills/'.$bill->id.'/approve', ['revision' => 3])->assertSessionHasErrors('status');
        $this->assertDatabaseCount('supplier_payables', 0);
    }

    public function test_approved_bills_cannot_be_edited_or_cancelled(): void
    {
        [, , , , , , $receipt, $source] = $this->received();
        $bill = $this->draft($receipt, $source);
        $this->approveBill($bill);
        $this->get('/erp/purchase/bills/'.$bill->id.'/edit')->assertForbidden();
        $data = $this->billData($receipt, $source, '1');
        unset($data['goods_receipt_id']);
        $this->put('/erp/purchase/bills/'.$bill->id, [...$data, 'revision' => 2])->assertSessionHasErrors('status');
        $this->post('/erp/purchase/bills/'.$bill->id.'/cancel', ['revision' => 2, 'reason' => 'Undo'])->assertSessionHasErrors('status');
        $this->assertDatabaseCount('supplier_payables', 1);
    }

    public function test_company_scope_protects_sources_bills_actions_and_payables(): void
    {
        [, , , , , , $receipt, $source] = $this->received();
        $bill = $this->draft($receipt, $source);
        $this->approveBill($bill);
        $other = $this->company('C-B');
        $actor = $this->employee($other, ['erp.supplier-bills.view', 'erp.supplier-bills.create', 'erp.supplier-bills.edit', 'erp.supplier-bills.approve', 'erp.supplier-bills.cancel', 'erp.supplier-payables.view']);
        $this->actingAs($actor)->get('/erp/purchase/bills/'.$bill->id)->assertNotFound();
        $this->get('/erp/purchase/bills/create?receipt_id='.$receipt->id)->assertNotFound();
        $this->post('/erp/purchase/bills', $this->billData($receipt, $source))->assertNotFound();
        $this->post('/erp/purchase/bills/'.$bill->id.'/approve', ['revision' => 2])->assertNotFound();
        $this->post('/erp/purchase/bills/'.$bill->id.'/cancel', ['revision' => 2, 'reason' => 'Other'])->assertNotFound();
        $this->get('/erp/purchase/bills')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Bills/Index')->has('bills.data', 0));
        $this->get('/erp/purchase/bills/payables')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Bills/Payables')->has('payables.data', 0)->has('summary', 0));
        $this->get('/erp/purchase/bills/payables?company_id='.$bill->company_id)->assertNotFound();
        $this->get('/erp/purchase/bills/payables?supplier_id='.$bill->supplier_id)->assertNotFound();
    }

    public function test_rejected_and_unposted_receipts_are_not_billable(): void
    {
        [, $company, $warehouse, , $order, $poLine] = $this->fixture();
        $receipt = $this->create($company, $warehouse, $order, $poLine, '0', '5');
        $source = GoodsReceiptItem::query()->firstOrFail();
        $this->post('/erp/purchase/bills', $this->billData($receipt, $source, '1'))->assertNotFound();
        $this->postReceipt($receipt);
        $this->post('/erp/purchase/bills', $this->billData($receipt, $source, '1'))->assertSessionHasErrors('items.0.quantity');
        $this->assertDatabaseCount('supplier_bills', 0);
    }

    public function test_nonstock_goods_can_create_payable_without_stock_entries(): void
    {
        [, , , , , , $receipt, $source] = $this->received(false);
        $bill = $this->draft($receipt, $source);
        $this->approveBill($bill);
        $this->assertDatabaseCount('supplier_payables', 1);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame('19.00', $bill->grand_total);
    }

    public function test_client_cannot_inject_source_currency_price_or_totals(): void
    {
        [, $company, , , , , $receipt, $source] = $this->received();
        $data = $this->billData($receipt, $source);
        $this->post('/erp/purchase/bills', [...$data, 'company_id' => $company->id, 'currency_code' => 'USD', 'grand_total' => 1, 'status' => 'approved'])->assertSessionHasErrors(['company_id', 'currency_code', 'grand_total', 'status']);
        $data['items'][0]['unit_price'] = '1.00';
        $this->post('/erp/purchase/bills', $data)->assertSessionHasErrors('items.0');
        $this->assertDatabaseCount('supplier_bills', 0);
    }

    public function test_dates_and_quantity_precision_are_validated(): void
    {
        [, , , , , , $receipt, $source] = $this->received();
        $data = $this->billData($receipt, $source);
        $this->post('/erp/purchase/bills', [...$data, 'invoice_date' => now()->addDay()->toDateString()])->assertSessionHasErrors('invoice_date');
        $this->post('/erp/purchase/bills', [...$data, 'due_date' => now()->subDays(11)->toDateString()])->assertSessionHasErrors('due_date');
        $this->post('/erp/purchase/bills', $this->billData($receipt, $source, '1.00001'))->assertSessionHasErrors('items.0.quantity');
        $this->post('/erp/purchase/bills', $this->billData($receipt, $source, '0'))->assertSessionHasErrors('items');
        $data['items'][] = $data['items'][0];
        $this->post('/erp/purchase/bills', $data)->assertSessionHasErrors('items.0.goods_receipt_item_id');
    }

    public function test_changed_po_terms_and_corrupted_saved_totals_block_approval(): void
    {
        [, , , , , $poLine, $receipt, $source] = $this->received();
        $bill = $this->draft($receipt, $source);
        $poLine->update(['unit_price' => '11.00']);
        $this->actingAs($this->admin())->post('/erp/purchase/bills/'.$bill->id.'/approve', ['revision' => 1])->assertSessionHasErrors('items');
        $poLine->update(['unit_price' => '10.00']);
        $bill->update(['grand_total' => '1.00']);
        $this->post('/erp/purchase/bills/'.$bill->id.'/approve', ['revision' => 1])->assertSessionHasErrors('totals');
        $this->assertDatabaseCount('supplier_payables', 0);
    }

    public function test_failure_after_payable_insert_rolls_back_bill_payable_and_audit(): void
    {
        [, , , , , , $receipt, $source] = $this->received();
        $bill = $this->draft($receipt, $source);
        $actor = $this->admin();
        DB::listen(function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'insert into') && str_contains($query->sql, 'supplier_bill_audit_logs') && in_array('approved', $query->bindings, true)) {
                throw ValidationException::withMessages(['audit' => 'Simulated approval audit failure']);
            }
        });
        try {
            app(SupplierBillService::class)->approve($actor, (int) $bill->id, 1);
            $this->fail('Expected approval failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('audit', $exception->errors());
            $this->assertSame('draft', $bill->refresh()->status);
            $this->assertSame(1, $bill->getAttribute('revision'));
            $this->assertDatabaseCount('supplier_payables', 0);
            $this->assertDatabaseCount('supplier_bill_audit_logs', 1);
        }
    }

    public function test_payables_show_outstanding_overdue_and_null_due_dates(): void
    {
        [, , , , , , $receipt, $source] = $this->received();
        $first = $this->draft($receipt, $source);
        $first->update(['due_date' => now()->subDay()->toDateString()]);
        $this->approveBill($first);
        $second = $this->draft($receipt, $source, '2', 'INV-002');
        $second->update(['due_date' => null]);
        $this->approveBill($second);
        $this->get('/erp/purchase/bills/payables')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Bills/Payables')->has('payables.data', 2)->has('summary', 1)->where('summary.0.outstanding', '38.00')->where('summary.0.overdue', '19.00')->where('summary.0.currency_code', 'BDT'));
        $this->get('/erp/purchase/bills/payables?due=overdue')->assertOk()->assertInertia(fn (Assert $page) => $page->has('payables.data', 1)->where('summary.0.outstanding', '19.00')->where('payables.data.0.overdue', true));
        $this->get('/erp/purchase/bills/payables?due=undated')->assertOk()->assertInertia(fn (Assert $page) => $page->has('payables.data', 1)->where('summary.0.overdue', '0.00'));
    }

    public function test_page_components_exist_and_sources_are_preloaded(): void
    {
        [, $company, , , , , $receipt, $source] = $this->received();
        $this->get('/erp/purchase/bills/create?receipt_id='.$receipt->id)->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Bills/Form')->where('companyId', $company->id)->has('sourceLines', 1)->where('sourceLines.0.product_name', 'বাংলা পণ্য')->where('sourceLines.0.remaining', '6.0000'));
        $bill = $this->draft($receipt, $source);
        $this->get('/erp/purchase/bills/'.$bill->id)->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Bills/Show')->has('items', 1)->has('history', 1)->where('payable', null));
        $this->get('/erp/purchase/bills/'.$bill->id.'/edit')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Bills/Form')->has('billItems', 1));
        $this->get('/erp/purchase/bills')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Bills/Index')->has('bills.data', 1));
        $this->get('/erp/purchase/bills/payables')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Bills/Payables')->has('payables.data', 0));
    }

    public function test_payable_balances_keep_companies_and_currencies_separate(): void
    {
        [, , , , , , $firstReceipt, $firstSource] = $this->received();
        $first = $this->draft($firstReceipt, $firstSource);
        $this->approveBill($first);
        [, , , , , , $secondReceipt, $secondSource] = $this->received(true, '6', '2', 'C-B', 'USD');
        $second = $this->draft($secondReceipt, $secondSource);
        $this->approveBill($second);
        $this->get('/erp/purchase/bills/payables')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Bills/Payables')->has('payables.data', 2)->has('summary', 2)->where('summary.0.currency_code', 'BDT')->where('summary.0.outstanding', '19.00')->where('summary.1.currency_code', 'USD')->where('summary.1.outstanding', '19.00'));
        $this->get('/erp/purchase/bills/payables?company_id='.$second->company_id)->assertOk()->assertInertia(fn (Assert $page) => $page->has('summary', 1)->where('summary.0.currency_code', 'USD')->has('payables.data', 1));
    }

    public function test_payables_subtract_recorded_paid_amount_and_treat_due_today_as_current(): void
    {
        [, , , , , , $receipt, $source] = $this->received();
        $bill = $this->draft($receipt, $source);
        $bill->update(['due_date' => now()->toDateString()]);
        $this->approveBill($bill);
        // Simulate future accounting integration's recorded settlement; this milestone exposes no payment endpoint.
        DB::table('supplier_payables')->where('supplier_bill_id', $bill->id)->update(['paid_amount' => '4.00']);
        $this->get('/erp/purchase/bills/payables?due=current')->assertOk()->assertInertia(fn (Assert $page) => $page->has('payables.data', 1)->where('summary.0.outstanding', '15.00')->where('summary.0.overdue', '0.00')->where('payables.data.0.outstanding', '15.00'));
        DB::table('supplier_payables')->where('supplier_bill_id', $bill->id)->update(['paid_amount' => '19.00']);
        $this->get('/erp/purchase/bills/payables')->assertOk()->assertInertia(fn (Assert $page) => $page->has('payables.data', 0)->has('summary', 0));
    }

    public function test_invoice_line_from_another_grn_is_rejected(): void
    {
        [, $company, $warehouse, , $order, $poLine, $receipt] = $this->received();
        $secondReceipt = $this->create($company, $warehouse, $order, $poLine, '2', '0');
        $this->postReceipt($secondReceipt);
        $otherSource = GoodsReceiptItem::query()->where('goods_receipt_id', $secondReceipt->id)->firstOrFail();
        $this->post('/erp/purchase/bills', $this->billData($receipt, $otherSource))->assertNotFound();
        $this->assertDatabaseCount('supplier_bills', 0);
    }

    public function test_fractional_quantity_prorates_discount_and_tax_to_cents(): void
    {
        [, $company, $warehouse, , $order, $poLine] = $this->fixture();
        $poLine->update(['quantity' => '3.0000', 'unit_price' => '6.00', 'discount_amount' => '1.00', 'tax_amount' => '2.00', 'line_total' => '19.00']);
        $order->update(['subtotal' => '18.00', 'discount_total' => '1.00', 'tax_total' => '2.00', 'grand_total' => '19.00']);
        $receipt = $this->create($company, $warehouse, $order, $poLine, '3', '0');
        $this->postReceipt($receipt);
        $source = GoodsReceiptItem::query()->where('goods_receipt_id', $receipt->id)->firstOrFail();
        $bill = $this->draft($receipt, $source, '1');
        $this->assertSame('6.00', $bill->subtotal);
        $this->assertSame('0.33', $bill->discount_total);
        $this->assertSame('0.67', $bill->tax_total);
        $this->assertSame('6.34', $bill->grand_total);
        $this->approveBill($bill);
        $this->assertDatabaseHas('supplier_payables', ['supplier_bill_id' => $bill->id, 'amount' => 6.34]);
    }

    public function test_closed_order_and_inactive_supplier_remain_billable_for_existing_receipts(): void
    {
        [, , , , $order, , $receipt, $source] = $this->received(true, '10', '0');
        $order->update(['status' => 'closed']);
        Supplier::query()->whereKey($order->supplier_id)->update(['status' => 'inactive']);
        $bill = $this->draft($receipt, $source, '10');
        $this->approveBill($bill);
        $this->assertSame('approved', $bill->status);
        $this->assertSame('closed', $order->refresh()->status);
        $this->assertSame('95.00', $bill->grand_total);
    }

    public function test_missing_payable_on_legacy_approved_bill_is_not_recreated_silently(): void
    {
        [, , , , , , $receipt, $source] = $this->received();
        $bill = $this->draft($receipt, $source);
        $bill->update(['status' => 'approved']);
        $this->actingAs($this->admin())->post('/erp/purchase/bills/'.$bill->id.'/approve', ['revision' => 1])->assertSessionHasErrors('payable');
        $this->assertDatabaseCount('supplier_payables', 0);
    }

    public function test_invoice_dates_serialize_without_timezone_day_shift(): void
    {
        config(['app.timezone' => 'Asia/Dhaka']);
        [, , , , , , $receipt, $source] = $this->received();
        $bill = $this->draft($receipt, $source);
        $this->get('/erp/purchase/bills/'.$bill->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('bill.invoice_date', $bill->getRawOriginal('invoice_date'))->where('bill.due_date', $bill->getRawOriginal('due_date')));
    }

    public function test_zero_value_bill_cannot_create_payable(): void
    {
        [, $company, $warehouse, , $order, $poLine] = $this->fixture();
        $poLine->update(['unit_price' => '0.00', 'discount_amount' => '0.00', 'tax_amount' => '0.00', 'line_total' => '0.00']);
        $receipt = $this->create($company, $warehouse, $order, $poLine);
        $this->postReceipt($receipt);
        $source = GoodsReceiptItem::query()->where('goods_receipt_id', $receipt->id)->firstOrFail();
        $bill = $this->draft($receipt, $source);
        $this->actingAs($this->admin())->post('/erp/purchase/bills/'.$bill->id.'/approve', ['revision' => 1])->assertSessionHasErrors('grand_total');
        $this->assertDatabaseCount('supplier_payables', 0);
    }
}
