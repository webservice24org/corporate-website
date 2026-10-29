<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingMappingVersion;
use App\Models\Accounting\AccountingPurchaseEvent;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\AccountingSupplierCredit;
use App\Models\Accounting\AccountingSupplierPaymentOperation;
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
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierBillItem;
use App\Models\SupplierPayment;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ERP\Accounting\AccountingAccountService;
use App\Services\ERP\Accounting\AccountingCalendarService;
use App\Services\ERP\Accounting\AccountingCashAccountService;
use App\Services\ERP\Accounting\AccountingMappingCatalog;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\Source\AccountingSourceReconciliation;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierCreditService;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentService;
use App\Services\ERP\Purchase\GoodsReceiptService;
use App\Services\ERP\Purchase\PurchaseReturnService;
use App\Services\ERP\Purchase\SupplierBillService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\AccountingPurchasePermissionSeeder;
use Database\Seeders\AccountingSupplierPaymentPermissionSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class AccountingSupplierPaymentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private AccountingCashAccount $cash;

    private AccountingCashAccount $bank;

    private AccountingSupplierPaymentService $payments;

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
        $this->seed(AccountingSupplierPaymentPermissionSeeder::class);
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
        app(AccountingCashAccountService::class)->initialize($this->actor, (int) $this->company->getKey(), 'Supplier payment integration fixture');
        $this->cash = AccountingCashAccount::query()->where('company_id', $this->company->getKey())->where('role', 'cash:MAIN')->firstOrFail();
        $this->bank = AccountingCashAccount::query()->where('company_id', $this->company->getKey())->where('role', 'bank:CURRENT')->firstOrFail();
        $this->payments = app(AccountingSupplierPaymentService::class);
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
        foreach ([...$catalog->roles(), 'cash:MAIN' => 'asset', 'bank:CURRENT' => 'asset'] as $role => $category) {
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

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed> */
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

    private function liveBill(): SupplierBill
    {
        return $this->approveBill($this->draftBill($this->receive($this->draftReceipt($this->order())), '10.0000'));
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed> */
    private function paymentData(SupplierBill $bill, string $amount = '95.00', string $allocated = '95.00', array $overrides = []): array
    {
        return ['company_id' => (int) $this->company->getKey(), 'supplier_id' => (int) $bill->getAttribute('supplier_id'), 'document_date' => now('Asia/Dhaka')->toDateString(), 'cash_account_id' => (int) $this->cash->getKey(), 'payment_method' => 'cash', 'amount' => $amount, 'advance_amount' => bcsub($amount, $allocated, 2), 'payment_reference' => 'PRIVATE-PAYMENT-REFERENCE', 'notes' => 'PRIVATE-PAYMENT-NOTES', 'targets' => $allocated === '0.00' ? [] : [['supplier_bill_id' => (int) $bill->getKey(), 'amount' => $allocated]], ...$overrides];
    }

    /** @param array<string, mixed>|null $data */
    private function ready(SupplierBill $bill, ?array $data = null): SupplierPayment
    {
        $p = $this->payments->save($this->actor, $data ?? $this->paymentData($bill));
        $p = $this->payments->submit($this->actor, (int) $p->getKey(), (int) $p->getAttribute('revision'));

        return $this->payments->approve($this->reviewer, (int) $p->getKey(), (int) $p->getAttribute('revision'));
    }

    private function postPayment(SupplierPayment $p, ?string $key = null, ?User $actor = null): SupplierPayment
    {
        return $this->payments->post($actor ?? $this->actor, (int) $p->getKey(), (int) $p->getAttribute('revision'), $key ?? (string) Str::uuid());
    }

    private function paid(SupplierBill $b): string
    {
        return bcadd((string) DB::table('supplier_payables')->where('supplier_bill_id', $b->getKey())->value('paid_amount'), '0', 2);
    }

    private function digestState(): string
    {
        $parts = [];
        foreach (['supplier_payments', 'supplier_payment_targets', 'supplier_payment_allocations', 'supplier_payment_audit_logs', 'accounting_supplier_payment_operations', 'supplier_payables', 'accounting_purchase_events', 'accounting_source_effects', 'journal_entries', 'journal_entry_lines', 'accounting_audit_events'] as $table) {
            $parts[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }

        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    private function failsAtomically(callable $action): void
    {
        $before = $this->digestState();
        $this->invalid($action);
        $this->assertSame($before, $this->digestState());
    }

    public function test_draft_submit_and_independent_approval_do_not_spend_cash(): void
    {
        $b = $this->liveBill();
        $p = $this->ready($b);
        $this->assertSame('approved', $p->getAttribute('status'));
        $this->assertSame($p->getAttribute('submitted_payload_hash'), $p->getAttribute('approved_payload_hash'));
        $this->assertSame('0.00', $this->paid($b));
        $this->assertDatabaseCount('supplier_payment_allocations', 0);
        $this->assertDatabaseCount('journal_entries', 2);
    }

    public function test_fully_allocated_payment_settles_ap_and_records_cash_once(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->assertSame('95.00', $this->paid($b));
        $this->assertSame('95.00', $this->role('trade_ap', 'debit'));
        $this->assertSame('95.00', $this->role('cash:MAIN', 'credit'));
        $this->assertSame('0.00', bcsub($this->role('supplier_advances', 'debit'), $this->role('supplier_advances', 'credit'), 2));
        $this->assertDatabaseCount('journal_entries', 4);
        $this->assertDatabaseCount('accounting_supplier_payment_operations', 1);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_explicit_unapplied_advance_remains_an_asset(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '60.00')));
        $this->assertSame('60.00', $this->paid($b));
        $this->assertSame('40.00', bcsub($this->role('supplier_advances', 'debit'), $this->role('supplier_advances', 'credit'), 2));
        $this->assertSame('100.00', $this->role('cash:MAIN', 'credit'));
    }

    public function test_advance_only_payment_needs_no_bill_finance_and_does_not_change_ap(): void
    {
        $this->settings(['state' => 'disabled']);
        $b = $this->liveBill();
        $this->settings(['state' => 'enabled']);
        $this->postPayment($this->ready($b, $this->paymentData($b, '50.00', '0.00')));
        $this->assertSame('0.00', $this->paid($b));
        $this->assertDatabaseCount('supplier_payment_allocations', 0);
        $this->assertSame('50.00', $this->role('supplier_advances', 'debit'));
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_multiple_partial_payments_can_settle_one_bill(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b, $this->paymentData($b, '40.00', '40.00')));
        $this->postPayment($this->ready($b, $this->paymentData($b, '55.00', '55.00')));
        $this->assertSame('95.00', $this->paid($b));
        $this->assertSame('95.00', $this->role('cash:MAIN', 'credit'));
        $this->assertDatabaseCount('journal_entries', 6);
    }

    public function test_one_payment_can_settle_two_bills_from_the_same_supplier(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $a = $this->approveBill($this->draftBill($r, '5.0000'));
        $b = $this->approveBill($this->draftBill($r, '5.0000'));
        $data = $this->paymentData($a, '95.00', '95.00', ['targets' => [['supplier_bill_id' => (int) $b->getKey(), 'amount' => '47.50'], ['supplier_bill_id' => (int) $a->getKey(), 'amount' => '47.50']]]);
        $this->postPayment($this->ready($a, $data));
        $this->assertSame('47.50', $this->paid($a));
        $this->assertSame('47.50', $this->paid($b));
        $this->assertDatabaseCount('supplier_payment_allocations', 2);
        $this->assertDatabaseCount('journal_entries', 6);
    }

    public function test_bank_payment_uses_selected_concrete_binding(): void
    {
        $b = $this->liveBill();
        $data = $this->paymentData($b, overrides: ['cash_account_id' => (int) $this->bank->getKey(), 'payment_method' => 'bank_transfer']);
        $this->postPayment($this->ready($b, $data));
        $this->assertSame('95.00', $this->role('bank:CURRENT', 'credit'));
        $this->assertSame('0.00', $this->role('cash:MAIN', 'credit'));
    }

    public function test_editing_a_draft_replaces_targets_and_requires_new_review(): void
    {
        $b = $this->liveBill();
        $p = $this->payments->save($this->actor, $this->paymentData($b));
        $p = $this->payments->save($this->actor, $this->paymentData($b, '50.00', '40.00', ['revision' => (int) $p->getAttribute('revision')]), (int) $p->getKey());
        $this->assertSame(2, $p->getAttribute('revision'));
        $this->assertSame('50.00', $p->getAttribute('amount'));
        $this->assertDatabaseCount('supplier_payment_targets', 1);
        $this->assertNull($p->getAttribute('approved_payload_hash'));
    }

    public function test_reject_clears_review_identity_before_resubmission(): void
    {
        $b = $this->liveBill();
        $p = $this->payments->save($this->actor, $this->paymentData($b));
        $p = $this->payments->submit($this->actor, (int) $p->getKey(), 1);
        $p = $this->payments->reject($this->reviewer, (int) $p->getKey(), 2, 'Review payout evidence');
        $this->assertSame('draft', $p->getAttribute('status'));
        $this->assertNull($p->getAttribute('submitted_payload_hash'));
        $this->assertNull($p->getAttribute('approved_payload_hash'));
        $this->assertSame('0.00', $this->paid($b));
    }

    public function test_approved_payment_can_be_cancelled_without_cash(): void
    {
        $b = $this->liveBill();
        $p = $this->ready($b);
        $p = $this->payments->cancel($this->actor, (int) $p->getKey(), (int) $p->getAttribute('revision'), 'Payment not needed');
        $this->assertSame('cancelled', $p->getAttribute('status'));
        $this->assertSame('0.00', $this->paid($b));
        $this->assertDatabaseCount('accounting_supplier_payment_operations', 0);
    }

    public function test_maker_cannot_approve_own_payment(): void
    {
        $b = $this->liveBill();
        $p = $this->payments->save($this->actor, $this->paymentData($b));
        $p = $this->payments->submit($this->actor, (int) $p->getKey(), 1);
        $this->expectException(AuthorizationException::class);
        $this->payments->approve($this->actor, (int) $p->getKey(), 2);
    }

    public function test_last_editor_cannot_approve_even_when_not_original_maker(): void
    {
        $b = $this->liveBill();
        $p = $this->payments->save($this->actor, $this->paymentData($b));
        $p = $this->payments->save($this->reviewer, $this->paymentData($b, overrides: ['revision' => 1]), (int) $p->getKey());
        $p = $this->payments->submit($this->actor, (int) $p->getKey(), 2);
        $this->expectException(AuthorizationException::class);
        $this->payments->approve($this->reviewer, (int) $p->getKey(), 3);
    }

    public function test_source_staff_can_post_without_manual_journal_permission(): void
    {
        $b = $this->liveBill();
        $maker = $this->staff(['erp.supplier-payments.create', 'erp.supplier-payments.submit', 'erp.supplier-payments.post', 'erp.supplier-payments.allocate']);
        $reviewer = $this->staff(['erp.supplier-payments.approve']);
        $p = $this->payments->save($maker, $this->paymentData($b));
        $p = $this->payments->submit($maker, (int) $p->getKey(), 1);
        $p = $this->payments->approve($reviewer, (int) $p->getKey(), 2);
        $this->postPayment($p, actor: $maker);
        $this->assertFalse($maker->can('erp.journal-entries.post'));
        $this->assertSame('95.00', $this->paid($b));
    }

    public function test_initial_allocation_requires_allocate_as_well_as_post_authority(): void
    {
        $b = $this->liveBill();
        $p = $this->ready($b);
        $staff = $this->staff(['erp.supplier-payments.post']);
        $this->expectException(AuthorizationException::class);
        $this->postPayment($p, actor: $staff);
    }

    public function test_posting_an_unapproved_draft_fails_atomically(): void
    {
        $b = $this->liveBill();
        $p = $this->payments->save($this->actor, $this->paymentData($b));
        $this->failsAtomically(fn () => $this->postPayment($p));
    }

    public function test_stale_revision_cannot_post_approved_payment(): void
    {
        $b = $this->liveBill();
        $p = $this->ready($b);
        $this->failsAtomically(fn () => $this->payments->post($this->actor, (int) $p->getKey(), 1, (string) Str::uuid()));
    }

    public function test_approved_intent_cannot_be_edited(): void
    {
        $b = $this->liveBill();
        $p = $this->ready($b);
        $this->failsAtomically(fn () => $this->payments->save($this->actor, $this->paymentData($b, '50.00', '50.00', ['revision' => 3]), (int) $p->getKey()));
    }

    public function test_post_rechecks_private_header_fingerprint(): void
    {
        $b = $this->liveBill();
        $p = $this->ready($b);
        DB::table('supplier_payments')->where('id', $p->getKey())->update(['payment_reference' => 'Changed after approval']);
        $this->failsAtomically(fn () => $this->postPayment($p));
    }

    public function test_post_rechecks_frozen_target_amount(): void
    {
        $b = $this->liveBill();
        $p = $this->ready($b);
        DB::table('supplier_payment_targets')->where('supplier_payment_id', $p->getKey())->update(['amount' => '94.00']);
        $this->failsAtomically(fn () => $this->postPayment($p));
    }

    public function test_same_post_request_replays_the_whole_batch_without_writes(): void
    {
        $b = $this->liveBill();
        $p = $this->ready($b);
        $key = (string) Str::uuid();
        $p = $this->postPayment($p, $key);
        $before = $this->digestState();
        $this->payments->post($this->actor, (int) $p->getKey(), 3, $key);
        $this->assertSame($before, $this->digestState());
    }

    public function test_different_key_does_not_create_second_payment_finance(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->failsAtomically(fn () => $this->postPayment($p));
    }

    public function test_replay_survives_closed_period_disabled_settings_and_inactive_binding(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        DB::table('accounting_periods')->update(['state' => 'hard_closed']);
        $this->settings(['state' => 'disabled', 'cutover_locked_at' => now()]);
        $this->cash->update(['status' => 'inactive']);
        $before = $this->digestState();
        $this->postPayment($p, (string) $p->getAttribute('idempotency_key'));
        $this->assertSame($before, $this->digestState());
    }

    public function test_missing_manifest_is_not_recreated_by_retry(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        DB::table('accounting_supplier_payment_operations')->delete();
        $this->settings(['state' => 'disabled']);
        $this->failsAtomically(fn () => $this->postPayment($p, (string) $p->getAttribute('idempotency_key')));
    }

    public function test_missing_allocation_finance_is_not_repaired_by_retry(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        DB::table('accounting_source_effects')->where('source_kind', 'supplier_payment_allocation')->delete();
        $this->failsAtomically(fn () => $this->postPayment($p, (string) $p->getAttribute('idempotency_key')));
    }

    public function test_missing_cash_admission_is_not_repaired_by_retry(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        DB::table('accounting_purchase_events')->where('source_kind', 'supplier_payment')->delete();
        $this->failsAtomically(fn () => $this->postPayment($p, (string) $p->getAttribute('idempotency_key')));
    }

    public function test_missing_native_batch_member_is_not_recreated(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        DB::table('supplier_payment_allocations')->delete();
        $this->failsAtomically(fn () => $this->postPayment($p, (string) $p->getAttribute('idempotency_key')));
    }

    public function test_corrupt_manifest_hash_blocks_retry(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        DB::table('accounting_supplier_payment_operations')->update(['payload_hash' => str_repeat('0', 64)]);
        $this->failsAtomically(fn () => $this->postPayment($p, (string) $p->getAttribute('idempotency_key')));
    }

    public function test_disabled_accounting_cannot_bypass_new_financial_payment(): void
    {
        $b = $this->liveBill();
        $p = $this->ready($b);
        $this->settings(['state' => 'disabled']);
        $this->failsAtomically(fn () => $this->postPayment($p));
        $this->assertSame('approved', $p->refresh()->getAttribute('status'));
    }

    public function test_cutover_lock_blocks_new_payment(): void
    {
        $b = $this->liveBill();
        $p = $this->ready($b);
        $this->settings(['cutover_locked_at' => now()]);
        $this->failsAtomically(fn () => $this->postPayment($p));
    }

    public function test_closed_cash_period_rolls_back_native_payment_and_paid_summary(): void
    {
        $b = $this->liveBill();
        $p = $this->ready($b);
        DB::table('accounting_periods')->update(['state' => 'hard_closed']);
        $this->failsAtomically(fn () => $this->postPayment($p));
        $this->assertSame('0.00', $this->paid($b));
    }

    public function test_allocation_failure_rolls_back_already_recorded_cash_and_native_batch(): void
    {
        $b = $this->liveBill();
        $p = $this->ready($b);
        $before = $this->digestState();
        $enabled = true;
        $observedCash = false;
        Event::listen('eloquent.creating: '.JournalEntry::class, function ($journal) use ($p, &$enabled, &$observedCash): void {
            if (! $enabled || ! str_starts_with((string) $journal->getAttribute('description'), 'supplier_payment_allocation ')) {
                return;
            }
            $observedCash = AccountingSourceEffect::query()->where('source_kind', 'supplier_payment')->where('source_id', $p->getKey())->exists();
            throw new \RuntimeException('Injected initial-allocation finance failure');
        });
        try {
            $this->postPayment($p);
            $this->fail('Expected allocation fault.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected initial-allocation finance failure', $e->getMessage());
        } finally {
            $enabled = false;
        }
        $this->assertTrue($observedCash);
        $this->assertSame($before, $this->digestState());
        $this->assertSame('0.00', $this->paid($b));
        $this->assertDatabaseCount('journal_entries', 2);
    }

    public function test_legacy_bill_without_original_finance_cannot_be_paid_live(): void
    {
        $this->settings(['state' => 'disabled']);
        $b = $this->liveBill();
        $p = $this->ready($b);
        $this->settings(['state' => 'enabled']);
        $this->failsAtomically(fn () => $this->postPayment($p));
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_tampered_paid_summary_blocks_new_money(): void
    {
        $b = $this->liveBill();
        $p = $this->ready($b);
        DB::table('supplier_payables')->where('supplier_bill_id', $b->getKey())->update(['paid_amount' => '1.00']);
        $this->failsAtomically(fn () => $this->postPayment($p));
    }

    public function test_damaged_previous_payment_blocks_another_partial_payment(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b, $this->paymentData($b, '20.00', '20.00')));
        $p = $this->ready($b, $this->paymentData($b, '20.00', '20.00'));
        DB::table('accounting_source_effects')->where('source_kind', 'supplier_payment_allocation')->delete();
        $this->failsAtomically(fn () => $this->postPayment($p));
    }

    public function test_inactive_binding_cannot_fund_new_payment(): void
    {
        $b = $this->liveBill();
        $p = $this->ready($b);
        $this->cash->update(['status' => 'inactive']);
        $this->failsAtomically(fn () => $this->postPayment($p));
    }

    public function test_inactive_supplier_cannot_receive_new_advance(): void
    {
        $b = $this->liveBill();
        Supplier::query()->whereKey($b->getAttribute('supplier_id'))->update(['status' => 'inactive']);
        $this->failsAtomically(fn () => $this->payments->save($this->actor, $this->paymentData($b, '100.00', '50.00')));
    }

    public function test_inactive_supplier_can_settle_existing_recognized_bill_without_advance(): void
    {
        $b = $this->liveBill();
        $p = $this->ready($b);
        Supplier::query()->whereKey($b->getAttribute('supplier_id'))->update(['status' => 'inactive']);
        $this->postPayment($p);
        $this->assertSame('95.00', $this->paid($b));
    }

    public function test_foreign_company_actor_cannot_read_or_post_payment(): void
    {
        $b = $this->liveBill();
        $p = $this->ready($b);
        $foreign = $this->newCompany('PAY-FOREIGN');
        $staff = User::factory()->create();
        Employee::query()->create(['company_id' => $foreign->getKey(), 'user_id' => $staff->getKey(), 'employee_number' => 'FOREIGN-PAY', 'first_name' => 'Other', 'last_name' => 'User']);
        $staff->givePermissionTo('erp.supplier-payments.post');
        $this->expectException(ModelNotFoundException::class);
        $this->postPayment($p, actor: $staff);
    }

    public function test_another_supplier_bill_cannot_be_injected(): void
    {
        $a = $this->liveBill();
        $b = $this->liveBill();
        $this->expectException(ModelNotFoundException::class);
        $this->payments->save($this->actor, $this->paymentData($a, overrides: ['targets' => [['supplier_bill_id' => (int) $b->getKey(), 'amount' => '95.00']]]));
    }

    public function test_posted_payment_cannot_be_cancelled(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->failsAtomically(fn () => $this->payments->cancel($this->actor, (int) $p->getKey(), (int) $p->getAttribute('revision'), 'Attempt direct cancellation'));
    }

    public function test_private_reference_notes_and_snapshots_are_absent_from_financial_admissions(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b));
        $json = json_encode(AccountingPurchaseEvent::query()->whereIn('source_kind', ['supplier_payment', 'supplier_payment_allocation'])->get()->toArray(), JSON_THROW_ON_ERROR);
        foreach (['PRIVATE-PAYMENT-REFERENCE', 'PRIVATE-PAYMENT-NOTES', 'supplier_snapshot', 'payment_reference', 'issuer_snapshot', 'idempotency_key'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
    }

    public function test_reconciliation_workspace_verifies_cash_and_initial_allocation_batches(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b));
        $page = app(AccountingSourceReconciliation::class)->page($this->actor, (int) $this->company->getKey(), ['kind' => 'supplier_payment']);
        $this->assertSame('verified_live', $page['rows'][0]['status']);
        $page = app(AccountingSourceReconciliation::class)->page($this->actor, (int) $this->company->getKey(), ['kind' => 'supplier_payment_allocation']);
        $this->assertSame('verified_live', $page['rows'][0]['status']);
    }

    public function test_supplier_credit_cannot_use_a_corrupted_paid_summary_after_payment(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $b = $this->approveBill($this->draftBill($r, '10.0000'));
        $this->postPayment($this->ready($b, $this->paymentData($b, '20.00', '20.00')));
        $ret = $this->postReturn($this->draftReturn($r, '1.0000'));
        DB::table('supplier_payables')->where('supplier_bill_id', $b->getKey())->update(['paid_amount' => '0.00']);
        $this->invalid(fn () => $this->draftCredit($b, $ret), 'supplier_credit');
        $this->assertDatabaseCount('accounting_supplier_credits', 0);
    }

    public function test_supplier_credit_and_later_new_payment_reconcile_ap_without_double_cash(): void
    {
        $r = $this->receive($this->draftReceipt($this->order()));
        $b = $this->approveBill($this->draftBill($r, '10.0000'));
        $this->postPayment($this->ready($b, $this->paymentData($b, '20.00', '20.00')));
        $ret = $this->postReturn($this->draftReturn($r, '1.0000'));
        $this->issueCredit($this->draftCredit($b, $ret));
        $this->postPayment($this->ready($b, $this->paymentData($b, '65.50', '65.50')));
        $this->assertSame('85.50', $this->paid($b));
        $this->assertSame('85.50', $this->role('cash:MAIN', 'credit'));
        $this->assertSame('0.00', bcsub($this->role('trade_ap', 'credit'), $this->role('trade_ap', 'debit'), 2));
    }

    public function test_competing_approved_payments_recheck_ap_before_spending_cash(): void
    {
        $b = $this->liveBill();
        $first = $this->ready($b);
        $second = $this->ready($b);
        $this->postPayment($first);
        $this->failsAtomically(fn () => $this->postPayment($second));
        $this->assertSame('95.00', $this->paid($b));
        $this->assertSame('95.00', $this->role('cash:MAIN', 'credit'));
    }

    public function test_posting_key_cannot_be_reused_for_another_payment(): void
    {
        $b = $this->liveBill();
        $first = $this->ready($b, $this->paymentData($b, '40.00', '40.00'));
        $second = $this->ready($b, $this->paymentData($b, '55.00', '55.00'));
        $key = (string) Str::uuid();
        $this->postPayment($first, $key);
        $this->failsAtomically(fn () => $this->postPayment($second, $key));
    }

    public function test_approval_seals_cash_binding_identity_not_only_its_id(): void
    {
        $b = $this->liveBill();
        $p = $this->ready($b);
        DB::table('accounting_cash_accounts')->where('id', $this->cash->getKey())->update(['role' => 'cash:TAMPERED']);
        $this->failsAtomically(fn () => $this->postPayment($p));
    }

    public function test_json_object_key_reordering_preserves_operation_replay(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $record = AccountingSupplierPaymentOperation::query()->firstOrFail();
        $members = [];
        foreach ($record->getAttribute('members') as $member) {
            $members[] = array_reverse($member, true);
        } DB::table('accounting_supplier_payment_operations')->where('id', $record->getKey())->update(['members' => json_encode($members, JSON_THROW_ON_ERROR)]);
        $before = $this->digestState();
        $this->postPayment($p, (string) $p->getAttribute('idempotency_key'));
        $this->assertSame($before, $this->digestState());
    }

    public function test_new_current_period_payment_can_settle_old_closed_period_ap(): void
    {
        $b = $this->liveBill();
        $year = FiscalYear::query()->where('company_id', $this->company->getKey())->firstOrFail();
        app(AccountingCalendarService::class)->savePeriod($this->actor, (int) $year->getKey(), ['company_id' => $this->company->getKey(), 'code' => 'NOV', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'reason' => 'Current AP settlement']);
        DB::table('accounting_periods')->where('code', 'OCT')->update(['state' => 'hard_closed']);
        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00', 'Asia/Dhaka'));
        $this->postPayment($this->ready($b));
        $this->assertSame('95.00', $this->paid($b));
        foreach (AccountingSourceEffect::query()->whereIn('source_kind', ['supplier_payment', 'supplier_payment_allocation'])->get() as $effect) {
            $this->assertSame('2026-11-03', $effect->getAttribute('accounting_date')->format('Y-m-d'));
        }
    }

    public function test_remapped_ap_control_cannot_silently_settle_old_ap(): void
    {
        $b = $this->liveBill();
        $p = $this->ready($b);
        $old = Account::query()->findOrFail($this->mapping['trade_ap']);
        $replacement = Account::query()->create(['company_id' => $this->company->getKey(), 'account_group_id' => $old->getAttribute('account_group_id'), 'code' => '9999', 'name' => 'Replacement AP', 'normal_side' => 'credit', 'is_postable' => true, 'control_role' => 'trade_ap', 'statement_role' => 'current_liability', 'status' => 'active', 'revision' => 1]);
        $map = $this->mapping;
        $map['trade_ap'] = (int) $replacement->getKey();
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $base = AccountingMappingVersion::query()->where('company_id', $this->company->getKey())->where('state', 'published')->firstOrFail();
        $this->publish(['base_version_id' => (int) $base->getKey(), 'effective_from' => '2026-10-17', 'mappings' => $map]);
        $before = $this->digestState();
        try {
            $this->postPayment($p);
            $this->fail('Expected the original AP control mismatch.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Recognized supplier control was remapped', implode(' ', $e->errors()['supplier_payment'] ?? []));
        }
        $this->assertSame($before, $this->digestState());
        $this->assertSame('0.00', $this->paid($b));
    }
}
