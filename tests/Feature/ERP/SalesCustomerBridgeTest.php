<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Opportunity;
use App\Models\OpportunityStage;
use App\Models\SalesQuotation;
use App\Models\User;
use App\Services\ERP\Sales\SalesCustomerBridge;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class SalesCustomerBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SalesPermissionSeeder::class);
    }

    private function company(string $code = 'SALES-A'): Company
    {
        return Company::query()->create(['name' => $code, 'code' => $code, 'currency_code' => 'BDT', 'status' => 'active']);
    }

    private function admin(): User
    {
        $actor = User::factory()->create();
        $actor->assignRole('super-admin');

        return $actor;
    }

    private function member(Company $company, bool $view = true): User
    {
        $actor = User::factory()->create();
        Employee::query()->create(['user_id' => $actor->getKey(), 'company_id' => $company->getKey(), 'employee_number' => 'SC-'.$actor->getKey(), 'first_name' => 'Sales', 'last_name' => 'Member']);
        if ($view) {
            $actor->givePermissionTo('erp.sales-customers.view');
        }

        return $actor;
    }

    private function customer(Company $company, string $name = 'Sales customer'): Customer
    {
        return Customer::query()->create(['company_id' => $company->getKey(), 'name' => $name, 'phone' => '01700000000', 'is_active' => true, 'credit_limit' => '1000.00', 'payment_terms_days' => 30, 'nid_number' => 'PRIVATE-NID', 'notes' => 'PRIVATE-NOTES']);
    }

    private function lead(Company $company): Lead
    {
        $status = LeadStatus::query()->firstOrCreate(['company_id' => $company->getKey(), 'code' => 'NEW'], ['name' => 'New']);

        return Lead::query()->create(['company_id' => $company->getKey(), 'lead_status_id' => $status->getKey(), 'lead_number' => 'SL-'.(Lead::query()->count() + 1), 'contact_name' => 'Lead customer', 'phone' => '01700000000', 'currency' => 'BDT', 'is_active' => true]);
    }

    private function opportunity(Company $company, Customer $customer): Opportunity
    {
        $stage = OpportunityStage::query()->firstOrCreate(['company_id' => $company->getKey(), 'code' => 'NEW'], ['name' => 'New']);

        return Opportunity::query()->create(['company_id' => $company->getKey(), 'opportunity_stage_id' => $stage->getKey(), 'title' => 'Sales opportunity', 'party_type' => Customer::class, 'party_id' => $customer->getKey(), 'currency' => 'BDT', 'is_active' => true]);
    }

    public function test_workspace_requires_authentication_and_permission(): void
    {
        $this->get('/erp/sales/customers')->assertRedirect('/login');
        $company = $this->company();
        $this->actingAs($this->member($company, false))->get('/erp/sales/customers')->assertForbidden();
    }

    public function test_staff_list_is_scoped_and_excludes_private_crm_fields(): void
    {
        $a = $this->company();
        $b = $this->company('SALES-B');
        $customer = $this->customer($a, 'Own customer');
        $this->customer($b, 'Foreign customer');
        $this->actingAs($this->member($a))->get('/erp/sales/customers')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('ERP/Sales/Customers/Index')->has('companies', 1)->has('customers.data', 1)
            ->where('customers.data.0.id', $customer->getKey())->missing('customers.data.0.nid_number')->missing('customers.data.0.notes'));
    }

    public function test_staff_cannot_request_another_company(): void
    {
        $a = $this->company();
        $b = $this->company('SALES-B');
        $this->actingAs($this->member($a))->get('/erp/sales/customers?company_id='.$b->getKey())->assertForbidden();
    }

    public function test_foreign_customer_id_is_not_found_in_own_scope(): void
    {
        $a = $this->company();
        $foreign = $this->customer($this->company('SALES-B'));
        $this->actingAs($this->member($a))->get('/erp/sales/customers/'.$foreign->getKey().'?company_id='.$a->getKey())->assertNotFound();
    }

    public function test_customer_detail_is_read_only_and_hides_crm_link_without_permission(): void
    {
        $company = $this->company();
        $customer = $this->customer($company);
        $customer->contacts()->create(['name' => 'Contact', 'notes' => 'PRIVATE-CONTACT']);
        $customer->addresses()->create(['address_type' => 'billing', 'address' => 'Billing address']);
        $this->actingAs($this->member($company))->get('/erp/sales/customers/'.$customer->getKey().'?company_id='.$company->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('ERP/Sales/Customers/Show')->where('customer.id', $customer->getKey())->has('contacts', 1)->has('addresses', 1)
            ->where('crmUrl', null)->missing('customer.nid_number')->missing('customer.notes')->missing('contacts.0.notes'));
    }

    public function test_inactive_customer_is_viewable_but_cannot_be_selected(): void
    {
        $company = $this->company();
        $customer = $this->customer($company);
        $customer->update(['is_active' => false]);
        $actor = $this->admin();
        $this->actingAs($actor)->get('/erp/sales/customers/'.$customer->getKey().'?company_id='.$company->getKey())->assertOk();
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => app(SalesCustomerBridge::class)->resolve($actor, (int) $company->getKey(), (int) $customer->getKey()));
    }

    public function test_snapshot_keeps_selected_data_after_master_edits(): void
    {
        $company = $this->company();
        $customer = $this->customer($company);
        $contact = $customer->contacts()->create(['name' => 'Original contact', 'is_primary' => true]);
        $address = $customer->addresses()->create(['address_type' => 'billing', 'address' => 'Original address', 'is_primary' => true]);
        $snapshot = DB::transaction(fn () => app(SalesCustomerBridge::class)->snapshot($this->admin(), (int) $company->getKey(), (int) $customer->getKey()));
        $document = SalesQuotation::query()->create([
            'company_id' => $company->getKey(), 'customer_id' => $customer->getKey(),
            'number' => 'SQ-2026-000001', 'currency_code' => 'BDT', 'document_date' => '2026-10-14',
            ...$snapshot,
        ]);
        $customer->update(['name' => 'Changed name', 'credit_limit' => '2000.00']);
        $contact->update(['name' => 'Changed contact']);
        $address->update(['address' => 'Changed address']);
        $document->refresh();
        $snapshot = $document->only(['customer_snapshot', 'billing_address_snapshot', 'terms_snapshot']);
        $this->assertSame('Sales customer', $snapshot['customer_snapshot']['name']);
        $this->assertSame('Original contact', $snapshot['customer_snapshot']['selected_contact']['name']);
        $this->assertSame('Original address', $snapshot['billing_address_snapshot']['address']);
        $this->assertSame('1000.00', $snapshot['terms_snapshot']['credit_limit']);
        $this->assertArrayNotHasKey('nid_number', $snapshot['customer_snapshot']);
        $this->assertArrayNotHasKey('notes', $snapshot['customer_snapshot']);
    }

    public function test_ambiguous_primary_contacts_require_explicit_selection(): void
    {
        $company = $this->company();
        $customer = $this->customer($company);
        $customer->contacts()->create(['name' => 'One', 'is_primary' => true]);
        $customer->contacts()->create(['name' => 'Two', 'is_primary' => true]);
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => app(SalesCustomerBridge::class)->snapshot($this->admin(), (int) $company->getKey(), (int) $customer->getKey()));
    }

    public function test_explicit_contact_resolves_primary_ambiguity(): void
    {
        $company = $this->company();
        $customer = $this->customer($company);
        $contact = $customer->contacts()->create(['name' => 'One', 'is_primary' => true]);
        $customer->contacts()->create(['name' => 'Two', 'is_primary' => true]);
        $snapshot = DB::transaction(fn () => app(SalesCustomerBridge::class)->snapshot($this->admin(), (int) $company->getKey(), (int) $customer->getKey(), (int) $contact->getKey()));
        $this->assertSame('One', $snapshot['customer_snapshot']['selected_contact']['name']);
    }

    public function test_snapshot_rejects_contact_from_another_customer(): void
    {
        $company = $this->company();
        $a = $this->customer($company);
        $b = $this->customer($company, 'Other');
        $contact = $b->contacts()->create(['name' => 'Other contact']);
        $this->expectException(ModelNotFoundException::class);
        DB::transaction(fn () => app(SalesCustomerBridge::class)->snapshot($this->admin(), (int) $company->getKey(), (int) $a->getKey(), (int) $contact->getKey()));
    }

    public function test_conversion_reuses_existing_crm_service_and_retries_do_not_duplicate(): void
    {
        $company = $this->company();
        $lead = $this->lead($company);
        $actor = $this->admin();
        $payload = ['customer_type' => 'individual', 'name' => 'Converted customer', 'phone' => '01700000000', 'credit_limit' => '9999.00', 'nid_number' => 'DO-NOT-COPY'];
        $bridge = app(SalesCustomerBridge::class);
        $first = $bridge->convertLead($actor, (int) $company->getKey(), (int) $lead->getKey(), $payload);
        $second = $bridge->convertLead($actor, (int) $company->getKey(), (int) $lead->getKey(), $payload);
        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertDatabaseCount('customers', 1);
        $this->assertSame('0.00', $first->getAttribute('credit_limit'));
        $this->assertNull($first->getAttribute('nid_number'));
        $resolved = DB::transaction(fn () => $bridge->resolve($actor, (int) $company->getKey(), leadId: (int) $lead->getKey()));
        $this->assertSame($first->getKey(), $resolved->getKey());
    }

    public function test_unconverted_lead_is_not_silently_converted(): void
    {
        $company = $this->company();
        $lead = $this->lead($company);
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => app(SalesCustomerBridge::class)->resolve($this->admin(), (int) $company->getKey(), leadId: (int) $lead->getKey()));
    }

    public function test_dealer_conversion_target_is_rejected_without_creating_a_customer(): void
    {
        $company = $this->company();
        $lead = $this->lead($company);
        $lead->forceFill(['converted_at' => now(), 'converted_to_type' => 'App\\Models\\Dealer', 'converted_to_id' => 1])->save();
        try {
            app(SalesCustomerBridge::class)->convertLead($this->admin(), (int) $company->getKey(), (int) $lead->getKey(), []);
            $this->fail('Dealer target accepted as customer.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('customers', 0);
        }
    }

    public function test_cross_company_lead_cannot_be_converted(): void
    {
        $a = $this->company();
        $lead = $this->lead($this->company('SALES-B'));
        $this->expectException(ModelNotFoundException::class);
        app(SalesCustomerBridge::class)->convertLead($this->admin(), (int) $a->getKey(), (int) $lead->getKey(), ['customer_type' => 'individual', 'name' => 'No', 'phone' => '01700000000']);
    }

    public function test_conversion_requires_crm_permissions_in_addition_to_sales_selection(): void
    {
        $company = $this->company();
        $actor = $this->member($company);
        $actor->givePermissionTo('erp.sales-customers.select');
        $lead = $this->lead($company);
        $this->expectException(AuthorizationException::class);
        app(SalesCustomerBridge::class)->convertLead($actor, (int) $company->getKey(), (int) $lead->getKey(), []);
    }

    public function test_opportunity_customer_is_resolved_and_mismatch_is_rejected(): void
    {
        $company = $this->company();
        $a = $this->customer($company);
        $b = $this->customer($company, 'Other');
        $opportunity = $this->opportunity($company, $a);
        $actor = $this->admin();
        $bridge = app(SalesCustomerBridge::class);
        $resolved = DB::transaction(fn () => $bridge->resolve($actor, (int) $company->getKey(), opportunityId: (int) $opportunity->getKey()));
        $this->assertSame($a->getKey(), $resolved->getKey());
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => $bridge->resolve($actor, (int) $company->getKey(), (int) $b->getKey(), opportunityId: (int) $opportunity->getKey()));
    }

    public function test_ambiguous_primary_billing_addresses_require_explicit_selection(): void
    {
        $company = $this->company();
        $customer = $this->customer($company);
        foreach (['One', 'Two'] as $address) {
            $customer->addresses()->create(['address_type' => 'billing', 'address' => $address, 'is_primary' => true]);
        }
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => app(SalesCustomerBridge::class)->snapshot($this->admin(), (int) $company->getKey(), (int) $customer->getKey()));
    }

    public function test_opportunity_with_dealer_party_is_rejected(): void
    {
        $company = $this->company();
        $customer = $this->customer($company);
        $opportunity = $this->opportunity($company, $customer);
        $opportunity->update(['party_type' => 'App\\Models\\Dealer']);
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => app(SalesCustomerBridge::class)->resolve($this->admin(), (int) $company->getKey(), opportunityId: (int) $opportunity->getKey()));
    }

    public function test_conversion_cannot_override_company_through_payload(): void
    {
        $company = $this->company();
        $lead = $this->lead($company);
        try {
            app(SalesCustomerBridge::class)->convertLead($this->admin(), (int) $company->getKey(), (int) $lead->getKey(), ['company_id' => $company->getKey(), 'customer_type' => 'individual', 'name' => 'No', 'phone' => '01700000000']);
            $this->fail('Payload company override accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('company_id', $exception->errors());
            $this->assertDatabaseCount('customers', 0);
            $this->assertNull($lead->fresh()->getAttribute('converted_at'));
        }
    }

    public function test_foreign_opportunity_party_cannot_escape_company_scope(): void
    {
        $a = $this->company();
        $foreign = $this->customer($this->company('SALES-B'));
        $opportunity = $this->opportunity($a, $foreign);
        $this->expectException(ModelNotFoundException::class);
        DB::transaction(fn () => app(SalesCustomerBridge::class)->resolve($this->admin(), (int) $a->getKey(), opportunityId: (int) $opportunity->getKey()));
    }
}
