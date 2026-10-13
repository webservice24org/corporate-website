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
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ERP\Purchase\GoodsReceiptService;
use App\Services\Inventory\InventoryService;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

final class GoodsReceiptManagementTest extends TestCase
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
    private function fixture(): array
    {
        $actor = $this->admin();
        $company = $this->company();
        $warehouse = Warehouse::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'WH-1', 'is_active' => true]);
        $unit = Unit::query()->create(['company_id' => $company->id, 'name' => 'Piece', 'short_name' => 'pc']);
        $product = Product::query()->create(['company_id' => $company->id, 'unit_id' => $unit->id, 'name' => 'বাংলা পণ্য', 'is_active' => true, 'product_type' => 'physical', 'track_inventory' => true, 'has_variants' => false]);
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

    public function test_guest_and_missing_permission_are_denied(): void
    {
        $this->get('/erp/purchase/receipts')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/erp/purchase/receipts')->assertForbidden();
        $this->get('/erp/purchase/receipts/create')->assertForbidden();
        $this->post('/erp/purchase/receipts', [])->assertForbidden();
    }

    public function test_draft_creation_does_not_change_stock_and_snapshots_server_cost(): void
    {
        [, $company, $warehouse, , $order, $item] = $this->fixture();
        $receipt = $this->create($company, $warehouse, $order, $item);
        $this->assertStringStartsWith('GRN-', $receipt->number);
        $this->assertSame('draft', $receipt->status);
        $this->assertSame('9.0000', GoodsReceiptItem::query()->where('goods_receipt_id', $receipt->id)->firstOrFail()->unit_cost);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('inventory_balances', 0);
        $this->assertSame('0.0000', $item->refresh()->received_quantity);
        $this->assertDatabaseCount('goods_receipt_audit_logs', 1);
    }

    public function test_partial_posting_updates_ledger_balance_and_order_progress(): void
    {
        [$actor, $company, $warehouse, $product, $order, $item] = $this->fixture();
        $receipt = $this->create($company, $warehouse, $order, $item, '3.0000', '2.0000');
        $this->postReceipt($receipt);
        $movement = StockMovement::query()->firstOrFail();
        $this->assertSame(StockMovement::TYPE_PURCHASE_RECEIPT, $movement->movement_type);
        $this->assertSame(GoodsReceipt::class, $movement->reference_type);
        $this->assertSame((int) $receipt->id, (int) $movement->reference_id);
        $this->assertSame('3.0000', $movement->quantity);
        $this->assertSame('9.0000', $movement->unit_cost);
        $this->assertSame('27.0000', $movement->total_cost);
        $this->assertSame('partially_received', $order->refresh()->status);
        $this->assertSame('3.0000', $item->refresh()->received_quantity);
        $this->assertSame((int) $actor->id, (int) $receipt->getAttribute('posted_by'));
        $this->assertSame('3.0000', app(InventoryService::class)->quantityOnHand($warehouse, $product));
        $this->assertDatabaseHas('goods_receipt_items', ['goods_receipt_id' => $receipt->id, 'stock_movement_id' => $movement->id]);
    }

    public function test_second_partial_receipt_completes_order_and_rejected_quantity_remains_open(): void
    {
        [, $company, $warehouse, $product, $order, $item] = $this->fixture();
        $first = $this->create($company, $warehouse, $order, $item, '3.0000', '2.0000');
        $this->postReceipt($first);
        $second = $this->create($company, $warehouse, $order, $item, '7.0000');
        $this->postReceipt($second);
        $this->assertSame('received', $order->refresh()->status);
        $this->assertSame('10.0000', $item->refresh()->received_quantity);
        $this->assertSame('10.0000', app(InventoryService::class)->quantityOnHand($warehouse, $product));
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_posting_retry_does_not_duplicate_stock_history_or_receipt_totals(): void
    {
        [, $company, $warehouse, $product, $order, $item] = $this->fixture();
        $receipt = $this->create($company, $warehouse, $order, $item);
        $this->postReceipt($receipt);
        $this->post('/erp/purchase/receipts/'.$receipt->id.'/post', ['revision' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('goods_receipt_audit_logs', 2);
        $this->assertSame('3.0000', $item->refresh()->received_quantity);
        $this->assertSame('3.0000', app(InventoryService::class)->quantityOnHand($warehouse, $product));
    }

    public function test_over_receipt_is_rejected_during_draft_creation(): void
    {
        [, $company, $warehouse, , $order, $item] = $this->fixture();
        $this->post('/erp/purchase/receipts', $this->data($company, $warehouse, $order, $item, '9.0000', '2.0000'))->assertSessionHasErrors('items.0.quantity_received');
        $this->assertDatabaseCount('goods_receipts', 0);
        $this->assertDatabaseCount('purchase_document_sequences', 0);
    }

    public function test_posting_rechecks_remaining_after_another_draft_is_posted(): void
    {
        [, $company, $warehouse, , $order, $item] = $this->fixture();
        $a = $this->create($company, $warehouse, $order, $item, '7.0000');
        $b = $this->create($company, $warehouse, $order, $item, '5.0000');
        $this->postReceipt($a);
        $this->post('/erp/purchase/receipts/'.$b->id.'/post', ['revision' => 1])->assertSessionHasErrors('items.0.quantity_received');
        $this->assertSame('draft', $b->refresh()->status);
        $this->assertSame('7.0000', $item->refresh()->received_quantity);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_rejection_only_receipt_does_not_change_stock_or_fulfillment(): void
    {
        [, $company, $warehouse, , $order, $item] = $this->fixture();
        $receipt = $this->create($company, $warehouse, $order, $item, '0.0000', '4.0000');
        $this->postReceipt($receipt);
        $this->assertSame('posted', $receipt->status);
        $this->assertSame('ordered', $order->refresh()->status);
        $this->assertSame('0.0000', $item->refresh()->received_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_service_acceptance_updates_fulfillment_without_inventory_movement(): void
    {
        [, $company, $warehouse, $product, $order, $item] = $this->fixture();
        $product->update(['product_type' => 'service', 'track_inventory' => false]);
        $receipt = $this->create($company, $warehouse, $order, $item, '10.0000');
        $this->postReceipt($receipt);
        $this->assertSame('received', $order->refresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('inventory_balances', 0);
        $this->assertFalse(GoodsReceiptItem::query()->where('goods_receipt_id', $receipt->id)->firstOrFail()->getAttribute('is_stock_item'));
    }

    public function test_variant_receipt_uses_correct_variant_balance_identity(): void
    {
        [, $company, $warehouse, $product, $order, $item] = $this->fixture();
        $product->update(['has_variants' => true]);
        $variant = ProductVariant::query()->create(['product_id' => $product->id, 'name' => 'Large', 'is_active' => true]);
        $item->update(['product_variant_id' => $variant->id]);
        $receipt = $this->create($company, $warehouse, $order, $item);
        $this->postReceipt($receipt);
        $this->assertDatabaseHas('inventory_balances', ['product_id' => $product->id, 'product_variant_id' => $variant->id, 'variant_key' => $variant->id, 'quantity_on_hand' => 3]);
        $this->assertDatabaseHas('stock_movements', ['product_variant_id' => $variant->id, 'reference_id' => $receipt->id]);
    }

    public function test_inventory_weighted_average_uses_existing_engine_and_discounted_cost(): void
    {
        [, $company, $warehouse, $product, $order, $item] = $this->fixture();
        app(InventoryService::class)->stockIn($warehouse, $product, '4.0000', StockMovement::TYPE_OPENING_STOCK, '2.0000');
        $receipt = $this->create($company, $warehouse, $order, $item, '3.0000');
        $this->postReceipt($receipt);
        $balance = InventoryBalance::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->firstOrFail();
        $this->assertSame('7.0000', $balance->quantity_on_hand);
        $this->assertSame('5.0000', $balance->average_cost);
    }

    public function test_draft_update_refreshes_quantities_and_checks_revision(): void
    {
        [, $company, $warehouse, , $order, $item] = $this->fixture();
        $receipt = $this->create($company, $warehouse, $order, $item);
        $data = $this->data($company, $warehouse, $order, $item, '2.0000');
        unset($data['company_id'], $data['purchase_order_id']);
        $data['revision'] = 1;
        $this->put('/erp/purchase/receipts/'.$receipt->id, $data)->assertSessionHasNoErrors();
        $this->assertSame(2, (int) $receipt->refresh()->getAttribute('revision'));
        $this->put('/erp/purchase/receipts/'.$receipt->id, $data)->assertSessionHasErrors('revision');
        $this->post('/erp/purchase/receipts/'.$receipt->id.'/post', ['revision' => 1])->assertSessionHasErrors('revision');
        $this->postReceipt($receipt);
        $this->assertSame('2.0000', $item->refresh()->received_quantity);
    }

    public function test_draft_can_be_corrected_after_another_receipt_consumes_quantity(): void
    {
        [, $company, $warehouse, , $order, $item] = $this->fixture();
        $a = $this->create($company, $warehouse, $order, $item, '7.0000');
        $b = $this->create($company, $warehouse, $order, $item, '5.0000');
        $this->postReceipt($a);
        $data = $this->data($company, $warehouse, $order, $item, '3.0000');
        unset($data['company_id'], $data['purchase_order_id']);
        $this->put('/erp/purchase/receipts/'.$b->id, [...$data, 'revision' => 1])->assertSessionHasNoErrors();
        $this->postReceipt($b);
        $this->assertSame('received', $order->refresh()->status);
    }

    public function test_cancelled_draft_has_no_stock_and_cannot_be_posted(): void
    {
        [, $company, $warehouse, , $order, $item] = $this->fixture();
        $receipt = $this->create($company, $warehouse, $order, $item);
        $this->post('/erp/purchase/receipts/'.$receipt->id.'/cancel', ['revision' => 1])->assertSessionHasErrors('reason');
        $this->post('/erp/purchase/receipts/'.$receipt->id.'/cancel', ['revision' => 1, 'reason' => 'Duplicate inspection'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $receipt->refresh()->status);
        $this->post('/erp/purchase/receipts/'.$receipt->id.'/post', ['revision' => 2])->assertSessionHasErrors('status');
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame('0.0000', $item->refresh()->received_quantity);
    }

    public function test_posted_receipt_cannot_be_edited_or_cancelled(): void
    {
        [, $company, $warehouse, , $order, $item] = $this->fixture();
        $receipt = $this->create($company, $warehouse, $order, $item);
        $this->postReceipt($receipt);
        $this->get('/erp/purchase/receipts/'.$receipt->id.'/edit')->assertForbidden();
        $this->post('/erp/purchase/receipts/'.$receipt->id.'/cancel', ['revision' => 2, 'reason' => 'Not allowed'])->assertSessionHasErrors('status');
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_unreleased_order_cannot_receive_goods(): void
    {
        [, $company, $warehouse, , $order, $item] = $this->fixture();
        foreach (['draft', 'submitted', 'approved', 'rejected', 'received', 'closed'] as $status) {
            $order->update(['status' => $status]);
            $this->post('/erp/purchase/receipts', $this->data($company, $warehouse, $order, $item))->assertSessionHasErrors('purchase_order_id');
        }
        $this->assertDatabaseCount('goods_receipts', 0);
    }

    public function test_foreign_inactive_or_deleted_warehouse_is_rejected(): void
    {
        [, $company, $warehouse, , $order, $item] = $this->fixture();
        $other = $this->company('C-B');
        $foreign = Warehouse::query()->create(['company_id' => $other->id, 'code' => 'WH-2', 'name' => 'Other', 'is_active' => true]);
        $this->post('/erp/purchase/receipts', $this->data($company, $foreign, $order, $item))->assertNotFound();
        $warehouse->update(['is_active' => false]);
        $this->post('/erp/purchase/receipts', $this->data($company, $warehouse, $order, $item))->assertNotFound();
        $warehouse->update(['is_active' => true]);
        $warehouse->delete();
        $this->post('/erp/purchase/receipts', $this->data($company, $warehouse, $order, $item))->assertNotFound();
    }

    public function test_inactive_warehouse_or_product_blocks_posting_without_stock_changes(): void
    {
        [, $company, $warehouse, $product, $order, $item] = $this->fixture();
        $receipt = $this->create($company, $warehouse, $order, $item);
        $warehouse->update(['is_active' => false]);
        $this->post('/erp/purchase/receipts/'.$receipt->id.'/post', ['revision' => 1])->assertNotFound();
        $warehouse->update(['is_active' => true]);
        $product->update(['is_active' => false]);
        $this->post('/erp/purchase/receipts/'.$receipt->id.'/post', ['revision' => 1])->assertNotFound();
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame('draft', $receipt->refresh()->status);
    }

    public function test_company_scope_protects_order_warehouse_and_receipt_access(): void
    {
        [, $a, $warehouse, , $order, $item] = $this->fixture();
        $receipt = $this->create($a, $warehouse, $order, $item);
        $b = $this->company('C-B');
        $actor = $this->employee($b, ['erp.goods-receipts.view', 'erp.goods-receipts.create', 'erp.goods-receipts.edit', 'erp.goods-receipts.post', 'erp.goods-receipts.cancel']);
        $this->actingAs($actor)->post('/erp/purchase/receipts', $this->data($a, $warehouse, $order, $item))->assertForbidden();
        $this->get('/erp/purchase/receipts/'.$receipt->id)->assertNotFound();
        $this->get('/erp/purchase/receipts/'.$receipt->id.'/edit')->assertNotFound();
        $this->post('/erp/purchase/receipts/'.$receipt->id.'/post', ['revision' => 1])->assertNotFound();
        $this->get('/erp/purchase/receipts')->assertInertia(fn (Assert $page) => $page->has('receipts.data', 0)->has('companies', 1));
    }

    public function test_line_must_belong_to_selected_order_and_be_distinct(): void
    {
        [$actor, $company, $warehouse, $product, $order, $item] = $this->fixture();
        $foreign = PurchaseOrder::query()->create(['company_id' => $company->id, 'supplier_id' => $order->supplier_id, 'created_by' => $actor->id, 'number' => 'PO-OTHER', 'status' => 'ordered', 'currency_code' => 'BDT']);
        $otherItem = PurchaseOrderItem::query()->create(['purchase_order_id' => $foreign->id, 'product_id' => $product->id, 'quantity' => 10]);
        $data = $this->data($company, $warehouse, $order, $otherItem);
        $this->post('/erp/purchase/receipts', $data)->assertSessionHasErrors('items.0.purchase_order_item_id');
        $data = $this->data($company, $warehouse, $order, $item);
        $data['items'][] = $data['items'][0];
        $this->post('/erp/purchase/receipts', $data)->assertSessionHasErrors('items.0.purchase_order_item_id');
    }

    public function test_zero_negative_excess_precision_and_client_costs_are_rejected(): void
    {
        [, $company, $warehouse, , $order, $item] = $this->fixture();
        $this->post('/erp/purchase/receipts', $this->data($company, $warehouse, $order, $item, '0', '0'))->assertSessionHasErrors('items');
        foreach (['-1', '1.00001', '1e3'] as $quantity) {
            $this->post('/erp/purchase/receipts', $this->data($company, $warehouse, $order, $item, $quantity))->assertSessionHasErrors('items.0.quantity_received');
        }
        $data = $this->data($company, $warehouse, $order, $item);
        $this->post('/erp/purchase/receipts', [...$data, 'unit_cost' => '0.01'])->assertSessionHasErrors('unit_cost');
        $this->assertDatabaseCount('goods_receipts', 0);
    }

    public function test_inventory_configuration_change_requires_draft_refresh(): void
    {
        [, $company, $warehouse, $product, $order, $item] = $this->fixture();
        $receipt = $this->create($company, $warehouse, $order, $item);
        $product->update(['track_inventory' => false]);
        $this->post('/erp/purchase/receipts/'.$receipt->id.'/post', ['revision' => 1])->assertSessionHasErrors('items');
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_order_counter_tampering_is_detected_before_receiving(): void
    {
        [, $company, $warehouse, , $order, $item] = $this->fixture();
        $item->update(['received_quantity' => '1.0000']);
        $this->post('/erp/purchase/receipts', $this->data($company, $warehouse, $order, $item))->assertSessionHasErrors('purchase_order_id');
        $this->assertDatabaseCount('goods_receipts', 0);
    }

    public function test_currency_mismatch_blocks_inventory_posting(): void
    {
        [, $company, $warehouse, , $order, $item] = $this->fixture();
        $receipt = $this->create($company, $warehouse, $order, $item);
        $company->update(['currency_code' => 'USD']);
        $this->post('/erp/purchase/receipts/'.$receipt->id.'/post', ['revision' => 1])->assertSessionHasErrors('currency');
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_post_and_cancel_permissions_are_separate_from_view(): void
    {
        [, $company, $warehouse, , $order, $item] = $this->fixture();
        $receipt = $this->create($company, $warehouse, $order, $item);
        $viewer = $this->employee($company, ['erp.goods-receipts.view']);
        $this->actingAs($viewer)->post('/erp/purchase/receipts/'.$receipt->id.'/post', ['revision' => 1])->assertForbidden();
        $this->post('/erp/purchase/receipts/'.$receipt->id.'/cancel', ['revision' => 1, 'reason' => 'Test'])->assertForbidden();
        $this->get('/erp/purchase/receipts/'.$receipt->id.'/edit')->assertForbidden();
    }

    public function test_second_inventory_failure_rolls_back_entire_multi_line_receipt(): void
    {
        [$actor, $company, $warehouse, $product, $order, $item] = $this->fixture();
        $secondProduct = Product::query()->create(['company_id' => $company->id, 'unit_id' => $product->unit_id, 'name' => 'Second product', 'is_active' => true, 'product_type' => 'physical', 'track_inventory' => true]);
        $second = PurchaseOrderItem::query()->create(['purchase_order_id' => $order->id, 'product_id' => $secondProduct->id, 'product_name' => 'Second product', 'quantity' => 10, 'unit_price' => 10, 'line_total' => 100]);
        $data = $this->data($company, $warehouse, $order, $item);
        $data['items'][] = ['purchase_order_item_id' => $second->id, 'quantity_received' => '2.0000', 'quantity_rejected' => '0'];
        $this->post('/erp/purchase/receipts', $data)->assertSessionHasNoErrors();
        $receipt = GoodsReceipt::query()->firstOrFail();
        $real = new InventoryService;
        $calls = 0;
        $mock = Mockery::mock(InventoryService::class)->makePartial();
        $mock->shouldReceive('stockIn')->andReturnUsing(function (...$arguments) use ($real, &$calls) {
            $calls++;
            if ($calls === 2) {
                throw ValidationException::withMessages(['inventory' => 'Simulated second-line failure']);
            }

            return $real->stockIn(...$arguments);
        });
        $this->app->instance(InventoryService::class, $mock);
        try {
            $this->app->make(GoodsReceiptService::class)->post($actor, (int) $receipt->id, 1);
            $this->fail('Expected an inventory posting failure.');
        } catch (ValidationException) {
            $this->assertSame(2, $calls);
            $this->assertDatabaseCount('stock_movements', 0);
            $this->assertDatabaseCount('inventory_balances', 0);
            $this->assertSame('draft', $receipt->refresh()->status);
            $this->assertSame('ordered', $order->refresh()->status);
            $this->assertSame('0.0000', $item->refresh()->received_quantity);
            $this->assertSame('0.0000', $second->refresh()->received_quantity);
            $this->assertDatabaseCount('goods_receipt_audit_logs', 1);
        }
    }

    public function test_page_components_and_order_receipt_links_resolve(): void
    {
        [, $company, $warehouse, , $order, $item] = $this->fixture();
        $this->get('/erp/purchase/receipts/create?company_id='.$company->id.'&order_id='.$order->id)->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Receipts/Form')->has('orderItems', 1)->has('warehouses', 1)->where('orderId', $order->id));
        $receipt = $this->create($company, $warehouse, $order, $item);
        $this->get('/erp/purchase/receipts/'.$receipt->id.'/edit')->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Receipts/Form')->has('receiptItems', 1));
        $this->get('/erp/purchase/receipts/'.$receipt->id)->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Receipts/Show')->has('items', 1)->has('history', 1));
        $this->get('/erp/purchase/orders/'.$order->id)->assertInertia(fn (Assert $page) => $page->where('receipts.0.id', $receipt->id));
    }

    public function test_fractional_net_unit_cost_rounds_to_four_inventory_places(): void
    {
        [, $company, $warehouse, , $order, $item] = $this->fixture();
        $item->update(['quantity' => '3.0000', 'unit_price' => '6.00', 'discount_amount' => '1.00', 'tax_amount' => '0.00', 'line_total' => '17.00']);
        $receipt = $this->create($company, $warehouse, $order, $item, '3.0000');
        $this->assertSame('5.6667', GoodsReceiptItem::query()->where('goods_receipt_id', $receipt->id)->firstOrFail()->unit_cost);
        $this->postReceipt($receipt);
        $this->assertSame('5.6667', StockMovement::query()->firstOrFail()->unit_cost);
    }

    public function test_future_received_time_and_negative_rejections_are_rejected(): void
    {
        [, $company, $warehouse, , $order, $item] = $this->fixture();
        $data = $this->data($company, $warehouse, $order, $item);
        $data['received_at'] = now()->addDay()->format('Y-m-d\TH:i');
        $this->post('/erp/purchase/receipts', $data)->assertSessionHasErrors('received_at');
        $this->post('/erp/purchase/receipts', $this->data($company, $warehouse, $order, $item, '1.0000', '-1'))->assertSessionHasErrors('items.0.quantity_rejected');
        $this->assertDatabaseCount('goods_receipts', 0);
    }

    public function test_fully_received_order_can_close_with_history_without_more_stock(): void
    {
        [, $company, $warehouse, , $order, $item] = $this->fixture();
        $receipt = $this->create($company, $warehouse, $order, $item, '10.0000');
        $this->postReceipt($receipt);
        $this->post('/erp/purchase/orders/'.$order->id.'/close', ['revision' => (int) $order->refresh()->getAttribute('revision'), 'comment' => 'Receiving complete'])->assertSessionHasNoErrors();
        $this->assertSame('closed', $order->refresh()->status);
        $this->assertNotNull($order->closed_at);
        $this->post('/erp/purchase/receipts/'.$receipt->id.'/post', ['revision' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('purchase_workflow_events', ['document_id' => $order->id, 'document_type' => 'order', 'to_status' => 'closed']);
    }

    public function test_order_cannot_close_before_complete_receipt(): void
    {
        [, $company, $warehouse, , $order, $item] = $this->fixture();
        $this->post('/erp/purchase/orders/'.$order->id.'/close', ['revision' => 1, 'comment' => 'Too early'])->assertSessionHasErrors('status');
        $receipt = $this->create($company, $warehouse, $order, $item, '3.0000');
        $this->postReceipt($receipt);
        $this->post('/erp/purchase/orders/'.$order->id.'/close', ['revision' => (int) $order->refresh()->getAttribute('revision'), 'comment' => 'Too early'])->assertSessionHasErrors('status');
        $this->assertSame('partially_received', $order->refresh()->status);
    }

    public function test_fully_received_order_rejects_new_receipts_but_posted_retry_succeeds(): void
    {
        [, $company, $warehouse, , $order, $item] = $this->fixture();
        $receipt = $this->create($company, $warehouse, $order, $item, '10.0000');
        $this->postReceipt($receipt);
        $this->post('/erp/purchase/receipts', $this->data($company, $warehouse, $order, $item, '1.0000'))->assertSessionHasErrors('purchase_order_id');
        $this->post('/erp/purchase/receipts/'.$receipt->id.'/post', ['revision' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('stock_movements', 1);
    }
}
