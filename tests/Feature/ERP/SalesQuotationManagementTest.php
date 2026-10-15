<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesDocumentEvent;
use App\Models\SalesQuotation;
use App\Models\Unit;
use App\Models\User;
use App\Services\ERP\Sales\SalesQuotationMath;
use App\Services\ERP\Sales\SalesQuotationService;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class SalesQuotationManagementTest extends TestCase
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
        $this->company = Company::query()->create(['name' => 'Sales A', 'code' => 'SQ-A', 'currency_code' => 'BDT', 'status' => 'active']);
        $this->customer = Customer::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Customer A', 'phone' => '01700000000', 'is_active' => true]);
        $unit = Unit::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Piece', 'short_name' => 'pc', 'is_active' => true]);
        $this->product = Product::query()->create(['company_id' => $this->company->getKey(), 'unit_id' => $unit->getKey(), 'name' => 'Sales product', 'selling_price' => '100.00', 'is_active' => true, 'has_variants' => false]);
        $this->creator = $this->admin();
        $this->approver = $this->admin();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        return $user;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['company_id' => $this->company->getKey(), 'customer_id' => $this->customer->getKey(), 'document_date' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString(), 'items' => [['product_id' => $this->product->getKey(), 'quantity' => '2', 'unit_price' => '100', 'discount_percent' => '10', 'tax_percent' => '15']]];
    }

    private function draft(): SalesQuotation
    {
        return app(SalesQuotationService::class)->save($this->creator, $this->payload());
    }

    private function sent(): SalesQuotation
    {
        $service = app(SalesQuotationService::class);
        $document = $this->draft();
        foreach ([['submit', $this->creator], ['approve', $this->approver], ['send', $this->creator]] as [$action, $actor]) {
            $document = $service->transition($actor, (int) $document->getKey(), $action, (int) $document->getAttribute('revision'));
        }

        return $document;
    }

    public function test_create_edit_show_and_print_components_exist_and_catalog_is_preloaded(): void
    {
        $this->actingAs($this->creator)->get('/erp/sales/quotations/create?company_id='.$this->company->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Sales/Quotations/Form')->has('catalog.products', 1)->has('catalog.customers', 1));
        $document = $this->draft();
        $this->get('/erp/sales/quotations/'.$document->getKey().'/edit')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Sales/Quotations/Form')->has('items', 1));
        $this->get('/erp/sales/quotations/'.$document->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Sales/Quotations/Show')->where('printMode', false));
        $this->get('/erp/sales/quotations/'.$document->getKey().'/print')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Sales/Quotations/Show')->where('printMode', true));
        $this->get('/erp/sales/quotations')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Sales/Quotations/Index')->has('quotations.data', 1));
    }

    public function test_server_calculates_totals_and_ignores_injected_status_currency_and_amounts(): void
    {
        $payload = [...$this->payload(), 'status' => 'accepted', 'currency_code' => 'USD', 'grand_total' => '1.00'];
        $document = app(SalesQuotationService::class)->save($this->creator, $payload);
        $this->assertSame('draft', $document->getAttribute('status'));
        $this->assertSame('BDT', $document->getAttribute('currency_code'));
        $this->assertSame('200.00', $document->getAttribute('subtotal'));
        $this->assertSame('20.00', $document->getAttribute('discount_total'));
        $this->assertSame('27.00', $document->getAttribute('tax_total'));
        $this->assertSame('207.00', $document->getAttribute('grand_total'));
        $event = SalesDocumentEvent::query()->where('document_type', 'quotation')->where('document_id', $document->getKey())->where('action', 'create')->firstOrFail();
        $this->assertSame([], $event->getAttribute('before_payload'));
        $this->assertSame('207.00', $event->getAttribute('after_payload')['changes']['grand_total']);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_exact_math_rounds_half_up_without_floats(): void
    {
        $math = app(SalesQuotationMath::class);
        $this->assertSame('1.01', $math->line('1', '1.0050', '0', '0')['line_total']);
        $this->assertSame('0.55', $math->line('1', '1', '50', '10')['line_total']);
        $this->assertSame('0.00', $math->line('0.0001', '50', '50', '0')['line_total']);
        $this->assertSame('20000000000.00', $math->line('10000', '1000000', '0', '100')['line_total']);
    }

    public function test_invalid_precision_and_zero_quantity_are_rejected_without_partial_writes(): void
    {
        foreach (['0', '1.00001', '-1', '1e2'] as $quantity) {
            $payload = $this->payload();
            $payload['items'][0]['quantity'] = $quantity;
            try {
                app(SalesQuotationService::class)->save($this->creator, $payload);
                $this->fail('Invalid quantity accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('sales_quotations', 0);
                $this->assertDatabaseCount('sales_document_events', 0);
                $this->assertDatabaseCount('sales_document_sequences', 0);
            }
        }
    }

    public function test_rates_over_one_hundred_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(SalesQuotationMath::class)->line('1', '10', '100.0001', '0');
    }

    public function test_lifecycle_requires_independent_approval_and_acceptance_is_idempotent(): void
    {
        $document = $this->sent();
        $service = app(SalesQuotationService::class);
        $revision = (int) $document->getAttribute('revision');
        $accepted = $service->transition($this->creator, (int) $document->getKey(), 'accept', $revision);
        $retry = $service->transition($this->creator, (int) $document->getKey(), 'accept', $revision);
        $this->assertSame('accepted', $accepted->getAttribute('status'));
        $this->assertSame($accepted->getAttribute('revision'), $retry->getAttribute('revision'));
        $this->assertSame(1, DB::table('sales_document_events')->where('action', 'accept')->count());
        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_creator_cannot_approve_their_own_quotation(): void
    {
        $service = app(SalesQuotationService::class);
        $document = $this->draft();
        $document = $service->transition($this->creator, (int) $document->getKey(), 'submit', 1);
        $this->expectException(AuthorizationException::class);
        $service->transition($this->creator, (int) $document->getKey(), 'approve', (int) $document->getAttribute('revision'));
    }

    public function test_submitted_or_sent_quotation_cannot_be_edited(): void
    {
        $document = $this->sent();
        $payload = [...$this->payload(), 'revision' => $document->getAttribute('revision')];
        $this->expectException(ValidationException::class);
        app(SalesQuotationService::class)->save($this->creator, $payload, (int) $document->getKey());
    }

    public function test_stale_edit_is_rejected_and_current_items_are_preserved(): void
    {
        $document = $this->draft();
        $service = app(SalesQuotationService::class);
        $payload = [...$this->payload(), 'revision' => 1];
        $payload['items'][0]['quantity'] = '3';
        $saved = $service->save($this->creator, $payload, (int) $document->getKey());
        $event = SalesDocumentEvent::query()->where('document_type', 'quotation')->where('document_id', $document->getKey())->where('action', 'edit')->firstOrFail();
        $this->assertSame(['revision' => 1, 'grand_total' => '207.00'], $event->getAttribute('before_payload'));
        $this->assertSame('310.50', $event->getAttribute('after_payload')['changes']['grand_total']);
        try {
            $service->save($this->creator, $payload, (int) $document->getKey());
            $this->fail('Stale edit accepted.');
        } catch (ValidationException) {
            $this->assertSame(2, $saved->getAttribute('revision'));
            $this->assertSame(1, $saved->items()->count());
            $this->assertSame('3.0000', $saved->items()->firstOrFail()->getAttribute('quantity'));
        }
    }

    public function test_expired_sent_quote_cannot_be_accepted(): void
    {
        $document = $this->sent();
        $this->travel(40)->days();
        try {
            $this->expectException(ValidationException::class);
            app(SalesQuotationService::class)->transition($this->creator, (int) $document->getKey(), 'accept', (int) $document->getAttribute('revision'));
        } finally {
            $this->travelBack();
        }
    }

    public function test_rejection_requires_reason_and_no_partial_status_change_occurs(): void
    {
        $document = $this->draft();
        $service = app(SalesQuotationService::class);
        $service->transition($this->creator, (int) $document->getKey(), 'submit', 1);
        try {
            $service->transition($this->approver, (int) $document->getKey(), 'reject', 2);
            $this->fail('Rejection without reason succeeded.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('sales_quotations', ['id' => $document->getKey(), 'status' => 'submitted']);
        }
    }

    public function test_foreign_customer_sources_are_rejected(): void
    {
        $foreign = Company::query()->create(['name' => 'Foreign', 'code' => 'SQ-B', 'status' => 'active', 'currency_code' => 'BDT']);
        $customer = Customer::query()->create(['company_id' => $foreign->getKey(), 'name' => 'Foreign', 'phone' => '01700000000']);
        $payload = [...$this->payload(), 'customer_id' => $customer->getKey()];
        $this->expectException(ModelNotFoundException::class);
        app(SalesQuotationService::class)->save($this->creator, $payload);
    }

    public function test_foreign_product_is_rejected_without_partial_document(): void
    {
        $foreign = Company::query()->create(['name' => 'Foreign', 'code' => 'SQ-B', 'status' => 'active', 'currency_code' => 'BDT']);
        $unit = Unit::query()->create(['company_id' => $foreign->getKey(), 'name' => 'Piece', 'short_name' => 'pc']);
        $product = Product::query()->create(['company_id' => $foreign->getKey(), 'unit_id' => $unit->getKey(), 'name' => 'Foreign', 'is_active' => true]);
        $payload = $this->payload();
        $payload['items'][0]['product_id'] = $product->getKey();
        try {
            app(SalesQuotationService::class)->save($this->creator, $payload);
            $this->fail('Foreign product accepted.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('sales_quotations', 0);
            $this->assertDatabaseCount('sales_document_sequences', 0);
        }
    }

    public function test_print_and_show_use_accepted_snapshots_after_master_changes(): void
    {
        $document = $this->sent();
        $document = app(SalesQuotationService::class)->transition($this->creator, (int) $document->getKey(), 'accept', (int) $document->getAttribute('revision'));
        $this->customer->update(['name' => 'Changed customer']);
        $this->product->update(['name' => 'Changed product']);
        $this->actingAs($this->creator)->get('/erp/sales/quotations/'.$document->getKey().'/print')->assertOk()->assertInertia(fn (Assert $page) => $page->where('quotation.customer_snapshot.name', 'Customer A')->where('items.0.product_snapshot.name', 'Sales product'));
    }

    public function test_required_variant_is_enforced(): void
    {
        $this->product->update(['has_variants' => true]);
        $this->expectException(ValidationException::class);
        $this->draft();
    }

    public function test_unexpected_variant_is_rejected(): void
    {
        $variant = ProductVariant::query()->create(['product_id' => $this->product->getKey(), 'name' => 'Variant']);
        $payload = $this->payload();
        $payload['items'][0]['product_variant_id'] = $variant->getKey();
        $this->expectException(ValidationException::class);
        app(SalesQuotationService::class)->save($this->creator, $payload);
    }

    public function test_approved_price_snapshot_does_not_change_with_catalog_price(): void
    {
        $document = $this->sent();
        $this->product->update(['selling_price' => '900.00']);
        $this->assertSame('100.0000', $document->items()->firstOrFail()->getAttribute('unit_price'));
        $this->assertSame('207.00', $document->fresh()->getAttribute('grand_total'));
    }

    public function test_accept_revalidates_inactive_master_sources(): void
    {
        $document = $this->sent();
        $this->customer->update(['is_active' => false]);
        $this->expectException(ValidationException::class);
        app(SalesQuotationService::class)->transition($this->creator, (int) $document->getKey(), 'accept', (int) $document->getAttribute('revision'));
    }

    public function test_lead_draft_can_be_resolved_before_submission_without_duplicate_customer(): void
    {
        $status = LeadStatus::query()->create(['company_id' => $this->company->getKey(), 'name' => 'New', 'code' => 'NEW']);
        $lead = Lead::query()->create(['company_id' => $this->company->getKey(), 'lead_status_id' => $status->getKey(), 'lead_number' => 'SQL-1', 'contact_name' => 'Prospect', 'phone' => '01700000000', 'is_active' => true, 'currency' => 'BDT']);
        $payload = [...$this->payload(), 'customer_id' => null, 'lead_id' => $lead->getKey()];
        $service = app(SalesQuotationService::class);
        $document = $service->save($this->creator, $payload);
        $this->assertNull($document->getAttribute('customer_id'));
        try {
            $service->transition($this->creator, (int) $document->getKey(), 'submit', 1);
            $this->fail('Unresolved lead submitted.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('sales_quotations', ['id' => $document->getKey(), 'status' => 'draft']);
        }
        $resolved = $service->resolveLead($this->creator, (int) $document->getKey(), 1);
        $this->assertNotNull($resolved->getAttribute('customer_id'));
        $this->assertDatabaseCount('customers', 2);
        $submitted = $service->transition($this->creator, (int) $document->getKey(), 'submit', 2);
        $this->assertSame('submitted', $submitted->getAttribute('status'));
    }

    public function test_http_actions_enforce_permission_and_revision(): void
    {
        $actor = User::factory()->create();
        Employee::query()->create(['user_id' => $actor->getKey(), 'company_id' => $this->company->getKey(), 'employee_number' => 'SQ-EMP', 'first_name' => 'Sales', 'last_name' => 'User']);
        $actor->givePermissionTo('erp.sales-quotations.view');
        $document = $this->draft();
        $this->actingAs($actor)->post('/erp/sales/quotations/'.$document->getKey().'/submit', ['revision' => 1])->assertForbidden();
        $this->actingAs($this->creator)->post('/erp/sales/quotations/'.$document->getKey().'/submit', ['revision' => 99])->assertSessionHasErrors('revision');
    }

    public function test_quotation_header_negative_money_check_survives_nullable_schema_change(): void
    {
        $document = $this->draft();
        $this->expectException(QueryException::class);
        DB::table('sales_quotations')->where('id', $document->getKey())->update(['grand_total' => '-1']);
    }
}
