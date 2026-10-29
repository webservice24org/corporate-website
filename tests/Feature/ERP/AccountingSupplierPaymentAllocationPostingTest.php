<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingMappingVersion;
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
use App\Models\SupplierPaymentAllocation;
use App\Models\SupplierPaymentTarget;
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
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentChangeService;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentPosition;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentPosting;
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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class AccountingSupplierPaymentAllocationPostingTest extends TestCase
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

    /** @return array<string, mixed> */
    private function position(SupplierPayment $payment): array
    {
        return app(AccountingSupplierPaymentPosition::class)->read($this->actor, (int) $payment->getKey());
    }

    /** @param list<array{supplier_bill_id: int, amount: mixed}> $targets
     * @param list<int> $compensate
     * @return array<string, mixed> */
    private function requestFor(SupplierPayment $payment, array $targets = [], array $compensate = []): array
    {
        $position = $this->position($payment);

        return ['revision' => $position['root']['revision'], 'history_hash' => $position['history_hash'], 'key' => (string) Str::uuid(), 'reason' => 'PRIVATE-ALLOCATION-CORRECTION', 'compensate_ids' => $compensate, 'targets' => $targets];
    }

    /** @param array<string, mixed> $request */
    private function change(SupplierPayment $payment, array $request, ?User $actor = null): SupplierPayment
    {
        return app(AccountingSupplierPaymentChangeService::class)->change($actor ?? $this->actor, (int) $payment->getKey(), $request);
    }

    /** @return array{supplier_bill_id: int, amount: string} */
    private function target(SupplierBill $bill, string $amount): array
    {
        return ['supplier_bill_id' => (int) $bill->getKey(), 'amount' => $amount];
    }

    private function anotherBill(SupplierBill $first): SupplierBill
    {
        $fixture = $this->order();
        $fixture[2]->update(['supplier_id' => $first->getAttribute('supplier_id')]);

        return $this->approveBill($this->draftBill($this->receive($this->draftReceipt($fixture)), '10.0000'));
    }

    private function rejectAtomically(callable $action): void
    {
        $before = $this->digestState();
        try {
            $action();
            $this->fail('Expected rejected supplier allocation.');
        } catch (ValidationException|ModelNotFoundException|AuthorizationException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        } $this->assertSame($before, $this->digestState());
    }

    public function test_later_allocation_settles_ap_without_second_cash_or_stock_effect(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $this->change($p, $this->requestFor($p, [$this->target($b, '40.00')]));
        $this->assertSame('40.00', $this->paid($b));
        $this->assertSame('100.00', $this->role('cash:MAIN', 'credit'));
        $this->assertSame('60.00', $this->position($p)['available']);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('journal_entries', 4);
    }

    public function test_later_multi_bill_allocation_is_one_operation_without_cash(): void
    {
        $a = $this->liveBill();
        $b = $this->anotherBill($a);
        $p = $this->postPayment($this->ready($a, $this->paymentData($a, '100.00', '0.00')));
        $this->change($p, $this->requestFor($p, [$this->target($b, '50.00'), $this->target($a, '40.00')]));
        $this->assertSame('40.00', $this->paid($a));
        $this->assertSame('50.00', $this->paid($b));
        $this->assertSame('10.00', $this->position($p)['available']);
        $this->assertDatabaseCount('accounting_supplier_payment_operations', 2);
    }

    public function test_compensating_initial_allocation_restores_ap_and_advance(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $id = $this->position($p)['active'][0]['id'];
        $this->change($p, $this->requestFor($p, [], [$id]));
        $this->assertSame('0.00', $this->paid($b));
        $this->assertSame('95.00', $this->position($p)['available']);
        $this->assertSame('95.00', bcsub($this->role('trade_ap', 'credit'), $this->role('trade_ap', 'debit'), 2));
        $this->assertSame('95.00', bcsub($this->role('supplier_advances', 'debit'), $this->role('supplier_advances', 'credit'), 2));
        $this->assertSame('95.00', $this->role('cash:MAIN', 'credit'));
        $this->assertDatabaseHas('accounting_source_effects', ['source_kind' => 'supplier_payment_allocation', 'event_kind' => 'reversed', 'outcome' => 'posted']);
    }

    public function test_compensating_later_allocation_uses_its_exact_original(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $this->change($p, $this->requestFor($p, [$this->target($b, '30.00')]));
        $id = $this->position($p)['active'][0]['id'];
        $this->change($p, $this->requestFor($p, [], [$id]));
        $this->assertSame('100.00', $this->position($p)['available']);
        $this->assertSame('0.00', $this->paid($b));
    }

    public function test_reallocation_restores_old_bill_and_settles_new_bill_atomically(): void
    {
        $a = $this->liveBill();
        $b = $this->anotherBill($a);
        $p = $this->postPayment($this->ready($a));
        $id = $this->position($p)['active'][0]['id'];
        $this->change($p, $this->requestFor($p, [$this->target($b, '95.00')], [$id]));
        $this->assertSame('0.00', $this->paid($a));
        $this->assertSame('95.00', $this->paid($b));
        $this->assertSame('0.00', $this->position($p)['available']);
        $this->assertSame('95.00', $this->role('cash:MAIN', 'credit'));
    }

    public function test_compensate_and_reallocate_part_of_same_bill_leaves_explicit_funds(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $id = $this->position($p)['active'][0]['id'];
        $this->change($p, $this->requestFor($p, [$this->target($b, '40.00')], [$id]));
        $this->assertSame('40.00', $this->paid($b));
        $this->assertSame('55.00', $this->position($p)['available']);
    }

    public function test_change_leaves_original_header_approval_and_initial_targets_immutable(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $header = $p->fresh()->getAttributes();
        $targets = SupplierPaymentTarget::query()->get()->toArray();
        $id = $this->position($p)['active'][0]['id'];
        $this->change($p, $this->requestFor($p, [], [$id]));
        $this->assertSame($header, $p->fresh()->getAttributes());
        $this->assertSame($targets, SupplierPaymentTarget::query()->get()->toArray());
    }

    public function test_allocate_authority_does_not_require_payment_or_manual_journal_post_permission(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $user = $this->staff(['erp.supplier-payments.allocate']);
        $this->assertFalse($user->can('erp.supplier-payments.post'));
        $this->assertFalse($user->can('erp.journal-entries.post'));
        $this->change($p, $this->requestFor($p, [$this->target($b, '20.00')]), $user);
        $this->assertSame('20.00', $this->paid($b));
    }

    public function test_view_only_actor_cannot_allocate(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $request = $this->requestFor($p, [], [$this->position($p)['active'][0]['id']]);
        $this->rejectAtomically(fn () => $this->change($p, $request, $this->staff(['erp.supplier-payments.view'])));
    }

    public function test_foreign_company_actor_cannot_allocate(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $request = $this->requestFor($p, [], [$this->position($p)['active'][0]['id']]);
        $user = $this->staff(['erp.supplier-payments.allocate']);
        $other = $this->newCompany('CHANGE-FOREIGN');
        DB::table('employees')->where('user_id', $user->getKey())->update(['company_id' => $other->getKey()]);
        $this->rejectAtomically(fn () => $this->change($p, $request, $user));
    }

    public function test_insufficient_original_funds_are_rejected(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '20.00', '0.00')));
        $request = $this->requestFor($p, [$this->target($b, '20.01')]);
        $this->rejectAtomically(fn () => $this->change($p, $request));
    }

    public function test_allocation_cannot_overpay_verified_bill(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '200.00', '0.00')));
        $request = $this->requestFor($p, [$this->target($b, '95.01')]);
        $this->rejectAtomically(fn () => $this->change($p, $request));
    }

    public function test_another_supplier_bill_cannot_be_settled(): void
    {
        $a = $this->liveBill();
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($a, $this->paymentData($a, '100.00', '0.00')));
        $request = $this->requestFor($p, [$this->target($b, '10.00')]);
        $this->rejectAtomically(fn () => $this->change($p, $request));
    }

    public function test_bad_money_reason_and_unsupported_input_are_rejected(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $base = $this->requestFor($p, [$this->target($b, '10.00')]);
        foreach ([['reason' => ' '], ['targets' => [['supplier_bill_id' => (int) $b->getKey(), 'amount' => 10.0]]], ['cash_account_id' => 999]] as $extra) {
            $this->rejectAtomically(fn () => $this->change($p, [...$base, ...$extra]));
        }
    }

    public function test_new_request_requires_current_history_and_header_revision(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $a = $this->requestFor($p, [$this->target($b, '20.00')]);
        $stale = [...$a, 'key' => (string) Str::uuid()];
        $this->change($p, $a);
        $this->rejectAtomically(fn () => $this->change($p, $stale));
        $bad = [...$this->requestFor($p, [$this->target($b, '10.00')]), 'revision' => 1];
        $this->rejectAtomically(fn () => $this->change($p, $bad));
    }

    public function test_same_key_and_request_replay_whole_operation_without_new_writes(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $request = $this->requestFor($p, [$this->target($b, '40.00')]);
        $this->change($p, $request);
        $before = $this->digestState();
        $this->change($p, $request);
        $this->assertSame($before, $this->digestState());
    }

    public function test_same_key_changed_intent_cannot_replay(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $request = $this->requestFor($p, [$this->target($b, '40.00')]);
        $this->change($p, $request);
        $this->rejectAtomically(fn () => $this->change($p, [...$request, 'reason' => 'Different intent']));
    }

    public function test_compensation_retry_replays_without_second_ap_restore(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $request = $this->requestFor($p, [], [$this->position($p)['active'][0]['id']]);
        $this->change($p, $request);
        $before = $this->digestState();
        $this->change($p, $request);
        $this->assertSame($before, $this->digestState());
        $this->assertSame('0.00', $this->paid($b));
    }

    public function test_old_change_can_replay_after_new_allocations_and_compensation(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $first = $this->requestFor($p, [$this->target($b, '30.00')]);
        $this->change($p, $first);
        $this->change($p, $this->requestFor($p, [], [$this->position($p)['active'][0]['id']]));
        $this->change($p, $this->requestFor($p, [$this->target($b, '20.00')]));
        $before = $this->digestState();
        $this->change($p, $first);
        $this->assertSame($before, $this->digestState());
        $this->assertSame('20.00', $this->paid($b));
    }

    public function test_original_cash_post_can_replay_after_later_compensation(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->change($p, $this->requestFor($p, [], [$this->position($p)['active'][0]['id']]));
        $before = $this->digestState();
        $this->postPayment($p, (string) $p->getAttribute('idempotency_key'));
        $this->assertSame($before, $this->digestState());
    }

    public function test_disabled_settings_block_new_changes_without_bypass(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $request = $this->requestFor($p, [], [$this->position($p)['active'][0]['id']]);
        $this->settings(['state' => 'disabled']);
        $this->rejectAtomically(fn () => $this->change($p, $request));
    }

    public function test_cutover_lock_blocks_new_changes(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $request = $this->requestFor($p, [], [$this->position($p)['active'][0]['id']]);
        $this->settings(['cutover_locked_at' => now()]);
        $this->rejectAtomically(fn () => $this->change($p, $request));
    }

    public function test_closed_period_rolls_back_new_compensation(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $request = $this->requestFor($p, [], [$this->position($p)['active'][0]['id']]);
        DB::table('accounting_periods')->update(['state' => 'hard_closed']);
        $this->rejectAtomically(fn () => $this->change($p, $request));
    }

    public function test_replay_precedes_closed_period_disabled_settings_and_inactive_binding_gates(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $request = $this->requestFor($p, [], [$this->position($p)['active'][0]['id']]);
        $this->change($p, $request);
        DB::table('accounting_periods')->update(['state' => 'hard_closed']);
        $this->settings(['state' => 'disabled', 'cutover_locked_at' => now()]);
        $this->cash->update(['status' => 'inactive']);
        $before = $this->digestState();
        $this->change($p, $request);
        $this->assertSame($before, $this->digestState());
    }

    public function test_inactive_original_cash_binding_does_not_block_allocation_of_existing_funds(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $request = $this->requestFor($p, [$this->target($b, '20.00')]);
        $this->cash->update(['status' => 'inactive']);
        $this->change($p, $request);
        $this->assertSame('20.00', $this->paid($b));
    }

    public function test_inactive_supplier_can_settle_existing_recognized_debt(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $request = $this->requestFor($p, [$this->target($b, '20.00')]);
        Supplier::query()->whereKey($b->getAttribute('supplier_id'))->update(['status' => 'inactive']);
        $this->change($p, $request);
        $this->assertSame('20.00', $this->paid($b));
    }

    public function test_missing_operation_manifest_is_not_recreated_on_retry(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $request = $this->requestFor($p, [], [$this->position($p)['active'][0]['id']]);
        $this->change($p, $request);
        DB::table('accounting_supplier_payment_operations')->where('kind', 'change')->delete();
        $this->rejectAtomically(fn () => $this->change($p, $request));
    }

    public function test_corrupt_operation_manifest_blocks_retry(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $request = $this->requestFor($p, [], [$this->position($p)['active'][0]['id']]);
        $this->change($p, $request);
        DB::table('accounting_supplier_payment_operations')->where('kind', 'change')->update(['payload_hash' => str_repeat('0', 64)]);
        $this->rejectAtomically(fn () => $this->change($p, $request));
    }

    public function test_missing_compensation_effect_blocks_retry_without_repair(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $request = $this->requestFor($p, [], [$this->position($p)['active'][0]['id']]);
        $this->change($p, $request);
        DB::table('accounting_source_effects')->where('source_kind', 'supplier_payment_allocation')->where('event_kind', 'reversed')->delete();
        $this->rejectAtomically(fn () => $this->change($p, $request));
    }

    public function test_missing_native_member_blocks_retry_without_repair(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $request = $this->requestFor($p, [$this->target($b, '20.00')]);
        $this->change($p, $request);
        DB::table('supplier_payment_allocations')->delete();
        $this->rejectAtomically(fn () => $this->change($p, $request));
    }

    public function test_deleted_manifest_and_native_rows_leave_detectable_orphan_finance(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $request = $this->requestFor($p, [$this->target($b, '20.00')]);
        $this->change($p, $request);
        DB::table('accounting_supplier_payment_operations')->where('kind', 'change')->delete();
        DB::table('supplier_payment_allocations')->delete();
        $this->rejectAtomically(fn () => $this->change($p, $request));
    }

    public function test_corrupt_paid_summary_blocks_new_change_and_retry_without_rewriting(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $request = $this->requestFor($p, [$this->target($b, '20.00')]);
        $this->change($p, $request);
        DB::table('supplier_payables')->where('supplier_bill_id', $b->getKey())->update(['paid_amount' => '1.00']);
        $this->rejectAtomically(fn () => $this->change($p, $request));
        $next = $this->requestFor($p, [$this->target($b, '10.00')]);
        $this->rejectAtomically(fn () => $this->change($p, $next));
    }

    public function test_new_key_cannot_compensate_an_already_compensated_original(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $id = $this->position($p)['active'][0]['id'];
        $this->change($p, $this->requestFor($p, [], [$id]));
        $request = $this->requestFor($p, [], [$id]);
        $this->rejectAtomically(fn () => $this->change($p, $request));
    }

    public function test_compensation_of_compensation_is_rejected(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->change($p, $this->requestFor($p, [], [$this->position($p)['active'][0]['id']]));
        $compensation = SupplierPaymentAllocation::query()->whereNotNull('reverses_allocation_id')->firstOrFail();
        $request = $this->requestFor($p, [], [(int) $compensation->getKey()]);
        $this->rejectAtomically(fn () => $this->change($p, $request));
    }

    public function test_second_payment_rechecks_ap_after_first_later_allocation(): void
    {
        $b = $this->liveBill();
        $a = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $c = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $request = $this->requestFor($c, [$this->target($b, '50.00')]);
        $this->change($a, $this->requestFor($a, [$this->target($b, '60.00')]));
        $this->rejectAtomically(fn () => $this->change($c, $request));
    }

    public function test_private_reason_and_original_payment_reference_do_not_enter_financial_payloads(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->change($p, $this->requestFor($p, [], [$this->position($p)['active'][0]['id']]));
        $text = json_encode(DB::table('accounting_purchase_events')->get(), JSON_THROW_ON_ERROR).json_encode(DB::table('accounting_source_effects')->get(), JSON_THROW_ON_ERROR);
        foreach (['PRIVATE-ALLOCATION-CORRECTION', 'PRIVATE-PAYMENT-REFERENCE', 'PRIVATE-PAYMENT-NOTES'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
    }

    public function test_reallocation_failure_after_compensation_rolls_back_every_financial_and_native_member(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $request = $this->requestFor($p, [$this->target($b, '40.00')], [$this->position($p)['active'][0]['id']]);
        $before = $this->digestState();
        $enabled = true;
        $sawCompensation = false;
        JournalEntry::creating(function (JournalEntry $journal) use (&$enabled, &$sawCompensation): void {
            if ($enabled && str_contains((string) $journal->getAttribute('description'), 'supplier_payment_allocation') && str_contains((string) $journal->getAttribute('description'), ' created ')) {
                $sawCompensation = AccountingSourceEffect::query()->where('source_kind', 'supplier_payment_allocation')->where('event_kind', 'reversed')->exists();
                throw new \RuntimeException('Injected new allocation finance failure');
            }
        });
        try {
            $this->change($p, $request);
            $this->fail('Expected injected failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected new allocation finance failure', $exception->getMessage());
        } finally {
            $enabled = false;
        }
        $this->assertTrue($sawCompensation);
        $this->assertSame($before, $this->digestState());
        $this->assertSame('95.00', $this->paid($b));
    }

    public function test_current_open_period_compensation_can_restore_old_closed_period_ap(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $year = FiscalYear::query()->where('company_id', $this->company->getKey())->firstOrFail();
        app(AccountingCalendarService::class)->savePeriod($this->actor, (int) $year->getKey(), ['company_id' => $this->company->getKey(), 'code' => 'NOV', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'reason' => 'Current supplier settlement period']);
        DB::table('accounting_periods')->where('code', 'OCT')->update(['state' => 'hard_closed']);
        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00', 'Asia/Dhaka'));
        $this->change($p, $this->requestFor($p, [], [$this->position($p)['active'][0]['id']]));
        $effect = AccountingSourceEffect::query()->where('source_kind', 'supplier_payment_allocation')->where('event_kind', 'reversed')->firstOrFail();
        $this->assertSame('2026-11-03', $effect->getAttribute('accounting_date')->format('Y-m-d'));
        $this->assertSame('0.00', $this->paid($b));
    }

    public function test_compensation_opens_ap_for_supplier_credit_without_corrupting_paid_history(): void
    {
        $fixture = $this->order();
        $receipt = $this->receive($this->draftReceipt($fixture));
        $b = $this->approveBill($this->draftBill($receipt, '10.0000'));
        $p = $this->postPayment($this->ready($b));
        $this->change($p, $this->requestFor($p, [], [$this->position($p)['active'][0]['id']]));
        $return = $this->postReturn($this->draftReturn($receipt, '1.0000'));
        $credit = $this->issueCredit($this->draftCredit($b, $return));
        $this->assertSame('0.00', $this->paid($b));
        $this->assertSame((string) $credit->getAttribute('amount'), bcadd((string) DB::table('supplier_payables')->where('supplier_bill_id', $b->getKey())->value('credited_amount'), '0', 2));
        $this->assertSame('95.00', $this->position($p)['available']);
    }

    public function test_credit_reduces_available_ap_for_later_allocation(): void
    {
        $receipt = $this->receive($this->draftReceipt($this->order()));
        $b = $this->approveBill($this->draftBill($receipt, '10.0000'));
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $return = $this->postReturn($this->draftReturn($receipt, '1.0000'));
        $this->issueCredit($this->draftCredit($b, $return));
        $request = $this->requestFor($p, [$this->target($b, '95.00')]);
        $this->rejectAtomically(fn () => $this->change($p, $request));
    }

    public function test_missing_original_bill_finance_cannot_be_settled_by_live_advance(): void
    {
        $this->settings(['state' => 'disabled']);
        $b = $this->liveBill();
        $this->settings(['state' => 'enabled']);
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $request = $this->requestFor($p, [$this->target($b, '20.00')]);
        $this->rejectAtomically(fn () => $this->change($p, $request));
    }

    private function remapControl(string $role): void
    {
        $old = Account::query()->findOrFail($this->mapping[$role]);
        $replacement = Account::query()->create(['company_id' => $this->company->getKey(), 'account_group_id' => $old->getAttribute('account_group_id'), 'code' => '9988', 'name' => 'Replacement '.$role, 'normal_side' => $old->getAttribute('normal_side'), 'is_postable' => true, 'control_role' => $role, 'statement_role' => $old->getAttribute('statement_role'), 'status' => 'active', 'revision' => 1]);
        $map = $this->mapping;
        $map[$role] = (int) $replacement->getKey();
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $base = AccountingMappingVersion::query()->where('company_id', $this->company->getKey())->where('state', 'published')->firstOrFail();
        $this->publish(['base_version_id' => (int) $base->getKey(), 'effective_from' => '2026-10-17', 'mappings' => $map]);
    }

    public function test_remapped_ap_cannot_reinterpret_compensation(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $request = $this->requestFor($p, [], [$this->position($p)['active'][0]['id']]);
        $this->remapControl('trade_ap');
        $before = $this->digestState();
        try {
            $this->change($p, $request);
            $this->fail('Expected remapped AP guard.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Recognized supplier control was remapped', implode(' ', $exception->errors()['supplier_payment'] ?? []));
        } $this->assertSame($before, $this->digestState());
    }

    public function test_remapped_advance_control_cannot_reinterpret_existing_funds(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $request = $this->requestFor($p, [$this->target($b, '20.00')]);
        $this->remapControl('supplier_advances');
        $this->rejectAtomically(fn () => $this->change($p, $request));
    }

    public function test_historical_change_retry_survives_later_profile_remap(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $request = $this->requestFor($p, [], [$this->position($p)['active'][0]['id']]);
        $this->change($p, $request);
        $this->remapControl('trade_ap');
        $before = $this->digestState();
        $this->change($p, $request);
        $this->assertSame($before, $this->digestState());
    }

    public function test_reordered_json_object_keys_preserve_complete_native_manifest_verification(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $request = $this->requestFor($p, [], [$this->position($p)['active'][0]['id']]);
        $this->change($p, $request);
        $op = AccountingSupplierPaymentOperation::query()->where('kind', 'change')->firstOrFail();
        $members = array_map(static fn (array $row): array => array_reverse($row, true), $op->getAttribute('members'));
        DB::table('accounting_supplier_payment_operations')->where('id', $op->getKey())->update(['members' => json_encode($members, JSON_THROW_ON_ERROR)]);
        $before = $this->digestState();
        $this->change($p, $request);
        $this->assertSame($before, $this->digestState());
    }

    public function test_change_operation_history_is_append_only(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->change($p, $this->requestFor($p, [], [$this->position($p)['active'][0]['id']]));
        $op = AccountingSupplierPaymentOperation::query()->where('kind', 'change')->firstOrFail();
        $this->expectException(\LogicException::class);
        $op->update(['request_hash' => str_repeat('0', 64)]);
    }

    public function test_one_company_cannot_reuse_a_change_key_for_another_payment(): void
    {
        $b = $this->liveBill();
        $a = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $first = $this->requestFor($a, [$this->target($b, '20.00')]);
        $this->change($a, $first);
        $next = [...$this->requestFor($p, [$this->target($b, '20.00')]), 'key' => $first['key']];
        $this->rejectAtomically(fn () => $this->change($p, $next));
    }

    public function test_source_reconciliation_verifies_later_and_compensating_finance(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->change($p, $this->requestFor($p, [$this->target($b, '40.00')], [$this->position($p)['active'][0]['id']]));
        DB::transaction(function () use ($p): void {
            app(AccountingSupplierPaymentPosting::class)->verify((int) $this->company->getKey(), (int) $p->getKey());
        });
        $report = app(AccountingSourceReconciliation::class)->page($this->actor, (int) $this->company->getKey(), ['kind' => 'supplier_payment_allocation']);
        $this->assertCount(3, $report['rows']);
        foreach ($report['rows'] as $row) {
            if (in_array($row['source_kind'], ['supplier_payment', 'supplier_payment_allocation'], true)) {
                $this->assertSame('verified_live', $row['status']);
            }
        }
    }

    public function test_missing_later_admission_is_not_recreated_on_retry(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $request = $this->requestFor($p, [], [$this->position($p)['active'][0]['id']]);
        $this->change($p, $request);
        $id = SupplierPaymentAllocation::query()->whereNotNull('reverses_allocation_id')->firstOrFail()->getKey();
        DB::table('accounting_purchase_events')->where('source_kind', 'supplier_payment_allocation')->where('source_id', $id)->delete();
        $this->rejectAtomically(fn () => $this->change($p, $request));
    }

    public function test_changed_native_operation_actor_blocks_retry(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $request = $this->requestFor($p, [], [$this->position($p)['active'][0]['id']]);
        $this->change($p, $request);
        DB::table('supplier_payment_allocations')->whereNotNull('reverses_allocation_id')->update(['posted_by' => $this->reviewer->getKey()]);
        $this->rejectAtomically(fn () => $this->change($p, $request));
    }
}
