<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseReturn;
use App\Models\Rfq;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierQuotation;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class PurchaseEndToEndIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(PurchasePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 14)->setTime(12, 0));
        Mail::fake();
        Notification::fake();
    }

    /** @param list<string>|null $permissions */
    private function staff(Company $company, ?array $permissions = null): User
    {
        $user = User::factory()->create();
        Employee::query()->create(['user_id' => $user->id, 'company_id' => $company->id, 'employee_number' => 'EMP-'.$user->id, 'first_name' => 'Purchasing', 'last_name' => 'User']);
        $user->givePermissionTo($permissions ?? Permission::query()->where('guard_name', 'web')->pluck('name')->all());

        return $user;
    }

    /** @return array<string, mixed> */
    private function chain(string $code = 'C-A', string $currency = 'BDT'): array
    {
        $company = Company::query()->create(['name' => $code, 'code' => $code, 'currency_code' => $currency, 'status' => 'active']);
        $maker = $this->staff($company);
        $approver = $this->staff($company);
        $department = Department::query()->create(['company_id' => $company->id, 'code' => 'PUR', 'name' => 'Purchasing', 'status' => 'active']);
        $unit = Unit::query()->create(['company_id' => $company->id, 'name' => 'Piece', 'short_name' => 'pc']);
        $product = Product::query()->create(['company_id' => $company->id, 'unit_id' => $unit->id, 'name' => 'বাংলা পণ্য', 'product_type' => 'physical', 'track_inventory' => true, 'has_variants' => false, 'is_active' => true]);
        $warehouse = Warehouse::query()->create(['company_id' => $company->id, 'code' => 'MAIN', 'name' => 'Main', 'is_active' => true]);
        $this->actingAs($maker)->post('/erp/purchase/suppliers', ['company_id' => $company->id, 'code' => 'S-'.$code, 'name' => 'Supplier '.$code, 'currency_code' => $currency, 'status' => 'active'])->assertRedirect()->assertSessionHasNoErrors();
        $supplier = Supplier::query()->where('company_id', $company->id)->firstOrFail();
        $this->post('/erp/purchase/requisitions', ['company_id' => $company->id, 'department_id' => $department->id, 'required_at' => '2026-10-21', 'notes' => 'Procurement integration',
            'items' => [['product_id' => $product->id, 'product_variant_id' => null, 'quantity' => '10.0000', 'estimated_unit_price' => '10.00', 'notes' => 'Stock purchase']]])->assertRedirect()->assertSessionHasNoErrors();
        $requisition = PurchaseRequisition::query()->where('company_id', $company->id)->firstOrFail();
        $this->post('/erp/purchase/requisitions/'.$requisition->id.'/submit', ['revision' => $requisition->getAttribute('revision')])->assertSessionHasNoErrors();
        $this->actingAs($approver)->post('/erp/purchase/requisitions/'.$requisition->id.'/approve', ['revision' => $requisition->refresh()->getAttribute('revision')])->assertSessionHasNoErrors();
        $this->actingAs($maker)->post('/erp/purchase/rfqs', ['company_id' => $company->id, 'purchase_requisition_id' => $requisition->id, 'supplier_ids' => [$supplier->id], 'deadline_at' => '2026-10-21', 'notes' => 'RFQ integration'])->assertSessionHasNoErrors();
        $rfq = Rfq::query()->where('company_id', $company->id)->firstOrFail();
        $this->post('/erp/purchase/rfqs/'.$rfq->id.'/issue', ['revision' => $rfq->getAttribute('revision')])->assertSessionHasNoErrors();
        $rfqItem = DB::table('rfq_items')->where('rfq_id', $rfq->id)->first();
        $this->assertNotNull($rfqItem);
        $this->post('/erp/purchase/rfqs/'.$rfq->id.'/quotations', ['rfq_revision' => $rfq->refresh()->getAttribute('revision'), 'supplier_id' => $supplier->id, 'quotation_number' => 'QUOTE-'.$code,
            'quoted_at' => '2026-10-14', 'valid_until' => '2026-11-13', 'items' => [['rfq_item_id' => $rfqItem->id, 'unit_price' => '10.00', 'discount_amount' => '10.00', 'tax_amount' => '5.00']]])->assertSessionHasNoErrors();
        $quote = SupplierQuotation::query()->where('rfq_id', $rfq->id)->firstOrFail();
        $this->post('/erp/purchase/rfqs/'.$rfq->id.'/award', ['revision' => $rfq->refresh()->getAttribute('revision'), 'quotation_id' => $quote->id, 'reason' => 'Matched quotation and delivery terms'])->assertSessionHasNoErrors();
        $this->post('/erp/purchase/orders', ['company_id' => $company->id, 'supplier_quotation_id' => $quote->id, 'expected_delivery_at' => '2026-10-21', 'notes' => 'Released purchasing commitment'])->assertSessionHasNoErrors();
        $order = PurchaseOrder::query()->where('supplier_quotation_id', $quote->id)->firstOrFail();
        $this->post('/erp/purchase/orders/'.$order->id.'/submit', ['revision' => $order->getAttribute('revision')])->assertSessionHasNoErrors();
        $this->actingAs($approver)->post('/erp/purchase/orders/'.$order->id.'/approve', ['revision' => $order->refresh()->getAttribute('revision')])->assertSessionHasNoErrors();
        $this->actingAs($maker)->post('/erp/purchase/orders/'.$order->id.'/order', ['revision' => $order->refresh()->getAttribute('revision')])->assertSessionHasNoErrors();
        $orderLine = PurchaseOrderItem::query()->where('purchase_order_id', $order->id)->firstOrFail();
        $receipt = $this->receive($maker, $warehouse, $order, $orderLine, '6', '2');
        $source = GoodsReceiptItem::query()->where('goods_receipt_id', $receipt->id)->firstOrFail();
        $return = $this->returnGoods($maker, $approver, $receipt, $source, '2');
        $bill = $this->bill($maker, $approver, $receipt, $source, '4', 'INV-'.$code.'-1', '2026-10-13');

        return compact('company', 'maker', 'approver', 'department', 'product', 'warehouse', 'supplier', 'requisition', 'rfq', 'quote', 'order', 'orderLine', 'receipt', 'source', 'return', 'bill');
    }

    private function receive(User $maker, Warehouse $warehouse, PurchaseOrder $order, PurchaseOrderItem $line, string $accepted, string $rejected): GoodsReceipt
    {
        $this->actingAs($maker)->post('/erp/purchase/receipts', ['company_id' => $order->company_id, 'purchase_order_id' => $order->id, 'warehouse_id' => $warehouse->id, 'received_at' => '2026-10-14T11:00',
            'items' => [['purchase_order_item_id' => $line->id, 'quantity_received' => $accepted, 'quantity_rejected' => $rejected]]])->assertSessionHasNoErrors();
        $receipt = GoodsReceipt::query()->where('purchase_order_id', $order->id)->latest('id')->firstOrFail();
        $this->post('/erp/purchase/receipts/'.$receipt->id.'/post', ['revision' => $receipt->getAttribute('revision')])->assertSessionHasNoErrors();

        return $receipt->refresh();
    }

    private function returnGoods(User $maker, User $approver, GoodsReceipt $receipt, GoodsReceiptItem $line, string $quantity): PurchaseReturn
    {
        $this->actingAs($maker)->post('/erp/purchase/returns', ['goods_receipt_id' => $receipt->id, 'reason' => 'Supplier accepted damaged-goods return', 'items' => [['goods_receipt_item_id' => $line->id, 'quantity' => $quantity]]])->assertSessionHasNoErrors();
        $return = PurchaseReturn::query()->where('goods_receipt_id', $receipt->id)->latest('id')->firstOrFail();
        $this->actingAs($approver)->post('/erp/purchase/returns/'.$return->id.'/approve', ['revision' => $return->getAttribute('revision')])->assertSessionHasNoErrors();
        $this->actingAs($maker)->post('/erp/purchase/returns/'.$return->id.'/post', ['revision' => $return->refresh()->getAttribute('revision')])->assertSessionHasNoErrors();

        return $return->refresh();
    }

    private function bill(User $maker, User $approver, GoodsReceipt $receipt, GoodsReceiptItem $line, string $quantity, string $invoice, string $due): SupplierBill
    {
        $this->actingAs($maker)->post('/erp/purchase/bills', ['goods_receipt_id' => $receipt->id, 'supplier_invoice_number' => $invoice, 'invoice_date' => '2026-10-04', 'due_date' => $due,
            'items' => [['goods_receipt_item_id' => $line->id, 'quantity' => $quantity]]])->assertSessionHasNoErrors();
        $bill = SupplierBill::query()->where('goods_receipt_id', $receipt->id)->latest('id')->firstOrFail();
        $this->actingAs($approver)->post('/erp/purchase/bills/'.$bill->id.'/approve', ['revision' => $bill->getAttribute('revision')])->assertSessionHasNoErrors();
        $this->actingAs($maker);

        return $bill->refresh();
    }

    public function test_company_users_complete_procurement_receiving_returns_bills_and_close(): void
    {
        $c = $this->chain();
        $this->assertFalse($c['maker']->hasRole('super-admin'));
        $this->assertSame('approved', $c['requisition']->refresh()->status);
        $this->assertSame('awarded', $c['rfq']->refresh()->status);
        $this->assertSame('accepted', $c['quote']->refresh()->status);
        $this->assertSame('partially_received', $c['order']->refresh()->status);
        $this->assertSame('38.00', $c['bill']->grand_total);
        $this->assertSame('4.0000', app(InventoryService::class)->quantityOnHand($c['warehouse'], $c['product']));
        $second = $this->receive($c['maker'], $c['warehouse'], $c['order'], $c['orderLine'], '4', '0');
        $secondLine = GoodsReceiptItem::query()->where('goods_receipt_id', $second->id)->firstOrFail();
        $this->bill($c['maker'], $c['approver'], $second, $secondLine, '4', 'INV-C-A-2', '2026-10-21');
        $this->post('/erp/purchase/orders/'.$c['order']->id.'/close', ['revision' => $c['order']->refresh()->getAttribute('revision'), 'comment' => 'All ordered quantities accepted'])->assertSessionHasNoErrors();
        $this->assertSame('closed', $c['order']->refresh()->status);
        $this->assertSame('10.0000', $c['orderLine']->refresh()->received_quantity);
        $this->assertSame('8.0000', app(InventoryService::class)->quantityOnHand($c['warehouse'], $c['product']));
        $this->assertDatabaseCount('stock_movements', 3);
        $this->assertDatabaseCount('supplier_payables', 2);
        $this->get('/erp/purchase/dashboard?from_date=2026-10-01&to_date=2026-10-14')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Purchase/Reports/Dashboard')->where('counts.orders', 1)->where('counts.receipts', 2)->where('counts.returns', 1)->where('counts.bills', 2)
            ->where('money.0.order_value', '95.00')->where('money.0.receipt_stock_value', '90.0000')->where('money.0.return_stock_value', '18.0000')->where('money.0.bill_value', '76.00')->where('money.0.outstanding', '76.00')->where('money.0.overdue', '38.00')->where('current.credit_review_source_lines', 0));
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_retries_across_po_receipt_return_and_bill_do_not_duplicate_effects(): void
    {
        $c = $this->chain();
        $this->post('/erp/purchase/orders', ['company_id' => $c['company']->id, 'supplier_quotation_id' => $c['quote']->id])->assertSessionHasNoErrors();
        $this->post('/erp/purchase/receipts/'.$c['receipt']->id.'/post', ['revision' => 1])->assertSessionHasNoErrors();
        $this->post('/erp/purchase/returns/'.$c['return']->id.'/post', ['revision' => 1])->assertSessionHasNoErrors();
        $this->actingAs($c['approver'])->post('/erp/purchase/bills/'.$c['bill']->id.'/approve', ['revision' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseCount('supplier_payables', 1);
        $this->assertDatabaseCount('purchase_return_audit_logs', 3);
        $this->assertDatabaseCount('supplier_bill_audit_logs', 2);
        $this->assertSame('4.0000', app(InventoryService::class)->quantityOnHand($c['warehouse'], $c['product']));
    }

    public function test_later_return_preserves_approved_debt_and_flags_credit_review(): void
    {
        $c = $this->chain();
        $this->returnGoods($c['maker'], $c['approver'], $c['receipt'], $c['source'], '1');
        $this->get('/erp/purchase/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->where('current.credit_review_source_lines', 1)->where('money.0.bill_value', '38.00')->where('money.0.outstanding', '38.00')->where('money.0.return_stock_value', '27.0000'));
        $this->assertSame('6.0000', $c['orderLine']->refresh()->received_quantity);
        $this->assertSame('3.0000', app(InventoryService::class)->quantityOnHand($c['warehouse'], $c['product']));
        $this->assertDatabaseCount('supplier_payables', 1);
    }

    public function test_separate_companies_cannot_read_or_mutate_each_others_documents(): void
    {
        $a = $this->chain();
        $b = $this->chain('C-B', 'USD');
        $this->actingAs($b['maker']);
        foreach (['suppliers' => $a['supplier'], 'requisitions' => $a['requisition'], 'rfqs' => $a['rfq'], 'orders' => $a['order'], 'receipts' => $a['receipt'], 'returns' => $a['return'], 'bills' => $a['bill']] as $path => $record) {
            $this->get('/erp/purchase/'.$path.'/'.$record->id)->assertNotFound();
            $this->get('/erp/purchase/'.$path.'/'.$record->id.'/edit')->assertNotFound();
        }
        foreach (['requisitions' => $a['requisition'], 'orders' => $a['order'], 'returns' => $a['return'], 'bills' => $a['bill']] as $path => $record) {
            $this->post('/erp/purchase/'.$path.'/'.$record->id.'/approve', ['revision' => $record->getAttribute('revision')])->assertNotFound();
        }
        $this->post('/erp/purchase/receipts/'.$a['receipt']->id.'/post', ['revision' => 1])->assertNotFound();
        $this->post('/erp/purchase/returns/'.$a['return']->id.'/post', ['revision' => 1])->assertNotFound();
        $this->get('/erp/purchase/reports?type=bills')->assertOk()->assertInertia(fn (Assert $page) => $page->has('rows.data', 1)->where('rows.data.0.document_id', $b['bill']->id));
        $this->get('/erp/purchase/bills/payables')->assertOk()->assertInertia(fn (Assert $page) => $page->has('payables.data', 1)->where('summary.0.currency_code', 'USD'));
        $this->assertSame('4.0000', app(InventoryService::class)->quantityOnHand($a['warehouse'], $a['product']));
        $this->assertDatabaseCount('supplier_payables', 2);
    }

    public function test_every_audit_chain_contains_the_correct_company_and_source(): void
    {
        $c = $this->chain();
        foreach (['purchase_requisition_audit_logs', 'purchase_rfq_audit_logs', 'purchase_order_audit_logs', 'goods_receipt_audit_logs', 'purchase_return_audit_logs', 'supplier_bill_audit_logs'] as $table) {
            $rows = DB::table($table)->get();
            $this->assertNotEmpty($rows);
            foreach ($rows as $row) {
                $this->assertSame((int) $c['company']->id, (int) $row->company_id);
                $this->assertContains((int) $row->actor_id, [(int) $c['maker']->id, (int) $c['approver']->id]);
                $this->assertIsArray(json_decode($row->new_values, true, 512, JSON_THROW_ON_ERROR));
            }
        }
        foreach (['purchase_return_audit_logs', 'supplier_bill_audit_logs'] as $table) {
            $last = DB::table($table)->latest('id')->first();
            $this->assertNotNull($last);
            $snapshot = json_decode($last->new_values, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame((int) $c['receipt']->id, (int) $snapshot['goods_receipt_id']);
            $this->assertSame((int) $c['order']->id, (int) $snapshot['purchase_order_id']);
            $this->assertSame((int) $c['supplier']->id, (int) $snapshot['supplier_id']);
            $this->assertSame((int) $c['company']->id, (int) $snapshot['company_id']);
        }
    }

    public function test_read_only_company_user_can_report_but_cannot_perform_writes(): void
    {
        $c = $this->chain();
        $reader = $this->staff($c['company'], ['erp.purchase-reports.view']);
        $this->actingAs($reader)->get('/erp/purchase/dashboard')->assertOk();
        $this->get('/erp/purchase/reports?type=payables')->assertOk();
        $this->get('/erp/purchase/reports/export')->assertForbidden();
        foreach (['requisitions' => $c['requisition'], 'orders' => $c['order'], 'returns' => $c['return'], 'bills' => $c['bill']] as $path => $record) {
            $this->post('/erp/purchase/'.$path.'/'.$record->id.'/approve', ['revision' => $record->getAttribute('revision')])->assertForbidden();
        }
        $this->post('/erp/purchase/receipts/'.$c['receipt']->id.'/post', ['revision' => 1])->assertForbidden();
        $this->post('/erp/purchase/returns/'.$c['return']->id.'/post', ['revision' => 1])->assertForbidden();
        $this->assertDatabaseCount('supplier_payables', 1);
    }
}
