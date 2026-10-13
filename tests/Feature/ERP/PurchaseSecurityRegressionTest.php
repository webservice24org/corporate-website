<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use App\Models\Employee;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Rfq;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierBillItem;
use App\Models\SupplierQuotation;
use App\Models\SupplierQuotationItem;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ERP\Purchase\PurchaseReturnService;
use App\Services\ERP\Purchase\PurchaseWorkflowService;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class PurchaseSecurityRegressionTest extends TestCase
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

    /** @return array{User, Company, Product, Supplier, Rfq, SupplierQuotation} */
    private function offer(): array
    {
        $actor = $this->admin();
        $company = $this->company();
        $unit = Unit::query()->create(['company_id' => $company->id, 'name' => 'Piece', 'short_name' => 'pc']);
        $product = Product::query()->create(['company_id' => $company->id, 'unit_id' => $unit->id, 'name' => 'বাংলা পণ্য', 'is_active' => true]);
        $supplier = Supplier::query()->create(['company_id' => $company->id, 'code' => 'S-1', 'name' => 'Supplier', 'currency_code' => 'BDT', 'status' => 'active']);
        $requisition = PurchaseRequisition::query()->create(['company_id' => $company->id, 'requested_by' => $actor->id, 'approved_by' => $this->admin()->id,
            'approved_at' => now()->toDateTimeString(), 'number' => 'PR-TEST', 'status' => 'approved', 'currency_code' => 'BDT']);
        $requestItem = PurchaseRequisitionItem::query()->create(['purchase_requisition_id' => $requisition->id, 'product_id' => $product->id, 'quantity' => '2.5000', 'estimated_unit_price' => '10.00']);
        $rfq = Rfq::query()->create(['company_id' => $company->id, 'purchase_requisition_id' => $requisition->id, 'created_by' => $actor->id, 'number' => 'RFQ-TEST', 'status' => 'issued', 'currency_code' => 'BDT']);
        DB::table('rfq_suppliers')->insert(['rfq_id' => $rfq->id, 'supplier_id' => $supplier->id]);
        $rfqItem = DB::table('rfq_items')->insertGetId(['rfq_id' => $rfq->id, 'purchase_requisition_item_id' => $requestItem->id, 'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => '2.5000', 'estimated_unit_price' => '10.00']);
        $quote = SupplierQuotation::query()->create(['company_id' => $company->id, 'rfq_id' => $rfq->id, 'supplier_id' => $supplier->id, 'quotation_number' => 'VENDOR-1',
            'status' => 'accepted', 'currency_code' => 'BDT', 'quoted_at' => now()->toDateString(), 'valid_until' => now()->addDays(10)->toDateString(), 'total_amount' => '16.00', 'notes' => 'Delivery in seven days']);
        SupplierQuotationItem::query()->create(['supplier_quotation_id' => $quote->id, 'rfq_item_id' => $rfqItem, 'product_id' => $product->id,
            'quantity' => '2.5000', 'unit_price' => '6.00', 'discount_amount' => '1.00', 'tax_amount' => '2.00', 'line_total' => '16.00']);
        $rfq->update(['status' => 'awarded', 'awarded_quotation_id' => $quote->id, 'awarded_by' => $actor->id, 'awarded_at' => now()->toDateTimeString()]);
        $this->actingAs($actor);

        return [$actor, $company, $product, $supplier, $rfq, $quote];
    }

    private function draftReturn(User $actor, GoodsReceipt $receipt, GoodsReceiptItem $source): PurchaseReturn
    {
        return app(PurchaseReturnService::class)->create($actor, ['goods_receipt_id' => $receipt->id, 'reason' => 'Source protection test', 'items' => [['goods_receipt_item_id' => $source->id, 'quantity' => '1']]]);
    }

    public function test_all_purchase_routes_require_authentication_and_catalog_has_its_own_authorization(): void
    {
        $count = 0;
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'erp/purchase/')) {
                continue;
            }
            $count++;
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth', $middleware, $route->uri());
            if ($route->getName() !== 'erp.purchase.requisitions.catalog') {
                $this->assertNotEmpty(array_filter($middleware, fn ($item): bool => is_string($item) && str_starts_with($item, 'can:erp.')), $route->uri());
            }
        }
        $this->assertGreaterThan(50, $count);
        $company = $this->company();
        $this->actingAs(User::factory()->create())->get('/erp/purchase/requisitions/catalog?company_id='.$company->id)->assertForbidden();
        $this->get('/erp/purchase/reports/export')->assertForbidden();
    }

    public function test_readers_cannot_write_or_export_without_the_specific_permission(): void
    {
        [, $company, , , , , $receipt, $source] = $this->received();
        $return = $this->draftReturn($this->admin(), $receipt, $source);
        $bill = $this->draft($receipt, $source);
        $reader = $this->employee($company, ['erp.purchase-returns.view', 'erp.supplier-bills.view', 'erp.goods-receipts.view', 'erp.purchase-reports.view']);
        $this->actingAs($reader);
        foreach (['/erp/purchase/returns/'.$return->id.'/approve', '/erp/purchase/returns/'.$return->id.'/post', '/erp/purchase/returns/'.$return->id.'/cancel', '/erp/purchase/bills/'.$bill->id.'/approve', '/erp/purchase/bills/'.$bill->id.'/cancel', '/erp/purchase/receipts/'.$receipt->id.'/post'] as $path) {
            $this->post($path, ['revision' => 1, 'reason' => 'Unauthorized write'])->assertForbidden();
        }
        $this->get('/erp/purchase/reports/export')->assertForbidden();
        $this->assertDatabaseCount('supplier_payables', 0);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_users_with_purchase_permissions_but_no_employee_cannot_access_company_data(): void
    {
        [, , , , , , $receipt, $source] = $this->received();
        $bill = $this->draft($receipt, $source);
        $user = User::factory()->create();
        $user->givePermissionTo(['erp.supplier-bills.view', 'erp.supplier-bills.approve', 'erp.purchase-reports.view']);
        $this->actingAs($user)->get('/erp/purchase/bills/'.$bill->id)->assertNotFound();
        $this->post('/erp/purchase/bills/'.$bill->id.'/approve', ['revision' => 1])->assertNotFound();
        $this->get('/erp/purchase/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->has('companies', 0)->has('money', 0));
    }

    public function test_corrupted_return_source_cannot_import_another_company_receipt_on_edit(): void
    {
        [$actor, $company, , , , , $receipt, $source] = $this->received();
        $return = $this->draftReturn($actor, $receipt, $source);
        [, , , , , , $foreignReceipt, $foreignSource] = $this->received(true, '6', '0', 'C-B');
        $return->update(['goods_receipt_id' => $foreignReceipt->id]);
        $local = $this->employee($company, ['erp.purchase-returns.edit', 'erp.purchase-returns.view']);
        $this->actingAs($local)->put('/erp/purchase/returns/'.$return->id, ['revision' => 1, 'reason' => 'Try replacing source lines', 'items' => [['goods_receipt_item_id' => $foreignSource->id, 'quantity' => '1']]])->assertNotFound();
        $this->assertSame(1, $return->refresh()->getAttribute('revision'));
        $this->assertDatabaseHas('purchase_return_items', ['purchase_return_id' => $return->id, 'goods_receipt_item_id' => $source->id]);
        $this->assertDatabaseCount('purchase_return_audit_logs', 1);
    }

    public function test_corrupted_return_supplier_header_is_rechecked_before_draft_edit(): void
    {
        [$actor, $company, , , , , $receipt, $source] = $this->received();
        $return = $this->draftReturn($actor, $receipt, $source);
        $other = Supplier::query()->create(['company_id' => $company->id, 'code' => 'S-2', 'name' => 'Other', 'currency_code' => 'BDT', 'status' => 'active']);
        $return->update(['supplier_id' => $other->id]);
        $this->put('/erp/purchase/returns/'.$return->id, ['revision' => 1, 'reason' => 'Header mismatch', 'items' => [['goods_receipt_item_id' => $source->id, 'quantity' => '2']]])->assertSessionHasErrors('goods_receipt_id');
        $this->assertSame(1, $return->refresh()->getAttribute('revision'));
        $this->assertSame('1.0000', PurchaseReturnItem::query()->where('purchase_return_id', $return->id)->firstOrFail()->quantity);
        $this->assertDatabaseCount('purchase_return_audit_logs', 1);
    }

    public function test_corrupted_return_source_is_blocked_at_approval_and_posting(): void
    {
        [$actor, , , , , , $receipt, $source] = $this->received();
        $return = $this->draftReturn($actor, $receipt, $source);
        app(PurchaseReturnService::class)->approve($this->admin(), (int) $return->id, 1);
        [, , , , , , $foreignReceipt] = $this->received(true, '6', '0', 'C-B');
        $return->update(['goods_receipt_id' => $foreignReceipt->id]);
        $this->actingAs($actor)->post('/erp/purchase/returns/'.$return->id.'/post', ['revision' => 2])->assertNotFound();
        $this->assertSame('approved', $return->refresh()->status);
        $draft = $this->draftReturn($actor, $receipt, $source);
        $draft->update(['goods_receipt_id' => $foreignReceipt->id]);
        $this->actingAs($this->admin())->post('/erp/purchase/returns/'.$draft->id.'/approve', ['revision' => 1])->assertNotFound();
        $this->assertSame('draft', $draft->refresh()->status);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_corrupted_bill_source_cannot_be_edited_or_approved(): void
    {
        [$actor, , , , , , $receipt, $source] = $this->received();
        $bill = $this->draft($receipt, $source);
        [, , , , , , $foreignReceipt, $foreignSource] = $this->received(true, '6', '0', 'C-B');
        $bill->update(['goods_receipt_id' => $foreignReceipt->id]);
        $data = $this->billData($receipt, $foreignSource);
        unset($data['goods_receipt_id']);
        $this->actingAs($actor)->put('/erp/purchase/bills/'.$bill->id, [...$data, 'revision' => 1])->assertNotFound();
        $this->actingAs($this->admin())->post('/erp/purchase/bills/'.$bill->id.'/approve', ['revision' => 1])->assertNotFound();
        $this->assertSame('draft', $bill->refresh()->status);
        $this->assertDatabaseCount('supplier_payables', 0);
        $this->assertDatabaseCount('supplier_bill_audit_logs', 1);
    }

    public function test_foreign_receipt_line_cannot_replace_local_return_or_bill_lines(): void
    {
        [$actor, , , , , , $receipt, $source] = $this->received();
        $return = $this->draftReturn($actor, $receipt, $source);
        $bill = $this->draft($receipt, $source);
        [, , , , , , , $foreignSource] = $this->received(true, '6', '0', 'C-B');
        $this->actingAs($actor)->put('/erp/purchase/returns/'.$return->id, ['revision' => 1, 'reason' => 'Foreign line', 'items' => [['goods_receipt_item_id' => $foreignSource->id, 'quantity' => '1']]])->assertNotFound();
        $data = $this->billData($receipt, $foreignSource);
        unset($data['goods_receipt_id']);
        $this->put('/erp/purchase/bills/'.$bill->id, [...$data, 'revision' => 1])->assertNotFound();
        $this->assertDatabaseHas('purchase_return_items', ['purchase_return_id' => $return->id, 'goods_receipt_item_id' => $source->id]);
        $this->assertDatabaseHas('supplier_bill_items', ['supplier_bill_id' => $bill->id, 'goods_receipt_item_id' => $source->id]);
    }

    public function test_requisition_rejects_variant_when_product_no_longer_uses_variants(): void
    {
        [, $company, , $product] = $this->fixture();
        $product->update(['has_variants' => true]);
        $variant = ProductVariant::query()->create(['product_id' => $product->id, 'name' => 'Large', 'sku' => 'L-1', 'is_active' => true]);
        $product->update(['has_variants' => false]);
        $this->post('/erp/purchase/requisitions', ['company_id' => $company->id, 'items' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => '1', 'estimated_unit_price' => '10']]])->assertSessionHasErrors('items.0.product_variant_id');
        $this->assertDatabaseCount('purchase_requisitions', 0);
    }

    public function test_rfq_creation_rejects_variant_configuration_changed_after_requisition_approval(): void
    {
        [$actor, $company, , $product, $order] = $this->fixture();
        $product->update(['has_variants' => true]);
        $variant = ProductVariant::query()->create(['product_id' => $product->id, 'name' => 'Large', 'sku' => 'L-1', 'is_active' => true]);
        $request = PurchaseRequisition::query()->create(['company_id' => $company->id, 'requested_by' => $actor->id, 'number' => 'PR-LEGACY', 'status' => 'approved', 'currency_code' => 'BDT']);
        PurchaseRequisitionItem::query()->create(['purchase_requisition_id' => $request->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => '10', 'estimated_unit_price' => '10']);
        $product->update(['has_variants' => false]);
        $this->post('/erp/purchase/rfqs', ['company_id' => $company->id, 'purchase_requisition_id' => $request->id, 'supplier_ids' => [$order->supplier_id]])->assertSessionHasErrors('purchase_requisition_id');
        $this->assertDatabaseCount('rfqs', 0);
    }

    public function test_rfq_issue_rechecks_variant_configuration(): void
    {
        [$actor, $company, , $product, $order] = $this->fixture();
        $product->update(['has_variants' => true]);
        $variant = ProductVariant::query()->create(['product_id' => $product->id, 'name' => 'Large', 'sku' => 'L-1', 'is_active' => true]);
        $request = PurchaseRequisition::query()->create(['company_id' => $company->id, 'requested_by' => $actor->id, 'number' => 'PR-LEGACY', 'status' => 'approved', 'currency_code' => 'BDT']);
        PurchaseRequisitionItem::query()->create(['purchase_requisition_id' => $request->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => '10', 'estimated_unit_price' => '10']);
        $this->post('/erp/purchase/rfqs', ['company_id' => $company->id, 'purchase_requisition_id' => $request->id, 'supplier_ids' => [$order->supplier_id]])->assertSessionHasNoErrors();
        $rfq = Rfq::query()->firstOrFail();
        $product->update(['has_variants' => false]);
        $this->post('/erp/purchase/rfqs/'.$rfq->id.'/issue', ['revision' => $rfq->getAttribute('revision')])->assertSessionHasErrors('items');
        $this->assertSame('draft', $rfq->refresh()->status);
    }

    public function test_core_workflow_cannot_submit_legacy_requisition_with_variant_configuration_mismatch(): void
    {
        [$actor, $company, , $product] = $this->fixture();
        $variant = ProductVariant::query()->create(['product_id' => $product->id, 'name' => 'Dormant', 'sku' => 'D-1', 'is_active' => true]);
        $request = PurchaseRequisition::query()->create(['company_id' => $company->id, 'requested_by' => $actor->id, 'number' => 'PR-LEGACY', 'status' => 'draft', 'currency_code' => 'BDT']);
        PurchaseRequisitionItem::query()->create(['purchase_requisition_id' => $request->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => '1', 'estimated_unit_price' => '10']);
        try {
            app(PurchaseWorkflowService::class)->transitionRequisition($actor, (int) $company->id, (int) $request->id, 'submit');
            $this->fail('Expected variant configuration validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
            $this->assertSame('draft', $request->refresh()->status);
            $this->assertDatabaseCount('purchase_workflow_events', 0);
        }
    }

    public function test_core_order_workflow_rechecks_variant_configuration(): void
    {
        [$actor, $company, , $product, $order, $line] = $this->fixture();
        $variant = ProductVariant::query()->create(['product_id' => $product->id, 'name' => 'Dormant', 'sku' => 'D-1', 'is_active' => true]);
        $line->update(['product_variant_id' => $variant->id]);
        $order->update(['status' => 'draft']);
        try {
            app(PurchaseWorkflowService::class)->transitionOrder($actor, (int) $company->id, (int) $order->id, 'submit');
            $this->fail('Expected variant configuration validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
            $this->assertSame('draft', $order->refresh()->status);
        }
    }

    public function test_stale_bill_and_return_edits_do_not_replace_saved_lines(): void
    {
        [$actor, , , , , , $receipt, $source] = $this->received();
        $return = $this->draftReturn($actor, $receipt, $source);
        $bill = $this->draft($receipt, $source);
        $this->put('/erp/purchase/returns/'.$return->id, ['revision' => 2, 'reason' => 'Stale', 'items' => [['goods_receipt_item_id' => $source->id, 'quantity' => '3']]])->assertSessionHasErrors('revision');
        $data = $this->billData($receipt, $source, '3');
        unset($data['goods_receipt_id']);
        $this->put('/erp/purchase/bills/'.$bill->id, [...$data, 'revision' => 2])->assertSessionHasErrors('revision');
        $this->assertSame('1.0000', PurchaseReturnItem::query()->firstOrFail()->quantity);
        $this->assertSame('2.0000', SupplierBillItem::query()->firstOrFail()->quantity);
        $this->assertDatabaseCount('purchase_return_audit_logs', 1);
        $this->assertDatabaseCount('supplier_bill_audit_logs', 1);
    }

    public function test_creator_cannot_self_approve_even_when_super_admin(): void
    {
        [$actor, , , , , , $receipt, $source] = $this->received();
        $return = $this->draftReturn($actor, $receipt, $source);
        $bill = $this->draft($receipt, $source);
        $this->post('/erp/purchase/returns/'.$return->id.'/approve', ['revision' => 1])->assertSessionHasErrors('approval');
        $this->post('/erp/purchase/bills/'.$bill->id.'/approve', ['revision' => 1])->assertSessionHasErrors('approval');
        $this->assertDatabaseCount('supplier_payables', 0);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_protected_fields_and_nested_line_costs_cannot_be_injected(): void
    {
        [, , , , , , $receipt, $source] = $this->received();
        $this->post('/erp/purchase/returns', ['goods_receipt_id' => $receipt->id, 'status' => 'posted', 'reason' => 'Injection', 'items' => [['goods_receipt_item_id' => $source->id, 'quantity' => '1', 'unit_cost' => '0']]])->assertSessionHasErrors(['status', 'items.0']);
        $data = $this->billData($receipt, $source);
        $data['items'][0]['tax_amount'] = '0';
        $this->post('/erp/purchase/bills', [...$data, 'grand_total' => '0', 'status' => 'approved'])->assertSessionHasErrors(['grand_total', 'status', 'items.0']);
        $this->assertDatabaseCount('purchase_returns', 0);
        $this->assertDatabaseCount('supplier_bills', 0);
    }

    public function test_awarded_offer_cannot_create_po_after_variant_configuration_changes(): void
    {
        [, $company, $product, , $rfq, $quote] = $this->offer();
        $product->update(['has_variants' => true]);
        $variant = ProductVariant::query()->create(['product_id' => $product->id, 'name' => 'Large', 'sku' => 'L-1', 'is_active' => true]);
        DB::table('rfq_items')->where('rfq_id', $rfq->id)->update(['product_variant_id' => $variant->id, 'variant_name' => $variant->name]);
        SupplierQuotationItem::query()->where('supplier_quotation_id', $quote->id)->update(['product_variant_id' => $variant->id]);
        $product->update(['has_variants' => false]);
        $this->post('/erp/purchase/orders', ['company_id' => $company->id, 'supplier_quotation_id' => $quote->id])->assertSessionHasErrors('items');
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_valid_variant_configuration_still_creates_po_from_awarded_offer(): void
    {
        [, $company, $product, , $rfq, $quote] = $this->offer();
        $product->update(['has_variants' => true]);
        $variant = ProductVariant::query()->create(['product_id' => $product->id, 'name' => 'Large', 'sku' => 'L-1', 'is_active' => true]);
        DB::table('rfq_items')->where('rfq_id', $rfq->id)->update(['product_variant_id' => $variant->id, 'variant_name' => $variant->name]);
        SupplierQuotationItem::query()->where('supplier_quotation_id', $quote->id)->update(['product_variant_id' => $variant->id]);
        $this->post('/erp/purchase/orders', ['company_id' => $company->id, 'supplier_quotation_id' => $quote->id])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseHas('purchase_order_items', ['product_variant_id' => $variant->id]);
    }

    public function test_catalog_cannot_expose_another_company_products_even_with_create_permission(): void
    {
        [, , , $product] = $this->fixture();
        $other = $this->company('C-B');
        $actor = $this->employee($other, ['erp.purchase-requisitions.create']);
        $this->actingAs($actor)->get('/erp/purchase/requisitions/catalog?company_id='.$product->company_id)->assertForbidden();
        $this->get('/erp/purchase/requisitions/catalog?company_id='.$other->id)->assertOk()->assertJsonCount(0, 'products');
    }
}
