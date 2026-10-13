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
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ERP\Purchase\PurchaseReturnService;
use App\Services\Inventory\InventoryService;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class PurchaseReportManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(PurchasePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 14)->setTime(12, 0));
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
        $order = PurchaseOrder::query()->create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'created_by' => $actor->id, 'number' => 'PO-TEST', 'status' => 'ordered', 'ordered_at' => now()->subMinute()->toDateTimeString(), 'currency_code' => $currency, 'subtotal' => '100.00', 'discount_total' => '10.00', 'tax_total' => '5.00', 'grand_total' => '95.00']);
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

    /** @return array<string, string> */
    private function filters(string $type = 'orders'): array
    {
        return ['from_date' => '2026-10-01', 'to_date' => '2026-10-14', 'type' => $type];
    }

    private function url(string $type = 'orders', string $path = 'reports'): string
    {
        return '/erp/purchase/'.$path.'?'.http_build_query($this->filters($type));
    }

    public function test_guest_and_missing_report_permission_are_denied(): void
    {
        $this->get('/erp/purchase/dashboard')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/erp/purchase/dashboard')->assertForbidden();
        $this->get('/erp/purchase/reports')->assertForbidden();
        $this->get('/erp/purchase/reports/export')->assertForbidden();
    }

    public function test_dashboard_reconciles_receipt_return_bill_and_payable_values(): void
    {
        [$actor, , , , , , $receipt, $source] = $this->received();
        $bill = $this->draft($receipt, $source);
        $bill->update(['due_date' => '2026-10-13']);
        $this->approveBill($bill);
        $this->returnGoods($actor, $receipt, $source, '2');
        $this->get($this->url('orders', 'dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Reports/Dashboard')
            ->where('counts.orders', 1)->where('counts.receipts', 1)->where('counts.returns', 1)->where('counts.bills', 1)
            ->has('money', 1)->where('money.0.order_value', '95.00')->where('money.0.receipt_stock_value', '54.0000')->where('money.0.return_stock_value', '18.0000')
            ->where('money.0.bill_value', '19.00')->where('money.0.outstanding', '19.00')->where('money.0.overdue', '19.00')
            ->where('supplierSummary.0.bill_count', 1)->where('supplierSummary.0.bill_value', '19.00')->where('months.0.month', '2026-10')->where('months.0.bill_value', '19.00')
            ->where('issues.stock_links', 0)->where('issues.payable_links', 0)->where('current.credit_review_source_lines', 0));
    }

    public function test_period_counts_do_not_double_count_multiline_receipts(): void
    {
        [, $company, $warehouse, $product, $order, $poLine] = $this->fixture();
        $second = PurchaseOrderItem::query()->create(['purchase_order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Another line', 'quantity' => 10, 'received_quantity' => 0, 'unit_price' => 10, 'line_total' => 100]);
        $data = $this->data($company, $warehouse, $order, $poLine, '3', '0');
        $data['items'][] = ['purchase_order_item_id' => $second->id, 'quantity_received' => '2', 'quantity_rejected' => '0'];
        $this->post('/erp/purchase/receipts', $data)->assertSessionHasNoErrors();
        $receipt = GoodsReceipt::query()->latest('id')->firstOrFail();
        $this->postReceipt($receipt);
        $this->get($this->url('orders', 'dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('counts.receipts', 1)->where('money.0.receipt_stock_value', '47.0000'));
        $this->get($this->url('receipts'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Reports/Index')->has('rows.data', 2)->where('rows.total', 2));
    }

    public function test_report_page_and_every_dataset_resolve(): void
    {
        [$actor, , , , , , $receipt, $source] = $this->received();
        $bill = $this->draft($receipt, $source);
        $this->approveBill($bill);
        $this->returnGoods($actor, $receipt, $source, '1');
        foreach (['orders', 'receipts', 'returns', 'bills', 'payables'] as $type) {
            $this->get($this->url($type))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Reports/Index')->where('filters.type', $type)->has('rows.data', 1)->has('columns'));
        }
    }

    public function test_company_scope_applies_to_pages_filters_and_csv(): void
    {
        [, , , , , , $receipt, $source] = $this->received();
        $bill = $this->draft($receipt, $source);
        $this->approveBill($bill);
        $other = $this->company('C-B');
        $actor = $this->employee($other, ['erp.purchase-reports.view', 'erp.purchase-reports.export']);
        $this->actingAs($actor)->get($this->url('orders', 'dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('counts.orders', 0)->has('money', 0)->has('suppliers', 0));
        foreach (['orders', 'receipts', 'returns', 'bills', 'payables'] as $type) {
            $this->get($this->url($type))->assertOk()->assertInertia(fn (Assert $page) => $page->has('rows.data', 0));
            $csv = $this->get($this->url($type, 'reports/export'))->assertOk()->streamedContent();
            $this->assertStringNotContainsString($bill->supplier_invoice_number, $csv);
            $this->assertStringNotContainsString('বাংলা পণ্য', $csv);
        }
        $this->get($this->url().'&company_id='.$bill->company_id)->assertNotFound();
        $this->get($this->url().'&supplier_id='.$bill->supplier_id)->assertNotFound();
        $this->get($this->url('orders', 'reports/export').'&company_id='.$bill->company_id)->assertNotFound();
    }

    public function test_export_requires_both_view_and_export_permissions(): void
    {
        [, $company] = $this->received();
        $reader = $this->employee($company, ['erp.purchase-reports.view']);
        $this->actingAs($reader)->get($this->url())->assertOk();
        $this->get($this->url('orders', 'reports/export'))->assertForbidden();
        $exporter = $this->employee($company, ['erp.purchase-reports.export']);
        $this->actingAs($exporter)->get($this->url('orders', 'reports/export'))->assertForbidden();
    }

    public function test_dates_and_report_type_are_validated(): void
    {
        $this->actingAs($this->admin());
        $this->get('/erp/purchase/reports?from_date=2026-10-14&to_date=2026-10-01')->assertSessionHasErrors('from_date');
        $this->get('/erp/purchase/reports?from_date=2026-10-01&to_date=2026-10-15')->assertSessionHasErrors('to_date');
        $this->get('/erp/purchase/reports?from_date=2026-02-30&to_date=2026-10-14')->assertSessionHasErrors('from_date');
        $this->get($this->url().'&currency=bdt')->assertSessionHasErrors('currency');
        $this->get('/erp/purchase/reports?type=unknown')->assertSessionHasErrors('type');
        $this->get('/erp/purchase/reports/export?type=unknown')->assertSessionHasErrors('type');
    }

    public function test_current_work_queue_is_not_filtered_by_period(): void
    {
        [$actor, $company, $warehouse, , $order, $poLine, $receipt, $source] = $this->received();
        $pending = $order->replicate();
        $pending->fill(['number' => 'PO-SUBMITTED', 'status' => 'submitted', 'ordered_at' => null, 'expected_delivery_at' => null])->save();
        $this->create($company, $warehouse, $order, $poLine, '1', '0');
        app(PurchaseReturnService::class)->create($actor, ['goods_receipt_id' => $receipt->id, 'reason' => 'Draft return', 'items' => [['goods_receipt_item_id' => $source->id, 'quantity' => '1']]]);
        $this->draft($receipt, $source, '1');
        $order->update(['expected_delivery_at' => '2026-10-13']);
        $this->get('/erp/purchase/dashboard?from_date=2026-09-01&to_date=2026-09-30')->assertOk()->assertInertia(fn (Assert $page) => $page->where('counts.orders', 0)->where('counts.receipts', 0)
            ->where('current.awaiting_order_approval', 1)->where('current.awaiting_receiving', 1)->where('current.draft_receipts', 1)->where('current.unposted_returns', 1)->where('current.draft_bills', 1)->where('current.late_open_orders', 1));
    }

    public function test_draft_documents_do_not_enter_activity_values(): void
    {
        [, $company, $warehouse, , $order, $poLine] = $this->fixture();
        $order->update(['status' => 'draft', 'ordered_at' => null]);
        $this->get($this->url('orders', 'dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('counts.orders', 0)->has('money', 0));
        $order->update(['status' => 'ordered', 'ordered_at' => now()->toDateTimeString()]);
        $this->create($company, $warehouse, $order, $poLine);
        $this->get($this->url('orders', 'dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('counts.receipts', 0)->where('money.0.receipt_stock_value', '0.0000')->where('money.0.bill_value', '0.00'));
    }

    public function test_period_uses_receiving_release_posting_and_approval_dates(): void
    {
        [$actor, , , , $order, , $receipt, $source] = $this->received();
        $bill = $this->draft($receipt, $source);
        $this->approveBill($bill);
        $this->returnGoods($actor, $receipt, $source, '1');
        $order->update(['ordered_at' => '2026-09-30 23:59:59']);
        $receipt->update(['received_at' => '2026-09-30 23:59:59', 'posted_at' => '2026-10-14 12:00:00']);
        $bill->update(['approved_at' => '2026-09-30 23:59:59', 'invoice_date' => '2026-10-01']);
        $this->get($this->url('orders', 'dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('counts.orders', 0)->where('counts.receipts', 0)->where('counts.bills', 0)->where('counts.returns', 1)
            ->where('money.0.bill_value', '0.00')->where('money.0.return_stock_value', '9.0000')->where('money.0.outstanding', '19.00'));
        $this->get($this->url('payables'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('rows.data', 1));
    }

    public function test_period_boundaries_are_inclusive_in_application_timezone(): void
    {
        config(['app.timezone' => 'Asia/Dhaka']);
        [, , , , $order] = $this->received();
        $order->update(['ordered_at' => '2026-10-01 00:00:00']);
        $this->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->has('rows.data', 1)->where('timezone', 'Asia/Dhaka'));
        $order->update(['ordered_at' => '2026-10-14 23:59:59']);
        $this->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->has('rows.data', 1));
        $order->update(['ordered_at' => '2026-09-30 23:59:59']);
        $this->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->has('rows.data', 0));
    }

    public function test_missing_dates_are_flagged_without_inventing_period_activity(): void
    {
        [, , , , $order, , $receipt, $source] = $this->received();
        $bill = $this->draft($receipt, $source);
        $this->approveBill($bill);
        $order->update(['ordered_at' => null]);
        $bill->update(['approved_at' => null]);
        $receipt->update(['received_at' => null]);
        $this->get($this->url('orders', 'dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('counts.orders', 0)->where('counts.receipts', 0)->where('counts.bills', 0)
            ->where('current.orders_without_release_date', 1)->where('current.receipts_without_received_date', 1)->where('current.bills_without_approved_date', 1)->where('money.0.outstanding', '19.00'));
    }

    public function test_missing_stock_link_is_visible_but_not_counted_as_inventory_value(): void
    {
        [, , , , , , , $source] = $this->received();
        $source->update(['stock_movement_id' => null]);
        $this->get($this->url('receipts'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.data.0.integrity', 'review_stock_link')->where('rows.data.0.amount', null));
        $this->get($this->url('orders', 'dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('counts.receipts', 1)->where('issues.stock_links', 1)->where('money.0.receipt_stock_value', '0.0000'));
    }

    public function test_mismatched_ledger_total_is_not_aggregated(): void
    {
        [, , , , , , , $source] = $this->received();
        StockMovement::query()->whereKey($source->stock_movement_id)->update(['total_cost' => '999.0000']);
        $this->get($this->url('receipts'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.data.0.integrity', 'review_stock_link')->where('rows.data.0.amount', null));
    }

    public function test_nonstock_receipts_have_zero_stock_value(): void
    {
        [, , , , , , $receipt, $source] = $this->received(false);
        $bill = $this->draft($receipt, $source);
        $this->approveBill($bill);
        $this->get($this->url('receipts'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.data.0.integrity', 'non_stock')->where('rows.data.0.amount', '0.0000')->where('rows.data.0.quantity', '6.0000'));
        $this->get($this->url('orders', 'dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('money.0.receipt_stock_value', '0.0000')->where('money.0.bill_value', '19.00')->where('issues.stock_links', 0));
    }

    public function test_invalid_payable_link_is_flagged_and_excluded_from_current_balance(): void
    {
        [, , , , , , $receipt, $source] = $this->received();
        $bill = $this->draft($receipt, $source);
        $this->approveBill($bill);
        DB::table('supplier_payables')->where('supplier_bill_id', $bill->id)->update(['amount' => '999.00']);
        $this->get($this->url('bills'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.data.0.integrity', 'review_payable_link')->where('rows.data.0.amount', '19.00'));
        $this->get($this->url('orders', 'dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('issues.payable_links', 1)->where('current.approved_bills_without_valid_payable', 1)->where('money.0.outstanding', '0.00')->where('money.0.bill_value', '19.00'));
    }

    public function test_returns_after_full_billing_flag_current_credit_review(): void
    {
        [$actor, , , , , , $receipt, $source] = $this->received();
        $bill = $this->draft($receipt, $source, '6');
        $this->approveBill($bill);
        $this->returnGoods($actor, $receipt, $source, '2');
        $this->get($this->url('orders', 'dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('current.credit_review_source_lines', 1)->where('money.0.bill_value', '57.00')->where('money.0.outstanding', '57.00'));
    }

    public function test_currency_and_company_filters_keep_values_separate(): void
    {
        [, , , , , , $firstReceipt, $firstSource] = $this->received();
        $firstBill = $this->draft($firstReceipt, $firstSource);
        $this->approveBill($firstBill);
        [, , , , , , $secondReceipt, $secondSource] = $this->received(true, '6', '2', 'C-B', 'USD');
        $secondBill = $this->draft($secondReceipt, $secondSource);
        $this->approveBill($secondBill);
        $this->get($this->url('orders', 'dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('money', 2)->has('supplierSummary', 2)->has('months', 2));
        $this->get($this->url('orders', 'dashboard').'&currency=USD')->assertOk()->assertInertia(fn (Assert $page) => $page->has('money', 1)->where('money.0.currency_code', 'USD')->where('counts.orders', 1)->where('money.0.bill_value', '19.00'));
        $this->get($this->url('bills').'&company_id='.$firstBill->company_id)->assertOk()->assertInertia(fn (Assert $page) => $page->has('rows.data', 1)->where('rows.data.0.currency_code', 'BDT'));
        $this->get($this->url('bills').'&supplier_id='.$secondBill->supplier_id)->assertOk()->assertInertia(fn (Assert $page) => $page->has('rows.data', 1)->where('rows.data.0.document_id', $secondBill->id));
    }

    public function test_paid_balances_and_due_today_are_read_correctly(): void
    {
        [, , , , , , $receipt, $source] = $this->received();
        $bill = $this->draft($receipt, $source);
        $bill->update(['due_date' => '2026-10-14']);
        $this->approveBill($bill);
        DB::table('supplier_payables')->where('supplier_bill_id', $bill->id)->update(['paid_amount' => '4.00']);
        $this->get($this->url('payables'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.data.0.outstanding', '15.00')->where('rows.data.0.overdue', false));
        $this->get($this->url('orders', 'dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('money.0.outstanding', '15.00')->where('money.0.overdue', '0.00'));
        DB::table('supplier_payables')->where('supplier_bill_id', $bill->id)->update(['paid_amount' => '19.00']);
        $this->get($this->url('payables'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('rows.data', 0));
    }

    public function test_csv_matches_filtered_report_and_preserves_decimal_costs(): void
    {
        [, , , , , , $receipt] = $this->received();
        $response = $this->get($this->url('receipts', 'reports/export'))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('বাংলা পণ্য', $csv);
        $this->assertStringContainsString('54.0000', $csv);
        $this->assertStringContainsString('6.0000', $csv);
        $this->assertStringContainsString($receipt->number, $csv);
        $stream = fopen('php://temp', 'w+');
        $this->assertNotFalse($stream);
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $records = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $records[] = $row;
        }
        fclose($stream);
        $this->assertCount(2, $records);
    }

    public function test_csv_neutralizes_formulas_in_supplier_and_document_names(): void
    {
        [, , , , $order] = $this->received();
        $order->update(['number' => '=SUM(1,2)']);
        Supplier::query()->whereKey($order->supplier_id)->update(['name' => '@IMPORTXML("bad")']);
        $csv = $this->get($this->url('orders', 'reports/export'))->assertOk()->streamedContent();
        $this->assertStringContainsString("'=SUM(1,2)", $csv);
        $this->assertStringContainsString("'@IMPORTXML", $csv);
    }

    public function test_dashboard_summary_covers_all_rows_not_only_first_page(): void
    {
        [, , , , $order] = $this->received();
        for ($i = 1; $i <= 26; $i++) {
            $copy = $order->replicate();
            $copy->number = 'PO-COPY-'.$i;
            $copy->save();
        }
        $this->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->has('rows.data', 25)->where('rows.total', 27));
        $this->get($this->url('orders', 'dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('counts.orders', 27)->where('money.0.order_value', '2565.00'));
    }

    public function test_reports_and_exports_do_not_mutate_purchasing_or_stock(): void
    {
        [, , $warehouse, $product, $order, $poLine, $receipt, $source] = $this->received();
        $bill = $this->draft($receipt, $source);
        $this->approveBill($bill);
        $revision = $bill->getAttribute('revision');
        $auditCount = DB::table('supplier_bill_audit_logs')->count();
        $this->get($this->url('orders', 'dashboard'))->assertOk();
        foreach (['orders', 'receipts', 'returns', 'bills', 'payables'] as $type) {
            $this->get($this->url($type))->assertOk();
            $this->get($this->url($type, 'reports/export'))->assertOk()->streamedContent();
        }
        $this->assertSame($revision, $bill->refresh()->getAttribute('revision'));
        $this->assertSame($auditCount, DB::table('supplier_bill_audit_logs')->count());
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('supplier_payables', 1);
        $this->assertSame('6.0000', app(InventoryService::class)->quantityOnHand($warehouse, $product));
        $this->assertSame('6.0000', $poLine->refresh()->received_quantity);
        $this->assertSame('partially_received', $order->refresh()->status);
    }

    public function test_rejected_only_receipt_is_counted_without_stock_value(): void
    {
        [, , , , , , $receipt] = $this->received(true, '0', '6');
        $this->get($this->url('receipts'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.data.0.integrity', 'no_accepted_stock')->where('rows.data.0.quantity', '0.0000')->where('rows.data.0.quantity_rejected', '6.0000')->where('rows.data.0.amount', '0.0000'));
        $this->get($this->url('orders', 'dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('counts.receipts', 1)->where('money.0.receipt_stock_value', '0.0000')->where('issues.stock_links', 0));
    }

    public function test_return_report_uses_posted_average_cost_and_keeps_original_cost(): void
    {
        [$actor, , $warehouse, $product, , , $receipt, $source] = $this->received();
        app(InventoryService::class)->stockIn($warehouse, $product, '6', StockMovement::TYPE_OPENING_STOCK, '15');
        $this->returnGoods($actor, $receipt, $source, '2');
        $this->get($this->url('returns'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.data.0.source_unit_cost', '9.0000')->where('rows.data.0.unit_cost', '12.0000')->where('rows.data.0.amount', '24.0000')->where('rows.data.0.integrity', 'ok'));
        $this->get($this->url('orders', 'dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('money.0.receipt_stock_value', '54.0000')->where('money.0.return_stock_value', '24.0000'));
    }

    public function test_valid_empty_scope_has_zero_counts_and_header_only_exports(): void
    {
        $this->actingAs($this->admin());
        $this->company();
        $this->get($this->url('orders', 'dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('counts.orders', 0)->where('counts.receipts', 0)->where('counts.returns', 0)->where('counts.bills', 0)->has('money', 0)->has('supplierSummary', 0)->has('months', 0));
        $csv = $this->get($this->url('bills', 'reports/export'))->assertOk()->streamedContent();
        $this->assertSame(1, substr_count(trim($csv), "\n") + 1);
        $this->assertStringContainsString('Supplier invoice', $csv);
    }

    public function test_supplier_options_remain_distinct_from_dashboard_supplier_totals(): void
    {
        [, , , , , , $receipt, $source] = $this->received();
        $bill = $this->draft($receipt, $source);
        $this->approveBill($bill);
        $this->get($this->url('orders', 'dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('suppliers.0.id', $bill->supplier_id)->where('suppliers.0.name', 'Supplier')->where('supplierSummary.0.supplier_id', $bill->supplier_id)->where('supplierSummary.0.bill_value', '19.00'));
    }

    public function test_fully_filtered_csv_does_not_include_other_supplier_company(): void
    {
        [, , , , , , $firstReceipt, $firstSource] = $this->received();
        $firstBill = $this->draft($firstReceipt, $firstSource, '2', 'INV-FIRST');
        $this->approveBill($firstBill);
        [, , , , , , $secondReceipt, $secondSource] = $this->received(true, '6', '2', 'C-B', 'USD');
        $secondBill = $this->draft($secondReceipt, $secondSource, '2', 'INV-SECOND');
        $this->approveBill($secondBill);
        $csv = $this->get($this->url('bills', 'reports/export').'&supplier_id='.$firstBill->supplier_id.'&currency=BDT')->assertOk()->streamedContent();
        $this->assertStringContainsString('INV-FIRST', $csv);
        $this->assertStringNotContainsString('INV-SECOND', $csv);
        $this->assertStringNotContainsString('USD', $csv);
    }

    public function test_missing_payable_does_not_hide_approved_invoice_value(): void
    {
        [, , , , , , $receipt, $source] = $this->received();
        $bill = $this->draft($receipt, $source);
        $bill->update(['status' => 'approved', 'approved_at' => now()->toDateTimeString()]);
        $this->get($this->url('bills'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.data.0.integrity', 'review_payable_link')->where('rows.data.0.amount', '19.00'));
        $this->get($this->url('orders', 'dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('counts.bills', 1)->where('money.0.bill_value', '19.00')->where('money.0.outstanding', '0.00')->where('issues.payable_links', 1)->where('current.approved_bills_without_valid_payable', 1));
    }
}
