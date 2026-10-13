<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Product;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Models\Rfq;
use App\Models\Supplier;
use App\Models\SupplierQuotation;
use App\Models\Unit;
use App\Models\User;
use App\Services\ERP\Purchase\PurchaseQuoteMath;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class PurchaseRfqManagementTest extends TestCase
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

    private function supplier(Company $company, string $code = 'S-1'): Supplier
    {
        return Supplier::query()->create(['company_id' => $company->id, 'code' => $code, 'name' => 'Supplier '.$code, 'currency_code' => 'BDT', 'status' => 'active']);
    }

    private function source(Company $company, User $actor): PurchaseRequisition
    {
        $unit = Unit::query()->firstOrCreate(['company_id' => $company->id, 'name' => 'Piece'], ['short_name' => 'pc']);
        $product = Product::query()->create(['company_id' => $company->id, 'unit_id' => $unit->id, 'name' => 'বাংলা পণ্য', 'is_active' => true]);
        $source = PurchaseRequisition::query()->create(['company_id' => $company->id, 'requested_by' => $actor->id,
            'approved_by' => $this->admin()->id, 'approved_at' => now()->toDateTimeString(), 'number' => 'PR-'.($company->id).'-'.($product->id), 'status' => 'approved', 'currency_code' => 'BDT']);
        PurchaseRequisitionItem::query()->create(['purchase_requisition_id' => $source->id, 'product_id' => $product->id, 'quantity' => '2.5000', 'estimated_unit_price' => '10.00', 'notes' => 'Needed']);

        return $source;
    }

    /** @return array{User, Company, PurchaseRequisition, Supplier, Supplier, Rfq} */
    private function draft(): array
    {
        $actor = $this->admin();
        $company = $this->company();
        $source = $this->source($company, $actor);
        $a = $this->supplier($company);
        $b = $this->supplier($company, 'S-2');
        $this->actingAs($actor)->post('/erp/purchase/rfqs', ['company_id' => $company->id, 'purchase_requisition_id' => $source->id, 'supplier_ids' => [$a->id, $b->id], 'deadline_at' => now()->addDays(5)->toDateString(), 'notes' => 'RFQ notes'])->assertSessionHasNoErrors();

        return [$actor, $company, $source, $a, $b, Rfq::query()->firstOrFail()];
    }

    private function issue(Rfq $rfq): void
    {
        $this->post('/erp/purchase/rfqs/'.$rfq->id.'/issue', ['revision' => (int) $rfq->refresh()->getAttribute('revision')])->assertSessionHasNoErrors();
        $rfq->refresh();
    }

    /** @return array<string, mixed> */
    private function quoteData(Rfq $rfq, Supplier $supplier, string $price = '6.00'): array
    {
        return ['rfq_revision' => (int) $rfq->refresh()->getAttribute('revision'), 'supplier_id' => $supplier->id, 'quotation_number' => 'VENDOR-'.$supplier->id,
            'quoted_at' => now()->toDateString(), 'valid_until' => now()->addDays(10)->toDateString(), 'notes' => 'Delivery in 7 days',
            'items' => DB::table('rfq_items')->where('rfq_id', $rfq->id)->orderBy('id')->get()->map(fn ($line): array => ['rfq_item_id' => $line->id, 'unit_price' => $price, 'discount_amount' => '1.00', 'tax_amount' => '2.00'])->all()];
    }

    private function quotation(Rfq $rfq, Supplier $supplier, string $price = '6.00'): SupplierQuotation
    {
        $this->post('/erp/purchase/rfqs/'.$rfq->id.'/quotations', $this->quoteData($rfq, $supplier, $price))->assertSessionHasNoErrors();

        return SupplierQuotation::query()->where('rfq_id', $rfq->id)->where('supplier_id', $supplier->id)->firstOrFail();
    }

    public function test_guest_and_missing_permission_are_denied(): void
    {
        $this->get('/erp/purchase/rfqs')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/erp/purchase/rfqs')->assertForbidden();
        $this->get('/erp/purchase/rfqs/create')->assertForbidden();
    }

    public function test_create_copies_requisition_lines_suppliers_currency_and_history(): void
    {
        [, $company, $source, , , $rfq] = $this->draft();
        $this->assertStringStartsWith('RFQ-', $rfq->number);
        $this->assertSame('draft', $rfq->status);
        $this->assertSame('BDT', $rfq->getAttribute('currency_code'));
        $this->assertSame((int) $source->id, (int) $rfq->purchase_requisition_id);
        $this->assertDatabaseHas('rfq_items', ['rfq_id' => $rfq->id, 'product_name' => 'বাংলা পণ্য', 'quantity' => 2.5]);
        $this->assertDatabaseCount('rfq_suppliers', 2);
        $this->assertDatabaseHas('purchase_rfq_audit_logs', ['company_id' => $company->id, 'action' => 'created']);
    }

    public function test_unapproved_requisition_is_rejected_without_sequence_or_document(): void
    {
        $actor = $this->admin();
        $company = $this->company();
        $source = $this->source($company, $actor);
        $source->update(['status' => 'submitted']);
        $supplier = $this->supplier($company);
        $this->actingAs($actor)->post('/erp/purchase/rfqs', ['company_id' => $company->id, 'purchase_requisition_id' => $source->id, 'supplier_ids' => [$supplier->id]])->assertNotFound();
        $this->assertDatabaseCount('rfqs', 0);
        $this->assertDatabaseCount('purchase_document_sequences', 0);
    }

    public function test_cross_company_requisition_and_supplier_are_rejected(): void
    {
        $actor = $this->admin();
        $a = $this->company();
        $b = $this->company('C-B');
        $source = $this->source($b, $actor);
        $supplier = $this->supplier($a);
        $data = ['company_id' => $a->id, 'purchase_requisition_id' => $source->id, 'supplier_ids' => [$supplier->id]];
        $this->actingAs($actor)->post('/erp/purchase/rfqs', $data)->assertNotFound();
        $data['purchase_requisition_id'] = $this->source($a, $actor)->id;
        $data['supplier_ids'] = [$this->supplier($b)->id];
        $this->post('/erp/purchase/rfqs', $data)->assertSessionHasErrors('supplier_ids');
        $this->assertDatabaseCount('rfqs', 0);
    }

    public function test_supplier_must_be_active_and_use_same_currency(): void
    {
        $actor = $this->admin();
        $company = $this->company();
        $source = $this->source($company, $actor);
        $supplier = $this->supplier($company);
        $data = ['company_id' => $company->id, 'purchase_requisition_id' => $source->id, 'supplier_ids' => [$supplier->id]];
        $supplier->update(['currency_code' => 'USD']);
        $this->actingAs($actor)->post('/erp/purchase/rfqs', $data)->assertSessionHasErrors('supplier_ids');
        $supplier->update(['currency_code' => 'BDT', 'status' => 'inactive']);
        $this->post('/erp/purchase/rfqs', $data)->assertSessionHasErrors('supplier_ids');
    }

    public function test_draft_can_change_suppliers_but_not_source_or_company(): void
    {
        [, , , $a, , $rfq] = $this->draft();
        $this->put('/erp/purchase/rfqs/'.$rfq->id, ['revision' => 1, 'supplier_ids' => [$a->id], 'notes' => 'Updated'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('rfq_suppliers', 1);
        $this->assertSame(2, (int) $rfq->refresh()->getAttribute('revision'));
        $this->put('/erp/purchase/rfqs/'.$rfq->id, ['revision' => 2, 'supplier_ids' => [$a->id], 'company_id' => $rfq->company_id])->assertSessionHasErrors('company_id');
    }

    public function test_issue_locks_draft_and_sets_invitation_timestamps(): void
    {
        [, , , $a, , $rfq] = $this->draft();
        $this->issue($rfq);
        $this->assertSame('issued', $rfq->status);
        $this->assertNotNull($rfq->issued_at);
        $this->assertSame(0, DB::table('rfq_suppliers')->where('rfq_id', $rfq->id)->whereNull('invited_at')->count());
        $this->put('/erp/purchase/rfqs/'.$rfq->id, ['revision' => 2, 'supplier_ids' => [$a->id]])->assertSessionHasErrors('status');
    }

    public function test_quotation_cannot_be_recorded_before_issue(): void
    {
        [, , , $a, , $rfq] = $this->draft();
        $this->post('/erp/purchase/rfqs/'.$rfq->id.'/quotations', $this->quoteData($rfq, $a))->assertSessionHasErrors('status');
        $this->assertDatabaseCount('supplier_quotations', 0);
    }

    public function test_quotation_total_uses_fixed_quantity_discount_tax_and_exact_rounding(): void
    {
        [, , , $a, , $rfq] = $this->draft();
        $this->issue($rfq);
        $quote = $this->quotation($rfq, $a);
        $this->assertSame('16.00', $quote->total_amount);
        $this->assertDatabaseHas('supplier_quotation_items', ['supplier_quotation_id' => $quote->id, 'quantity' => 2.5, 'line_total' => 16]);
        $math = app(PurchaseQuoteMath::class);
        $this->assertSame(1, $math->line('0.5000', '0.01', '0', '0'));
        $this->assertSame('0.01', $math->format(1));
    }

    public function test_uninvited_supplier_and_duplicate_supplier_quotation_are_rejected(): void
    {
        [, $company, , $a, , $rfq] = $this->draft();
        $this->issue($rfq);
        $uninvited = $this->supplier($company, 'S-3');
        $this->post('/erp/purchase/rfqs/'.$rfq->id.'/quotations', $this->quoteData($rfq, $uninvited))->assertSessionHasErrors('supplier_id');
        $this->quotation($rfq, $a);
        $this->post('/erp/purchase/rfqs/'.$rfq->id.'/quotations', $this->quoteData($rfq, $a))->assertSessionHasErrors('supplier_id');
        $this->assertDatabaseCount('supplier_quotations', 1);
    }

    public function test_quotation_must_cover_every_rfq_line_once(): void
    {
        [, , , $a, , $rfq] = $this->draft();
        $this->issue($rfq);
        $data = $this->quoteData($rfq, $a);
        $data['items'][0]['rfq_item_id'] = 999999;
        $this->post('/erp/purchase/rfqs/'.$rfq->id.'/quotations', $data)->assertSessionHasErrors('items');
        $data = $this->quoteData($rfq, $a);
        $data['items'][] = $data['items'][0];
        $this->post('/erp/purchase/rfqs/'.$rfq->id.'/quotations', $data)->assertSessionHasErrors('items.0.rfq_item_id');
        $this->assertDatabaseCount('supplier_quotations', 0);
    }

    public function test_negative_prices_excess_discount_and_client_totals_are_rejected(): void
    {
        [, , , $a, , $rfq] = $this->draft();
        $this->issue($rfq);
        $data = $this->quoteData($rfq, $a);
        $data['items'][0]['unit_price'] = '-1';
        $this->post('/erp/purchase/rfqs/'.$rfq->id.'/quotations', $data)->assertSessionHasErrors('items.0.unit_price');
        $data = $this->quoteData($rfq, $a);
        $data['items'][0]['discount_amount'] = '100.00';
        $this->post('/erp/purchase/rfqs/'.$rfq->id.'/quotations', $data)->assertSessionHasErrors('items');
        $data = $this->quoteData($rfq, $a);
        $data['total_amount'] = '0';
        $this->post('/erp/purchase/rfqs/'.$rfq->id.'/quotations', $data)->assertSessionHasErrors('total_amount');
    }

    public function test_stale_rfq_and_quotation_revisions_are_rejected(): void
    {
        [, , , $a, $b, $rfq] = $this->draft();
        $this->issue($rfq);
        $stale = $this->quoteData($rfq, $b);
        $quote = $this->quotation($rfq, $a);
        $this->post('/erp/purchase/rfqs/'.$rfq->id.'/quotations', $stale)->assertSessionHasErrors('revision');
        $data = $this->quoteData($rfq, $a, '8.00');
        $data['revision'] = 1;
        $this->put('/erp/purchase/rfqs/'.$rfq->id.'/quotations/'.$quote->id, $data)->assertSessionHasNoErrors();
        $data['rfq_revision'] = (int) $rfq->refresh()->getAttribute('revision');
        $this->put('/erp/purchase/rfqs/'.$rfq->id.'/quotations/'.$quote->id, $data)->assertSessionHasErrors('revision');
        $this->assertSame('21.00', $quote->refresh()->total_amount);
    }

    public function test_quotation_entry_and_editing_close_after_response_deadline(): void
    {
        [, , , $a, , $rfq] = $this->draft();
        $this->issue($rfq);
        $quote = $this->quotation($rfq, $a);
        $rfq->update(['deadline_at' => now()->subDay()->toDateTimeString()]);
        $data = $this->quoteData($rfq, $a);
        $data['revision'] = 1;
        $this->put('/erp/purchase/rfqs/'.$rfq->id.'/quotations/'.$quote->id, $data)->assertSessionHasErrors('deadline_at');
    }

    public function test_comparison_sorts_totals_and_keeps_supplier_terms(): void
    {
        [, , , $a, $b, $rfq] = $this->draft();
        $this->issue($rfq);
        $this->quotation($rfq, $a, '8.00');
        $cheap = $this->quotation($rfq, $b, '6.00');
        $this->get('/erp/purchase/rfqs/'.$rfq->id.'/compare')->assertInertia(fn (Assert $page) => $page
            ->component('ERP/Purchase/Rfqs/Compare')->has('quotations', 2)->where('quotations.0.id', $cheap->id)
            ->where('quotations.0.eligible', true)->where('quotations.0.notes', 'Delivery in 7 days')->has('quotations.0.items', 1));
    }

    public function test_award_accepts_one_quote_and_locks_all_quotation_writes(): void
    {
        [, , , $a, $b, $rfq] = $this->draft();
        $this->issue($rfq);
        $winner = $this->quotation($rfq, $a);
        $other = $this->quotation($rfq, $b, '8.00');
        $revision = (int) $rfq->refresh()->getAttribute('revision');
        $this->post('/erp/purchase/rfqs/'.$rfq->id.'/award', ['revision' => $revision, 'quotation_id' => $winner->id, 'reason' => 'Best value and delivery'])->assertSessionHasNoErrors();
        $this->assertSame('awarded', $rfq->refresh()->status);
        $this->assertSame((int) $winner->id, (int) $rfq->getAttribute('awarded_quotation_id'));
        $this->assertSame('accepted', $winner->refresh()->status);
        $this->assertSame('not_selected', $other->refresh()->status);
        $data = $this->quoteData($rfq, $a);
        $data['revision'] = (int) $winner->getAttribute('revision');
        $this->put('/erp/purchase/rfqs/'.$rfq->id.'/quotations/'.$winner->id, $data)->assertSessionHasErrors('status');
        $this->assertDatabaseHas('purchase_rfq_audit_logs', ['rfq_id' => $rfq->id, 'action' => 'awarded', 'comment' => 'Best value and delivery']);
    }

    public function test_expired_quotation_cannot_be_awarded(): void
    {
        [, , , $a, , $rfq] = $this->draft();
        $this->issue($rfq);
        $quote = $this->quotation($rfq, $a);
        $quote->update(['valid_until' => now()->subDay()->toDateString()]);
        $this->post('/erp/purchase/rfqs/'.$rfq->id.'/award', ['revision' => (int) $rfq->refresh()->getAttribute('revision'), 'quotation_id' => $quote->id, 'reason' => 'Test'])->assertSessionHasErrors('quotation_id');
        $this->assertSame('issued', $rfq->refresh()->status);
    }

    public function test_award_requires_reason_and_rechecks_totals(): void
    {
        [, , , $a, , $rfq] = $this->draft();
        $this->issue($rfq);
        $quote = $this->quotation($rfq, $a);
        $data = ['revision' => (int) $rfq->refresh()->getAttribute('revision'), 'quotation_id' => $quote->id];
        $this->post('/erp/purchase/rfqs/'.$rfq->id.'/award', $data)->assertSessionHasErrors('reason');
        $quote->update(['total_amount' => '0.01']);
        $this->post('/erp/purchase/rfqs/'.$rfq->id.'/award', [...$data, 'reason' => 'Test'])->assertSessionHasErrors('quotation_id');
        $this->assertSame('issued', $rfq->refresh()->status);
    }

    public function test_employee_company_scope_applies_to_list_show_compare_and_writes(): void
    {
        [, , , , , $rfq] = $this->draft();
        $other = $this->company('C-B');
        $actor = $this->employee($other, ['erp.purchase-rfqs.view', 'erp.purchase-rfqs.issue', 'erp.supplier-quotations.compare']);
        $this->actingAs($actor)->get('/erp/purchase/rfqs')->assertInertia(fn (Assert $page) => $page->has('rfqs.data', 0)->has('companies', 1));
        $this->get('/erp/purchase/rfqs/'.$rfq->id)->assertNotFound();
        $this->get('/erp/purchase/rfqs/'.$rfq->id.'/compare')->assertNotFound();
        $this->post('/erp/purchase/rfqs/'.$rfq->id.'/issue', ['revision' => 1])->assertNotFound();
    }

    public function test_rfq_view_does_not_expose_quotation_prices_without_commercial_permission(): void
    {
        [, $company, , $a, , $rfq] = $this->draft();
        $this->issue($rfq);
        $this->quotation($rfq, $a);
        $actor = $this->employee($company, ['erp.purchase-rfqs.view']);
        $this->actingAs($actor)->get('/erp/purchase/rfqs/'.$rfq->id)->assertInertia(fn (Assert $page) => $page->has('quotations', 0));
        $this->get('/erp/purchase/rfqs/'.$rfq->id.'/compare')->assertForbidden();
    }

    public function test_award_requires_comparison_permission_too(): void
    {
        [, $company, , $a, , $rfq] = $this->draft();
        $this->issue($rfq);
        $quote = $this->quotation($rfq, $a);
        $actor = $this->employee($company, ['erp.purchase-rfqs.award']);
        $this->actingAs($actor)->post('/erp/purchase/rfqs/'.$rfq->id.'/award', ['revision' => (int) $rfq->refresh()->getAttribute('revision'), 'quotation_id' => $quote->id, 'reason' => 'Test'])->assertForbidden();
    }

    public function test_nested_quotation_cannot_be_edited_through_another_rfq(): void
    {
        [, $company, , $a, , $rfq] = $this->draft();
        $this->issue($rfq);
        $quote = $this->quotation($rfq, $a);
        $other = Rfq::query()->create(['company_id' => $company->id, 'purchase_requisition_id' => $rfq->purchase_requisition_id, 'created_by' => $rfq->created_by, 'number' => 'RFQ-OTHER', 'status' => 'issued', 'currency_code' => 'BDT']);
        $this->get('/erp/purchase/rfqs/'.$other->id.'/quotations/'.$quote->id.'/edit')->assertNotFound();
    }

    public function test_page_components_resolve_and_source_options_are_server_loaded(): void
    {
        [, , , , , $rfq] = $this->draft();
        $this->get('/erp/purchase/rfqs/create')->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Rfqs/Form')->has('requisitions', 1)->has('suppliers', 2));
        $this->get('/erp/purchase/rfqs/'.$rfq->id.'/edit')->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Rfqs/Form')->has('selectedSuppliers', 2));
        $this->get('/erp/purchase/rfqs/'.$rfq->id)->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Rfqs/Show')->has('items', 1));
        $this->issue($rfq);
        $this->get('/erp/purchase/rfqs/'.$rfq->id.'/quotations/create')->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Rfqs/QuotationForm')->has('items', 1)->has('suppliers', 2));
    }

    public function test_stale_issue_does_not_overwrite_a_newer_draft(): void
    {
        [, , , $a, , $rfq] = $this->draft();
        $this->put('/erp/purchase/rfqs/'.$rfq->id, ['revision' => 1, 'supplier_ids' => [$a->id]])->assertSessionHasNoErrors();
        $this->post('/erp/purchase/rfqs/'.$rfq->id.'/issue', ['revision' => 1])->assertSessionHasErrors('revision');
        $this->assertSame('draft', $rfq->refresh()->status);
    }

    public function test_award_rejects_a_quotation_from_another_rfq(): void
    {
        [, $company, , $a, , $rfq] = $this->draft();
        $this->issue($rfq);
        $foreign = Rfq::query()->create(['company_id' => $company->id, 'purchase_requisition_id' => $rfq->purchase_requisition_id, 'created_by' => $rfq->created_by, 'number' => 'RFQ-FOREIGN', 'status' => 'issued', 'currency_code' => 'BDT']);
        $quote = SupplierQuotation::query()->create(['company_id' => $company->id, 'rfq_id' => $foreign->id, 'supplier_id' => $a->id, 'quotation_number' => 'FOREIGN', 'currency_code' => 'BDT', 'status' => 'submitted']);
        $this->post('/erp/purchase/rfqs/'.$rfq->id.'/award', ['revision' => 2, 'quotation_id' => $quote->id, 'reason' => 'Test'])->assertNotFound();
        $this->assertSame('issued', $rfq->refresh()->status);
    }

    public function test_rfq_and_quotation_workflow_does_not_create_stock_or_orders(): void
    {
        $before = DB::table('stock_movements')->count();
        [, , , $a, , $rfq] = $this->draft();
        $this->issue($rfq);
        $quote = $this->quotation($rfq, $a);
        $this->post('/erp/purchase/rfqs/'.$rfq->id.'/award', ['revision' => (int) $rfq->refresh()->getAttribute('revision'), 'quotation_id' => $quote->id, 'reason' => 'Test'])->assertSessionHasNoErrors();
        $this->assertSame($before, DB::table('stock_movements')->count());
        $this->assertDatabaseCount('purchase_orders', 0);
    }
}
