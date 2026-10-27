<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingMappingVersion;
use App\Models\Accounting\AccountingPurchaseCostSlice;
use App\Models\Accounting\AccountingPurchaseEvent;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\AccountingSupplierCredit;
use App\Models\Accounting\JournalEntryLine;
use App\Models\Company;
use App\Models\Employee;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierBillItem;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ERP\Accounting\AccountingAccountService;
use App\Services\ERP\Accounting\AccountingCalendarService;
use App\Services\ERP\Accounting\AccountingMappingCatalog;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\Source\AccountingSourceOrchestrator;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierCreditService;
use App\Services\ERP\Purchase\GoodsReceiptService;
use App\Services\ERP\Purchase\PurchaseReturnService;
use App\Services\ERP\Purchase\SupplierBillService;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\StockTransferService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\AccountingPurchasePermissionSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class AccountingPurchasePostingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private User $reviewer;

    private AccountingMappingService $service;

    /** @var array<string, int> */
    private array $mapping;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(AccountingPermissionSeeder::class);
        $this->seed(PurchasePermissionSeeder::class);
        $this->seed(AccountingPurchasePermissionSeeder::class);
        Permission::findOrCreate('erp.stock-transfers.post', 'web');
        $this->company = $this->newCompany('AUTO-P');
        $this->actor = User::factory()->create();
        $this->actor->assignRole('super-admin');
        $this->actor->givePermissionTo('erp.stock-transfers.post');
        $this->reviewer = User::factory()->create();
        $this->reviewer->assignRole('super-admin');
        $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00:00', 'Asia/Dhaka'));
        app(AccountingSetupService::class)->initialize($this->actor, (int) $this->company->getKey());
        $this->service = app(AccountingMappingService::class);
        $this->mapping = $this->chart($this->company);
        $this->publish();
        $calendar = app(AccountingCalendarService::class);
        $year = $calendar->saveYear($this->actor, ['company_id' => $this->company->getKey(), 'code' => 'FY26', 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'reason' => 'Source integration fixture']);
        $calendar->savePeriod($this->actor, (int) $year->getKey(), ['company_id' => $this->company->getKey(), 'code' => 'OCT', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'reason' => 'Source integration fixture']);
        // Isolated synthetic activation prerequisite; production activation is still reserved for 6.13.
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['state' => 'enabled', 'activation_date' => '2026-10-01', 'activated_by' => $this->actor->getKey(), 'activated_at' => now()]);
        AccountingCutover::query()->create(['company_id' => $this->company->getKey(), 'cutover_date' => '2026-10-01', 'strategy' => 'snapshot', 'status' => 'activated', 'created_by' => $this->actor->getKey(), 'approved_by' => $this->reviewer->getKey(), 'approved_at' => now(), 'activated_by' => $this->actor->getKey(), 'activated_at' => now(), 'manifest_hash' => str_repeat('a', 64), 'reconciliation_hash' => str_repeat('b', 64), 'revision' => 1]);
    }

    private function newCompany(string $code): Company
    {
        return Company::query()->create(['name' => $code, 'code' => $code, 'status' => 'active', 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka']);
    }

    /** @return array<string, int> */
    private function chart(Company $company): array
    {
        app(AccountingAccountService::class)->initializeGroups($this->actor, (int) $company->getKey());
        $catalog = app(AccountingMappingCatalog::class);
        $map = [];
        $number = 1000;
        foreach ([...$catalog->roles(), 'cash:MAIN' => 'asset'] as $role => $category) {
            $group = AccountGroup::query()->where('company_id', $company->getKey())->where('category', $category)->firstOrFail();
            $account = Account::query()->create(['company_id' => $company->getKey(), 'account_group_id' => $group->getKey(), 'code' => (string) $number++, 'name' => $role, 'normal_side' => $role === 'sales_allowances' ? 'debit' : $group->getAttribute('normal_side'), 'is_postable' => true, 'control_role' => $catalog->control($role), 'statement_role' => $catalog->statements($role)[0], 'cash_flow_role' => $catalog->cashRole($role) ? 'cash' : null, 'status' => 'active', 'revision' => 1]);
            $map[$role] = (int) $account->getKey();
        }

        return $map;
    }

    /** @param list<string> $permissions */
    private function staff(array $permissions): User
    {
        $user = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $user->getKey(), 'employee_number' => 'MAP-'.$user->getKey(), 'first_name' => 'Finance', 'last_name' => 'User']);
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function data(array $overrides = []): array
    {
        return ['company_id' => (int) $this->company->getKey(), 'base_version_id' => null, 'effective_from' => '2026-10-01', 'mappings' => $this->mapping, 'reason' => 'Approve initial company posting profile', ...$overrides];
    }

    /** @param array<string, mixed> $overrides */
    private function publish(array $overrides = []): AccountingMappingVersion
    {
        return $this->service->publish($this->actor, $this->data($overrides));
    }

    /** @return array{Warehouse, Product, PurchaseOrder, PurchaseOrderItem} */
    private function order(bool $stock = true, string $quantity = '10.0000', string $price = '10.00', string $discount = '10.00', string $tax = '5.00'): array
    {
        $n = PurchaseOrder::query()->count() + 1;
        $company = $this->company->getKey();
        $warehouse = Warehouse::query()->create(['company_id' => $company, 'name' => 'Main '.$n, 'code' => 'WH-'.$n, 'is_active' => true]);
        $unit = Unit::query()->create(['company_id' => $company, 'name' => 'Piece '.$n, 'short_name' => 'pc'.$n]);
        $product = Product::query()->create(['company_id' => $company, 'unit_id' => $unit->getKey(), 'name' => 'Purchase product '.$n, 'product_type' => $stock ? 'physical' : 'service', 'track_inventory' => $stock, 'has_variants' => false, 'allow_negative_stock' => false, 'is_active' => true]);
        $supplier = Supplier::query()->create(['company_id' => $company, 'code' => 'S-'.$n, 'name' => 'Supplier '.$n, 'currency_code' => 'BDT', 'status' => 'active']);
        $base = bcmul($quantity, $price, 2);
        $total = bcadd(bcsub($base, $discount, 2), $tax, 2);
        $order = PurchaseOrder::query()->create(['company_id' => $company, 'supplier_id' => $supplier->getKey(), 'created_by' => $this->actor->getKey(), 'number' => 'PO-'.$n, 'status' => 'ordered', 'currency_code' => 'BDT', 'subtotal' => $base, 'discount_total' => $discount, 'tax_total' => $tax, 'grand_total' => $total]);
        $item = PurchaseOrderItem::query()->create(['purchase_order_id' => $order->getKey(), 'product_id' => $product->getKey(), 'product_name' => $product->getAttribute('name'), 'quantity' => $quantity, 'received_quantity' => '0.0000', 'unit_price' => $price, 'discount_amount' => $discount, 'tax_amount' => $tax, 'line_total' => $total]);

        return [$warehouse, $product, $order, $item];
    }

    /** @param array{Warehouse, Product, PurchaseOrder, PurchaseOrderItem} $fixture */
    private function draftReceipt(array $fixture, ?string $quantity = null): GoodsReceipt
    {
        [$warehouse, , $order, $item] = $fixture;

        return app(GoodsReceiptService::class)->create($this->actor, ['company_id' => $this->company->getKey(), 'purchase_order_id' => $order->getKey(), 'warehouse_id' => $warehouse->getKey(), 'received_at' => now()->subMinute()->format('Y-m-d H:i:s'), 'notes' => 'PRIVATE-INSPECTION', 'items' => [['purchase_order_item_id' => $item->getKey(), 'quantity_received' => $quantity ?? (string) $item->getAttribute('quantity'), 'quantity_rejected' => '0.0000']]]);
    }

    private function receive(GoodsReceipt $r, ?User $actor = null): GoodsReceipt
    {
        return app(GoodsReceiptService::class)->post($actor ?? $this->actor, (int) $r->getKey(), (int) $r->getAttribute('revision'));
    }

    private function draftBill(GoodsReceipt $receipt, string $quantity): SupplierBill
    {
        $line = GoodsReceiptItem::query()->where('goods_receipt_id', $receipt->getKey())->firstOrFail();

        return app(SupplierBillService::class)->create($this->actor, ['goods_receipt_id' => $receipt->getKey(), 'supplier_invoice_number' => 'VENDOR-'.(SupplierBill::query()->count() + 1), 'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(15)->toDateString(), 'notes' => 'PRIVATE-INVOICE', 'items' => [['goods_receipt_item_id' => $line->getKey(), 'quantity' => $quantity]]]);
    }

    private function approveBill(SupplierBill $bill): SupplierBill
    {
        return app(SupplierBillService::class)->approve($this->reviewer, (int) $bill->getKey(), (int) $bill->getAttribute('revision'));
    }

    private function draftReturn(GoodsReceipt $receipt, string $quantity): PurchaseReturn
    {
        $line = GoodsReceiptItem::query()->where('goods_receipt_id', $receipt->getKey())->firstOrFail();
        $r = app(PurchaseReturnService::class)->create($this->actor, ['goods_receipt_id' => $receipt->getKey(), 'reason' => 'PRIVATE-RETURN-REASON', 'items' => [['goods_receipt_item_id' => $line->getKey(), 'quantity' => $quantity]]]);

        return app(PurchaseReturnService::class)->approve($this->reviewer, (int) $r->getKey(), (int) $r->getAttribute('revision'));
    }

    private function postReturn(PurchaseReturn $r): PurchaseReturn
    {
        return app(PurchaseReturnService::class)->post($this->actor, (int) $r->getKey(), (int) $r->getAttribute('revision'));
    }

    private function role(string $role, string $side): string
    {
        return bcadd((string) JournalEntryLine::query()->where('account_id', $this->mapping[$role])->sum($side), '0', 2);
    }

    private function invalid(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Expected a posting validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    private function settings(array $changes): void
    {
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update($changes);
    }

    public function test_receipt_posts_actual_inventory_cost_and_grni_once(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $this->assertSame('90.00', $this->role('inventory', 'debit'));
        $this->assertSame('90.00', $this->role('grni', 'credit'));
        $this->assertSame('0.00', $this->role('trade_ap', 'credit'));
        $this->receive($r);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('accounting_purchase_events', 1);
    }

    public function test_bill_clears_grni_posts_input_tax_and_ap_without_recapitalizing_stock(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $b = $this->approveBill($this->draftBill($r, '10.0000'));
        $this->assertSame('90.00', $this->role('grni', 'debit'));
        $this->assertSame('5.00', $this->role('input_tax', 'debit'));
        $this->assertSame('95.00', $this->role('trade_ap', 'credit'));
        $this->assertSame('90.00', $this->role('inventory', 'debit'));
        $this->assertSame('0.00', $this->role('general_expense', 'debit'));
        app(SupplierBillService::class)->approve($this->reviewer, (int) $b->getKey(), 1);
        $this->assertDatabaseCount('supplier_payables', 1);
        $this->assertDatabaseCount('journal_entries', 2);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_nonstock_bill_posts_expense_and_never_creates_inventory(): void
    {
        $r = $this->receive($this->draftReceipt($this->order(false)));
        $this->approveBill($this->draftBill($r, '10.0000'));
        $this->assertSame('90.00', $this->role('general_expense', 'debit'));
        $this->assertSame('0.00', $this->role('inventory', 'debit'));
        $this->assertSame('0.00', $this->role('grni', 'credit'));
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertSame('verified_no_journal', AccountingSourceEffect::query()->where('source_kind', 'goods_receipt')->firstOrFail()->getAttribute('outcome'));
    }

    public function test_partial_bills_release_exact_original_receipt_cents(): void
    {
        $r = $this->receive($this->draftReceipt($this->order(true, '3.0000', '1.00', '2.00', '0.00')));
        for ($i = 0; $i < 3; $i++) {
            $this->approveBill($this->draftBill($r, '1.0000'));
        }
        $this->assertSame('1.00', $this->role('grni', 'credit'));
        $this->assertSame('1.00', $this->role('grni', 'debit'));
        $this->assertSame('0.99', $this->role('trade_ap', 'credit'));
        $this->assertSame('0.01', $this->role('purchase_variance', 'credit'));
        $this->assertDatabaseCount('accounting_purchase_cost_slices', 3);
    }

    public function test_unbilled_return_uses_actual_outgoing_cost_and_explicit_variance(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        InventoryBalance::query()->where('company_id', $this->company->getKey())->update(['average_cost' => '12.0000']);
        $return = $this->postReturn($this->draftReturn($r, '1.0000'));
        $this->assertSame('9.00', $this->role('grni', 'debit'));
        $this->assertSame('12.00', $this->role('inventory', 'credit'));
        $this->assertSame('3.00', $this->role('purchase_variance', 'debit'));
        $this->assertSame('0.00', $this->role('trade_ap', 'debit'));
        $this->postReturn($return);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseCount('journal_entries', 2);
    }

    public function test_billed_return_posts_supplier_return_clearing_without_automatic_supplier_credit(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $this->approveBill($this->draftBill($r, '10.0000'));
        $this->postReturn($this->draftReturn($r, '2.0000'));
        $this->assertSame('18.00', $this->role('supplier_return_clearing', 'debit'));
        $this->assertSame('90.00', $this->role('grni', 'debit'));
        $this->assertSame('0.00', $this->role('trade_ap', 'debit'));
        $this->assertSame('95.00', $this->role('trade_ap', 'credit'));
        $this->assertSame('95.00', bcadd((string) DB::table('supplier_payables')->value('amount'), '0', 2));
    }

    public function test_mixed_billed_return_allocates_unbilled_part_first(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $this->approveBill($this->draftBill($r, '8.0000'));
        $this->postReturn($this->draftReturn($r, '4.0000'));
        $this->assertSame('90.00', $this->role('grni', 'debit'));
        $this->assertSame('18.00', $this->role('supplier_return_clearing', 'debit'));
        $this->assertSame('36.00', $this->role('inventory', 'credit'));
    }

    public function test_nonstock_return_does_not_reverse_expense_or_ap(): void
    {
        $r = $this->receive($this->draftReceipt($this->order(false)));
        $this->approveBill($this->draftBill($r, '10.0000'));
        $this->postReturn($this->draftReturn($r, '2.0000'));
        $this->assertSame('90.00', $this->role('general_expense', 'debit'));
        $this->assertSame('0.00', $this->role('general_expense', 'credit'));
        $this->assertSame('0.00', $this->role('trade_ap', 'debit'));
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('accounting_source_effects', 3);
    }

    public function test_subcent_receipt_records_explicit_rounding_proof(): void
    {
        $this->receive($this->draftReceipt($this->order(true, '3.0000', '0.01', '0.02', '0.00'), '1.0000'));
        $effect = AccountingSourceEffect::query()->firstOrFail();
        $this->assertSame('verified_no_journal', $effect->getAttribute('outcome'));
        $this->assertSame('subcent_valuation', $effect->getAttribute('no_journal_reason'));
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_closed_period_rolls_back_receipt_stock_counters_and_admission(): void
    {
        $f = $this->order();
        $r = $this->draftReceipt($f);
        DB::table('accounting_periods')->update(['state' => 'hard_closed']);
        $this->invalid(fn () => $this->receive($r), 'accounting_date');
        $this->assertSame('draft', $r->refresh()->getAttribute('status'));
        $this->assertSame('0.0000', $f[3]->refresh()->getAttribute('received_quantity'));
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('inventory_balances', 0);
        $this->assertDatabaseCount('accounting_purchase_events', 0);
        $this->assertDatabaseCount('accounting_document_sequences', 0);
    }

    public function test_bill_mapping_failure_rolls_back_approval_payable_and_cost_slices(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $bill = $this->draftBill($r, '2.0000');
        Account::query()->whereKey($this->mapping['trade_ap'])->update(['status' => 'inactive']);
        $this->invalid(fn () => $this->approveBill($bill), 'mappings');
        $this->assertSame('draft', $bill->refresh()->getAttribute('status'));
        $this->assertDatabaseCount('supplier_payables', 0);
        $this->assertDatabaseCount('accounting_purchase_cost_slices', 0);
        $this->assertDatabaseCount('accounting_purchase_events', 1);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_return_finance_failure_rolls_back_stock_and_return_status(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $return = $this->draftReturn($r, '2.0000');
        DB::table('accounting_periods')->update(['state' => 'soft_closed']);
        $this->invalid(fn () => $this->postReturn($return), 'accounting_date');
        $this->assertSame('approved', $return->refresh()->getAttribute('status'));
        $this->assertSame('10.0000', InventoryBalance::query()->firstOrFail()->getAttribute('quantity_on_hand'));
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('accounting_purchase_events', 1);
    }

    public function test_receipt_retry_uses_historical_evidence_after_period_close_and_disable(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $this->settings(['state' => 'disabled', 'cutover_locked_at' => now()]);
        DB::table('accounting_periods')->update(['state' => 'hard_closed']);
        Account::query()->whereKey($this->mapping['inventory'])->update(['status' => 'inactive']);
        $this->receive($r);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_mutated_movement_is_rejected_on_retry(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        DB::table('stock_movements')->update(['total_cost' => '91.0000']);
        $this->invalid(fn () => $this->receive($r), 'accounting_source');
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_private_operational_notes_are_not_in_financial_evidence(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $this->approveBill($this->draftBill($r, '2.0000'));
        $this->postReturn($this->draftReturn($r, '1.0000'));
        $json = json_encode(AccountingPurchaseEvent::query()->pluck('payload')->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('PRIVATE-', $json);
    }

    public function test_source_staff_can_post_without_manual_journal_permission(): void
    {
        $r = $this->draftReceipt($this->order());
        $staff = $this->staff(['erp.goods-receipts.post']);
        $this->assertFalse($staff->can('erp.journal-entries.post'));
        $this->receive($r, $staff);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_foreign_company_actor_cannot_post_source(): void
    {
        $r = $this->draftReceipt($this->order());
        $foreign = $this->newCompany('FOREIGN');
        $actor = User::factory()->create();
        Employee::query()->create(['company_id' => $foreign->getKey(), 'user_id' => $actor->getKey(), 'employee_number' => 'FOREIGN-1', 'first_name' => 'Foreign', 'last_name' => 'User']);
        $actor->givePermissionTo('erp.goods-receipts.post');
        try {
            $this->receive($r, $actor);
            $this->fail('Expected scoped rejection.');
        } catch (ModelNotFoundException|\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertDatabaseCount('stock_movements', 0);
        }
    }

    public function test_disabled_accounting_preserves_operational_behavior_without_financial_markers(): void
    {
        $this->settings(['state' => 'disabled']);
        $r = $this->receive($this->draftReceipt($this->order()));
        $this->approveBill($this->draftBill($r, '2.0000'));
        $this->postReturn($this->draftReturn($r, '1.0000'));
        $this->assertDatabaseCount('accounting_source_effects', 0);
        $this->assertDatabaseCount('accounting_purchase_events', 0);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseCount('supplier_payables', 1);
    }

    public function test_old_disabled_receipt_is_not_backfilled_when_enabled(): void
    {
        $this->settings(['state' => 'disabled']);
        $r = $this->receive($this->draftReceipt($this->order()));
        $this->settings(['state' => 'enabled']);
        $this->invalid(fn () => $this->receive($r), 'accounting_source');
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_bill_against_unrecognized_legacy_receipt_is_blocked_atomically(): void
    {
        $this->settings(['state' => 'disabled']);
        $r = $this->receive($this->draftReceipt($this->order()));
        $b = $this->draftBill($r, '2.0000');
        $this->settings(['state' => 'enabled']);
        $this->invalid(fn () => $this->approveBill($b), 'accounting_source');
        $this->assertSame('draft', $b->refresh()->getAttribute('status'));
        $this->assertDatabaseCount('supplier_payables', 0);
    }

    public function test_bill_approved_while_disabled_cannot_be_ignored_by_later_return_allocation(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $this->settings(['state' => 'disabled']);
        $this->approveBill($this->draftBill($r, '5.0000'));
        $this->settings(['state' => 'enabled']);
        $return = $this->draftReturn($r, '2.0000');
        $this->invalid(fn () => $this->postReturn($return), 'accounting_source');
        $this->assertSame('approved', $return->refresh()->getAttribute('status'));
    }

    public function test_cutover_lock_blocks_even_disabled_new_stock_writes(): void
    {
        $r = $this->draftReceipt($this->order());
        $this->settings(['state' => 'disabled', 'cutover_locked_at' => now()]);
        $this->invalid(fn () => $this->receive($r), 'accounting_source');
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame('draft', $r->refresh()->getAttribute('status'));
    }

    public function test_raw_enabled_adjustment_and_opening_writes_cannot_bypass_accounting(): void
    {
        [$w, $p] = $this->order();
        foreach ([StockMovement::TYPE_ADJUSTMENT_IN, StockMovement::TYPE_OPENING_STOCK, StockMovement::TYPE_PURCHASE_RECEIPT] as $type) {
            $this->invalid(fn () => app(InventoryService::class)->stockIn($w, $p, '1.0000', $type, '9.0000', createdBy: (int) $this->actor->getKey()), 'accounting_source');
        }
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('inventory_balances', 0);
    }

    public function test_balanced_transfer_records_zero_gl_without_changing_total_inventory(): void
    {
        $f = $this->order();
        $this->receive($this->draftReceipt($f));
        [$w, $p] = $f;
        $destination = Warehouse::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Destination', 'code' => 'DEST', 'is_active' => true]);
        $t = StockTransfer::query()->create(['company_id' => $this->company->getKey(), 'document_number' => 'ST-AUTO', 'transfer_date' => now()->toDateString(), 'source_warehouse_id' => $w->getKey(), 'destination_warehouse_id' => $destination->getKey(), 'status' => 'draft', 'created_by' => $this->actor->getKey()]);
        $t->items()->create(['product_id' => $p->getKey(), 'variant_key' => 0, 'quantity' => '3.0000']);
        app(StockTransferService::class)->post($t, (int) $this->actor->getKey());
        $this->assertSame('90.00', $this->role('inventory', 'debit'));
        $this->assertSame('0.00', $this->role('inventory', 'credit'));
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('stock_movements', 3);
        $effect = AccountingSourceEffect::query()->where('source_kind', 'stock_transfer')->firstOrFail();
        $this->assertSame('same_company_transfer', $effect->getAttribute('no_journal_reason'));
        $result = DB::transaction(fn () => app(AccountingSourceOrchestrator::class)->execute($this->actor, (int) $this->company->getKey(), 'stock_transfer', (int) $t->getKey(), 'posted', 'original'));
        $this->assertSame('replayed', $result['mode']);
        DB::table('stock_movements')->where('movement_type', StockMovement::TYPE_TRANSFER_IN)->update(['total_cost' => '28.0000']);
        $this->invalid(fn () => DB::transaction(fn () => app(AccountingSourceOrchestrator::class)->execute($this->actor, (int) $this->company->getKey(), 'stock_transfer', (int) $t->getKey(), 'posted', 'original')), 'accounting_source');
    }

    public function test_damaged_frozen_admission_is_rejected(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        DB::table('accounting_purchase_events')->update(['payload_hash' => str_repeat('0', 64)]);
        $this->invalid(fn () => $this->receive($r), 'accounting_source');
    }

    public function test_purchase_admission_and_cost_slices_are_append_only(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $this->approveBill($this->draftBill($r, '2.0000'));
        foreach ([AccountingPurchaseEvent::query()->firstOrFail(), AccountingPurchaseCostSlice::query()->firstOrFail()] as $record) {
            try {
                $record->delete();
                $this->fail('Expected immutable history guard.');
            } catch (\LogicException) {
                $this->assertTrue($record->exists);
            }
        }
    }

    public function test_changed_cost_slice_is_rejected_before_next_bill_approval(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $this->approveBill($this->draftBill($r, '2.0000'));
        DB::table('accounting_purchase_cost_slices')->update(['grni_amount' => '19.00']);
        $next = $this->draftBill($r, '2.0000');
        $this->invalid(fn () => $this->approveBill($next), 'accounting_source');
        $this->assertSame('draft', $next->refresh()->getAttribute('status'));
        $this->assertDatabaseCount('supplier_payables', 1);
    }

    public function test_missing_receipt_financial_bundle_is_not_filled_by_a_bill(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        DB::table('accounting_source_effects')->where('source_kind', 'goods_receipt')->delete();
        $b = $this->draftBill($r, '2.0000');
        $this->invalid(fn () => $this->approveBill($b), 'accounting_source');
        $this->assertDatabaseCount('supplier_payables', 0);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_context_is_cleared_after_failed_source_posting(): void
    {
        $f = $this->order();
        $r = $this->draftReceipt($f);
        DB::table('accounting_periods')->update(['state' => 'hard_closed']);
        $this->invalid(fn () => $this->receive($r), 'accounting_date');
        $this->invalid(fn () => app(InventoryService::class)->stockIn($f[0], $f[1], '1.0000', StockMovement::TYPE_PURCHASE_RECEIPT, '9.0000', referenceType: GoodsReceipt::class, referenceId: (int) $r->getKey(), createdBy: (int) $this->actor->getKey()), 'accounting_source');
        $this->assertDatabaseCount('stock_movements', 0);
    }

    private function draftCredit(SupplierBill $bill, PurchaseReturn $return, string $quantity = '1.0000'): AccountingSupplierCredit
    {
        $billLine = SupplierBillItem::query()->where('supplier_bill_id', $bill->getKey())->firstOrFail();
        $returnLine = PurchaseReturnItem::query()->where('purchase_return_id', $return->getKey())->firstOrFail();

        return app(AccountingSupplierCreditService::class)->create($this->actor, ['company_id' => $this->company->getKey(), 'supplier_bill_id' => $bill->getKey(), 'purchase_return_id' => $return->getKey(), 'document_date' => now()->toDateString(), 'reason' => 'Approved vendor credit evidence', 'items' => [['supplier_bill_item_id' => $billLine->getKey(), 'purchase_return_item_id' => $returnLine->getKey(), 'quantity' => $quantity]]]);
    }

    private function issueCredit(AccountingSupplierCredit $credit): AccountingSupplierCredit
    {
        $service = app(AccountingSupplierCreditService::class);
        $approved = $service->approve($this->reviewer, (int) $this->company->getKey(), (int) $credit->getKey(), (int) $credit->getAttribute('revision'));

        return $service->issue($this->actor, (int) $this->company->getKey(), (int) $approved->getKey(), (int) $approved->getAttribute('revision'));
    }

    public function test_linked_supplier_credit_clears_return_asset_and_explicitly_reduces_unpaid_ap(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $bill = $this->approveBill($this->draftBill($r, '10.0000'));
        $return = $this->postReturn($this->draftReturn($r, '2.0000'));
        $credit = $this->issueCredit($this->draftCredit($bill, $return, '2.0000'));
        $this->assertSame('issued', $credit->getAttribute('status'));
        $this->assertSame('19.00', $this->role('trade_ap', 'debit'));
        $this->assertSame('18.00', $this->role('supplier_return_clearing', 'credit'));
        $this->assertSame('1.00', $this->role('input_tax', 'credit'));
        $this->assertSame('18.00', $this->role('inventory', 'credit'));
        $this->assertSame('19.00', bcadd((string) DB::table('supplier_payables')->value('credited_amount'), '0', 2));
        $this->assertSame('0.00', bcadd((string) DB::table('supplier_payables')->value('paid_amount'), '0', 2));
        app(AccountingSupplierCreditService::class)->issue($this->actor, (int) $this->company->getKey(), (int) $credit->getKey(), 1);
        $this->assertDatabaseCount('journal_entries', 4);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->actingAs($this->actor)->get('/erp/purchase/bills/'.$bill->getKey())->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('payable.outstanding', '76.00')->where('payable.credited_amount', '19.00')->where('items.0.credit_review_quantity', '0.0000'));
    }

    public function test_supplier_credit_requires_independent_approval(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $bill = $this->approveBill($this->draftBill($r, '10.0000'));
        $return = $this->postReturn($this->draftReturn($r, '2.0000'));
        $credit = $this->draftCredit($bill, $return);
        $service = app(AccountingSupplierCreditService::class);
        $this->invalid(fn () => $service->approve($this->actor, (int) $this->company->getKey(), (int) $credit->getKey(), 1), 'supplier_credit');
        $this->invalid(fn () => $service->issue($this->actor, (int) $this->company->getKey(), (int) $credit->getKey(), 1), 'supplier_credit');
        $this->assertSame('0.00', $this->role('trade_ap', 'debit'));
    }

    public function test_supplier_credit_cannot_exceed_returned_billed_quantity(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $bill = $this->approveBill($this->draftBill($r, '10.0000'));
        $return = $this->postReturn($this->draftReturn($r, '1.0000'));
        $this->invalid(fn () => $this->draftCredit($bill, $return, '2.0000'), 'supplier_credit');
        $this->issueCredit($this->draftCredit($bill, $return));
        $this->invalid(fn () => $this->draftCredit($bill, $return), 'supplier_credit');
    }

    public function test_paid_bill_credit_requires_refund_workflow_and_does_not_invent_cash(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $bill = $this->approveBill($this->draftBill($r, '10.0000'));
        $return = $this->postReturn($this->draftReturn($r, '1.0000'));
        DB::table('supplier_payables')->update(['paid_amount' => '95.00']);
        $this->invalid(fn () => $this->draftCredit($bill, $return), 'supplier_credit');
        $this->assertDatabaseCount('accounting_supplier_credits', 0);
        $this->assertSame('0.00', $this->role('trade_ap', 'debit'));
    }

    public function test_nonstock_supplier_credit_reverses_expense_only_when_independently_issued(): void
    {
        $r = $this->receive($this->draftReceipt($this->order(false)));
        $bill = $this->approveBill($this->draftBill($r, '10.0000'));
        $return = $this->postReturn($this->draftReturn($r, '2.0000'));
        $this->issueCredit($this->draftCredit($bill, $return, '2.0000'));
        $this->assertSame('18.00', $this->role('general_expense', 'credit'));
        $this->assertSame('19.00', $this->role('trade_ap', 'debit'));
        $this->assertSame('0.00', $this->role('supplier_return_clearing', 'credit'));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_credit_period_failure_rolls_back_payable_summary_issue_and_financial_admission(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $bill = $this->approveBill($this->draftBill($r, '10.0000'));
        $return = $this->postReturn($this->draftReturn($r, '2.0000'));
        $credit = $this->draftCredit($bill, $return);
        $service = app(AccountingSupplierCreditService::class);
        $credit = $service->approve($this->reviewer, (int) $this->company->getKey(), (int) $credit->getKey(), 1);
        DB::table('accounting_periods')->update(['state' => 'hard_closed']);
        $this->invalid(fn () => $service->issue($this->actor, (int) $this->company->getKey(), (int) $credit->getKey(), 2), 'accounting_date');
        $this->assertSame('approved', $credit->refresh()->getAttribute('status'));
        $this->assertSame('0.00', bcadd((string) DB::table('supplier_payables')->value('credited_amount'), '0', 2));
        $this->assertDatabaseCount('accounting_source_effects', 3);
    }

    public function test_partial_supplier_credits_preserve_exact_total_and_do_not_repost_stock(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $bill = $this->approveBill($this->draftBill($r, '10.0000'));
        $return = $this->postReturn($this->draftReturn($r, '2.0000'));
        $this->issueCredit($this->draftCredit($bill, $return));
        $this->issueCredit($this->draftCredit($bill, $return));
        $this->assertSame('19.00', $this->role('trade_ap', 'debit'));
        $this->assertSame('18.00', $this->role('supplier_return_clearing', 'credit'));
        $this->assertSame('1.00', $this->role('input_tax', 'credit'));
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseCount('accounting_supplier_credits', 2);
    }

    public function test_unbilled_physical_return_cannot_be_used_for_supplier_credit(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $bill = $this->approveBill($this->draftBill($r, '5.0000'));
        $return = $this->postReturn($this->draftReturn($r, '2.0000'));
        $this->invalid(fn () => $this->draftCredit($bill, $return), 'supplier_credit');
        $this->assertDatabaseCount('accounting_supplier_credits', 0);
    }
}
