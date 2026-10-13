<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\ERP\Purchase\PurchaseDocumentNumberService;
use App\Services\ERP\Purchase\PurchaseWorkflowService;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

final class PurchaseWorkflowFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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

    private function supplier(Company $company): Supplier
    {
        return Supplier::query()->create(['company_id' => $company->getKey(), 'code' => 'S-1', 'name' => 'Supplier', 'status' => 'active', 'currency_code' => 'BDT']);
    }

    private function product(Company $company): Product
    {
        $unit = Unit::query()->create(['company_id' => $company->getKey(), 'name' => 'Piece', 'short_name' => 'pc']);

        return Product::query()->create(['company_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'name' => 'Test product', 'is_active' => true]);
    }

    private function requisitionItem(PurchaseRequisition $document, Product $product): void
    {
        DB::table('purchase_requisition_items')->insert(['purchase_requisition_id' => $document->getKey(), 'product_id' => $product->getKey(), 'quantity' => '2.0000', 'estimated_unit_price' => '10.00']);
    }

    private function orderItem(PurchaseOrder $document, Product $product): void
    {
        DB::table('purchase_order_items')->insert(['purchase_order_id' => $document->getKey(), 'product_id' => $product->getKey(), 'quantity' => '2.0000', 'unit_price' => '10.00', 'discount_amount' => '1.00', 'tax_amount' => '2.00', 'line_total' => '21.00']);
        $document->update(['subtotal' => '20.00', 'discount_total' => '1.00', 'tax_total' => '2.00', 'grand_total' => '21.00']);
    }

    public function test_numbers_are_separate_by_company_and_type_and_events_are_recorded(): void
    {
        $actor = $this->admin();
        $a = $this->company();
        $b = $this->company('C-B');
        $service = app(PurchaseWorkflowService::class);
        $first = $service->createRequisitionDraft($actor, (int) $a->id);
        $second = $service->createRequisitionDraft($actor, (int) $a->id);
        $other = $service->createRequisitionDraft($actor, (int) $b->id);
        $order = $service->createOrderDraft($actor, (int) $a->id, (int) $this->supplier($a)->id);
        $this->assertStringEndsWith('000001', $first->number);
        $this->assertStringEndsWith('000002', $second->number);
        $this->assertSame($first->number, $other->number);
        $this->assertStringStartsWith('PO-', $order->number);
        $this->assertDatabaseCount('purchase_workflow_events', 4);
    }

    public function test_requisition_submit_and_approval_capture_actor_and_timestamps(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $approver = $this->admin();
        $service = app(PurchaseWorkflowService::class);
        $document = $service->createRequisitionDraft($actor, (int) $company->id);
        $this->requisitionItem($document, $this->product($company));
        $submitted = $service->transitionRequisition($actor, (int) $company->id, (int) $document->id, 'submit');
        $this->assertNotNull($submitted->submitted_at);
        $approved = $service->transitionRequisition($approver, (int) $company->id, (int) $document->id, 'approve');
        $this->assertSame('approved', $approved->status);
        $this->assertSame((int) $approver->id, (int) $approved->approved_by);
        $this->assertNotNull($approved->approved_at);
        $this->assertDatabaseCount('purchase_workflow_events', 3);
    }

    public function test_order_can_be_submitted_approved_and_ordered_without_stock_movement(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $service = app(PurchaseWorkflowService::class);
        $document = $service->createOrderDraft($actor, (int) $company->id, (int) $this->supplier($company)->id);
        $this->orderItem($document, $this->product($company));
        $stockBefore = DB::table('stock_movements')->count();
        $service->transitionOrder($actor, (int) $company->id, (int) $document->id, 'submit');
        $service->transitionOrder($this->admin(), (int) $company->id, (int) $document->id, 'approve');
        $ordered = $service->transitionOrder($actor, (int) $company->id, (int) $document->id, 'order');
        $this->assertSame('ordered', $ordered->status);
        $this->assertNotNull($ordered->ordered_at);
        $this->assertSame($stockBefore, DB::table('stock_movements')->count());
    }

    public function test_empty_submission_leaves_state_and_audit_unchanged(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $service = app(PurchaseWorkflowService::class);
        $document = $service->createRequisitionDraft($actor, (int) $company->id);
        try {
            $service->transitionRequisition($actor, (int) $company->id, (int) $document->id, 'submit');
            $this->fail('Empty requisition was submitted.');
        } catch (ValidationException) {
            $this->assertSame('draft', $document->refresh()->status);
            $this->assertDatabaseCount('purchase_workflow_events', 1);
        }
    }

    public function test_self_approval_and_duplicate_submission_are_rejected(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $service = app(PurchaseWorkflowService::class);
        $document = $service->createRequisitionDraft($actor, (int) $company->id);
        $this->requisitionItem($document, $this->product($company));
        $service->transitionRequisition($actor, (int) $company->id, (int) $document->id, 'submit');
        foreach (['submit', 'approve'] as $action) {
            try {
                $service->transitionRequisition($actor, (int) $company->id, (int) $document->id, $action);
                $this->fail('Invalid action succeeded.');
            } catch (ValidationException) {
                $this->assertSame('submitted', $document->refresh()->status);
            }
        }
        $this->assertDatabaseCount('purchase_workflow_events', 2);
    }

    public function test_rejection_requires_reason_and_records_it(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $service = app(PurchaseWorkflowService::class);
        $document = $service->createRequisitionDraft($actor, (int) $company->id);
        $this->requisitionItem($document, $this->product($company));
        $service->transitionRequisition($actor, (int) $company->id, (int) $document->id, 'submit');
        try {
            $service->transitionRequisition($actor, (int) $company->id, (int) $document->id, 'reject', '  ');
            $this->fail('Rejection without reason succeeded.');
        } catch (ValidationException) {
            $this->assertSame('submitted', $document->refresh()->status);
        }
        $service->transitionRequisition($actor, (int) $company->id, (int) $document->id, 'reject', 'Not required');
        $this->assertDatabaseHas('purchase_workflow_events', ['document_type' => 'requisition', 'document_id' => $document->id, 'to_status' => 'rejected', 'comment' => 'Not required']);
    }

    public function test_permission_alone_does_not_grant_company_access(): void
    {
        $company = $this->company();
        $actor = User::factory()->create();
        $actor->givePermissionTo('erp.purchase-requisitions.create');
        $this->expectException(AuthorizationException::class);
        app(PurchaseWorkflowService::class)->createRequisitionDraft($actor, (int) $company->id);
    }

    public function test_missing_permission_is_denied(): void
    {
        $company = $this->company();
        $this->expectException(AuthorizationException::class);
        app(PurchaseWorkflowService::class)->createRequisitionDraft(User::factory()->create(), (int) $company->id);
    }

    public function test_cross_company_document_lookup_is_rejected(): void
    {
        $actor = $this->admin();
        $a = $this->company();
        $b = $this->company('C-B');
        $service = app(PurchaseWorkflowService::class);
        $document = $service->createRequisitionDraft($actor, (int) $a->id);
        $this->expectException(ModelNotFoundException::class);
        $service->transitionRequisition($actor, (int) $b->id, (int) $document->id, 'submit');
    }

    public function test_cross_company_supplier_rolls_back_number_allocation(): void
    {
        $actor = $this->admin();
        $a = $this->company();
        $b = $this->company('C-B');
        try {
            app(PurchaseWorkflowService::class)->createOrderDraft($actor, (int) $a->id, (int) $this->supplier($b)->id);
            $this->fail('Cross-company supplier accepted.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('purchase_orders', 0);
            $this->assertDatabaseCount('purchase_document_sequences', 0);
            $this->assertDatabaseCount('purchase_workflow_events', 0);
        }
    }

    public function test_bad_order_totals_roll_back_submission(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $service = app(PurchaseWorkflowService::class);
        $document = $service->createOrderDraft($actor, (int) $company->id, (int) $this->supplier($company)->id);
        $this->orderItem($document, $this->product($company));
        $document->update(['grand_total' => '20.99']);
        try {
            $service->transitionOrder($actor, (int) $company->id, (int) $document->id, 'submit');
            $this->fail('Incorrect totals accepted.');
        } catch (ValidationException) {
            $this->assertSame('draft', $document->refresh()->status);
            $this->assertDatabaseCount('purchase_workflow_events', 1);
        }
    }

    public function test_cross_company_product_cannot_be_submitted(): void
    {
        $actor = $this->admin();
        $a = $this->company();
        $b = $this->company('C-B');
        $service = app(PurchaseWorkflowService::class);
        $document = $service->createRequisitionDraft($actor, (int) $a->id);
        $this->requisitionItem($document, $this->product($b));
        $this->expectException(ModelNotFoundException::class);
        $service->transitionRequisition($actor, (int) $a->id, (int) $document->id, 'submit');
    }

    public function test_fractional_quantity_uses_half_up_rounding(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $service = app(PurchaseWorkflowService::class);
        $document = $service->createOrderDraft($actor, (int) $company->id, (int) $this->supplier($company)->id);
        $product = $this->product($company);
        DB::table('purchase_order_items')->insert(['purchase_order_id' => $document->id, 'product_id' => $product->id, 'quantity' => '0.5000', 'unit_price' => '0.01', 'line_total' => '0.01']);
        $document->update(['subtotal' => '0.01', 'grand_total' => '0.01']);
        $submitted = $service->transitionOrder($actor, (int) $company->id, (int) $document->id, 'submit');
        $this->assertSame('submitted', $submitted->status);
    }

    public function test_number_allocation_rolls_back_with_outer_document_transaction(): void
    {
        $company = $this->company();
        try {
            DB::transaction(function () use ($company): void {
                app(PurchaseDocumentNumberService::class)->next((int) $company->id, 'order');
                throw new LogicException('Force rollback');
            });
            $this->fail('Expected rollback exception.');
        } catch (LogicException) {
            $this->assertDatabaseCount('purchase_document_sequences', 0);
        }
        $number = DB::transaction(fn (): string => app(PurchaseDocumentNumberService::class)->next((int) $company->id, 'order'));
        $this->assertStringEndsWith('000001', $number);
    }
}
