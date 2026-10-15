<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\SalesDocumentEvent;
use App\Models\SalesQuotation;
use App\Models\User;
use App\Services\ERP\Sales\SalesAuditService;
use App\Services\ERP\Sales\SalesCompanyAccess;
use App\Services\ERP\Sales\SalesDocumentGuard;
use App\Services\ERP\Sales\SalesDocumentNumberService;
use App\Services\ERP\Sales\SalesDomainRegistry;
use App\Services\ERP\Sales\SalesSourceGuard;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/** Composes the internal foundation; does not expose operational routes. */
final class SalesFoundationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SalesPermissionSeeder::class);
    }

    private function company(string $code = 'SALES-A', string $currency = 'BDT'): Company
    {
        return Company::query()->create(['name' => $code, 'code' => $code, 'status' => 'active', 'currency_code' => $currency]);
    }

    private function customer(Company $company): Customer
    {
        return Customer::query()->create(['company_id' => $company->getKey(), 'name' => 'Customer '.$company->getAttribute('code'), 'phone' => '01700000000', 'is_active' => true]);
    }

    private function admin(): User
    {
        $actor = User::factory()->create();
        $actor->assignRole('super-admin');

        return $actor;
    }

    /** @param list<string> $permissions */
    private function member(Company $company, array $permissions): User
    {
        $actor = User::factory()->create();
        Employee::query()->create(['user_id' => $actor->getKey(), 'company_id' => $company->getKey(), 'employee_number' => 'SI-'.$actor->getKey(), 'first_name' => 'Sales', 'last_name' => 'Member']);
        if ($permissions !== []) {
            $actor->givePermissionTo($permissions);
        }

        return $actor;
    }

    /** Trusted test fixture exercises the complete authorized draft-write boundary. */
    private function createDraft(User $actor, Company $company, Customer $customer, bool $failAfterAudit = false): SalesQuotation
    {
        return DB::transaction(function () use ($actor, $company, $customer, $failAfterAudit): SalesQuotation {
            $companyId = (int) $company->getKey();
            $registry = app(SalesDomainRegistry::class);
            $lockedCompany = app(SalesCompanyAccess::class)->authorize($actor, $companyId, $registry->permission('quotation', 'create'), true);
            $number = app(SalesDocumentNumberService::class)->next($companyId, 'quotation', '2026-10-14');
            $source = app(SalesSourceGuard::class)->customer($companyId, (int) $customer->getKey());
            $currency = (string) $lockedCompany->getAttribute('currency_code');
            app(SalesSourceGuard::class)->currency($currency, $currency);
            $document = SalesQuotation::query()->create([
                'company_id' => $companyId, 'customer_id' => $source->getKey(), 'number' => $number,
                'document_date' => '2026-10-14', 'currency_code' => $currency, 'status' => 'draft',
                'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
                'customer_snapshot' => ['name' => $source->getAttribute('name')],
            ]);
            app(SalesAuditService::class)->record($actor, $companyId, 'quotation', (int) $document->getKey(), 'create', [], ['status' => 'draft', 'revision' => 1]);
            if ($failAfterAudit) {
                throw new RuntimeException('Injected failure after event');
            }

            return $document;
        });
    }

    public function test_authorized_draft_write_records_one_scoped_document_number_and_event(): void
    {
        $company = $this->company();
        $actor = $this->member($company, ['erp.sales-quotations.create', 'erp.sales-quotations.view']);
        $document = $this->createDraft($actor, $company, $this->customer($company));
        $this->assertSame('SQ-2026-000001', $document->getAttribute('number'));
        $this->assertSame($document->getKey(), app(SalesDocumentGuard::class)->view($actor, (int) $company->getKey(), 'quotation', (int) $document->getKey())->getKey());
        $this->assertDatabaseHas('sales_document_events', ['company_id' => $company->getKey(), 'document_type' => 'quotation', 'document_id' => $document->getKey(), 'action' => 'create', 'actor_id' => $actor->getKey()]);
        $this->assertDatabaseCount('sales_document_events', 1);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('inventory_balances', 0);
    }

    public function test_failure_after_audit_rolls_back_document_event_and_sequence_together(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $customer = $this->customer($company);
        try {
            $this->createDraft($actor, $company, $customer, true);
            $this->fail('Failure fixture did not throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected failure after event', $exception->getMessage());
        }
        $this->assertDatabaseCount('sales_quotations', 0);
        $this->assertDatabaseCount('sales_document_events', 0);
        $this->assertDatabaseCount('sales_document_sequences', 0);
        $this->assertSame('SQ-2026-000001', $this->createDraft($actor, $company, $customer)->getAttribute('number'));
    }

    public function test_foreign_customer_failure_rolls_back_early_number_allocation(): void
    {
        $a = $this->company();
        $foreignCustomer = $this->customer($this->company('SALES-B'));
        try {
            $this->createDraft($this->admin(), $a, $foreignCustomer);
            $this->fail('Cross-company customer was accepted.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('sales_quotations', 0);
            $this->assertDatabaseCount('sales_document_events', 0);
            $this->assertDatabaseCount('sales_document_sequences', 0);
        }
    }

    public function test_invalid_legacy_currency_cannot_create_a_draft_or_consume_a_number(): void
    {
        $company = $this->company('SALES-A', 'bdt');
        try {
            $this->createDraft($this->admin(), $company, $this->customer($company));
            $this->fail('Invalid currency was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('currency_code', $exception->errors());
            $this->assertDatabaseCount('sales_quotations', 0);
            $this->assertDatabaseCount('sales_document_sequences', 0);
            $this->assertDatabaseCount('sales_document_events', 0);
        }
    }

    public function test_denied_staff_write_produces_no_document_sequence_or_event(): void
    {
        $company = $this->company();
        $actor = $this->member($company, ['erp.sales-quotations.view']);
        try {
            $this->createDraft($actor, $company, $this->customer($company));
            $this->fail('View-only member created a document.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('sales_quotations', 0);
            $this->assertDatabaseCount('sales_document_sequences', 0);
            $this->assertDatabaseCount('sales_document_events', 0);
        }
    }

    public function test_stale_edit_rolls_back_and_preserves_the_successful_revision_and_event(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $document = $this->createDraft($actor, $company, $this->customer($company));
        $write = function (int $expectedRevision) use ($actor, $company, $document): void {
            DB::transaction(function () use ($actor, $company, $document, $expectedRevision): void {
                $guard = app(SalesDocumentGuard::class);
                $locked = $guard->lockForAction($actor, (int) $company->getKey(), 'quotation', (int) $document->getKey(), 'edit');
                $guard->assertRevision($locked, $expectedRevision);
                $locked->setAttribute('notes', 'Saved revision '.$expectedRevision);
                $locked->setAttribute('revision', $expectedRevision + 1);
                $locked->save();
                app(SalesAuditService::class)->record($actor, (int) $company->getKey(), 'quotation', (int) $document->getKey(), 'edit', ['revision' => $expectedRevision], ['revision' => $expectedRevision + 1]);
            });
        };
        $write(1);
        try {
            $write(1);
            $this->fail('Stale edit was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('revision', $exception->errors());
        }
        $this->assertDatabaseHas('sales_quotations', ['id' => $document->getKey(), 'revision' => 2, 'notes' => 'Saved revision 1']);
        $this->assertSame(1, SalesDocumentEvent::query()->where('action', 'edit')->count());
    }

    public function test_independent_approval_guard_and_post_change_audit_work_in_one_transaction(): void
    {
        $company = $this->company();
        $creator = $this->admin();
        $approver = $this->admin();
        $document = $this->createDraft($creator, $company, $this->customer($company));
        // Submitted fixture; actual submit/approve commands are introduced in 5.3.
        $document->update(['status' => 'submitted']);
        DB::transaction(function () use ($company, $document, $approver): void {
            $locked = app(SalesDocumentGuard::class)->lockForAction($approver, (int) $company->getKey(), 'quotation', (int) $document->getKey(), 'approve');
            $locked->setAttribute('status', 'approved');
            $locked->setAttribute('approved_by', $approver->getKey());
            $locked->setAttribute('approved_at', now()->toDateTimeString());
            $locked->save();
            app(SalesAuditService::class)->record($approver, (int) $company->getKey(), 'quotation', (int) $document->getKey(), 'approve', ['status' => 'submitted'], ['status' => 'approved']);
        });
        $this->assertDatabaseHas('sales_quotations', ['id' => $document->getKey(), 'status' => 'approved', 'approved_by' => $approver->getKey()]);
        $this->assertDatabaseHas('sales_document_events', ['document_id' => $document->getKey(), 'action' => 'approve', 'actor_id' => $approver->getKey()]);
    }

    public function test_same_human_number_in_two_companies_has_separate_document_events(): void
    {
        $a = $this->company();
        $b = $this->company('SALES-B');
        $actor = $this->admin();
        $first = $this->createDraft($actor, $a, $this->customer($a));
        $second = $this->createDraft($actor, $b, $this->customer($b));
        $this->assertSame($first->getAttribute('number'), $second->getAttribute('number'));
        $this->assertNotSame($first->getKey(), $second->getKey());
        foreach ([$first, $second] as $document) {
            $this->assertSame(1, SalesDocumentEvent::query()->where('company_id', $document->getAttribute('company_id'))->where('document_type', 'quotation')->where('document_id', $document->getKey())->count());
        }
    }

    public function test_required_reason_failure_reverts_attempted_business_change(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $document = $this->createDraft($actor, $company, $this->customer($company));
        try {
            DB::transaction(function () use ($company, $actor, $document): void {
                $locked = app(SalesDocumentGuard::class)->lockForAction($actor, (int) $company->getKey(), 'quotation', (int) $document->getKey(), 'cancel');
                $locked->setAttribute('status', 'cancelled');
                $locked->save();
                app(SalesAuditService::class)->record($actor, (int) $company->getKey(), 'quotation', (int) $document->getKey(), 'cancel', ['status' => 'draft'], ['status' => 'cancelled'], ' ');
            });
            $this->fail('Cancellation without a reason succeeded.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }
        $this->assertDatabaseHas('sales_quotations', ['id' => $document->getKey(), 'status' => 'draft']);
        $this->assertSame(0, SalesDocumentEvent::query()->where('action', 'cancel')->count());
    }
}
