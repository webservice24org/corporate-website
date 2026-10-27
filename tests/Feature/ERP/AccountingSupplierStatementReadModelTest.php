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
use App\Models\SupplierPaymentReversalRequest;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ERP\Accounting\AccountingAccountService;
use App\Services\ERP\Accounting\AccountingCalendarService;
use App\Services\ERP\Accounting\AccountingCashAccountService;
use App\Services\ERP\Accounting\AccountingMappingCatalog;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\Reports\AccountingSupplierStatementReader;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierCreditService;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentChangeService;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentPosition;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentReversalService;
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

final class AccountingSupplierStatementReadModelTest extends TestCase
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
        foreach (['supplier_payment_reversal_requests', 'supplier_payments', 'supplier_payment_targets', 'supplier_payment_allocations', 'supplier_payment_audit_logs', 'accounting_supplier_payment_operations', 'supplier_payables', 'accounting_purchase_events', 'accounting_source_effects', 'journal_entries', 'journal_entry_lines', 'accounting_audit_events'] as $table) {
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

    private function reversals(): AccountingSupplierPaymentReversalService
    {
        return app(AccountingSupplierPaymentReversalService::class);
    }

    private function prepareReverse(SupplierPayment $p, ?User $actor = null): SupplierPaymentReversalRequest
    {
        return $this->reversals()->prepare($actor ?? $this->actor, (int) $p->getKey(), ['document_date' => now('Asia/Dhaka')->toDateString(), 'reason' => 'PRIVATE-SUPPLIER-REFUND-REASON']);
    }

    private function approvedReverse(SupplierPayment $p, ?User $maker = null): SupplierPaymentReversalRequest
    {
        $maker ??= $this->actor;
        $r = $this->prepareReverse($p, $maker);
        $r = $this->reversals()->submit($maker, (int) $r->getKey(), 1);

        return $this->reversals()->approve($this->reviewer, (int) $r->getKey(), 2);
    }

    private function refund(SupplierPaymentReversalRequest $r, ?string $key = null, ?User $actor = null): SupplierPaymentReversalRequest
    {
        return $this->reversals()->post($actor ?? $this->actor, (int) $r->getKey(), (int) $r->getAttribute('revision'), $key ?? (string) Str::uuid());
    }

    private function reader(): AccountingSupplierStatementReader
    {
        return app(AccountingSupplierStatementReader::class);
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed> */
    private function statement(SupplierBill $bill, array $filters = [], ?User $actor = null): array
    {
        return $this->reader()->statement($actor ?? $this->actor, (int) $this->company->getKey(), (int) $bill->getAttribute('supplier_id'), $filters);
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed> */
    private function reconcile(array $filters = []): array
    {
        return $this->reader()->reconcile($this->actor, (int) $this->company->getKey(), $filters);
    }

    public function test_recognized_bill_reconciles_ap_to_gl(): void
    {
        $b = $this->liveBill();
        $r = $this->statement($b);
        $this->assertSame('95.00', $r['closing']['ap']);
        $this->assertSame('0.00', $r['closing']['advance']);
        $this->assertTrue($r['reconciliation']['reconciled']);
        $this->assertTrue($this->reconcile()['complete']);
    }

    public function test_unapplied_cash_keeps_ap_and_advance_separate(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $r = $this->statement($b);
        $this->assertSame('95.00', $r['closing']['ap']);
        $this->assertSame('100.00', $r['closing']['advance']);
        $this->assertSame('-5.00', $r['closing']['net']);
        $this->assertTrue($this->reconcile()['reconciliation']['reconciled']);
    }

    public function test_initial_allocation_settles_ap_without_second_cash(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b));
        $r = $this->statement($b);
        $this->assertCount(3, $r['rows']);
        $this->assertSame('0.00', $r['closing']['ap']);
        $this->assertSame('95.00', $r['closing']['cash_paid']);
        $this->assertTrue($r['reconciliation']['reconciled']);
    }

    public function test_later_allocation_uses_its_own_accounting_date(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $this->change($p, $this->requestFor($p, [$this->target($b, '30.00')]));
        $old = $this->statement($b, ['to' => '2026-10-16']);
        $r = $this->statement($b, ['from' => '2026-10-17', 'to' => '2026-10-17']);
        $this->assertSame('95.00', $old['closing']['ap']);
        $this->assertSame('100.00', $old['closing']['advance']);
        $this->assertSame($old['closing'], $r['opening']);
        $this->assertSame('65.00', $r['closing']['ap']);
        $this->assertSame('70.00', $r['closing']['advance']);
        $this->assertTrue($old['reconciliation']['reconciled']);
    }

    public function test_compensation_restores_ap_without_new_cash(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $this->change($p, $this->requestFor($p, [], [$this->position($p)['active'][0]['id']]));
        $r = $this->statement($b);
        $this->assertSame('95.00', $r['closing']['ap']);
        $this->assertSame('95.00', $r['closing']['advance']);
        $this->assertSame('95.00', $r['closing']['cash_paid']);
        $this->assertTrue($r['reconciliation']['reconciled']);
    }

    public function test_full_refund_restores_ap_and_removes_advance_but_retains_history(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $this->refund($this->approvedReverse($p));
        $r = $this->statement($b);
        $this->assertCount(5, $r['rows']);
        $this->assertSame('95.00', $r['closing']['ap']);
        $this->assertSame('0.00', $r['closing']['advance']);
        $this->assertSame('95.00', $r['closing']['cash_reversed']);
        $this->assertSame('0.00', $this->statement($b, ['to' => '2026-10-16'])['closing']['ap']);
        $this->assertTrue($this->reconcile()['reconciliation']['reconciled']);
    }

    public function test_payable_register_is_dated_rather_than_current_paid_summary(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $this->refund($this->approvedReverse($p));
        $old = $this->reader()->payables($this->actor, (int) $this->company->getKey(), ['as_of' => '2026-10-16']);
        $new = $this->reader()->payables($this->actor, (int) $this->company->getKey());
        $this->assertSame('0.00', $old['rows'][0]['outstanding_as_of']);
        $this->assertSame('95.00', $new['rows'][0]['outstanding_as_of']);
        $this->assertSame('current_native_terms', $new['due_date_basis']);
    }

    public function test_statement_pagination_preserves_complete_totals(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b));
        $a = $this->statement($b, ['per_page' => 1]);
        $z = $this->statement($b, ['per_page' => 1, 'page' => 3]);
        $this->assertSame($a['closing'], $z['closing']);
        $this->assertSame(3, $z['pagination']['total']);
        $this->assertSame('0.00', $z['rows'][0]['ap_balance']);
    }

    public function test_payables_pagination_preserves_full_register_totals(): void
    {
        $a = $this->liveBill();
        $this->anotherBill($a);
        $r = $this->reader()->payables($this->actor, (int) $this->company->getKey(), ['per_page' => 1, 'page' => 2]);
        $this->assertCount(1, $r['rows']);
        $this->assertSame(2, $r['pagination']['total']);
        $this->assertSame('190.00', $r['totals']['ap_signed']);
    }

    public function test_readers_do_not_write_financial_or_native_rows(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b));
        $before = $this->digestState();
        $this->statement($b);
        $this->reconcile();
        $this->reader()->payables($this->actor, (int) $this->company->getKey());
        $this->assertSame($before, $this->digestState());
    }

    public function test_private_native_fields_and_evidence_are_not_exposed(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b));
        $r = $this->statement($b);
        $text = json_encode($r, JSON_THROW_ON_ERROR);
        foreach (['PRIVATE-PAYMENT-REFERENCE', 'PRIVATE-PAYMENT-NOTES', 'payload_hash', 'source_snapshot', 'idempotency_key', 'control_lines'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
    }

    public function test_statement_only_staff_can_read_without_source_or_journal_links(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b));
        $user = $this->staff(['erp.supplier-statements.view']);
        $r = $this->statement($b, [], $user);
        $this->assertTrue($r['complete']);
        foreach ($r['rows'] as $row) {
            $this->assertNull($row['source_url']);
            $this->assertNull($row['journal_url']);
        }
    }

    public function test_missing_statement_permission_is_forbidden(): void
    {
        $b = $this->liveBill();
        $this->expectException(AuthorizationException::class);
        $this->statement($b, [], $this->staff([]));
    }

    public function test_company_reconciliation_also_requires_accounting_report_permission(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->reader()->reconcile($this->staff(['erp.supplier-statements.view']), (int) $this->company->getKey());
    }

    public function test_cross_company_staff_is_forbidden(): void
    {
        $b = $this->liveBill();
        $user = $this->staff(['erp.supplier-statements.view']);
        $foreign = $this->newCompany('STATEMENT-FOREIGN');
        DB::table('employees')->where('user_id', $user->getKey())->update(['company_id' => $foreign->getKey()]);
        $this->expectException(AuthorizationException::class);
        $this->statement($b, [], $user);
    }

    public function test_foreign_supplier_id_is_not_found_in_own_company(): void
    {
        $b = $this->liveBill();
        $foreign = $this->newCompany('STATEMENT-SUPPLIER');
        DB::table('suppliers')->where('id', $b->getAttribute('supplier_id'))->update(['company_id' => $foreign->getKey()]);
        $this->expectException(ModelNotFoundException::class);
        $this->statement($b);
    }

    public function test_drafts_are_excluded_from_financial_balances(): void
    {
        $b = $this->liveBill();
        $this->ready($b);
        $r = $this->statement($b);
        $this->assertCount(1, $r['rows']);
        $this->assertSame('0.00', $r['closing']['cash_paid']);
    }

    public function test_pre_recognition_as_of_excludes_bill(): void
    {
        $b = $this->liveBill();
        $r = $this->statement($b, ['to' => '2026-10-15']);
        $this->assertSame('0.00', $r['closing']['ap']);
        $this->assertCount(0, $r['rows']);
        $p = $this->reader()->payables($this->actor, (int) $this->company->getKey(), ['as_of' => '2026-10-15']);
        $this->assertCount(0, $p['rows']);
    }

    public function test_future_dates_are_rejected(): void
    {
        $b = $this->liveBill();
        $this->expectException(ValidationException::class);
        $this->statement($b, ['to' => '2026-10-17']);
    }

    public function test_company_reconciliation_cannot_be_narrowed_to_one_supplier(): void
    {
        $b = $this->liveBill();
        $this->expectException(ValidationException::class);
        $this->reconcile(['supplier_id' => $b->getAttribute('supplier_id')]);
    }

    public function test_unknown_filter_is_rejected(): void
    {
        $b = $this->liveBill();
        $this->expectException(ValidationException::class);
        $this->statement($b, ['include_private' => true]);
    }

    public function test_damaged_manifest_is_reviewed_without_repair(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b));
        DB::table('accounting_supplier_payment_operations')->delete();
        $before = $this->digestState();
        $r = $this->statement($b);
        $this->assertFalse($r['complete']);
        $this->assertFalse($r['reconciliation']['reconciled']);
        $this->assertContains('payment_family_review', array_column($r['exceptions'], 'code'));
        $this->assertSame($before, $this->digestState());
    }

    public function test_corrupt_paid_summary_is_reviewed_without_mutating_it(): void
    {
        $b = $this->liveBill();
        DB::table('supplier_payables')->where('supplier_bill_id', $b->getKey())->update(['paid_amount' => '1.00']);
        $before = $this->digestState();
        $r = $this->statement($b);
        $this->assertFalse($r['complete']);
        $this->assertContains('payable_summary_review', array_column($r['exceptions'], 'code'));
        $this->assertSame($before, $this->digestState());
    }

    public function test_unrecognized_legacy_bill_has_unknown_dated_balance(): void
    {
        $this->settings(['state' => 'disabled']);
        $b = $this->liveBill();
        $this->settings(['state' => 'enabled']);
        $r = $this->reader()->payables($this->actor, (int) $this->company->getKey());
        $this->assertFalse($r['complete']);
        $this->assertNull($r['rows'][0]['outstanding_as_of']);
        $this->assertSame('0.00', $r['totals']['ap_signed']);
        $this->assertFalse($this->reconcile()['reconciliation']['reconciled']);
        $this->assertSame('review', $this->statement($b)['balance_status']);
    }

    /** Inject deliberately unsupported balanced GL evidence, never through a financial writer. */
    private function unsupportedControl(SupplierBill $bill, string $kind = 'manual', bool $unassigned = false): void
    {
        $journalId = AccountingSourceEffect::query()->where('source_kind', 'supplier_bill')->where('source_id', $bill->getKey())->value('journal_entry_id');
        $j = (array) DB::table('journal_entries')->where('id', $journalId)->first();
        unset($j['id']);
        $j['number'] = 'QA-UNSUPPORTED-'.Str::uuid();
        $j['entry_kind'] = $kind;
        $new = DB::table('journal_entries')->insertGetId($j);
        $line = (array) DB::table('journal_entry_lines')->where('journal_entry_id', $journalId)->where('account_id', $this->mapping['trade_ap'])->first();
        unset($line['id']);
        $line['journal_entry_id'] = $new;
        if ($unassigned) {
            $line['party_type'] = null;
            $line['party_id'] = null;
        } $line['line_number'] = 1;
        $line['debit'] = '10.00';
        $line['credit'] = '0.00';
        DB::table('journal_entry_lines')->insert($line);
        $line['line_number'] = 2;
        $line['debit'] = '0.00';
        $line['credit'] = '10.00';
        DB::table('journal_entry_lines')->insert($line);
    }

    public function test_offsetting_unsupported_control_lines_do_not_pass_numeric_reconciliation(): void
    {
        $b = $this->liveBill();
        $this->unsupportedControl($b);
        $r = $this->reconcile();
        $this->assertTrue($r['reconciliation']['numeric_match']);
        $this->assertFalse($r['reconciliation']['reconciled']);
        $this->assertContains('unsupported_control_line', array_column($r['exceptions'], 'code'));
    }

    public function test_opening_control_rows_require_dedicated_coverage_verification(): void
    {
        $b = $this->liveBill();
        $this->unsupportedControl($b, 'opening');
        $r = $this->reconcile();
        $this->assertFalse($r['complete']);
        $this->assertContains('opening_coverage_not_integrated', array_column($r['exceptions'], 'code'));
    }

    public function test_company_reconciliation_detects_unassigned_control_party(): void
    {
        $b = $this->liveBill();
        $this->unsupportedControl($b, unassigned: true);
        $r = $this->reconcile();
        $this->assertContains('unassigned_or_foreign_supplier', array_column($r['exceptions'], 'code'));
        $this->assertFalse($r['reconciliation']['reconciled']);
    }

    public function test_closed_period_and_disabled_settings_do_not_hide_existing_evidence(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b));
        $this->settings(['state' => 'disabled', 'cutover_locked_at' => now()]);
        DB::table('accounting_periods')->update(['state' => 'hard_closed']);
        $r = $this->statement($b);
        $this->assertTrue($r['reconciliation']['reconciled']);
    }

    public function test_issued_credit_reduces_dated_ap_without_cash(): void
    {
        $receipt = $this->receive($this->draftReceipt($this->order()));
        $b = $this->approveBill($this->draftBill($receipt, '10.0000'));
        $ret = $this->postReturn($this->draftReturn($receipt, '1.0000'));
        $this->issueCredit($this->draftCredit($b, $ret));
        $r = $this->statement($b);
        $this->assertSame('85.50', $r['closing']['ap']);
        $this->assertSame('0.00', $r['closing']['cash_paid']);
        $this->assertTrue($r['reconciliation']['reconciled']);
        $register = $this->reader()->payables($this->actor, (int) $this->company->getKey());
        $this->assertSame('9.50', $register['rows'][0]['credited_as_of']);
    }

    public function test_another_supplier_is_excluded_from_individual_statement(): void
    {
        $a = $this->liveBill();
        $this->liveBill();
        $this->assertSame('95.00', $this->statement($a)['closing']['ap']);
        $this->assertSame('190.00', $this->reconcile()['source_position']['ap']);
    }

    public function test_empty_company_has_zero_position_without_invented_activity(): void
    {
        $r = $this->reconcile();
        $this->assertSame('0.00', $r['source_position']['ap']);
        $this->assertTrue($r['reconciliation']['reconciled']);
    }

    public function test_admission_date_mismatch_is_reviewed_even_if_gl_totals_balance(): void
    {
        $b = $this->liveBill();
        DB::table('accounting_source_effects')->where('source_kind', 'supplier_bill')->where('source_id', $b->getKey())->update(['accounting_date' => '2026-10-15']);
        $r = $this->statement($b);
        $this->assertFalse($r['complete']);
        $this->assertContains('bill_recognition_review', array_column($r['exceptions'], 'code'));
    }

    private function remap(string $role): int
    {
        $old = Account::query()->findOrFail($this->mapping[$role]);
        $replacement = Account::query()->create(['company_id' => $this->company->getKey(), 'account_group_id' => $old->getAttribute('account_group_id'), 'code' => '9987', 'name' => 'Replacement '.$role, 'normal_side' => $old->getAttribute('normal_side'), 'is_postable' => true, 'control_role' => $old->getAttribute('control_role'), 'statement_role' => $old->getAttribute('statement_role'), 'cash_flow_role' => $old->getAttribute('cash_flow_role'), 'status' => 'active', 'revision' => 1]);
        $map = $this->mapping;
        $map[$role] = (int) $replacement->getKey();
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $base = AccountingMappingVersion::query()->where('company_id', $this->company->getKey())->where('state', 'published')->firstOrFail();
        $this->publish(['base_version_id' => (int) $base->getKey(), 'effective_from' => '2026-10-17', 'mappings' => $map]);

        return (int) $replacement->getKey();
    }

    public function test_historical_ap_mapping_remains_in_control_reconciliation_after_refund(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $replacement = $this->remap('trade_ap');
        $this->refund($this->approvedReverse($p));
        $r = $this->reconcile();
        $this->assertTrue($r['reconciliation']['reconciled']);
        $this->assertSame('95.00', $r['source_position']['ap']);
        $this->assertContains($this->mapping['trade_ap'], array_column($r['reconciliation']['accounts'], 'account_id'));
        $this->assertSame('0.00', bcadd((string) JournalEntryLine::query()->where('account_id', $replacement)->sum('credit'), '0', 2));
    }
}
