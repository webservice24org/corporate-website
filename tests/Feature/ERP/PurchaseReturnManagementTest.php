<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use App\Models\Employee;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ERP\Purchase\PurchaseReturnService;
use App\Services\Inventory\InventoryService;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

final class PurchaseReturnManagementTest extends TestCase
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

    /** @return array{User, Company, Warehouse, Product, PurchaseOrder, PurchaseOrderItem} */
    private function fixture(bool $stock = true): array
    {
        $actor = $this->admin();
        $company = $this->company();
        $warehouse = Warehouse::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'WH-1', 'is_active' => true]);
        $unit = Unit::query()->create(['company_id' => $company->id, 'name' => 'Piece', 'short_name' => 'pc']);
        $product = Product::query()->create(['company_id' => $company->id, 'unit_id' => $unit->id, 'name' => 'বাংলা পণ্য', 'is_active' => true, 'product_type' => $stock ? 'physical' : 'service', 'track_inventory' => $stock, 'has_variants' => false]);
        $supplier = Supplier::query()->create(['company_id' => $company->id, 'code' => 'S-1', 'name' => 'Supplier', 'currency_code' => 'BDT', 'status' => 'active']);
        $order = PurchaseOrder::query()->create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'created_by' => $actor->id, 'number' => 'PO-TEST', 'status' => 'ordered', 'currency_code' => 'BDT', 'subtotal' => '100.00', 'discount_total' => '10.00', 'tax_total' => '5.00', 'grand_total' => '95.00']);
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
    private function received(bool $stock = true, string $accepted = '6.0000', string $rejected = '2.0000'): array
    {
        [$actor, $company, $warehouse, $product, $order, $item] = $this->fixture($stock);
        $receipt = $this->create($company, $warehouse, $order, $item, $accepted, $rejected);
        $this->postReceipt($receipt);
        $source = GoodsReceiptItem::query()->where('goods_receipt_id', $receipt->id)->firstOrFail();

        return [$actor, $company, $warehouse, $product, $order, $item, $receipt, $source];
    }

    /** @return array<string, mixed> */
    private function returnData(GoodsReceipt $receipt, GoodsReceiptItem $source, string $quantity = '2.0000'): array
    {
        return ['goods_receipt_id' => $receipt->id, 'reason' => 'Damaged goods returned to supplier', 'items' => [['goods_receipt_item_id' => $source->id, 'quantity' => $quantity]]];
    }

    private function draft(GoodsReceipt $receipt, GoodsReceiptItem $source, string $quantity = '2.0000'): PurchaseReturn
    {
        $this->post('/erp/purchase/returns', $this->returnData($receipt, $source, $quantity))->assertSessionHasNoErrors();

        return PurchaseReturn::query()->latest('id')->firstOrFail();
    }

    private function approveReturn(PurchaseReturn $record): void
    {
        $approver = $this->admin();
        $this->actingAs($approver)->post('/erp/purchase/returns/'.$record->id.'/approve', ['revision' => $record->refresh()->getAttribute('revision')])->assertSessionHasNoErrors();
        $record->refresh();
    }

    private function postReturn(PurchaseReturn $record): void
    {
        $this->post('/erp/purchase/returns/'.$record->id.'/post', ['revision' => $record->refresh()->getAttribute('revision')])->assertSessionHasNoErrors();
        $record->refresh();
    }

    public function test_guests_and_users_without_permission_are_denied(): void
    {
        $this->get('/erp/purchase/returns')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/erp/purchase/returns')->assertForbidden();
        $this->get('/erp/purchase/returns/create')->assertForbidden();
        $this->post('/erp/purchase/returns', [])->assertForbidden();
    }

    public function test_draft_and_approval_leave_stock_unchanged(): void
    {
        [, , $warehouse, $product, , , $receipt, $source] = $this->received();
        $record = $this->draft($receipt, $source);
        $this->assertStringStartsWith('PRT-', $record->number);
        $this->assertSame('draft', $record->status);
        $line = PurchaseReturnItem::query()->firstOrFail();
        $this->assertSame('9.0000', $line->getAttribute('source_unit_cost'));
        $this->assertNull($line->stock_movement_id);
        $this->approveReturn($record);
        $this->assertSame('approved', $record->status);
        $this->assertSame('6.0000', app(InventoryService::class)->quantityOnHand($warehouse, $product));
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('purchase_return_audit_logs', 2);
    }

    public function test_posting_records_stock_out_without_rewriting_receipt_or_po_progress(): void
    {
        [, , $warehouse, $product, $order, $poLine, $receipt, $source] = $this->received();
        $record = $this->draft($receipt, $source);
        $this->approveReturn($record);
        $this->postReturn($record);
        $movement = StockMovement::query()->where('movement_type', StockMovement::TYPE_PURCHASE_RETURN)->firstOrFail();
        $this->assertSame(StockMovement::DIRECTION_OUT, $movement->direction);
        $this->assertSame(PurchaseReturn::class, $movement->reference_type);
        $this->assertSame((int) $record->id, (int) $movement->reference_id);
        $this->assertSame('2.0000', $movement->quantity);
        $this->assertSame('18.0000', $movement->total_cost);
        $this->assertSame('4.0000', app(InventoryService::class)->quantityOnHand($warehouse, $product));
        $this->assertSame('6.0000', $poLine->refresh()->received_quantity);
        $this->assertSame('partially_received', $order->refresh()->status);
        $this->assertSame('6.0000', $source->refresh()->quantity_received);
        $this->assertSame('posted', $receipt->refresh()->status);
        $this->assertSame('posted', $record->status);
        $this->assertDatabaseCount('purchase_return_audit_logs', 3);
        $this->assertDatabaseHas('purchase_return_items', ['purchase_return_id' => $record->id, 'stock_movement_id' => $movement->id]);
    }

    public function test_creator_cannot_approve_and_draft_cannot_post(): void
    {
        [, , , , , , $receipt, $source] = $this->received();
        $record = $this->draft($receipt, $source);
        $this->post('/erp/purchase/returns/'.$record->id.'/approve', ['revision' => 1])->assertSessionHasErrors('approval');
        $this->post('/erp/purchase/returns/'.$record->id.'/post', ['revision' => 1])->assertSessionHasErrors('status');
        $this->assertSame('draft', $record->refresh()->status);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_duplicate_post_retry_is_idempotent(): void
    {
        [, , $warehouse, $product, , , $receipt, $source] = $this->received();
        $record = $this->draft($receipt, $source);
        $this->approveReturn($record);
        $revision = (int) $record->getAttribute('revision');
        $this->postReturn($record);
        $this->post('/erp/purchase/returns/'.$record->id.'/post', ['revision' => $revision])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseCount('purchase_return_audit_logs', 3);
        $this->assertSame('4.0000', app(InventoryService::class)->quantityOnHand($warehouse, $product));
    }

    public function test_cumulative_returns_exclude_rejected_units_and_cannot_exceed_acceptance(): void
    {
        [, , , , , , $receipt, $source] = $this->received();
        $record = $this->draft($receipt, $source, '4.0000');
        $this->approveReturn($record);
        $this->postReturn($record);
        $this->post('/erp/purchase/returns', $this->returnData($receipt, $source, '2.0001'))->assertSessionHasErrors('items.0.quantity');
        $second = $this->draft($receipt, $source, '2.0000');
        $this->approveReturn($second);
        $this->postReturn($second);
        $this->post('/erp/purchase/returns', $this->returnData($receipt, $source, '0.0001'))->assertSessionHasErrors('items.0.quantity');
        $this->assertDatabaseCount('purchase_returns', 2);
    }

    public function test_competing_approved_drafts_recheck_capacity_at_posting(): void
    {
        [, , $warehouse, $product, , , $receipt, $source] = $this->received();
        $first = $this->draft($receipt, $source, '4.0000');
        $second = $this->draft($receipt, $source, '4.0000');
        $this->approveReturn($first);
        $this->approveReturn($second);
        $this->postReturn($first);
        $this->post('/erp/purchase/returns/'.$second->id.'/post', ['revision' => $second->getAttribute('revision')])->assertSessionHasErrors('items.0.quantity');
        $this->assertSame('approved', $second->refresh()->status);
        $this->assertSame('2.0000', app(InventoryService::class)->quantityOnHand($warehouse, $product));
    }

    public function test_reserved_stock_is_not_available_for_return(): void
    {
        [, , $warehouse, $product, , , $receipt, $source] = $this->received();
        $record = $this->draft($receipt, $source);
        $this->approveReturn($record);
        InventoryBalance::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->update(['quantity_reserved' => '5.0000']);
        $this->post('/erp/purchase/returns/'.$record->id.'/post', ['revision' => $record->getAttribute('revision')])->assertSessionHasErrors('stock');
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame('approved', $record->refresh()->status);
    }

    public function test_return_does_not_allow_negative_stock_even_when_product_does(): void
    {
        [, , $warehouse, $product, , , $receipt, $source] = $this->received();
        $record = $this->draft($receipt, $source);
        $this->approveReturn($record);
        $product->update(['allow_negative_stock' => true]);
        app(InventoryService::class)->stockOut($warehouse, $product, '5.0000', StockMovement::TYPE_SALE);
        $this->post('/erp/purchase/returns/'.$record->id.'/post', ['revision' => $record->getAttribute('revision')])->assertSessionHasErrors('stock');
        $this->assertSame('1.0000', app(InventoryService::class)->quantityOnHand($warehouse, $product));
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_stock_out_uses_current_average_and_preserves_grn_cost(): void
    {
        [, , $warehouse, $product, , , $receipt, $source] = $this->received();
        $record = $this->draft($receipt, $source);
        $this->approveReturn($record);
        app(InventoryService::class)->stockIn($warehouse, $product, '6.0000', StockMovement::TYPE_OPENING_STOCK, '15.0000');
        $this->postReturn($record);
        $line = PurchaseReturnItem::query()->firstOrFail();
        $this->assertSame('9.0000', $line->getAttribute('source_unit_cost'));
        $this->assertSame('12.0000', $line->unit_cost);
        $this->assertSame('12.0000', InventoryBalance::query()->firstOrFail()->average_cost);
        $this->assertSame('24.0000', StockMovement::query()->where('movement_type', StockMovement::TYPE_PURCHASE_RETURN)->firstOrFail()->total_cost);
    }

    public function test_non_stock_acceptance_can_be_returned_without_ledger_movement(): void
    {
        [, , , , , $poLine, $receipt, $source] = $this->received(false);
        $record = $this->draft($receipt, $source);
        $this->approveReturn($record);
        $this->postReturn($record);
        $this->assertSame('posted', $record->status);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('inventory_balances', 0);
        $this->assertSame('6.0000', $poLine->refresh()->received_quantity);
        $this->assertNull(PurchaseReturnItem::query()->firstOrFail()->stock_movement_id);
    }

    public function test_unposted_receipts_and_zero_or_duplicate_lines_are_rejected(): void
    {
        [, $company, $warehouse, , $order, $poLine] = $this->fixture();
        $receipt = $this->create($company, $warehouse, $order, $poLine);
        $source = GoodsReceiptItem::query()->firstOrFail();
        $this->post('/erp/purchase/returns', $this->returnData($receipt, $source))->assertNotFound();
        $this->postReceipt($receipt);
        $this->post('/erp/purchase/returns', $this->returnData($receipt, $source, '0'))->assertSessionHasErrors('items');
        $data = $this->returnData($receipt, $source);
        $data['items'][] = $data['items'][0];
        $this->post('/erp/purchase/returns', $data)->assertSessionHasErrors('items.0.goods_receipt_item_id');
    }

    public function test_draft_edit_revision_and_unposted_cancellation(): void
    {
        [, , , , , , $receipt, $source] = $this->received();
        $record = $this->draft($receipt, $source);
        $data = ['revision' => 1, 'reason' => 'Changed return quantity', 'items' => [['goods_receipt_item_id' => $source->id, 'quantity' => '3.0000']]];
        $this->put('/erp/purchase/returns/'.$record->id, $data)->assertSessionHasNoErrors();
        $this->assertSame(2, $record->refresh()->getAttribute('revision'));
        $this->put('/erp/purchase/returns/'.$record->id, $data)->assertSessionHasErrors('revision');
        $this->approveReturn($record);
        $this->get('/erp/purchase/returns/'.$record->id.'/edit')->assertForbidden();
        $this->put('/erp/purchase/returns/'.$record->id, [...$data, 'revision' => $record->getAttribute('revision')])->assertSessionHasErrors('status');
        $this->post('/erp/purchase/returns/'.$record->id.'/cancel', ['revision' => $record->getAttribute('revision'), 'reason' => 'Supplier agreed to keep goods'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $record->refresh()->status);
        $this->post('/erp/purchase/returns/'.$record->id.'/post', ['revision' => $record->getAttribute('revision')])->assertSessionHasErrors('status');
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_posted_return_is_immutable(): void
    {
        [, , , , , , $receipt, $source] = $this->received();
        $record = $this->draft($receipt, $source);
        $this->approveReturn($record);
        $this->postReturn($record);
        $revision = $record->getAttribute('revision');
        $this->get('/erp/purchase/returns/'.$record->id.'/edit')->assertForbidden();
        $this->put('/erp/purchase/returns/'.$record->id, ['revision' => $revision, 'reason' => 'Change', 'items' => [['goods_receipt_item_id' => $source->id, 'quantity' => '1']]])->assertSessionHasErrors('status');
        $this->post('/erp/purchase/returns/'.$record->id.'/cancel', ['revision' => $revision, 'reason' => 'Undo'])->assertSessionHasErrors('status');
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_company_scope_protects_pages_sources_and_mutations(): void
    {
        [, , , , , , $receipt, $source] = $this->received();
        $record = $this->draft($receipt, $source);
        $other = $this->company('C-B');
        $actor = $this->employee($other, ['erp.purchase-returns.view', 'erp.purchase-returns.create', 'erp.purchase-returns.edit', 'erp.purchase-returns.approve', 'erp.purchase-returns.post', 'erp.purchase-returns.cancel']);
        $this->actingAs($actor)->get('/erp/purchase/returns/'.$record->id)->assertNotFound();
        $this->get('/erp/purchase/returns/create?receipt_id='.$receipt->id)->assertNotFound();
        $this->post('/erp/purchase/returns', $this->returnData($receipt, $source))->assertNotFound();
        foreach (['approve', 'post', 'cancel'] as $action) {
            $this->post('/erp/purchase/returns/'.$record->id.'/'.$action, ['revision' => 1, 'reason' => 'Other company'])->assertNotFound();
        }
        $this->get('/erp/purchase/returns')->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Returns/Index')->has('returns.data', 0));
    }

    public function test_header_and_cost_injection_are_rejected(): void
    {
        [, $company, $warehouse, , $order, , $receipt, $source] = $this->received();
        $data = $this->returnData($receipt, $source);
        $this->post('/erp/purchase/returns', [...$data, 'company_id' => $company->id, 'warehouse_id' => $warehouse->id, 'purchase_order_id' => $order->id, 'unit_cost' => 1, 'status' => 'posted'])->assertSessionHasErrors(['company_id', 'warehouse_id', 'purchase_order_id', 'unit_cost', 'status']);
        $data['items'][0]['unit_cost'] = '1.0000';
        $this->post('/erp/purchase/returns', $data)->assertSessionHasErrors('items.0');
        $this->assertDatabaseCount('purchase_returns', 0);
    }

    public function test_configuration_change_and_missing_source_ledger_block_posting(): void
    {
        [, , , $product, , , $receipt, $source] = $this->received();
        $record = $this->draft($receipt, $source);
        $this->approveReturn($record);
        $product->update(['track_inventory' => false]);
        $this->post('/erp/purchase/returns/'.$record->id.'/post', ['revision' => $record->getAttribute('revision')])->assertSessionHasErrors('items');
        $product->update(['track_inventory' => true]);
        $source->update(['stock_movement_id' => null]);
        $this->post('/erp/purchase/returns/'.$record->id.'/post', ['revision' => $record->getAttribute('revision')])->assertSessionHasErrors('items');
        $this->assertSame('approved', $record->refresh()->status);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_pages_exist_and_source_products_are_preloaded(): void
    {
        [, $company, , , , , $receipt, $source] = $this->received();
        $this->get('/erp/purchase/returns/create?receipt_id='.$receipt->id)->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Returns/Form')->where('companyId', $company->id)->has('sourceLines', 1)->where('sourceLines.0.remaining', '6.0000')->where('sourceLines.0.product_name', 'বাংলা পণ্য'));
        $record = $this->draft($receipt, $source);
        $this->get('/erp/purchase/returns/'.$record->id)->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Returns/Show')->has('items', 1)->has('history', 1));
        $this->get('/erp/purchase/returns/'.$record->id.'/edit')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Returns/Form')->has('returnItems', 1));
        $this->get('/erp/purchase/returns')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Returns/Index')->has('returns.data', 1));
    }

    public function test_second_line_failure_rolls_back_all_stock_out_and_history(): void
    {
        [$actor, $company, $warehouse, $product, $order, $poLine] = $this->fixture();
        $secondProduct = Product::query()->create(['company_id' => $company->id, 'unit_id' => $product->unit_id, 'name' => 'Second product', 'is_active' => true, 'product_type' => 'physical', 'track_inventory' => true, 'has_variants' => false]);
        $second = PurchaseOrderItem::query()->create(['purchase_order_id' => $order->id, 'product_id' => $secondProduct->id, 'product_name' => 'Second product', 'quantity' => 10, 'received_quantity' => 0, 'unit_price' => 10, 'line_total' => 100]);
        $data = $this->data($company, $warehouse, $order, $poLine);
        $data['items'][] = ['purchase_order_item_id' => $second->id, 'quantity_received' => '2.0000', 'quantity_rejected' => '0'];
        $this->post('/erp/purchase/receipts', $data)->assertSessionHasNoErrors();
        $receipt = GoodsReceipt::query()->firstOrFail();
        $this->postReceipt($receipt);
        $sources = GoodsReceiptItem::query()->where('goods_receipt_id', $receipt->id)->orderBy('id')->get();
        $this->post('/erp/purchase/returns', ['goods_receipt_id' => $receipt->id, 'reason' => 'Two-line return', 'items' => $sources->map(fn (GoodsReceiptItem $line): array => ['goods_receipt_item_id' => $line->id, 'quantity' => '1.0000'])->all()])->assertSessionHasNoErrors();
        $record = PurchaseReturn::query()->firstOrFail();
        $this->approveReturn($record);
        $real = new InventoryService;
        $calls = 0;
        $mock = Mockery::mock(InventoryService::class)->makePartial();
        $mock->shouldReceive('stockOut')->andReturnUsing(function (...$arguments) use ($real, &$calls) {
            $calls++;
            if ($calls === 2) {
                throw ValidationException::withMessages(['inventory' => 'Simulated second-line failure']);
            }

            return $real->stockOut(...$arguments);
        });
        $this->app->instance(InventoryService::class, $mock);
        try {
            $this->app->make(PurchaseReturnService::class)->post($actor, (int) $record->id, (int) $record->getAttribute('revision'));
            $this->fail('Expected an inventory posting failure.');
        } catch (ValidationException) {
            $this->assertSame(2, $calls);
            $this->assertSame('approved', $record->refresh()->status);
            $this->assertSame('3.0000', $real->quantityOnHand($warehouse, $product));
            $this->assertSame('2.0000', $real->quantityOnHand($warehouse, $secondProduct));
            $this->assertDatabaseCount('stock_movements', 2);
            $this->assertDatabaseCount('purchase_return_audit_logs', 2);
            $this->assertSame(0, PurchaseReturnItem::query()->whereNotNull('stock_movement_id')->count());
        }
    }

    public function test_variant_return_uses_exact_received_variant_balance(): void
    {
        [, $company, $warehouse, $product, $order, $poLine] = $this->fixture();
        $product->update(['has_variants' => true]);
        $variant = ProductVariant::query()->create(['product_id' => $product->id, 'name' => 'Large', 'sku' => 'LARGE-1', 'is_active' => true]);
        $other = ProductVariant::query()->create(['product_id' => $product->id, 'name' => 'Small', 'sku' => 'SMALL-1', 'is_active' => true]);
        $poLine->update(['product_variant_id' => $variant->id, 'variant_name' => 'Large']);
        $receipt = $this->create($company, $warehouse, $order, $poLine);
        $this->postReceipt($receipt);
        $source = GoodsReceiptItem::query()->firstOrFail();
        app(InventoryService::class)->stockIn($warehouse, $product, '5.0000', StockMovement::TYPE_OPENING_STOCK, '9.0000', $other);
        $record = $this->draft($receipt, $source);
        $this->approveReturn($record);
        $this->postReturn($record);
        $this->assertSame('1.0000', app(InventoryService::class)->quantityOnHand($warehouse, $product, $variant));
        $this->assertSame('5.0000', app(InventoryService::class)->quantityOnHand($warehouse, $product, $other));
        $this->assertSame((int) $variant->id, (int) StockMovement::query()->where('movement_type', StockMovement::TYPE_PURCHASE_RETURN)->firstOrFail()->product_variant_id);
    }

    public function test_receipt_line_from_another_receipt_cannot_be_used(): void
    {
        [, , , , , , $receipt, $source] = $this->received();
        $otherReceipt = $receipt->replicate();
        $otherReceipt->number = 'GRN-OTHER';
        $otherReceipt->save();
        $otherLine = $source->replicate();
        $otherLine->goods_receipt_id = $otherReceipt->id;
        $otherLine->stock_movement_id = null;
        $otherLine->save();
        $this->post('/erp/purchase/returns', $this->returnData($receipt, $otherLine))->assertNotFound();
        $this->assertDatabaseCount('purchase_returns', 0);
    }

    public function test_currency_change_and_stale_revision_block_posting(): void
    {
        [, $company, , , , , $receipt, $source] = $this->received();
        $record = $this->draft($receipt, $source);
        $this->approveReturn($record);
        $this->post('/erp/purchase/returns/'.$record->id.'/post', ['revision' => 1])->assertSessionHasErrors('revision');
        $company->update(['currency_code' => 'USD']);
        $this->post('/erp/purchase/returns/'.$record->id.'/post', ['revision' => $record->getAttribute('revision')])->assertSessionHasErrors('currency');
        $this->assertSame('approved', $record->refresh()->status);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_closed_po_and_inactive_supplier_do_not_prevent_return_of_existing_receipt(): void
    {
        [, , , , $order, , $receipt, $source] = $this->received(true, '10.0000', '0');
        $order->update(['status' => 'closed']);
        Supplier::query()->whereKey($order->supplier_id)->update(['status' => 'inactive']);
        $record = $this->draft($receipt, $source);
        $this->approveReturn($record);
        $this->postReturn($record);
        $this->assertSame('posted', $record->status);
        $this->assertSame('closed', $order->refresh()->status);
    }

    public function test_rejected_only_receipt_has_no_returnable_quantity(): void
    {
        [, , , , , , $receipt, $source] = $this->received(true, '0', '6.0000');
        $this->post('/erp/purchase/returns', $this->returnData($receipt, $source, '1'))->assertSessionHasErrors('items.0.quantity');
        $this->assertDatabaseCount('purchase_returns', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_other_warehouse_stock_cannot_cover_source_warehouse_shortage(): void
    {
        [, $company, $warehouse, $product, , , $receipt, $source] = $this->received();
        $record = $this->draft($receipt, $source);
        $this->approveReturn($record);
        $other = Warehouse::query()->create(['company_id' => $company->id, 'code' => 'WH-2', 'name' => 'Other', 'is_active' => true]);
        app(InventoryService::class)->stockIn($other, $product, '10.0000', StockMovement::TYPE_OPENING_STOCK, '9.0000');
        app(InventoryService::class)->stockOut($warehouse, $product, '5.0000', StockMovement::TYPE_SALE);
        $this->post('/erp/purchase/returns/'.$record->id.'/post', ['revision' => $record->getAttribute('revision')])->assertSessionHasErrors('stock');
        $this->assertSame('10.0000', app(InventoryService::class)->quantityOnHand($other, $product));
        $this->assertSame('1.0000', app(InventoryService::class)->quantityOnHand($warehouse, $product));
    }
}
