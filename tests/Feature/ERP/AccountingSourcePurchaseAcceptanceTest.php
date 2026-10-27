<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingMappingVersion;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\AccountingSupplierCredit;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalEntryLine;
use App\Models\Company;
use App\Models\Employee;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierBillItem;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ERP\Accounting\AccountingAccountService;
use App\Services\ERP\Accounting\AccountingCalendarService;
use App\Services\ERP\Accounting\AccountingJournalReversalService;
use App\Services\ERP\Accounting\AccountingMappingCatalog;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierCreditService;
use App\Services\ERP\Purchase\GoodsReceiptService;
use App\Services\ERP\Purchase\PurchaseReturnService;
use App\Services\ERP\Purchase\SupplierBillService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\AccountingPurchasePermissionSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class AccountingSourcePurchaseAcceptanceTest extends TestCase
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

    /** @return array{0: Warehouse, 1: Product, 2: PurchaseOrder, 3: PurchaseOrderItem} */
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

    /** @param array{0: Warehouse, 1: Product, 2: PurchaseOrder, 3: PurchaseOrderItem} $fixture */
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

    private function invalid(callable $action, ?string $field = null): void
    {
        try {
            $action();
            $this->fail('Expected a posting validation error.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
            if ($field !== null) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }
    }

    private function settings(array $changes): void
    {
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update($changes);
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

    private function november(): void
    {
        $year = FiscalYear::query()->where('company_id', $this->company->getKey())->firstOrFail();
        app(AccountingCalendarService::class)->savePeriod($this->actor, (int) $year->getKey(), ['company_id' => $this->company->getKey(), 'code' => 'NOV', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'reason' => 'Purchase current-period correction acceptance']);
        DB::table('accounting_periods')->where('company_id', $this->company->getKey())->where('code', 'OCT')->update(['state' => 'hard_closed']);
        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00', 'Asia/Dhaka'));
    }

    private function lastEffect(): int
    {
        return (int) AccountingSourceEffect::query()->max('id');
    }

    private function newEffectsAreCurrent(int $after): void
    {
        $rows = AccountingSourceEffect::query()->where('id', '>', $after)->get();
        $this->assertNotEmpty($rows->all());
        foreach ($rows as $row) {
            $this->assertSame('2026-11-03', $row->getAttribute('accounting_date')->format('Y-m-d'));
            if ($row->getAttribute('journal_entry_id') !== null) {
                $journal = JournalEntry::query()->findOrFail($row->getAttribute('journal_entry_id'));
                $this->assertSame('2026-11-03', $journal->getAttribute('accounting_date')->format('Y-m-d'));
            }
        }
    }

    private function stateDigest(): string
    {
        $parts = [];
        foreach (['journal_entries', 'journal_entry_lines', 'accounting_purchase_events', 'accounting_purchase_cost_slices', 'accounting_source_effects', 'goods_receipts', 'goods_receipt_items', 'purchase_order_items', 'purchase_returns', 'purchase_return_items', 'supplier_bills', 'supplier_bill_items', 'supplier_payables', 'accounting_supplier_credits', 'accounting_supplier_credit_lines', 'inventory_balances', 'stock_movements'] as $table) {
            $parts[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }

return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    private function failsAtomically(callable $action): void
    {
        $before = $this->stateDigest();
        $this->invalid($action);
        $this->assertSame($before, $this->stateDigest());
    }

    /** @return array{journal: int, effect: int, movement: int, digest: string} */
    private function originalTrace(): array
    {
        $j = (int) JournalEntry::query()->max('id');
        $e = $this->lastEffect();
        $m = (int) StockMovement::query()->max('id');

        return ['journal' => $j, 'effect' => $e, 'movement' => $m, 'digest' => $this->traceDigest($j, $e, $m)];
    }

    private function traceDigest(int $journal, int $effect, int $movement): string
    {
        $parts = [];
        foreach (['journal_entries' => ['id', $journal], 'journal_entry_lines' => ['journal_entry_id', $journal], 'accounting_source_effects' => ['id', $effect], 'stock_movements' => ['id', $movement]] as $table => $bound) {
            $parts[$table] = DB::table($table)->where($bound[0], '<=', $bound[1])->orderBy('id')->get()->toArray();
        }

return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    /** @param array{journal: int, effect: int, movement: int, digest: string} $trace */
    private function assertOriginalTrace(array $trace): void
    {
        $this->assertSame($trace['digest'], $this->traceDigest($trace['journal'], $trace['effect'], $trace['movement']));
    }

    public function test_unbilled_purchase_return_posts_in_current_open_period(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $trace = $this->originalTrace();
        $this->november();
        $after = $this->lastEffect();
        $this->postReturn($this->draftReturn($r, '2.0000'));
        $this->newEffectsAreCurrent($after);
        $this->assertOriginalTrace($trace);
        $this->assertSame('18.00', $this->role('grni', 'debit'));
        $this->assertSame('0.00', $this->role('trade_ap', 'debit'));
    }

    public function test_billed_purchase_return_uses_current_period_without_reducing_ap(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $bill = $this->approveBill($this->draftBill($r, '10.0000'));
        $trace = $this->originalTrace();
        $this->november();
        $after = $this->lastEffect();
        $this->postReturn($this->draftReturn($r, '2.0000'));
        $this->newEffectsAreCurrent($after);
        $this->assertOriginalTrace($trace);
        $this->assertSame('18.00', $this->role('supplier_return_clearing', 'debit'));
        $this->assertSame('0.00', $this->role('trade_ap', 'debit'));
        $this->assertSame('0.00', bcadd((string) DB::table('supplier_payables')->where('supplier_bill_id', $bill->getKey())->value('credited_amount'), '0', 2));
    }

    public function test_supplier_credit_against_old_bill_and_return_posts_in_current_period(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $b = $this->approveBill($this->draftBill($r, '10.0000'));
        $ret = $this->postReturn($this->draftReturn($r, '2.0000'));
        $trace = $this->originalTrace();
        $this->november();
        $after = $this->lastEffect();
        $this->issueCredit($this->draftCredit($b, $ret, '2.0000'));
        $this->newEffectsAreCurrent($after);
        $this->assertOriginalTrace($trace);
        $this->assertSame('19.00', $this->role('trade_ap', 'debit'));
        $this->assertSame('1.00', $this->role('input_tax', 'credit'));
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_current_return_and_credit_keep_physical_and_commercial_corrections_separate(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $b = $this->approveBill($this->draftBill($r, '10.0000'));
        $trace = $this->originalTrace();
        $this->november();
        $after = $this->lastEffect();
        $ret = $this->postReturn($this->draftReturn($r, '2.0000'));
        $this->assertSame('0.00', $this->role('trade_ap', 'debit'));
        $this->issueCredit($this->draftCredit($b, $ret, '2.0000'));
        $this->assertSame('19.00', $this->role('trade_ap', 'debit'));
        $this->newEffectsAreCurrent($after);
        $this->assertOriginalTrace($trace);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_closed_current_period_rolls_back_purchase_return_stock_and_counters(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $this->november();
        $ret = $this->draftReturn($r, '2.0000');
        DB::table('accounting_periods')->where('code', 'NOV')->update(['state' => 'hard_closed']);
        $this->failsAtomically(fn () => $this->postReturn($ret));
    }

    public function test_closed_current_period_rolls_back_supplier_credit_and_payable(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $b = $this->approveBill($this->draftBill($r, '10.0000'));
        $ret = $this->postReturn($this->draftReturn($r, '2.0000'));
        $this->november();
        $credit = $this->draftCredit($b, $ret);
        $service = app(AccountingSupplierCreditService::class);
        $credit = $service->approve($this->reviewer, (int) $this->company->getKey(), (int) $credit->getKey(), (int) $credit->getAttribute('revision'));
        DB::table('accounting_periods')->where('code', 'NOV')->update(['state' => 'hard_closed']);
        $this->failsAtomically(fn () => $service->issue($this->actor, (int) $this->company->getKey(), (int) $credit->getKey(), (int) $credit->getAttribute('revision')));
    }

    public function test_draft_goods_receipt_cancellation_has_no_stock_or_finance(): void
    {
        $r = $this->draftReceipt($this->order());
        $journals = JournalEntry::query()->count();
        $r = app(GoodsReceiptService::class)->cancel($this->actor, (int) $r->getKey(), (int) $r->getAttribute('revision'), 'Unposted receiving cancelled');
        $this->assertSame('cancelled', $r->getAttribute('status'));
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('journal_entries', $journals);
        $this->assertDatabaseCount('accounting_source_effects', 0);
    }

    public function test_draft_supplier_bill_cancellation_does_not_reverse_received_stock(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $b = $this->draftBill($r, '10.0000');
        $trace = $this->originalTrace();
        $b = app(SupplierBillService::class)->cancel($this->actor, (int) $b->getKey(), (int) $b->getAttribute('revision'), 'Unapproved bill cancelled');
        $this->assertSame('cancelled', $b->getAttribute('status'));
        $this->assertOriginalTrace($trace);
        $this->assertDatabaseCount('supplier_payables', 0);
    }

    public function test_approved_unposted_purchase_return_can_be_cancelled_without_finance(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $ret = $this->draftReturn($r, '2.0000');
        $trace = $this->originalTrace();
        $ret = app(PurchaseReturnService::class)->cancel($this->actor, (int) $ret->getKey(), (int) $ret->getAttribute('revision'), 'Approved return not posted');
        $this->assertSame('cancelled', $ret->getAttribute('status'));
        $this->assertOriginalTrace($trace);
        $this->assertDatabaseCount('accounting_source_effects', 1);
    }

    public function test_posted_goods_receipt_cannot_be_cancelled(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $this->failsAtomically(fn () => app(GoodsReceiptService::class)->cancel($this->actor, (int) $r->getKey(), (int) $r->getAttribute('revision'), 'Attempt posted receiving cancellation'));
    }

    public function test_approved_supplier_bill_cannot_be_cancelled(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $b = $this->approveBill($this->draftBill($r, '10.0000'));
        $this->failsAtomically(fn () => app(SupplierBillService::class)->cancel($this->actor, (int) $b->getKey(), (int) $b->getAttribute('revision'), 'Attempt approved bill cancellation'));
    }

    public function test_posted_purchase_return_cannot_be_cancelled(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $ret = $this->postReturn($this->draftReturn($r, '2.0000'));
        $this->failsAtomically(fn () => app(PurchaseReturnService::class)->cancel($this->actor, (int) $ret->getKey(), (int) $ret->getAttribute('revision'), 'Attempt posted return cancellation'));
    }

    public function test_automatic_purchase_journals_cannot_use_manual_reversal(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $this->approveBill($this->draftBill($r, '10.0000'));
        $journals = JournalEntry::query()->where('entry_kind', 'automatic')->get();
        $this->assertNotEmpty($journals->all());
        foreach ($journals as $j) {
            $this->failsAtomically(fn () => app(AccountingJournalReversalService::class)->prepare($this->actor, (int) $this->company->getKey(), (int) $j->getKey(), ['revision' => (int) $j->getAttribute('revision'), 'accounting_date' => '2026-10-16', 'reason' => 'Native source correction is required']));
        }
    }

    public function test_current_return_and_credit_retries_preserve_all_native_and_financial_rows(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $b = $this->approveBill($this->draftBill($r, '10.0000'));
        $this->november();
        $ret = $this->postReturn($this->draftReturn($r, '2.0000'));
        $c = $this->issueCredit($this->draftCredit($b, $ret));
        DB::table('accounting_periods')->update(['state' => 'hard_closed']);
        $this->settings(['state' => 'disabled']);
        $before = $this->stateDigest();
        $this->postReturn($ret);
        app(AccountingSupplierCreditService::class)->issue($this->actor, (int) $this->company->getKey(), (int) $c->getKey(), 1);
        $this->assertSame($before, $this->stateDigest());
    }

    public function test_missing_current_supplier_credit_effect_is_not_repaired_by_retry(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $b = $this->approveBill($this->draftBill($r, '10.0000'));
        $ret = $this->postReturn($this->draftReturn($r, '2.0000'));
        $this->november();
        $c = $this->issueCredit($this->draftCredit($b, $ret));
        DB::table('accounting_source_effects')->where('source_kind', 'supplier_credit')->where('source_id', $c->getKey())->delete();
        $this->failsAtomically(fn () => app(AccountingSupplierCreditService::class)->issue($this->actor, (int) $this->company->getKey(), (int) $c->getKey(), 1));
    }

    public function test_unrecognized_legacy_receipt_cannot_fund_current_return(): void
    {
        $this->settings(['state' => 'disabled']);
        $r = $this->receive($this->draftReceipt($this->order()));
        $this->settings(['state' => 'enabled']);
        $this->november();
        $ret = $this->draftReturn($r, '2.0000');
        $this->failsAtomically(fn () => $this->postReturn($ret));
    }
}
