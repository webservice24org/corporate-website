<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Models\Rfq;
use App\Models\Supplier;
use App\Models\SupplierQuotation;
use App\Models\SupplierQuotationItem;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class PurchaseOrderManagementTest extends TestCase
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

    /** @return array<string, mixed> */
    private function data(Company $company, SupplierQuotation $quote): array
    {
        return ['company_id' => $company->id, 'supplier_quotation_id' => $quote->id, 'expected_delivery_at' => now()->addDays(7)->toDateString(), 'notes' => 'Order notes'];
    }

    private function create(Company $company, SupplierQuotation $quote): PurchaseOrder
    {
        $this->post('/erp/purchase/orders', $this->data($company, $quote))->assertSessionHasNoErrors();

        return PurchaseOrder::query()->where('supplier_quotation_id', $quote->id)->firstOrFail();
    }

    private function action(PurchaseOrder $order, string $action, ?string $comment = null): void
    {
        $this->post('/erp/purchase/orders/'.$order->id.'/'.$action, ['revision' => (int) $order->refresh()->getAttribute('revision'), 'comment' => $comment])->assertSessionHasNoErrors();
        $order->refresh();
    }

    public function test_guest_and_missing_permissions_are_denied(): void
    {
        $this->get('/erp/purchase/orders')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/erp/purchase/orders')->assertForbidden();
        $this->get('/erp/purchase/orders/create')->assertForbidden();
        $this->post('/erp/purchase/orders', [])->assertForbidden();
    }

    public function test_creation_copies_awarded_source_lines_totals_and_history(): void
    {
        [$actor, $company, , $supplier, , $quote] = $this->offer();
        $order = $this->create($company, $quote);
        $this->assertStringStartsWith('PO-', $order->number);
        $this->assertSame('draft', $order->status);
        $this->assertSame((int) $actor->id, (int) $order->created_by);
        $this->assertSame((int) $supplier->id, (int) $order->supplier_id);
        $this->assertSame('15.00', $order->subtotal);
        $this->assertSame('1.00', $order->discount_total);
        $this->assertSame('2.00', $order->tax_total);
        $this->assertSame('16.00', $order->grand_total);
        $this->assertDatabaseHas('purchase_order_items', ['purchase_order_id' => $order->id, 'product_name' => 'বাংলা পণ্য', 'quantity' => 2.5, 'received_quantity' => 0]);
        $this->assertDatabaseCount('purchase_order_audit_logs', 1);
        $this->assertDatabaseCount('purchase_workflow_events', 1);
    }

    public function test_duplicate_creation_returns_original_without_new_number_or_changes(): void
    {
        [, $company, , , , $quote] = $this->offer();
        $order = $this->create($company, $quote);
        $this->post('/erp/purchase/orders', [...$this->data($company, $quote), 'notes' => 'Retry should not overwrite'])->assertRedirect('/erp/purchase/orders/'.$order->id);
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseCount('purchase_order_items', 1);
        $this->assertDatabaseCount('purchase_order_audit_logs', 1);
        $this->assertSame('Order notes', $order->refresh()->notes);
        $this->assertDatabaseHas('purchase_document_sequences', ['company_id' => $company->id, 'document_type' => 'order', 'last_number' => 1]);
    }

    public function test_only_awarded_accepted_quotation_can_be_converted(): void
    {
        [, $company, , , $rfq, $quote] = $this->offer();
        $rfq->update(['status' => 'issued']);
        $this->post('/erp/purchase/orders', $this->data($company, $quote))->assertSessionHasErrors('supplier_quotation_id');
        $rfq->update(['status' => 'awarded']);
        $quote->update(['status' => 'submitted']);
        $this->post('/erp/purchase/orders', $this->data($company, $quote))->assertSessionHasErrors('supplier_quotation_id');
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_expired_quotation_cannot_be_converted(): void
    {
        [, $company, , , , $quote] = $this->offer();
        $quote->update(['valid_until' => now()->subDay()->toDateString()]);
        $this->post('/erp/purchase/orders', $this->data($company, $quote))->assertSessionHasErrors('supplier_quotation_id');
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_supplier_activity_and_currency_are_rechecked(): void
    {
        [, $company, , $supplier, , $quote] = $this->offer();
        $supplier->update(['status' => 'inactive']);
        $this->post('/erp/purchase/orders', $this->data($company, $quote))->assertSessionHasErrors('supplier_quotation_id');
        $supplier->update(['status' => 'active', 'currency_code' => 'USD']);
        $this->post('/erp/purchase/orders', $this->data($company, $quote))->assertSessionHasErrors('supplier_quotation_id');
    }

    public function test_inconsistent_quote_totals_or_missing_lines_roll_back_creation(): void
    {
        [, $company, , , , $quote] = $this->offer();
        $quote->update(['total_amount' => '15.00']);
        $this->post('/erp/purchase/orders', $this->data($company, $quote))->assertSessionHasErrors('items');
        $quote->update(['total_amount' => '16.00']);
        SupplierQuotationItem::query()->where('supplier_quotation_id', $quote->id)->delete();
        $this->post('/erp/purchase/orders', $this->data($company, $quote))->assertSessionHasErrors('items');
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseCount('purchase_document_sequences', 0);
        $this->assertDatabaseCount('purchase_order_audit_logs', 0);
    }

    public function test_client_cannot_override_prices_quantities_supplier_or_currency(): void
    {
        [, $company, , , , $quote] = $this->offer();
        $data = [...$this->data($company, $quote), 'items' => [['quantity' => 99]], 'grand_total' => '0.01', 'currency_code' => 'USD', 'supplier_id' => 99];
        $this->post('/erp/purchase/orders', $data)->assertSessionHasErrors(['items', 'grand_total', 'currency_code', 'supplier_id']);
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_draft_edit_changes_date_notes_and_revision_but_not_commercial_terms(): void
    {
        [, $company, , , , $quote] = $this->offer();
        $order = $this->create($company, $quote);
        $date = now()->addDays(9)->toDateString();
        $this->put('/erp/purchase/orders/'.$order->id, ['revision' => 1, 'expected_delivery_at' => $date, 'notes' => 'Updated'])->assertSessionHasNoErrors();
        $this->assertSame('Updated', $order->refresh()->notes);
        $this->assertSame(2, (int) $order->getAttribute('revision'));
        $this->assertSame('16.00', $order->grand_total);
        $this->assertSame($date, PurchaseOrderItem::query()->where('purchase_order_id', $order->id)->firstOrFail()->expected_delivery_at->format('Y-m-d'));
        $this->assertDatabaseCount('purchase_order_audit_logs', 2);
    }

    public function test_stale_draft_edits_and_submissions_are_rejected(): void
    {
        [, $company, , , , $quote] = $this->offer();
        $order = $this->create($company, $quote);
        $this->put('/erp/purchase/orders/'.$order->id, ['revision' => 1, 'notes' => 'First edit'])->assertSessionHasNoErrors();
        $this->put('/erp/purchase/orders/'.$order->id, ['revision' => 1, 'notes' => 'Stale edit'])->assertSessionHasErrors('revision');
        $this->post('/erp/purchase/orders/'.$order->id.'/submit', ['revision' => 1])->assertSessionHasErrors('revision');
        $this->assertSame('First edit', $order->refresh()->notes);
        $this->assertSame('draft', $order->status);
    }

    public function test_submission_locks_editing_and_self_approval_is_rejected(): void
    {
        [, $company, , , , $quote] = $this->offer();
        $order = $this->create($company, $quote);
        $this->action($order, 'submit');
        $this->get('/erp/purchase/orders/'.$order->id.'/edit')->assertForbidden();
        $this->put('/erp/purchase/orders/'.$order->id, ['revision' => 2, 'notes' => 'Not allowed'])->assertSessionHasErrors('status');
        $this->post('/erp/purchase/orders/'.$order->id.'/approve', ['revision' => 2])->assertSessionHasErrors('approved_by');
        $this->assertSame('submitted', $order->refresh()->status);
        $this->assertDatabaseCount('purchase_order_audit_logs', 2);
    }

    public function test_different_user_can_approve_and_order_release_records_timestamps(): void
    {
        [$creator, $company, , , , $quote] = $this->offer();
        $order = $this->create($company, $quote);
        $this->action($order, 'submit');
        $approver = $this->admin();
        $this->actingAs($approver);
        $this->action($order, 'approve', 'Approved');
        $this->assertSame((int) $approver->id, (int) $order->approved_by);
        $this->assertNotNull($order->approved_at);
        $this->actingAs($creator);
        $this->action($order, 'order', 'Released');
        $this->assertSame('ordered', $order->status);
        $this->assertNotNull($order->ordered_at);
        $this->assertSame(4, (int) $order->getAttribute('revision'));
        $this->assertDatabaseCount('purchase_order_audit_logs', 4);
        $this->assertDatabaseCount('purchase_workflow_events', 4);
    }

    public function test_rejection_and_revision_require_reasons_and_preserve_source(): void
    {
        [, $company, , , , $quote] = $this->offer();
        $order = $this->create($company, $quote);
        $this->action($order, 'submit');
        $this->post('/erp/purchase/orders/'.$order->id.'/reject', ['revision' => 2, 'comment' => ' '])->assertSessionHasErrors('comment');
        $this->action($order, 'reject', 'Revise delivery');
        $this->post('/erp/purchase/orders/'.$order->id.'/revise', ['revision' => 3])->assertSessionHasErrors('comment');
        $number = $order->number;
        $this->action($order, 'revise', 'Delivery date corrected');
        $this->assertSame('draft', $order->status);
        $this->assertNull($order->submitted_at);
        $this->assertSame($number, $order->number);
        $this->assertSame((int) $quote->id, (int) $order->supplier_quotation_id);
        $this->assertSame('16.00', $order->grand_total);
        $this->action($order, 'submit');
        $this->assertSame('submitted', $order->status);
    }

    public function test_order_release_cannot_skip_approval_and_ordered_state_is_locked(): void
    {
        [, $company, , , , $quote] = $this->offer();
        $order = $this->create($company, $quote);
        $this->post('/erp/purchase/orders/'.$order->id.'/order', ['revision' => 1])->assertSessionHasErrors('status');
        $this->action($order, 'submit');
        $this->actingAs($this->admin());
        $this->action($order, 'approve');
        $this->action($order, 'order');
        $this->post('/erp/purchase/orders/'.$order->id.'/revise', ['revision' => 4, 'comment' => 'Not allowed'])->assertSessionHasErrors('status');
        $this->get('/erp/purchase/orders/'.$order->id.'/edit')->assertForbidden();
    }

    public function test_source_expiry_before_approval_blocks_transition(): void
    {
        [, $company, , , , $quote] = $this->offer();
        $order = $this->create($company, $quote);
        $this->action($order, 'submit');
        $quote->update(['valid_until' => now()->subDay()->toDateString()]);
        $this->actingAs($this->admin())->post('/erp/purchase/orders/'.$order->id.'/approve', ['revision' => 2])->assertSessionHasErrors('supplier_quotation_id');
        $this->assertSame('submitted', $order->refresh()->status);
        $this->assertSame(2, (int) $order->getAttribute('revision'));
    }

    public function test_tampered_order_lines_or_header_totals_block_submission(): void
    {
        [, $company, , , , $quote] = $this->offer();
        $order = $this->create($company, $quote);
        $order->update(['grand_total' => '15.00']);
        $this->post('/erp/purchase/orders/'.$order->id.'/submit', ['revision' => 1])->assertSessionHasErrors('source');
        $order->update(['grand_total' => '16.00']);
        PurchaseOrderItem::query()->where('purchase_order_id', $order->id)->update(['unit_price' => '5.00']);
        $this->post('/erp/purchase/orders/'.$order->id.'/submit', ['revision' => 1])->assertSessionHasErrors('items');
        $this->assertSame('draft', $order->refresh()->status);
    }

    public function test_employee_cannot_create_or_operate_another_company_order(): void
    {
        [, $a, , , , $quote] = $this->offer();
        $order = $this->create($a, $quote);
        $b = $this->company('C-B');
        $actor = $this->employee($b, ['erp.purchase-orders.create', 'erp.purchase-orders.view', 'erp.purchase-orders.edit', 'erp.purchase-orders.submit']);
        $this->actingAs($actor)->post('/erp/purchase/orders', $this->data($a, $quote))->assertForbidden();
        $this->get('/erp/purchase/orders/'.$order->id)->assertNotFound();
        $this->get('/erp/purchase/orders/'.$order->id.'/edit')->assertNotFound();
        $this->post('/erp/purchase/orders/'.$order->id.'/submit', ['revision' => 1])->assertNotFound();
        $this->get('/erp/purchase/orders')->assertInertia(fn (Assert $page) => $page->has('orders.data', 0)->has('companies', 1));
    }

    public function test_quotation_company_cannot_be_switched_by_posted_company_id(): void
    {
        [, , , , , $quote] = $this->offer();
        $other = $this->company('C-B');
        $this->post('/erp/purchase/orders', $this->data($other, $quote))->assertNotFound();
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_review_actions_require_their_own_permissions(): void
    {
        [, $company, , , , $quote] = $this->offer();
        $order = $this->create($company, $quote);
        $viewer = $this->employee($company, ['erp.purchase-orders.view']);
        $this->actingAs($viewer);
        foreach (['submit', 'approve', 'reject', 'order', 'revise'] as $action) {
            $this->post('/erp/purchase/orders/'.$order->id.'/'.$action, ['revision' => 1, 'comment' => 'Test'])->assertForbidden();
        }
    }

    public function test_create_edit_and_show_pages_resolve_and_awarded_rfq_links_to_order(): void
    {
        [, $company, , , $rfq, $quote] = $this->offer();
        $this->get('/erp/purchase/orders/create?company_id='.$company->id.'&quotation_id='.$quote->id)->assertInertia(fn (Assert $page) => $page
            ->component('ERP/Purchase/Orders/Form')->has('quotations', 1)->where('selectedQuotationId', $quote->id));
        $order = $this->create($company, $quote);
        $this->get('/erp/purchase/orders/'.$order->id.'/edit')->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Orders/Form')->has('items', 1));
        $this->get('/erp/purchase/orders/'.$order->id)->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Orders/Show')->has('items', 1)->has('history', 1)->has('changes', 1)->where('sourceQuotation.notes', 'Delivery in seven days'));
        $this->get('/erp/purchase/rfqs/'.$rfq->id)->assertInertia(fn (Assert $page) => $page->where('purchaseOrderId', $order->id));
        $this->get('/erp/purchase/orders/create?company_id='.$company->id.'&quotation_id='.$quote->id)->assertRedirect('/erp/purchase/orders/'.$order->id);
    }

    public function test_snapshot_names_and_source_prices_survive_product_name_changes(): void
    {
        [, $company, $product, , , $quote] = $this->offer();
        $product->update(['name' => 'Renamed product']);
        $order = $this->create($company, $quote);
        $this->assertDatabaseHas('purchase_order_items', ['purchase_order_id' => $order->id, 'product_name' => 'বাংলা পণ্য', 'unit_price' => 6]);
    }

    public function test_creation_and_order_release_do_not_change_stock_or_create_receipts(): void
    {
        $before = DB::table('stock_movements')->count();
        [, $company, , , , $quote] = $this->offer();
        $order = $this->create($company, $quote);
        $this->action($order, 'submit');
        $this->actingAs($this->admin());
        $this->action($order, 'approve');
        $this->action($order, 'order');
        $this->assertSame($before, DB::table('stock_movements')->count());
        $this->assertDatabaseCount('goods_receipts', 0);
        $this->assertDatabaseHas('purchase_order_items', ['purchase_order_id' => $order->id, 'received_quantity' => 0]);
    }

    public function test_draft_update_cannot_change_source_or_totals(): void
    {
        [, $company, , , , $quote] = $this->offer();
        $order = $this->create($company, $quote);
        $this->put('/erp/purchase/orders/'.$order->id, ['revision' => 1, 'supplier_quotation_id' => $quote->id, 'grand_total' => '0.01', 'items' => [['quantity' => 99]]])->assertSessionHasErrors(['supplier_quotation_id', 'grand_total', 'items']);
        $this->assertSame('16.00', $order->refresh()->grand_total);
        $this->assertSame(1, (int) $order->getAttribute('revision'));
    }

    public function test_product_activity_is_rechecked_before_creation_and_release(): void
    {
        [, $company, $product, , , $quote] = $this->offer();
        $product->update(['is_active' => false]);
        $this->post('/erp/purchase/orders', $this->data($company, $quote))->assertNotFound();
        $this->assertDatabaseCount('purchase_orders', 0);
        $product->update(['is_active' => true]);
        $order = $this->create($company, $quote);
        $this->action($order, 'submit');
        $this->actingAs($this->admin());
        $this->action($order, 'approve');
        $product->update(['is_active' => false]);
        $this->post('/erp/purchase/orders/'.$order->id.'/order', ['revision' => 3])->assertNotFound();
        $this->assertSame('approved', $order->refresh()->status);
        $this->assertNull($order->ordered_at);
    }

    public function test_received_quantities_cannot_be_present_before_order_release(): void
    {
        [, $company, , , , $quote] = $this->offer();
        $order = $this->create($company, $quote);
        PurchaseOrderItem::query()->where('purchase_order_id', $order->id)->update(['received_quantity' => '1.0000']);
        $this->post('/erp/purchase/orders/'.$order->id.'/submit', ['revision' => 1])->assertSessionHasErrors('items');
        $this->assertSame('draft', $order->refresh()->status);
        $this->assertDatabaseCount('purchase_workflow_events', 1);
    }

    public function test_legacy_order_without_awarded_source_cannot_be_submitted(): void
    {
        [$actor, $company, , $supplier] = $this->offer();
        $order = PurchaseOrder::query()->create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'created_by' => $actor->id, 'number' => 'PO-LEGACY', 'currency_code' => 'BDT', 'status' => 'draft']);
        $this->post('/erp/purchase/orders/'.$order->id.'/submit', ['revision' => 1])->assertSessionHasErrors('source');
        $this->assertSame('draft', $order->refresh()->status);
    }
}
