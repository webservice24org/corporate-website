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
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class AccountingSupplierLifecycleIntegrationTest extends TestCase
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

    private function day(int $day): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-'.$day.' 12:00:00', 'Asia/Dhaka'));
    }

    /** @return array{ap: string, advance: string, net: string} */
    private function gate(string $ap, string $advance, string $date): array
    {
        $report = $this->reconcile(['as_of' => $date]);
        $this->assertTrue($report['complete'], json_encode($report['exceptions'], JSON_THROW_ON_ERROR));
        $this->assertTrue($report['reconciliation']['reconciled']);
        $this->assertSame($ap, $report['source_position']['ap']);
        $this->assertSame($advance, $report['source_position']['advance']);
        $this->assertSame($ap, $report['payable_totals']['ap_signed']);
        $this->assertSame($ap, $report['reconciliation']['gl']['ap']);
        $this->assertSame($advance, $report['reconciliation']['gl']['advance']);

        return ['ap' => $ap, 'advance' => $advance, 'net' => bcsub($ap, $advance, 2)];
    }

    /** @return array{a: SupplierBill, b: SupplierBill, payment: SupplierPayment, review: SupplierPaymentReversalRequest, change: array<string, mixed>} */
    private function lifecycle(): array
    {
        $receipt = $this->receive($this->draftReceipt($this->order()));
        $a = $this->approveBill($this->draftBill($receipt, '10.0000'));
        $b = $this->anotherBill($a);
        $p = $this->postPayment($this->ready($a, $this->paymentData($a, '100.00', '30.00')));
        $this->gate('160.00', '70.00', '2026-10-16');
        $this->day(17);
        $return = $this->postReturn($this->draftReturn($receipt, '1.0000'));
        $this->issueCredit($this->draftCredit($a, $return));
        $later = $this->requestFor($p, [$this->target($b, '50.00')]);
        $this->change($p, $later);
        $this->gate('100.50', '20.00', '2026-10-17');
        $this->day(18);
        $active = $this->position($p)['active'];
        $original = collect($active)->firstWhere('supplier_bill_id', $a->getKey());
        $this->assertNotNull($original);
        $change = $this->requestFor($p, [$this->target($a, '20.00')], [$original['id']]);
        $this->change($p, $change);
        $this->gate('110.50', '30.00', '2026-10-18');
        $this->day(19);
        $review = $this->refund($this->approvedReverse($p));
        $this->gate('180.50', '0.00', '2026-10-19');

        return ['a' => $a, 'b' => $b, 'payment' => $p, 'review' => $review, 'change' => $change];
    }

    public function test_full_multi_bill_credit_change_refund_lifecycle_reconciles_at_each_date(): void
    {
        $f = $this->lifecycle();
        foreach ([16 => ['160.00', '70.00'], 17 => ['100.50', '20.00'], 18 => ['110.50', '30.00'], 19 => ['180.50', '0.00']] as $day => [$ap, $advance]) {
            $this->gate($ap, $advance, '2026-10-'.$day);
        }
        $r = $this->statement($f['a']);
        $this->assertSame('100.00', $r['closing']['cash_paid']);
        $this->assertSame('100.00', $r['closing']['cash_reversed']);
        $this->assertSame('180.50', $r['closing']['net']);
        $this->assertSame('0.00', $this->paid($f['a']));
        $this->assertSame('0.00', $this->paid($f['b']));
    }

    public function test_original_post_change_and_refund_replays_do_not_change_reconciled_history(): void
    {
        $f = $this->lifecycle();
        $before = $this->digestState();
        $p = $f['payment'];
        $this->postPayment($p, (string) $p->getAttribute('idempotency_key'));
        $this->change($p, $f['change']);
        $r = $f['review'];
        $this->refund($r, (string) $r->getAttribute('idempotency_key'));
        $this->assertSame($before, $this->digestState());
        $this->gate('180.50', '0.00', '2026-10-19');
    }

    public function test_statement_export_and_register_remain_dated_after_complete_refund(): void
    {
        $f = $this->lifecycle();
        $id = (int) $this->company->getKey();
        $supplier = (int) $f['a']->getAttribute('supplier_id');
        $statement = $this->reader()->statementExport($this->actor, $id, $supplier, ['from' => '2026-10-17', 'to' => '2026-10-18', 'page' => 99, 'per_page' => 1]);
        $this->assertSame('160.00', $statement['opening']['ap']);
        $this->assertSame('110.50', $statement['closing']['ap']);
        $this->assertSame('30.00', $statement['closing']['advance']);
        $this->assertSame('0.00', $statement['activity']['cash_paid']);
        $this->assertSame('0.00', $statement['activity']['cash_reversed']);
        $this->assertCount(4, $statement['rows']);
        $register = $this->reader()->payablesExport($this->actor, $id, ['as_of' => '2026-10-18']);
        $this->assertSame('110.50', $register['totals']['ap_signed']);
        $balances = array_column($register['rows'], 'outstanding_as_of', 'id');
        $this->assertSame('65.50', $balances[$f['a']->getKey()]);
        $this->assertSame('45.00', $balances[$f['b']->getKey()]);
    }

    public function test_lifecycle_http_screen_csv_and_print_keep_safe_final_totals(): void
    {
        $f = $this->lifecycle();
        $query = http_build_query(['company_id' => $this->company->getKey(), 'supplier_id' => $f['a']->getAttribute('supplier_id')]);
        $base = '/erp/accounting/supplier-statements';
        $this->actingAs($this->actor)->get($base.'?'.$query)->assertOk()->assertInertia(fn (Assert $p) => $p->component('ERP/Accounting/SupplierReports/Statement')->where('report.closing.ap', '180.50')->where('report.closing.advance', '0.00')->where('report.reconciliation.reconciled', true));
        $csv = $this->get($base.'/csv?'.$query)->assertOk()->streamedContent();
        foreach (['180.50', 'allocation_compensated', 'cash_reversed', 'credit_issued'] as $text) {
            $this->assertStringContainsString($text, $csv);
        } foreach (['PRIVATE-PAYMENT-NOTES', 'PRIVATE-PAYMENT-REFERENCE', 'PRIVATE-SUPPLIER-REFUND-REASON', 'PRIVATE-ALLOCATION-CORRECTION'] as $secret) {
            $this->assertStringNotContainsString($secret, $csv);
        } $this->get($base.'/print?'.$query)->assertOk()->assertSee('180.50')->assertDontSee('REVIEW REQUIRED');
    }

    /** Balanced unsupported control fixture; overrides simulate damaged posting markers without disabling FK checks.
     * @param  array<string, mixed>  $overrides
     */
    private function controlFixture(SupplierBill $bill, array $overrides = []): int
    {
        $original = AccountingSourceEffect::query()->where('company_id', $this->company->getKey())->where('source_kind', 'supplier_bill')->where('source_id', $bill->getKey())->value('journal_entry_id');
        $journal = (array) DB::table('journal_entries')->where('id', $original)->first();
        unset($journal['id']);
        $journal = [...$journal, 'number' => 'QA-CONTROL-'.Str::uuid(), 'entry_kind' => 'manual', ...$overrides];
        $id = DB::table('journal_entries')->insertGetId($journal);
        $line = (array) DB::table('journal_entry_lines')->where('journal_entry_id', $original)->where('account_id', $this->mapping['trade_ap'])->first();
        unset($line['id']);
        $line = [...$line, 'journal_entry_id' => $id, 'line_number' => 1, 'debit' => '10.00', 'credit' => '0.00'];
        DB::table('journal_entry_lines')->insert($line);
        $line = [...$line, 'line_number' => 2, 'debit' => '0.00', 'credit' => '10.00'];
        DB::table('journal_entry_lines')->insert($line);

        return $id;
    }

    private function assertReviewCode(string $code): void
    {
        $before = $this->digestState();
        $r = $this->reconcile();
        $this->assertFalse($r['complete']);
        $this->assertFalse($r['reconciliation']['reconciled']);
        $this->assertContains($code, array_column($r['exceptions'], 'code'));
        $this->assertSame($before, $this->digestState());
    }

    public function test_posted_control_without_timestamp_cannot_disappear_from_reconciliation(): void
    {
        $b = $this->liveBill();
        $this->controlFixture($b, ['posted_at' => null]);
        $this->assertReviewCode('incomplete_control_posting_markers');
        $this->assertTrue($this->reconcile()['reconciliation']['numeric_match']);
    }

    public function test_posted_control_without_actor_requires_review(): void
    {
        $b = $this->liveBill();
        $this->controlFixture($b, ['posted_by' => null]);
        $this->assertReviewCode('incomplete_control_posting_markers');
    }

    public function test_nonposted_control_with_posting_timestamp_requires_review_without_counting_draft_amounts(): void
    {
        $b = $this->liveBill();
        $this->controlFixture($b, ['status' => 'draft', 'posted_by' => null]);
        $this->assertReviewCode('inconsistent_control_posting_state');
        $this->assertSame('95.00', $this->reconcile()['reconciliation']['gl']['ap']);
    }

    public function test_nonposted_control_with_posting_actor_requires_review(): void
    {
        $b = $this->liveBill();
        $this->controlFixture($b, ['status' => 'draft', 'posted_at' => null]);
        $this->assertReviewCode('inconsistent_control_posting_state');
    }

    public function test_unposted_draft_without_posting_markers_does_not_affect_the_gate(): void
    {
        $b = $this->liveBill();
        $this->controlFixture($b, ['status' => 'draft', 'posted_at' => null, 'posted_by' => null]);
        $this->gate('95.00', '0.00', '2026-10-16');
    }

    public function test_marker_exceptions_remain_visible_in_both_export_formats(): void
    {
        $b = $this->liveBill();
        $this->controlFixture($b, ['posted_at' => null]);
        $query = http_build_query(['company_id' => $this->company->getKey()]);
        $this->actingAs($this->actor);
        $csv = $this->get('/erp/accounting/payable-reconciliation/csv?'.$query)->assertOk()->streamedContent();
        $this->assertStringContainsString('incomplete_control_posting_markers', $csv);
        $this->assertStringContainsString('REVIEW', $csv);
        $this->get('/erp/accounting/payable-reconciliation/print?'.$query)->assertOk()->assertSee('REVIEW REQUIRED')->assertSee('incomplete_control_posting_markers');
    }

    public function test_inactive_original_binding_refund_is_recognized_by_all_read_models(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $review = $this->approvedReverse($p);
        $this->cash->update(['status' => 'inactive']);
        $this->refund($review);
        $this->gate('95.00', '0.00', '2026-10-16');
        $r = $this->statement($b);
        $this->assertSame('95.00', $r['closing']['cash_reversed']);
        $this->assertTrue($r['complete']);
    }

    public function test_approved_but_unposted_refund_review_has_no_report_effect(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->approvedReverse($p);
        $this->gate('0.00', '0.00', '2026-10-16');
        $this->assertSame('0.00', $this->statement($b)['closing']['cash_reversed']);
    }

    public function test_overallocated_bill_is_rejected_without_changing_the_reconciliation_gate(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $request = $this->requestFor($p, [$this->target($b, '96.00')]);
        $this->rejectAtomically(fn () => $this->change($p, $request));
        $this->gate('95.00', '100.00', '2026-10-16');
    }

    public function test_another_supplier_bill_cannot_consume_original_supplier_funds(): void
    {
        $b = $this->liveBill();
        $other = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $request = $this->requestFor($p, [$this->target($other, '50.00')]);
        $this->rejectAtomically(fn () => $this->change($p, $request));
        $this->gate('190.00', '100.00', '2026-10-16');
    }

    public function test_closed_period_allocation_failure_rolls_back_native_financial_and_report_state(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $request = $this->requestFor($p, [$this->target($b, '10.00')]);
        DB::table('accounting_periods')->update(['state' => 'hard_closed']);
        $this->rejectAtomically(fn () => $this->change($p, $request));
        $this->gate('95.00', '100.00', '2026-10-16');
    }

    public function test_existing_reports_remain_readable_after_company_deactivation(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b));
        $this->company->update(['status' => 'inactive']);
        $this->gate('0.00', '0.00', '2026-10-16');
        $this->actingAs($this->actor)->get('/erp/accounting/payable-register?company_id='.$this->company->getKey())->assertOk()->assertInertia(fn (Assert $p) => $p->component('ERP/Accounting/SupplierReports/Payables')->where('company.status', 'inactive')->where('report.complete', true));
    }
}
