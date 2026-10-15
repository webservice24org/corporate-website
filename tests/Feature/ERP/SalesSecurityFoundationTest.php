<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesDocumentEvent;
use App\Models\SalesQuotation;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ERP\Sales\SalesAuditService;
use App\Services\ERP\Sales\SalesDocumentGuard;
use App\Services\ERP\Sales\SalesDomainRegistry;
use App\Services\ERP\Sales\SalesSourceGuard;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

final class SalesSecurityFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SalesPermissionSeeder::class);
    }

    private function company(string $code = 'SALES-A'): Company
    {
        return Company::query()->create(['name' => $code, 'code' => $code, 'status' => 'active', 'currency_code' => 'BDT']);
    }

    private function admin(): User
    {
        $actor = User::factory()->create();
        $actor->assignRole('super-admin');

        return $actor;
    }

    /** @param list<string> $permissions */
    private function member(Company $company, array $permissions = []): User
    {
        $actor = User::factory()->create();
        Employee::query()->create(['user_id' => $actor->getKey(), 'company_id' => $company->getKey(), 'employee_number' => 'SE-'.$actor->getKey(), 'first_name' => 'Sales', 'last_name' => 'Member']);
        if ($permissions !== []) {
            $actor->givePermissionTo($permissions);
        }

        return $actor;
    }

    private function customer(Company $company): Customer
    {
        return Customer::query()->create(['company_id' => $company->getKey(), 'name' => 'Sales customer', 'phone' => '01700000000', 'is_active' => true]);
    }

    private function quotation(Company $company, User $creator, string $status = 'draft'): SalesQuotation
    {
        $customer = $this->customer($company);

        return SalesQuotation::query()->create(['company_id' => $company->getKey(), 'customer_id' => $customer->getKey(), 'number' => 'SQ-2026-'.sprintf('%06d', $customer->getKey()), 'document_date' => '2026-10-14', 'currency_code' => 'BDT', 'created_by' => $creator->getKey(), 'status' => $status]);
    }

    private function product(Company $company, bool $hasVariants = false): Product
    {
        $unit = Unit::query()->create(['company_id' => $company->getKey(), 'name' => 'Piece', 'short_name' => 'pc']);

        return Product::query()->create(['company_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'name' => 'Sales product', 'is_active' => true, 'has_variants' => $hasVariants]);
    }

    public function test_permission_seeding_is_repeatable_and_does_not_grant_ordinary_users_access(): void
    {
        $permissions = app(SalesDomainRegistry::class)->permissions();
        $count = DB::table('permissions')->whereIn('name', $permissions)->count();
        $this->seed(SalesPermissionSeeder::class);
        $this->assertSame(count($permissions), $count);
        $this->assertSame($count, DB::table('permissions')->whereIn('name', $permissions)->count());
        $this->assertFalse(User::factory()->create()->can('erp.sales-quotations.create'));
        $this->assertTrue($this->admin()->can('erp.sales-quotations.create'));
    }

    public function test_employee_with_view_permission_can_read_their_company_document(): void
    {
        $company = $this->company();
        $actor = $this->member($company, ['erp.sales-quotations.view']);
        $document = $this->quotation($company, $actor);
        $this->assertSame($document->getKey(), app(SalesDocumentGuard::class)->view($actor, (int) $company->getKey(), 'quotation', (int) $document->getKey())->getKey());
    }

    public function test_missing_permission_is_forbidden(): void
    {
        $company = $this->company();
        $actor = $this->member($company);
        $document = $this->quotation($company, $actor);
        $this->expectException(AuthorizationException::class);
        app(SalesDocumentGuard::class)->view($actor, (int) $company->getKey(), 'quotation', (int) $document->getKey());
    }

    public function test_employee_cannot_select_another_company_even_with_permission(): void
    {
        $a = $this->company();
        $b = $this->company('SALES-B');
        $actor = $this->member($a, ['erp.sales-quotations.view']);
        $document = $this->quotation($b, $this->admin());
        $this->expectException(AuthorizationException::class);
        app(SalesDocumentGuard::class)->view($actor, (int) $b->getKey(), 'quotation', (int) $document->getKey());
    }

    public function test_other_company_document_id_is_not_found_within_an_authorized_scope(): void
    {
        $a = $this->company();
        $b = $this->company('SALES-B');
        $actor = $this->member($a, ['erp.sales-quotations.view']);
        $document = $this->quotation($b, $this->admin());
        $this->expectException(ModelNotFoundException::class);
        app(SalesDocumentGuard::class)->view($actor, (int) $a->getKey(), 'quotation', (int) $document->getKey());
    }

    public function test_inactive_company_allows_history_but_blocks_writes(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $document = $this->quotation($company, $actor);
        $company->update(['status' => 'inactive']);
        $this->assertSame($document->getKey(), app(SalesDocumentGuard::class)->view($actor, (int) $company->getKey(), 'quotation', (int) $document->getKey())->getKey());
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => app(SalesDocumentGuard::class)->lockForAction($actor, (int) $company->getKey(), 'quotation', (int) $document->getKey(), 'edit'));
    }

    public function test_draft_edit_guard_returns_a_locked_document_without_changing_it(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $document = $this->quotation($company, $actor);
        DB::transaction(function () use ($actor, $company, $document): void {
            $locked = app(SalesDocumentGuard::class)->lockForAction($actor, (int) $company->getKey(), 'quotation', (int) $document->getKey(), 'edit');
            $this->assertSame('draft', $locked->getAttribute('status'));
        });
        $this->assertDatabaseCount('sales_document_events', 0);
    }

    public function test_accepted_quotation_cannot_be_edited(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $document = $this->quotation($company, $actor, 'accepted');
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => app(SalesDocumentGuard::class)->lockForAction($actor, (int) $company->getKey(), 'quotation', (int) $document->getKey(), 'edit'));
    }

    public function test_super_admin_cannot_approve_their_own_document(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $document = $this->quotation($company, $actor, 'submitted');
        $this->expectException(AuthorizationException::class);
        DB::transaction(fn () => app(SalesDocumentGuard::class)->lockForAction($actor, (int) $company->getKey(), 'quotation', (int) $document->getKey(), 'approve'));
    }

    public function test_independent_approver_passes_without_automatically_transitioning_the_document(): void
    {
        $company = $this->company();
        $document = $this->quotation($company, $this->admin(), 'submitted');
        $approver = $this->admin();
        $locked = DB::transaction(fn () => app(SalesDocumentGuard::class)->lockForAction($approver, (int) $company->getKey(), 'quotation', (int) $document->getKey(), 'approve'));
        $this->assertSame('submitted', $locked->getAttribute('status'));
    }

    public function test_arbitrary_model_type_and_unsupported_action_are_rejected(): void
    {
        $registry = app(SalesDomainRegistry::class);
        try {
            $registry->model(User::class);
            $this->fail('Arbitrary model type accepted.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
        $this->expectException(InvalidArgumentException::class);
        $registry->permission('invoice', 'approve');
    }

    public function test_customer_source_is_company_scoped(): void
    {
        $a = $this->company();
        $customer = $this->customer($this->company('SALES-B'));
        $this->expectException(ModelNotFoundException::class);
        DB::transaction(fn () => app(SalesSourceGuard::class)->customer((int) $a->getKey(), (int) $customer->getKey()));
    }

    public function test_inactive_customer_is_rejected(): void
    {
        $company = $this->company();
        $customer = $this->customer($company);
        $customer->update(['is_active' => false]);
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => app(SalesSourceGuard::class)->customer((int) $company->getKey(), (int) $customer->getKey()));
    }

    public function test_contact_from_another_customer_is_rejected(): void
    {
        $company = $this->company();
        $a = $this->customer($company);
        $b = $this->customer($company);
        $contact = CustomerContact::query()->create(['customer_id' => $b->getKey(), 'name' => 'Other contact']);
        $this->expectException(ModelNotFoundException::class);
        DB::transaction(fn () => app(SalesSourceGuard::class)->contact($a, (int) $contact->getKey()));
    }

    public function test_shipping_address_cannot_be_used_as_billing_address(): void
    {
        $company = $this->company();
        $customer = $this->customer($company);
        $address = CustomerAddress::query()->create(['customer_id' => $customer->getKey(), 'address_type' => 'shipping', 'address' => 'Shipping address']);
        $this->expectException(ModelNotFoundException::class);
        DB::transaction(fn () => app(SalesSourceGuard::class)->address($customer, (int) $address->getKey(), 'billing'));
    }

    public function test_product_without_variants_rejects_a_variant(): void
    {
        $company = $this->company();
        $product = $this->product($company);
        $variant = ProductVariant::query()->create(['product_id' => $product->getKey(), 'name' => 'Unexpected variant']);
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => app(SalesSourceGuard::class)->product((int) $company->getKey(), (int) $product->getKey(), (int) $variant->getKey()));
    }

    public function test_variant_product_requires_a_variant(): void
    {
        $company = $this->company();
        $product = $this->product($company, true);
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => app(SalesSourceGuard::class)->product((int) $company->getKey(), (int) $product->getKey()));
    }

    public function test_valid_active_variant_is_accepted(): void
    {
        $company = $this->company();
        $product = $this->product($company, true);
        $variant = ProductVariant::query()->create(['product_id' => $product->getKey(), 'name' => 'Variant', 'is_active' => true]);
        $resolved = DB::transaction(fn () => app(SalesSourceGuard::class)->product((int) $company->getKey(), (int) $product->getKey(), (int) $variant->getKey()));
        $this->assertSame($product->getKey(), $resolved->getKey());
    }

    public function test_currency_must_match_a_valid_company_currency(): void
    {
        $guard = app(SalesSourceGuard::class);
        $guard->currency('BDT', 'BDT');
        $this->expectException(ValidationException::class);
        $guard->currency('BDT', 'USD');
    }

    public function test_audit_event_captures_actor_number_and_trusted_changes(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $document = $this->quotation($company, $actor);
        $event = DB::transaction(fn () => app(SalesAuditService::class)->record($actor, (int) $company->getKey(), 'quotation', (int) $document->getKey(), 'edit', ['revision' => 1], ['revision' => 2]));
        $this->assertSame((int) $actor->getKey(), (int) $event->actor_id);
        $this->assertSame($actor->name, $event->actor_snapshot['name']);
        $this->assertSame($document->number, $event->after_payload['document_number']);
        $this->assertSame(2, $event->after_payload['changes']['revision']);
        $this->assertDatabaseCount('sales_document_events', 1);
    }

    public function test_audit_rejects_cross_company_document_target(): void
    {
        $a = $this->company();
        $b = $this->company('SALES-B');
        $actor = $this->admin();
        $document = $this->quotation($b, $actor);
        $this->expectException(ModelNotFoundException::class);
        DB::transaction(fn () => app(SalesAuditService::class)->record($actor, (int) $a->getKey(), 'quotation', (int) $document->getKey(), 'edit'));
    }

    public function test_cancellation_audit_requires_a_reason(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $document = $this->quotation($company, $actor);
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => app(SalesAuditService::class)->record($actor, (int) $company->getKey(), 'quotation', (int) $document->getKey(), 'cancel', [], [], ' '));
    }

    public function test_audit_rolls_back_with_the_business_transaction(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $document = $this->quotation($company, $actor);
        try {
            DB::transaction(function () use ($company, $actor, $document): void {
                app(SalesAuditService::class)->record($actor, (int) $company->getKey(), 'quotation', (int) $document->getKey(), 'edit');
                throw new RuntimeException('Rollback fixture');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Rollback fixture', $exception->getMessage());
        }
        $this->assertDatabaseCount('sales_document_events', 0);
    }

    public function test_event_model_update_is_rejected(): void
    {
        $event = $this->eventFixture();
        $this->expectException(LogicException::class);
        $event->update(['reason' => 'Rewrite history']);
    }

    public function test_event_model_delete_is_rejected(): void
    {
        $event = $this->eventFixture();
        $this->expectException(LogicException::class);
        $event->delete();
    }

    public function test_stale_document_revision_is_rejected(): void
    {
        $company = $this->company();
        $document = $this->quotation($company, $this->admin());
        $document->update(['revision' => 2]);
        $this->expectException(ValidationException::class);
        app(SalesDocumentGuard::class)->assertRevision($document, 1);
    }

    public function test_product_from_another_company_is_not_found(): void
    {
        $a = $this->company();
        $product = $this->product($this->company('SALES-B'));
        $this->expectException(ModelNotFoundException::class);
        DB::transaction(fn () => app(SalesSourceGuard::class)->product((int) $a->getKey(), (int) $product->getKey()));
    }

    public function test_inactive_variant_is_rejected(): void
    {
        $company = $this->company();
        $product = $this->product($company, true);
        $variant = ProductVariant::query()->create(['product_id' => $product->getKey(), 'name' => 'Inactive', 'is_active' => false]);
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => app(SalesSourceGuard::class)->product((int) $company->getKey(), (int) $product->getKey(), (int) $variant->getKey()));
    }

    public function test_approval_without_creator_provenance_is_rejected(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $document = $this->quotation($company, $actor, 'submitted');
        $document->update(['created_by' => null]);
        $this->expectException(AuthorizationException::class);
        DB::transaction(fn () => app(SalesDocumentGuard::class)->lockForAction($actor, (int) $company->getKey(), 'quotation', (int) $document->getKey(), 'approve'));
    }

    public function test_audit_write_requires_the_specific_action_permission(): void
    {
        $company = $this->company();
        $actor = $this->member($company, ['erp.sales-quotations.view']);
        $document = $this->quotation($company, $actor);
        $this->expectException(AuthorizationException::class);
        DB::transaction(fn () => app(SalesAuditService::class)->record($actor, (int) $company->getKey(), 'quotation', (int) $document->getKey(), 'edit'));
    }

    public function test_audit_cannot_bypass_independent_approval(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $document = $this->quotation($company, $actor, 'submitted');
        $this->expectException(AuthorizationException::class);
        DB::transaction(fn () => app(SalesAuditService::class)->record($actor, (int) $company->getKey(), 'quotation', (int) $document->getKey(), 'approve'));
    }

    public function test_inactive_warehouse_is_rejected(): void
    {
        $company = $this->company();
        $warehouse = Warehouse::query()->create(['company_id' => $company->getKey(), 'code' => 'SALES-WH', 'name' => 'Sales warehouse', 'is_active' => false]);
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => app(SalesSourceGuard::class)->warehouse((int) $company->getKey(), (int) $warehouse->getKey()));
    }

    private function eventFixture(): SalesDocumentEvent
    {
        $company = $this->company();
        $actor = $this->admin();
        $document = $this->quotation($company, $actor);

        return DB::transaction(fn () => app(SalesAuditService::class)->record($actor, (int) $company->getKey(), 'quotation', (int) $document->getKey(), 'create'));
    }
}
