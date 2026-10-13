<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseRequisition;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class PurchaseRequisitionManagementTest extends TestCase
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
        return Company::query()->create(['name' => $code, 'code' => $code, 'status' => 'active', 'currency_code' => 'BDT']);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        return $user;
    }

    /** @param list<string> $permissions */
    private function employee(Company $company, array $permissions): User
    {
        $user = User::factory()->create();
        Employee::query()->create(['user_id' => $user->id, 'company_id' => $company->id, 'employee_number' => 'EMP-'.$user->id, 'first_name' => 'Test', 'last_name' => 'Employee']);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function product(Company $company, bool $variants = false): Product
    {
        $unit = Unit::query()->firstOrCreate(['company_id' => $company->id, 'name' => 'Piece'], ['short_name' => 'pc']);

        return Product::query()->create(['company_id' => $company->id, 'unit_id' => $unit->id, 'name' => 'Product', 'purchase_price' => '10.00', 'is_active' => true, 'has_variants' => $variants]);
    }

    /** @return array<string, mixed> */
    private function data(Company $company, Product $product): array
    {
        return ['company_id' => $company->id, 'department_id' => null, 'required_at' => '2026-10-20', 'notes' => 'ক্রয়ের অনুরোধ', 'items' => [
            ['product_id' => $product->id, 'product_variant_id' => null, 'quantity' => '2.5000', 'estimated_unit_price' => '10.00', 'notes' => 'Test item'],
        ]];
    }

    /** @return array{User, Company, Product, PurchaseRequisition} */
    private function draft(): array
    {
        $company = $this->company();
        $product = $this->product($company);
        $actor = $this->admin();
        $this->actingAs($actor)->post('/erp/purchase/requisitions', $this->data($company, $product))->assertSessionHasNoErrors();
        $document = PurchaseRequisition::query()->firstOrFail();

        return [$actor, $company, $product, $document];
    }

    /** @return array<string, mixed> */
    private function updateData(Company $company, Product $product, int $revision): array
    {
        $data = $this->data($company, $product);
        unset($data['company_id']);
        $data['revision'] = $revision;

        return $data;
    }

    public function test_anonymous_and_unprivileged_users_cannot_access_requisitions(): void
    {
        $this->get('/erp/purchase/requisitions')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/erp/purchase/requisitions')->assertForbidden();
        $this->get('/erp/purchase/requisitions/create')->assertForbidden();
    }

    public function test_create_saves_items_currency_requester_and_audit(): void
    {
        [$actor, $company, , $document] = $this->draft();
        $this->assertSame('draft', $document->status);
        $this->assertSame('BDT', $document->getAttribute('currency_code'));
        $this->assertSame(1, (int) $document->getAttribute('revision'));
        $this->assertSame((int) $actor->id, (int) $document->requested_by);
        $this->assertSame('ক্রয়ের অনুরোধ', $document->notes);
        $this->assertDatabaseHas('purchase_requisition_items', ['purchase_requisition_id' => $document->id, 'quantity' => 2.5]);
        $this->assertDatabaseHas('purchase_requisition_audit_logs', ['company_id' => $company->id, 'action' => 'created']);
        $this->assertDatabaseCount('purchase_workflow_events', 1);
    }

    public function test_list_and_company_options_are_company_scoped(): void
    {
        [, $a, , $document] = $this->draft();
        $b = $this->company('C-B');
        PurchaseRequisition::query()->create(['company_id' => $b->id, 'requested_by' => $document->requested_by, 'number' => 'PR-SECRET', 'status' => 'draft']);
        $actor = $this->employee($a, ['erp.purchase-requisitions.view']);
        $this->actingAs($actor)->get('/erp/purchase/requisitions')->assertInertia(fn (Assert $page) => $page
            ->component('ERP/Purchase/Requisitions/Index')->has('requisitions.data', 1)->where('requisitions.data.0.id', $document->id)->has('companies', 1)->where('companies.0.id', $a->id));
        $this->get('/erp/purchase/requisitions?company_id='.$b->id)->assertNotFound();
    }

    public function test_employee_cannot_create_for_another_company(): void
    {
        $a = $this->company();
        $b = $this->company('C-B');
        $product = $this->product($b);
        $actor = $this->employee($a, ['erp.purchase-requisitions.create']);
        $this->actingAs($actor)->post('/erp/purchase/requisitions', $this->data($b, $product))->assertForbidden();
        $this->assertDatabaseCount('purchase_requisitions', 0);
    }

    public function test_cross_company_product_rolls_back_document_sequence_and_audit(): void
    {
        $a = $this->company();
        $b = $this->company('C-B');
        $this->actingAs($this->admin())->post('/erp/purchase/requisitions', $this->data($a, $this->product($b)))->assertSessionHasErrors('items.0.product_id');
        $this->assertDatabaseCount('purchase_requisitions', 0);
        $this->assertDatabaseCount('purchase_document_sequences', 0);
        $this->assertDatabaseCount('purchase_workflow_events', 0);
        $this->assertDatabaseCount('purchase_requisition_audit_logs', 0);
    }

    public function test_department_must_be_active_in_document_company(): void
    {
        $a = $this->company();
        $b = $this->company('C-B');
        $department = Department::query()->create(['company_id' => $b->id, 'name' => 'Other department', 'code' => 'DEPT-OTHER', 'status' => 'active']);
        $data = $this->data($a, $this->product($a));
        $data['department_id'] = $department->id;
        $this->actingAs($this->admin())->post('/erp/purchase/requisitions', $data)->assertSessionHasErrors('department_id');
        $department->update(['company_id' => $a->id, 'status' => 'inactive']);
        $this->post('/erp/purchase/requisitions', $data)->assertSessionHasErrors('department_id');
    }

    public function test_variant_is_required_for_variant_products_and_must_match_product(): void
    {
        $company = $this->company();
        $product = $this->product($company, true);
        $other = $this->product($company, true);
        $variant = ProductVariant::query()->create(['product_id' => $other->id, 'name' => 'Wrong variant', 'is_active' => true]);
        $data = $this->data($company, $product);
        $this->actingAs($this->admin())->post('/erp/purchase/requisitions', $data)->assertSessionHasErrors('items.0.product_variant_id');
        $data['items'][0]['product_variant_id'] = $variant->id;
        $this->post('/erp/purchase/requisitions', $data)->assertSessionHasErrors('items.0.product_variant_id');
        $variant->update(['product_id' => $product->id, 'is_active' => false]);
        $this->post('/erp/purchase/requisitions', $data)->assertSessionHasErrors('items.0.product_variant_id');
        $variant->update(['is_active' => true]);
        $this->post('/erp/purchase/requisitions', $data)->assertSessionHasNoErrors();
    }

    public function test_duplicate_product_variant_rows_are_rejected(): void
    {
        $company = $this->company();
        $data = $this->data($company, $this->product($company));
        $data['items'][] = $data['items'][0];
        $this->actingAs($this->admin())->post('/erp/purchase/requisitions', $data)->assertSessionHasErrors('items.1.product_id');
        $this->assertDatabaseCount('purchase_requisitions', 0);
    }

    public function test_quantity_price_precision_and_limits_are_validated(): void
    {
        $company = $this->company();
        $data = $this->data($company, $this->product($company));
        $this->actingAs($this->admin());
        foreach (['0', '-1', '1.00001', '1000001', '1e3'] as $quantity) {
            $bad = $data;
            $bad['items'][0]['quantity'] = $quantity;
            $this->post('/erp/purchase/requisitions', $bad)->assertSessionHasErrors('items.0.quantity');
        }
        foreach (['-1', '10.001', '1000001'] as $price) {
            $bad = $data;
            $bad['items'][0]['estimated_unit_price'] = $price;
            $this->post('/erp/purchase/requisitions', $bad)->assertSessionHasErrors('items.0.estimated_unit_price');
        }
        $this->assertDatabaseCount('purchase_requisitions', 0);
    }

    public function test_edit_replaces_draft_items_and_increments_revision_without_changing_number_or_currency(): void
    {
        [, $company, $product, $document] = $this->draft();
        $company->update(['currency_code' => 'USD']);
        $data = $this->updateData($company, $product, 1);
        $data['items'][0]['quantity'] = '5.0000';
        $this->put('/erp/purchase/requisitions/'.$document->id, $data)->assertSessionHasNoErrors();
        $changed = $document->fresh();
        $this->assertSame($document->number, $changed->number);
        $this->assertSame('BDT', $changed->getAttribute('currency_code'));
        $this->assertSame(2, (int) $changed->getAttribute('revision'));
        $this->assertDatabaseCount('purchase_requisition_items', 1);
        $this->assertDatabaseHas('purchase_requisition_items', ['purchase_requisition_id' => $document->id, 'quantity' => 5]);
        $this->assertDatabaseCount('purchase_requisition_audit_logs', 2);
    }

    public function test_stale_draft_edit_is_rejected_without_overwriting_items(): void
    {
        [, $company, $product, $document] = $this->draft();
        $data = $this->updateData($company, $product, 1);
        $data['notes'] = 'First edit';
        $this->put('/erp/purchase/requisitions/'.$document->id, $data)->assertSessionHasNoErrors();
        $data['notes'] = 'Stale overwrite';
        $this->put('/erp/purchase/requisitions/'.$document->id, $data)->assertSessionHasErrors('revision');
        $this->assertSame('First edit', $document->refresh()->notes);
        $this->assertDatabaseCount('purchase_requisition_audit_logs', 2);
    }

    public function test_stale_submission_is_rejected_after_an_edit(): void
    {
        [, $company, $product, $document] = $this->draft();
        $this->put('/erp/purchase/requisitions/'.$document->id, $this->updateData($company, $product, 1))->assertSessionHasNoErrors();
        $this->post('/erp/purchase/requisitions/'.$document->id.'/submit', ['revision' => 1])->assertSessionHasErrors('revision');
        $this->assertSame('draft', $document->refresh()->status);
        $this->assertDatabaseCount('purchase_workflow_events', 1);
    }

    public function test_submission_locks_edits_and_approval_records_different_actor(): void
    {
        [, $company, $product, $document] = $this->draft();
        $this->post('/erp/purchase/requisitions/'.$document->id.'/submit', ['revision' => 1])->assertSessionHasNoErrors();
        $this->assertSame('submitted', $document->refresh()->status);
        $this->get('/erp/purchase/requisitions/'.$document->id.'/edit')->assertForbidden();
        $this->put('/erp/purchase/requisitions/'.$document->id, $this->updateData($company, $product, 2))->assertSessionHasErrors('status');
        $approver = $this->admin();
        $this->actingAs($approver)->post('/erp/purchase/requisitions/'.$document->id.'/approve', ['revision' => 2, 'comment' => 'Approved for purchase'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $document->refresh()->status);
        $this->assertSame((int) $approver->id, (int) $document->approved_by);
        $this->assertSame(3, (int) $document->getAttribute('revision'));
        $this->assertNotNull($document->approved_at);
        $this->assertDatabaseHas('purchase_workflow_events', ['document_id' => $document->id, 'to_status' => 'approved', 'comment' => 'Approved for purchase']);
        $this->assertDatabaseCount('purchase_requisition_audit_logs', 3);
    }

    public function test_self_approval_is_rejected_without_revision_or_history_change(): void
    {
        [, , , $document] = $this->draft();
        $this->post('/erp/purchase/requisitions/'.$document->id.'/submit', ['revision' => 1]);
        $this->post('/erp/purchase/requisitions/'.$document->id.'/approve', ['revision' => 2])->assertSessionHasErrors('approved_by');
        $this->assertSame('submitted', $document->refresh()->status);
        $this->assertSame(2, (int) $document->getAttribute('revision'));
        $this->assertDatabaseCount('purchase_workflow_events', 2);
        $this->assertDatabaseCount('purchase_requisition_audit_logs', 2);
    }

    public function test_rejection_requires_reason_and_is_terminal(): void
    {
        [, , , $document] = $this->draft();
        $this->post('/erp/purchase/requisitions/'.$document->id.'/submit', ['revision' => 1]);
        $this->post('/erp/purchase/requisitions/'.$document->id.'/reject', ['revision' => 2, 'comment' => ' '])->assertSessionHasErrors('comment');
        $this->post('/erp/purchase/requisitions/'.$document->id.'/reject', ['revision' => 2, 'comment' => 'Budget unavailable'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $document->refresh()->status);
        $this->post('/erp/purchase/requisitions/'.$document->id.'/submit', ['revision' => 3])->assertSessionHasErrors('status');
        $this->assertDatabaseHas('purchase_workflow_events', ['document_id' => $document->id, 'to_status' => 'rejected', 'comment' => 'Budget unavailable']);
    }

    public function test_cross_company_show_edit_and_review_are_hidden(): void
    {
        [, , , $document] = $this->draft();
        $other = $this->company('C-B');
        $actor = $this->employee($other, ['erp.purchase-requisitions.view', 'erp.purchase-requisitions.edit', 'erp.purchase-requisitions.submit']);
        $this->actingAs($actor)->get('/erp/purchase/requisitions/'.$document->id)->assertNotFound();
        $this->get('/erp/purchase/requisitions/'.$document->id.'/edit')->assertNotFound();
        $this->post('/erp/purchase/requisitions/'.$document->id.'/submit', ['revision' => 1])->assertNotFound();
    }

    public function test_catalog_requires_create_or_edit_and_company_access(): void
    {
        $a = $this->company();
        $b = $this->company('C-B');
        $this->product($a);
        $viewer = $this->employee($a, ['erp.purchase-requisitions.view']);
        $this->actingAs($viewer)->getJson('/erp/purchase/requisitions/catalog?company_id='.$a->id)->assertForbidden();
        $editor = $this->employee($a, ['erp.purchase-requisitions.edit']);
        $this->actingAs($editor)->getJson('/erp/purchase/requisitions/catalog?company_id='.$a->id)->assertOk()->assertJsonCount(1, 'products');
        $this->getJson('/erp/purchase/requisitions/catalog?company_id='.$b->id)->assertForbidden();
    }

    public function test_catalog_search_pagination_and_active_filters(): void
    {
        $company = $this->company();
        for ($i = 0; $i < 21; $i++) {
            $this->product($company)->update(['name' => 'Catalog '.$i]);
        }
        $this->product($company)->update(['name' => 'Inactive', 'is_active' => false]);
        $this->actingAs($this->admin())->getJson('/erp/purchase/requisitions/catalog?company_id='.$company->id)->assertOk()->assertJsonCount(20, 'products')->assertJsonPath('has_more', true);
        $this->getJson('/erp/purchase/requisitions/catalog?company_id='.$company->id.'&page=2')->assertJsonCount(1, 'products')->assertJsonPath('has_more', false);
        $this->getJson('/erp/purchase/requisitions/catalog?company_id='.$company->id.'&search=Inactive')->assertJsonCount(0, 'products');
    }

    public function test_body_cannot_override_workflow_fields_or_company_ownership(): void
    {
        [, $company, $product, $document] = $this->draft();
        $data = $this->data($company, $product);
        $data['status'] = 'approved';
        $data['requested_by'] = $this->admin()->id;
        $this->post('/erp/purchase/requisitions', $data)->assertSessionHasErrors(['status', 'requested_by']);
        $update = $this->updateData($company, $product, 1);
        $update['company_id'] = $company->id;
        $this->put('/erp/purchase/requisitions/'.$document->id, $update)->assertSessionHasErrors('company_id');
        $this->assertSame('draft', $document->refresh()->status);
    }

    public function test_approval_revalidates_product_activity_after_submission(): void
    {
        [, , $product, $document] = $this->draft();
        $this->post('/erp/purchase/requisitions/'.$document->id.'/submit', ['revision' => 1]);
        $product->update(['is_active' => false]);
        $this->actingAs($this->admin())->post('/erp/purchase/requisitions/'.$document->id.'/approve', ['revision' => 2])->assertSessionHasErrors('items.0.product_id');
        $this->assertSame('submitted', $document->refresh()->status);
        $this->assertDatabaseCount('purchase_workflow_events', 2);
    }

    public function test_creation_submission_and_rejection_do_not_change_inventory(): void
    {
        $before = DB::table('stock_movements')->count();
        [, , , $document] = $this->draft();
        $this->post('/erp/purchase/requisitions/'.$document->id.'/submit', ['revision' => 1]);
        $this->post('/erp/purchase/requisitions/'.$document->id.'/reject', ['revision' => 2, 'comment' => 'Not needed']);
        $this->assertSame($before, DB::table('stock_movements')->count());
    }

    public function test_create_edit_and_show_pages_expose_expected_components(): void
    {
        [, , , $document] = $this->draft();
        $this->get('/erp/purchase/requisitions/create')->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Requisitions/Form')->where('requisition', null)->has('initialProducts', 1)->where('initialProducts.0.name', 'Product')->has('initialDepartments', 0));
        $this->get('/erp/purchase/requisitions/'.$document->id.'/edit')->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Requisitions/Form')->has('items', 1)->has('initialProducts', 1));
        $this->get('/erp/purchase/requisitions/'.$document->id)->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Requisitions/Show')->has('items', 1)->has('history', 1)->has('changes', 1)->where('requester.id', $document->requested_by));
    }

    public function test_review_endpoints_require_their_dedicated_permissions(): void
    {
        [, $company, , $document] = $this->draft();
        $viewer = $this->employee($company, ['erp.purchase-requisitions.view']);
        $this->actingAs($viewer);
        foreach (['submit', 'approve', 'reject'] as $action) {
            $this->post('/erp/purchase/requisitions/'.$document->id.'/'.$action, ['revision' => 1, 'comment' => 'Test'])->assertForbidden();
        }
        $this->assertSame('draft', $document->refresh()->status);
    }

    public function test_create_preloads_active_company_products_and_pagination_flag(): void
    {
        $company = $this->company();
        for ($i = 0; $i < 21; $i++) {
            $this->product($company);
        }
        $this->product($company)->update(['is_active' => false]);
        $this->actingAs($this->admin())->get('/erp/purchase/requisitions/create')->assertInertia(fn (Assert $page) => $page
            ->component('ERP/Purchase/Requisitions/Form')->has('initialProducts', 20)
            ->where('defaultCompanyId', $company->id)->where('initialHasMore', true));
    }

    public function test_create_company_query_preloads_only_authorized_company_products(): void
    {
        $a = $this->company();
        $b = $this->company('C-B');
        $this->product($a)->update(['name' => 'Authorized product']);
        $this->product($b)->update(['name' => 'Private product']);
        $actor = $this->employee($a, ['erp.purchase-requisitions.create']);
        $this->actingAs($actor)->get('/erp/purchase/requisitions/create?company_id='.$a->id)->assertInertia(fn (Assert $page) => $page
            ->has('initialProducts', 1)->where('initialProducts.0.name', 'Authorized product'));
        $this->get('/erp/purchase/requisitions/create?company_id='.$b->id)->assertNotFound();
    }

    public function test_invalid_or_missing_items_do_not_create_a_document(): void
    {
        $company = $this->company();
        $data = $this->data($company, $this->product($company));
        $data['items'] = [];
        $this->actingAs($this->admin())->post('/erp/purchase/requisitions', $data)->assertSessionHasErrors('items');
        $data['items'] = array_fill(0, 101, ['product_id' => 1, 'quantity' => '1', 'estimated_unit_price' => '0']);
        $this->post('/erp/purchase/requisitions', $data)->assertSessionHasErrors('items');
        $this->assertDatabaseCount('purchase_requisitions', 0);
    }
}
