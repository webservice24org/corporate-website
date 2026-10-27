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
use App\Services\ERP\Accounting\Source\AccountingSourceReconciliation;
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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class AccountingSupplierPaymentReversalPostingTest extends TestCase
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

    public function test_prepare_submit_and_independent_approval_do_not_reverse_cash_or_paid_ap(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->approvedReverse($p);
        $this->assertSame('approved', $r->getAttribute('status'));
        $this->assertSame('95.00', $this->paid($b));
        $this->assertDatabaseCount('supplier_payments', 1);
        $this->assertDatabaseCount('journal_entries', 4);
    }

    public function test_fully_allocated_payment_restores_ap_and_reverses_original_cash_once(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $header = $p->fresh()->getAttributes();
        $r = $this->refund($this->approvedReverse($p));
        $this->assertSame('posted', $r->getAttribute('status'));
        $this->assertSame('0.00', $this->paid($b));
        $this->assertSame('95.00', $this->role('cash:MAIN', 'debit'));
        $this->assertSame('95.00', $this->role('cash:MAIN', 'credit'));
        $this->assertSame('0.00', bcsub($this->role('supplier_advances', 'debit'), $this->role('supplier_advances', 'credit'), 2));
        $this->assertSame($header, $p->fresh()->getAttributes());
        $this->assertDatabaseCount('supplier_payments', 2);
        $this->assertDatabaseCount('journal_entries', 6);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_cash_only_advance_reversal_has_one_cash_effect_and_no_allocations(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $this->refund($this->approvedReverse($p));
        $this->assertDatabaseCount('supplier_payment_allocations', 0);
        $this->assertSame('100.00', $this->role('cash:MAIN', 'debit'));
        $this->assertSame('0.00', $this->paid($b));
    }

    public function test_partial_allocation_and_unallocated_advance_reverse_full_original_amount(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '40.00')));
        $this->refund($this->approvedReverse($p));
        $this->assertSame('0.00', $this->paid($b));
        $this->assertSame('100.00', $this->role('cash:MAIN', 'debit'));
        $this->assertSame('0.00', bcsub($this->role('supplier_advances', 'debit'), $this->role('supplier_advances', 'credit'), 2));
    }

    public function test_full_refund_compensates_initial_and_later_allocations(): void
    {
        $a = $this->liveBill();
        $b = $this->anotherBill($a);
        $p = $this->postPayment($this->ready($a, $this->paymentData($a, '100.00', '30.00')));
        $this->change($p, $this->requestFor($p, [$this->target($b, '50.00')]));
        $this->refund($this->approvedReverse($p));
        $this->assertSame('0.00', $this->paid($a));
        $this->assertSame('0.00', $this->paid($b));
        $this->assertSame(2, SupplierPaymentAllocation::query()->whereNotNull('reverses_allocation_id')->count());
    }

    public function test_previously_compensated_rows_are_not_compensated_twice(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->change($p, $this->requestFor($p, [], [$this->position($p)['active'][0]['id']]));
        $this->refund($this->approvedReverse($p));
        $this->assertSame(1, SupplierPaymentAllocation::query()->whereNotNull('reverses_allocation_id')->count());
        $this->assertSame('95.00', $this->role('cash:MAIN', 'debit'));
    }

    public function test_other_payments_on_the_bill_are_not_reversed(): void
    {
        $b = $this->liveBill();
        $a = $this->postPayment($this->ready($b, $this->paymentData($b, '40.00', '40.00')));
        $this->postPayment($this->ready($b, $this->paymentData($b, '35.00', '35.00')));
        $this->refund($this->approvedReverse($a));
        $this->assertSame('35.00', $this->paid($b));
        $this->assertSame('40.00', $this->role('cash:MAIN', 'debit'));
    }

    public function test_reversal_maker_cannot_approve_own_request(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->prepareReverse($p);
        $r = $this->reversals()->submit($this->actor, (int) $r->getKey(), 1);
        $this->rejectAtomically(fn () => $this->reversals()->approve($this->actor, (int) $r->getKey(), 2));
    }

    public function test_last_editor_cannot_approve_even_when_not_original_request_maker(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->prepareReverse($p);
        $r = $this->reversals()->prepare($this->reviewer, (int) $p->getKey(), ['document_date' => now('Asia/Dhaka')->toDateString(), 'reason' => 'Independent editor changed reason', 'revision' => 1], (int) $r->getKey());
        $r = $this->reversals()->submit($this->actor, (int) $r->getKey(), 2);
        $this->rejectAtomically(fn () => $this->reversals()->approve($this->reviewer, (int) $r->getKey(), 3));
    }

    public function test_draft_or_submitted_request_cannot_post(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->prepareReverse($p);
        $this->rejectAtomically(fn () => $this->refund($r));
        $r = $this->reversals()->submit($this->actor, (int) $r->getKey(), 1);
        $this->rejectAtomically(fn () => $this->refund($r));
    }

    public function test_reasoned_rejection_returns_to_editable_draft(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->prepareReverse($p);
        $r = $this->reversals()->submit($this->actor, (int) $r->getKey(), 1);
        $r = $this->reversals()->reject($this->reviewer, (int) $r->getKey(), 2, 'Please review the refund reason');
        $this->assertSame('draft', $r->getAttribute('status'));
        $this->assertNull($r->getAttribute('submitted_payload_hash'));
        $r = $this->reversals()->prepare($this->actor, (int) $p->getKey(), ['document_date' => now('Asia/Dhaka')->toDateString(), 'reason' => 'Reviewed refund evidence', 'revision' => 3], (int) $r->getKey());
        $this->assertSame(4, $r->getAttribute('revision'));
    }

    public function test_cancelled_request_releases_original_for_new_allocations_or_review(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $before = $this->requestFor($p, [$this->target($b, '10.00')]);
        $r = $this->prepareReverse($p);
        $this->rejectAtomically(fn () => $this->change($p, $before));
        $this->reversals()->cancel($this->actor, (int) $r->getKey(), 1, 'Retain funds for existing debt');
        $this->change($p, $before);
        $new = $this->prepareReverse($p);
        $this->assertNotSame($r->getKey(), $new->getKey());
    }

    public function test_an_active_review_blocks_duplicate_preparation(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->prepareReverse($p);
        $this->rejectAtomically(fn () => $this->prepareReverse($p));
    }

    public function test_new_allocation_is_blocked_while_reversal_is_approved(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $request = $this->requestFor($p, [$this->target($b, '10.00')]);
        $this->approvedReverse($p);
        $this->rejectAtomically(fn () => $this->change($p, $request));
    }

    public function test_reverse_only_staff_can_post_without_allocate_or_manual_journal_permission(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $user = $this->staff(['erp.supplier-payments.reverse']);
        $this->assertFalse($user->can('erp.supplier-payments.allocate'));
        $this->assertFalse($user->can('erp.journal-entries.post'));
        $this->refund($this->approvedReverse($p, $user), actor: $user);
        $this->assertSame('0.00', $this->paid($b));
    }

    public function test_view_only_actor_cannot_prepare_or_post_reversal(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $user = $this->staff(['erp.supplier-payments.view']);
        $this->rejectAtomically(fn () => $this->prepareReverse($p, $user));
        $r = $this->approvedReverse($p);
        $this->rejectAtomically(fn () => $this->refund($r, actor: $user));
    }

    public function test_cross_company_actor_cannot_prepare_or_post(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $user = $this->staff(['erp.supplier-payments.reverse']);
        $other = $this->newCompany('REFUND-FOREIGN');
        DB::table('employees')->where('user_id', $user->getKey())->update(['company_id' => $other->getKey()]);
        $this->rejectAtomically(fn () => $this->prepareReverse($p, $user));
        $r = $this->approvedReverse($p);
        $this->rejectAtomically(fn () => $this->refund($r, actor: $user));
    }

    public function test_stale_review_revision_and_blank_reason_are_rejected(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->rejectAtomically(fn () => $this->reversals()->prepare($this->actor, (int) $p->getKey(), ['document_date' => now('Asia/Dhaka')->toDateString(), 'reason' => ' ']));
        $r = $this->prepareReverse($p);
        $this->rejectAtomically(fn () => $this->reversals()->submit($this->actor, (int) $r->getKey(), 99));
    }

    public function test_partial_amount_or_replacement_binding_is_not_accepted(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->rejectAtomically(fn () => $this->reversals()->prepare($this->actor, (int) $p->getKey(), ['document_date' => now('Asia/Dhaka')->toDateString(), 'reason' => 'No partial refunds', 'amount' => '10.00', 'cash_account_id' => $this->bank->getKey()]));
    }

    public function test_posted_request_and_native_refund_header_are_immutable(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->refund($this->approvedReverse($p));
        $this->expectException(\LogicException::class);
        DB::transaction(fn () => $r->update(['reason' => 'Changed after refund']));
    }

    public function test_inactive_original_cash_binding_can_receive_the_exact_refund(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->approvedReverse($p);
        $this->cash->update(['status' => 'inactive']);
        $this->refund($r);
        $this->assertSame('95.00', $this->role('cash:MAIN', 'debit'));
        $this->assertSame('inactive', $this->cash->fresh()->getAttribute('status'));
    }

    public function test_bank_refund_uses_original_concrete_bank_account(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, overrides: ['cash_account_id' => (int) $this->bank->getKey(), 'payment_method' => 'bank_transfer'])));
        $r = $this->refund($this->approvedReverse($p));
        $this->assertSame('95.00', $this->role('bank:CURRENT', 'debit'));
        $this->assertSame('0.00', $this->role('cash:MAIN', 'debit'));
        $header = SupplierPayment::query()->findOrFail((int) $r->getAttribute('reversal_payment_id'));
        $this->assertSame((int) $this->bank->getKey(), (int) $header->getAttribute('cash_account_id'));
    }

    public function test_original_post_request_replays_after_full_refund_without_second_cash(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->refund($this->approvedReverse($p));
        $before = $this->digestState();
        $this->postPayment($p, (string) $p->getAttribute('idempotency_key'));
        $this->assertSame($before, $this->digestState());
    }

    public function test_original_later_allocation_request_replays_after_its_full_refund(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $change = $this->requestFor($p, [$this->target($b, '30.00')]);
        $this->change($p, $change);
        $this->refund($this->approvedReverse($p));
        $before = $this->digestState();
        $this->change($p, $change);
        $this->assertSame($before, $this->digestState());
    }

    public function test_same_full_refund_request_replays_without_new_financial_or_native_writes(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->refund($this->approvedReverse($p));
        $before = $this->digestState();
        $this->refund($r, (string) $r->getAttribute('idempotency_key'));
        $this->assertSame($before, $this->digestState());
    }

    public function test_changed_retry_key_is_rejected(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->refund($this->approvedReverse($p));
        $this->rejectAtomically(fn () => $this->refund($r, (string) Str::uuid()));
    }

    public function test_new_refund_requires_enabled_accounting_without_bypass(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->approvedReverse($p);
        $this->settings(['state' => 'disabled']);
        $this->rejectAtomically(fn () => $this->refund($r));
    }

    public function test_cutover_lock_blocks_new_refund(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->approvedReverse($p);
        $this->settings(['cutover_locked_at' => now()]);
        $this->rejectAtomically(fn () => $this->refund($r));
    }

    public function test_closed_current_period_rolls_back_compensations_and_refund_header(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->approvedReverse($p);
        DB::table('accounting_periods')->update(['state' => 'hard_closed']);
        $this->rejectAtomically(fn () => $this->refund($r));
        $this->assertSame('approved', $r->fresh()->getAttribute('status'));
    }

    public function test_refund_retry_survives_disabled_settings_closed_period_and_inactive_binding(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->refund($this->approvedReverse($p));
        $this->settings(['state' => 'disabled', 'cutover_locked_at' => now()]);
        DB::table('accounting_periods')->update(['state' => 'hard_closed']);
        $this->cash->update(['status' => 'inactive']);
        $before = $this->digestState();
        $this->refund($r, (string) $r->getAttribute('idempotency_key'));
        $this->assertSame($before, $this->digestState());
    }

    public function test_approval_from_an_earlier_business_day_requires_fresh_date_review(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->approvedReverse($p);
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $this->rejectAtomically(fn () => $this->refund($r));
    }

    public function test_missing_full_refund_manifest_is_not_recreated_on_retry(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->refund($this->approvedReverse($p));
        DB::table('accounting_supplier_payment_operations')->where('kind', 'reverse')->delete();
        $this->rejectAtomically(fn () => $this->refund($r, (string) $r->getAttribute('idempotency_key')));
    }

    public function test_missing_cash_effect_is_not_recreated_on_retry(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->refund($this->approvedReverse($p));
        DB::table('accounting_source_effects')->where('source_kind', 'supplier_payment')->where('source_id', $r->getAttribute('reversal_payment_id'))->delete();
        $this->rejectAtomically(fn () => $this->refund($r, (string) $r->getAttribute('idempotency_key')));
    }

    public function test_missing_refund_admission_is_not_recreated_on_retry(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->refund($this->approvedReverse($p));
        DB::table('accounting_purchase_events')->where('source_kind', 'supplier_payment')->where('source_id', $r->getAttribute('reversal_payment_id'))->delete();
        $this->rejectAtomically(fn () => $this->refund($r, (string) $r->getAttribute('idempotency_key')));
    }

    public function test_corrupt_review_approval_is_not_accepted_as_refund_proof(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->refund($this->approvedReverse($p));
        DB::table('supplier_payment_reversal_requests')->where('id', $r->getKey())->update(['approved_by' => $r->getAttribute('created_by')]);
        $this->rejectAtomically(fn () => $this->refund($r, (string) $r->getAttribute('idempotency_key')));
    }

    public function test_corrupt_paid_summary_blocks_refund_without_repair(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->approvedReverse($p);
        DB::table('supplier_payables')->where('supplier_bill_id', $b->getKey())->update(['paid_amount' => '1.00']);
        $this->rejectAtomically(fn () => $this->refund($r));
    }

    public function test_a_new_payment_can_settle_ap_reopened_by_full_refund(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->refund($this->approvedReverse($p));
        $this->postPayment($this->ready($b));
        $this->assertSame('95.00', $this->paid($b));
        $this->assertSame('190.00', $this->role('cash:MAIN', 'credit'));
        $this->assertSame('95.00', $this->role('cash:MAIN', 'debit'));
    }

    public function test_completed_refund_blocks_second_preparation_and_new_allocation(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $allocation = $this->requestFor($p, [$this->target($b, '10.00')]);
        $this->refund($this->approvedReverse($p));
        $this->rejectAtomically(fn () => $this->prepareReverse($p));
        $this->rejectAtomically(fn () => $this->change($p, $allocation));
    }

    public function test_refund_failure_after_actual_compensation_rolls_back_the_whole_batch(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->approvedReverse($p);
        $before = $this->digestState();
        $enabled = true;
        $sawCompensation = false;
        JournalEntry::creating(function (JournalEntry $journal) use (&$enabled, &$sawCompensation): void {
            if ($enabled && str_starts_with((string) $journal->getAttribute('description'), 'supplier_payment ') && str_contains((string) $journal->getAttribute('description'), ' reversed ')) {
                $sawCompensation = AccountingSourceEffect::query()->where('source_kind', 'supplier_payment_allocation')->where('event_kind', 'reversed')->exists();
                throw new \RuntimeException('Injected supplier refund failure');
            }
        });
        try {
            $this->refund($r);
            $this->fail('Expected injected refund failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected supplier refund failure', $exception->getMessage());
        } finally {
            $enabled = false;
        } $this->assertTrue($sawCompensation);
        $this->assertSame($before, $this->digestState());
        $this->assertSame('approved', $r->fresh()->getAttribute('status'));
    }

    public function test_current_open_period_refund_does_not_edit_closed_original_journals(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $year = FiscalYear::query()->where('company_id', $this->company->getKey())->firstOrFail();
        app(AccountingCalendarService::class)->savePeriod($this->actor, (int) $year->getKey(), ['company_id' => $this->company->getKey(), 'code' => 'NOV', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'reason' => 'Refund period']);
        DB::table('accounting_periods')->where('code', 'OCT')->update(['state' => 'hard_closed']);
        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00', 'Asia/Dhaka'));
        $r = $this->refund($this->approvedReverse($p));
        $effect = AccountingSourceEffect::query()->where('source_kind', 'supplier_payment')->where('source_id', $r->getAttribute('reversal_payment_id'))->firstOrFail();
        $this->assertSame('2026-11-03', $effect->getAttribute('accounting_date')->format('Y-m-d'));
        $this->assertSame('0.00', $this->paid($b));
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

    public function test_remapped_cash_role_refunds_original_account_without_redirecting_money(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $replacement = $this->remap('cash:MAIN');
        $this->cash->update(['status' => 'inactive']);
        $this->refund($this->approvedReverse($p));
        $this->assertSame('95.00', $this->role('cash:MAIN', 'debit'));
        $this->assertSame('0.00', bcadd((string) JournalEntryLine::query()->where('account_id', $replacement)->sum('debit'), '0', 2));
    }

    public function test_remapped_ap_restores_original_control_account_on_full_refund(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $replacement = $this->remap('trade_ap');
        $this->refund($this->approvedReverse($p));
        $this->assertSame('0.00', $this->paid($b));
        $this->assertSame('95.00', bcsub($this->role('trade_ap', 'credit'), $this->role('trade_ap', 'debit'), 2));
        $this->assertSame('0.00', bcadd((string) JournalEntryLine::query()->where('account_id', $replacement)->sum('credit'), '0', 2));
    }

    public function test_private_review_reason_is_not_copied_to_financial_evidence(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->refund($this->approvedReverse($p));
        $text = json_encode(DB::table('accounting_purchase_events')->get(), JSON_THROW_ON_ERROR).json_encode(DB::table('accounting_source_effects')->get(), JSON_THROW_ON_ERROR);
        foreach (['PRIVATE-SUPPLIER-REFUND-REASON', 'PRIVATE-PAYMENT-REFERENCE', 'PRIVATE-PAYMENT-NOTES'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
    }

    public function test_source_reconciliation_verifies_original_and_refund_cash_sources(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->refund($this->approvedReverse($p));
        $report = app(AccountingSourceReconciliation::class)->page($this->actor, (int) $this->company->getKey(), ['kind' => 'supplier_payment']);
        $this->assertCount(2, $report['rows']);
        foreach ($report['rows'] as $row) {
            $this->assertSame('verified_live', $row['status']);
        }
    }

    public function test_native_cash_binding_still_rejects_inactive_new_money_without_reversal_proof(): void
    {
        $b = $this->liveBill();
        $p = $this->ready($b);
        $this->cash->update(['status' => 'inactive']);
        $this->rejectAtomically(fn () => $this->postPayment($p));
    }

    public function test_native_refund_header_cannot_be_modified_after_posting(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->refund($this->approvedReverse($p));
        $cash = SupplierPayment::query()->findOrFail((int) $r->getAttribute('reversal_payment_id'));
        $this->expectException(\LogicException::class);
        $cash->update(['amount' => '1.00']);
    }

    public function test_original_payment_identity_cannot_opt_into_inactive_binding_exception(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->cash->update(['status' => 'inactive']);
        $this->rejectAtomically(fn () => DB::transaction(fn () => app(AccountingCashAccountService::class)->line((int) $this->company->getKey(), 'cash:MAIN', $this->mapping['cash:MAIN'], (int) $this->cash->getKey(), 'cash_account_id', ['kind' => 'supplier_payment', 'id' => (int) $p->getKey()])));
    }

    public function test_cash_only_live_advance_can_be_refunded_without_legacy_ap_recognition(): void
    {
        $this->settings(['state' => 'disabled']);
        $b = $this->liveBill();
        $this->settings(['state' => 'enabled']);
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $this->refund($this->approvedReverse($p));
        $this->assertSame('100.00', $this->role('cash:MAIN', 'debit'));
        $this->assertSame('0.00', $this->paid($b));
        $this->assertDatabaseCount('journal_entries', 2);
    }

    public function test_reversal_migration_collision_is_reported_before_any_creation(): void
    {
        $files = glob(database_path('migrations/*create_supplier_payment_reversal_requests.php'));
        $this->assertIsArray($files);
        $this->assertCount(1, $files);
        $before = Schema::getTableListing();
        $migration = require $files[0];
        try {
            $migration->up();
            $this->fail('Expected reversal migration collision.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('No DDL was attempted', $exception->getMessage());
        } $this->assertSame($before, Schema::getTableListing());
    }

    public function test_orphan_refund_cash_finance_blocks_another_refund_without_repair(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $r = $this->refund($this->approvedReverse($p));
        $cashId = (int) $r->getAttribute('reversal_payment_id');
        DB::table('supplier_payment_reversal_requests')->where('id', $r->getKey())->delete();
        DB::table('supplier_payments')->where('id', $cashId)->delete();
        DB::table('accounting_supplier_payment_operations')->where('kind', 'reverse')->delete();
        $this->rejectAtomically(fn () => $this->prepareReverse($p));
        DB::table('accounting_purchase_events')->where('source_kind', 'supplier_payment')->where('source_id', $cashId)->delete();
        $this->rejectAtomically(fn () => $this->prepareReverse($p));
    }
}
