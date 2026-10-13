<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierAddress;
use App\Models\SupplierContact;
use App\Models\User;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class SupplierManagementTest extends TestCase
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

    private function supplier(Company $company, string $code = 'S-1'): Supplier
    {
        return Supplier::query()->create(['company_id' => $company->id, 'code' => $code, 'name' => 'Supplier '.$code, 'currency_code' => 'BDT', 'status' => 'active']);
    }

    /** @return array<string, mixed> */
    private function data(Company $company, string $code = 'S-1'): array
    {
        return ['company_id' => $company->id, 'code' => $code, 'name' => 'Supplier', 'currency_code' => 'bdt', 'status' => 'active', 'payment_terms_days' => 30];
    }

    public function test_anonymous_user_cannot_access_suppliers(): void
    {
        $this->get('/erp/purchase/suppliers')->assertRedirect('/login');
    }

    public function test_permission_is_required_for_list_and_write_routes(): void
    {
        $user = User::factory()->create();
        $company = $this->company();
        $this->actingAs($user)->get('/erp/purchase/suppliers')->assertForbidden();
        $this->post('/erp/purchase/suppliers', $this->data($company))->assertForbidden();
    }

    public function test_index_only_lists_employee_company_suppliers_and_company_options(): void
    {
        $a = $this->company();
        $b = $this->company('C-B');
        $supplier = $this->supplier($a);
        $this->supplier($b, 'SECRET');
        $user = $this->employee($a, ['erp.suppliers.view']);
        $this->actingAs($user)->get('/erp/purchase/suppliers')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('ERP/Purchase/Suppliers/Index')->has('suppliers.data', 1)
            ->where('suppliers.data.0.id', $supplier->id)->has('companies', 1)->where('companies.0.id', $a->id));
        $this->get('/erp/purchase/suppliers?company_id='.$b->id)->assertNotFound();
    }

    public function test_create_normalizes_currency_and_writes_audit(): void
    {
        $company = $this->company();
        $this->actingAs($this->admin())->post('/erp/purchase/suppliers', $this->data($company))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('suppliers', ['company_id' => $company->id, 'code' => 'S-1', 'currency_code' => 'BDT']);
        $this->assertDatabaseHas('supplier_audit_logs', ['company_id' => $company->id, 'record_type' => 'supplier', 'action' => 'created']);
    }

    public function test_duplicate_supplier_code_is_validation_error_but_allowed_in_other_company(): void
    {
        $a = $this->company();
        $b = $this->company('C-B');
        $this->supplier($a);
        $this->actingAs($this->admin())->post('/erp/purchase/suppliers', $this->data($a))->assertSessionHasErrors('code');
        $this->post('/erp/purchase/suppliers', $this->data($b))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('suppliers', 2);
    }

    public function test_create_permission_does_not_allow_cross_company_write(): void
    {
        $a = $this->company();
        $b = $this->company('C-B');
        $user = $this->employee($a, ['erp.suppliers.create']);
        $this->actingAs($user)->post('/erp/purchase/suppliers', $this->data($b))->assertForbidden();
        $this->assertDatabaseCount('suppliers', 0);
    }

    public function test_cross_company_show_update_and_delete_are_hidden(): void
    {
        $a = $this->company();
        $b = $this->company('C-B');
        $supplier = $this->supplier($b);
        $user = $this->employee($a, ['erp.suppliers.view', 'erp.suppliers.edit', 'erp.suppliers.delete']);
        $this->actingAs($user)->get('/erp/purchase/suppliers/'.$supplier->id)->assertNotFound();
        $data = $this->data($b);
        unset($data['company_id']);
        $this->put('/erp/purchase/suppliers/'.$supplier->id, $data)->assertNotFound();
        $this->delete('/erp/purchase/suppliers/'.$supplier->id)->assertNotFound();
        $this->assertDatabaseCount('suppliers', 1);
    }

    public function test_supplier_company_cannot_be_changed(): void
    {
        $company = $this->company();
        $supplier = $this->supplier($company);
        $this->actingAs($this->admin())->put('/erp/purchase/suppliers/'.$supplier->id, $this->data($company))->assertSessionHasErrors('company_id');
        $this->assertSame((int) $company->id, (int) $supplier->refresh()->company_id);
    }

    public function test_supplier_update_writes_old_and_new_audit_values(): void
    {
        $company = $this->company();
        $supplier = $this->supplier($company);
        $data = $this->data($company);
        unset($data['company_id']);
        $data['name'] = 'Updated supplier';
        $this->actingAs($this->admin())->put('/erp/purchase/suppliers/'.$supplier->id, $data)->assertSessionHasNoErrors();
        $this->assertSame('Updated supplier', $supplier->refresh()->name);
        $row = DB::table('supplier_audit_logs')->where('action', 'updated')->first();
        $this->assertNotNull($row);
        $this->assertSame('Updated supplier', json_decode($row->new_values, true)['name']);
        $this->assertSame('Supplier S-1', json_decode($row->old_values, true)['name']);
    }

    public function test_used_supplier_cannot_be_deleted(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $supplier = $this->supplier($company);
        PurchaseOrder::query()->create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'created_by' => $actor->id, 'number' => 'PO-TEST', 'currency_code' => 'BDT', 'status' => 'draft']);
        $this->actingAs($actor)->delete('/erp/purchase/suppliers/'.$supplier->id)->assertSessionHasErrors('record');
        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id]);
    }

    public function test_unused_supplier_deletion_removes_children_and_retains_history(): void
    {
        $company = $this->company();
        $supplier = $this->supplier($company);
        SupplierContact::query()->create(['supplier_id' => $supplier->id, 'name' => 'Contact']);
        SupplierAddress::query()->create(['supplier_id' => $supplier->id, 'label' => 'Office', 'address_line_1' => 'Dhaka']);
        $this->actingAs($this->admin())->delete('/erp/purchase/suppliers/'.$supplier->id)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('suppliers', 0);
        $this->assertDatabaseCount('supplier_contacts', 0);
        $this->assertDatabaseCount('supplier_addresses', 0);
        $this->assertDatabaseHas('supplier_audit_logs', ['supplier_id' => $supplier->id, 'action' => 'deleted']);
    }

    public function test_contact_permission_is_separate_from_supplier_edit(): void
    {
        $company = $this->company();
        $supplier = $this->supplier($company);
        $user = $this->employee($company, ['erp.suppliers.edit']);
        $this->actingAs($user)->post('/erp/purchase/suppliers/'.$supplier->id.'/contacts', ['name' => 'Contact', 'is_primary' => true])->assertForbidden();
    }

    public function test_new_primary_contact_clears_previous_primary_and_audits_both(): void
    {
        $company = $this->company();
        $supplier = $this->supplier($company);
        $old = SupplierContact::query()->create(['supplier_id' => $supplier->id, 'name' => 'Old', 'is_primary' => true]);
        $this->actingAs($this->admin())->post('/erp/purchase/suppliers/'.$supplier->id.'/contacts', ['name' => 'New', 'is_primary' => true])->assertSessionHasNoErrors();
        $this->assertFalse($old->refresh()->is_primary);
        $this->assertSame(1, SupplierContact::query()->where('supplier_id', $supplier->id)->where('is_primary', true)->count());
        $this->assertDatabaseCount('supplier_audit_logs', 2);
    }

    public function test_default_address_selection_is_unique_per_supplier(): void
    {
        $company = $this->company();
        $supplier = $this->supplier($company);
        $old = SupplierAddress::query()->create(['supplier_id' => $supplier->id, 'label' => 'Old', 'address_line_1' => 'Dhaka', 'is_default' => true]);
        $this->actingAs($this->admin())->post('/erp/purchase/suppliers/'.$supplier->id.'/addresses', ['label' => 'New', 'address_line_1' => 'Chattogram', 'is_default' => true])->assertSessionHasNoErrors();
        $this->assertFalse($old->refresh()->is_default);
        $this->assertSame(1, SupplierAddress::query()->where('supplier_id', $supplier->id)->where('is_default', true)->count());
    }

    public function test_nested_contact_cannot_be_moved_or_deleted_through_another_supplier(): void
    {
        $company = $this->company();
        $a = $this->supplier($company);
        $b = $this->supplier($company, 'S-2');
        $contact = SupplierContact::query()->create(['supplier_id' => $a->id, 'name' => 'Private']);
        $this->actingAs($this->admin())->put('/erp/purchase/suppliers/'.$b->id.'/contacts/'.$contact->id, ['name' => 'Hijacked', 'is_primary' => false])->assertNotFound();
        $this->delete('/erp/purchase/suppliers/'.$b->id.'/contacts/'.$contact->id)->assertNotFound();
        $this->assertSame('Private', $contact->refresh()->name);
    }

    public function test_nested_address_company_boundary_is_enforced(): void
    {
        $a = $this->company();
        $b = $this->company('C-B');
        $supplier = $this->supplier($b);
        $address = SupplierAddress::query()->create(['supplier_id' => $supplier->id, 'label' => 'Private', 'address_line_1' => 'Dhaka']);
        $actor = $this->employee($a, ['erp.suppliers.addresses.manage']);
        $this->actingAs($actor)->delete('/erp/purchase/suppliers/'.$supplier->id.'/addresses/'.$address->id)->assertNotFound();
    }

    public function test_contact_and_address_validation_and_parent_tampering_are_rejected(): void
    {
        $company = $this->company();
        $supplier = $this->supplier($company);
        $this->actingAs($this->admin())->post('/erp/purchase/suppliers/'.$supplier->id.'/contacts', ['name' => '', 'email' => 'invalid', 'is_primary' => true])->assertSessionHasErrors(['name', 'email']);
        $this->post('/erp/purchase/suppliers/'.$supplier->id.'/addresses', ['label' => 'Office', 'is_default' => true])->assertSessionHasErrors('address_line_1');
        $this->post('/erp/purchase/suppliers/'.$supplier->id.'/contacts', ['name' => 'Contact', 'is_primary' => true, 'supplier_id' => $supplier->id])->assertSessionHasErrors('supplier_id');
        $this->assertDatabaseCount('supplier_contacts', 0);
        $this->assertDatabaseCount('supplier_addresses', 0);
    }

    public function test_contact_and_address_can_be_updated_and_deleted_with_history(): void
    {
        $company = $this->company();
        $supplier = $this->supplier($company);
        $contact = SupplierContact::query()->create(['supplier_id' => $supplier->id, 'name' => 'Old contact']);
        $address = SupplierAddress::query()->create(['supplier_id' => $supplier->id, 'label' => 'Office', 'address_line_1' => 'Old address']);
        $this->actingAs($this->admin())->put('/erp/purchase/suppliers/'.$supplier->id.'/contacts/'.$contact->id, ['name' => 'Updated contact', 'is_primary' => true])->assertSessionHasNoErrors();
        $this->put('/erp/purchase/suppliers/'.$supplier->id.'/addresses/'.$address->id, ['label' => 'Office', 'address_line_1' => 'Updated address', 'is_default' => true])->assertSessionHasNoErrors();
        $this->assertSame('Updated contact', $contact->refresh()->name);
        $this->assertSame('Updated address', $address->refresh()->address_line_1);
        $this->delete('/erp/purchase/suppliers/'.$supplier->id.'/contacts/'.$contact->id)->assertSessionHasNoErrors();
        $this->delete('/erp/purchase/suppliers/'.$supplier->id.'/addresses/'.$address->id)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('supplier_contacts', 0);
        $this->assertDatabaseCount('supplier_addresses', 0);
        $this->assertDatabaseCount('supplier_audit_logs', 4);
    }

    public function test_create_edit_and_profile_pages_resolve_existing_erp_components(): void
    {
        $company = $this->company();
        $supplier = $this->supplier($company);
        $this->actingAs($this->admin())->get('/erp/purchase/suppliers/create')->assertInertia(fn (Assert $page) => $page
            ->component('ERP/Purchase/Suppliers/Form')->where('supplier', null)->has('companies', 1));
        $this->get('/erp/purchase/suppliers/'.$supplier->id.'/edit')->assertInertia(fn (Assert $page) => $page
            ->component('ERP/Purchase/Suppliers/Form')->where('supplier.id', $supplier->id));
        $this->get('/erp/purchase/suppliers/'.$supplier->id)->assertInertia(fn (Assert $page) => $page
            ->component('ERP/Purchase/Suppliers/Show')->where('supplier.id', $supplier->id)->has('contacts', 0)->has('addresses', 0));
    }

    public function test_search_and_status_filters_keep_company_scope(): void
    {
        $a = $this->company();
        $b = $this->company('C-B');
        $this->supplier($a, 'MATCH')->update(['status' => 'inactive']);
        $this->supplier($b, 'MATCH');
        $this->supplier($a, 'OTHER');
        $user = $this->employee($a, ['erp.suppliers.view']);
        $this->actingAs($user)->get('/erp/purchase/suppliers?search=MATCH&status=inactive')->assertInertia(fn (Assert $page) => $page
            ->has('suppliers.data', 1)->where('suppliers.data.0.code', 'MATCH')->where('suppliers.data.0.company_id', $a->id));
    }
}
