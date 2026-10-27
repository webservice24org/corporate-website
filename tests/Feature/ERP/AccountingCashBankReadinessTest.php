<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingMappingVersion;
use App\Models\Accounting\AccountingSupplierCredit;
use App\Models\Accounting\JournalEntryLine;
use App\Models\Company;
use App\Models\Customer;
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
use App\Services\ERP\Accounting\AccountingCashBankReadinessService;
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
use App\Services\ERP\Sales\CustomerReceiptService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingCashBankPermissionSeeder;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\AccountingPurchasePermissionSeeder;
use Database\Seeders\AccountingSupplierPaymentPermissionSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class AccountingCashBankReadinessTest extends TestCase
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
        $this->seed(AccountingCashBankPermissionSeeder::class);
        $this->seed(SalesPermissionSeeder::class);
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

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    private function readiness(array $filters = [], ?User $actor = null): array
    {
        return app(AccountingCashBankReadinessService::class)->page($actor ?? $this->actor, (int) $this->company->getKey(), $filters);
    }

    private function readDigest(): string
    {
        $state = ['finance' => $this->digestState()];
        foreach (['accounting_cash_accounts', 'accounting_company_settings', 'accounting_mapping_versions', 'accounting_account_mappings', 'accounts', 'customer_receipts', 'accounting_receipt_operations', 'customer_receipt_allocations'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }

return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    public function test_guest_is_redirected_and_route_is_mounted_once(): void
    {
        $this->get('/erp/accounting/cash-bank')->assertRedirect('/login');
        $routes = array_filter(Route::getRoutes()->getRoutes(), static fn ($route): bool => $route->getName() === 'erp.accounting.cash-bank.index');
        $this->assertCount(1, $routes);
    }

    public function test_workspace_requires_its_own_permission(): void
    {
        $this->actingAs($this->staff(['erp.accounting-mappings.view']))->get('/erp/accounting/cash-bank')->assertForbidden();
    }

    public function test_read_only_staff_needs_no_mapping_publish_or_journal_permission(): void
    {
        $user = $this->staff(['erp.cash-accounts.view']);
        $this->assertFalse($user->can('erp.accounting-mappings.publish'));
        $this->assertFalse($user->can('erp.journal-entries.view'));
        $this->actingAs($user)->get('/erp/accounting/cash-bank')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/CashBank/Index')->has('companies', 1)->where('canOpenSetup', false)->where('report.summary.total_bindings', 2));
    }

    public function test_ready_workspace_reuses_existing_binding_ids_and_profile(): void
    {
        $r = $this->readiness();
        $this->assertSame('ready_for_source_checks', $r['report']['status']);
        $this->assertTrue($r['report']['profile']['verified']);
        $this->assertSame(2, $r['report']['summary']['usable_bindings']);
        $ids = array_column($r['report']['rows'], 'id');
        $this->assertContains((int) $this->cash->getKey(), $ids);
        $this->assertContains((int) $this->bank->getKey(), $ids);
    }

    public function test_multiple_companies_require_explicit_selection(): void
    {
        $this->newCompany('CASH-OTHER');
        $this->actingAs($this->actor)->get('/erp/accounting/cash-bank')->assertOk()->assertInertia(fn (Assert $page) => $page->where('company', null)->where('report', null)->has('companies', 2));
    }

    public function test_foreign_company_selection_is_forbidden(): void
    {
        $foreign = $this->newCompany('CASH-FOREIGN');
        $this->actingAs($this->staff(['erp.cash-accounts.view']))->get('/erp/accounting/cash-bank?company_id='.$foreign->getKey())->assertForbidden();
    }

    public function test_missing_setup_is_reported_without_initializing_anything(): void
    {
        $fresh = $this->newCompany('CASH-NO-SETUP');
        $before = $this->readDigest();
        $r = app(AccountingCashBankReadinessService::class)->page($this->actor, (int) $fresh->getKey());
        $this->assertSame('review_required', $r['report']['status']);
        $this->assertContains('accounting_setup_missing', $r['report']['issues']);
        $this->assertSame(0, $r['report']['summary']['total_bindings']);
        $this->assertSame($before, $this->readDigest());
        $this->assertDatabaseMissing('accounting_company_settings', ['company_id' => $fresh->getKey()]);
    }

    public function test_missing_initialized_role_is_visible_and_not_backfilled(): void
    {
        DB::table('accounting_cash_accounts')->where('id', $this->bank->getKey())->delete();
        $before = $this->readDigest();
        $r = $this->readiness();
        $this->assertContains('roles_without_initialized_binding', $r['report']['issues']);
        $this->assertSame('bank:CURRENT', $r['report']['missing_bindings'][0]['role']);
        $this->assertSame($before, $this->readDigest());
    }

    public function test_inactive_binding_stays_visible_and_cannot_be_counted_usable(): void
    {
        $this->cash->update(['status' => 'inactive']);
        $r = $this->readiness();
        $this->assertSame(1, $r['report']['summary']['inactive_bindings']);
        $this->assertSame(1, $r['report']['summary']['usable_bindings']);
        $cash = collect($r['report']['rows'])->firstWhere('id', $this->cash->getKey());
        $this->assertSame('inactive', $cash['setup_status']);
    }

    public function test_all_inactive_bindings_require_review(): void
    {
        $this->cash->update(['status' => 'inactive']);
        $this->bank->update(['status' => 'inactive']);
        $r = $this->readiness();
        $this->assertContains('no_usable_binding', $r['report']['issues']);
        $this->assertSame('review_required', $r['report']['status']);
    }

    public function test_disabled_accounting_is_not_treated_as_activation(): void
    {
        $this->settings(['state' => 'disabled']);
        $before = $this->readDigest();
        $r = $this->readiness();
        $this->assertContains('accounting_not_enabled', $r['report']['issues']);
        $this->assertSame('disabled', $r['report']['accounting_state']);
        $this->assertSame($before, $this->readDigest());
    }

    public function test_cutover_lock_keeps_workspace_readable_but_requires_review(): void
    {
        $this->settings(['cutover_locked_at' => now()]);
        $r = $this->readiness();
        $this->assertContains('cutover_locked', $r['report']['issues']);
        $this->actingAs($this->actor)->get('/erp/accounting/cash-bank?company_id='.$this->company->getKey())->assertOk();
    }

    public function test_inactive_company_is_audited_without_being_reactivated(): void
    {
        $this->company->update(['status' => 'inactive']);
        $before = $this->readDigest();
        $r = $this->readiness();
        $this->assertContains('company_inactive', $r['report']['issues']);
        $this->assertSame($before, $this->readDigest());
    }

    public function test_profile_drift_requires_a_transition_and_keeps_binding_identity(): void
    {
        $old = Account::query()->findOrFail($this->mapping['cash:MAIN']);
        $replacement = Account::query()->create(['company_id' => $this->company->getKey(), 'account_group_id' => $old->getAttribute('account_group_id'), 'code' => '9968', 'name' => 'Replacement cash', 'normal_side' => 'debit', 'is_postable' => true, 'control_role' => null, 'statement_role' => $old->getAttribute('statement_role'), 'cash_flow_role' => 'cash', 'status' => 'active', 'revision' => 1]);
        $map = $this->mapping;
        $map['cash:MAIN'] = (int) $replacement->getKey();
        $base = AccountingMappingVersion::query()->where('company_id', $this->company->getKey())->where('state', 'published')->firstOrFail();
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $this->publish(['base_version_id' => $base->getKey(), 'effective_from' => '2026-10-17', 'mappings' => $map]);
        $before = $this->readDigest();
        $r = $this->readiness();
        $cash = collect($r['report']['rows'])->firstWhere('id', $this->cash->getKey());
        $this->assertContains('mapping_transition_required', $cash['issues']);
        $this->assertSame($this->mapping['cash:MAIN'], $cash['account_id']);
        $this->assertSame($before, $this->readDigest());
    }

    public function test_inactive_gl_and_cash_classification_damage_are_reviewed(): void
    {
        DB::table('accounts')->where('id', $this->mapping['cash:MAIN'])->update(['status' => 'inactive', 'cash_flow_role' => null]);
        $r = $this->readiness();
        $cash = collect($r['report']['rows'])->firstWhere('id', $this->cash->getKey());
        $this->assertContains('gl_not_active_postable_leaf', $cash['issues']);
        $this->assertContains('gl_cash_classification_review', $cash['issues']);
        $this->assertSame('review', $cash['setup_status']);
    }

    public function test_currency_damage_does_not_make_a_binding_usable(): void
    {
        DB::table('accounting_cash_accounts')->where('id', $this->cash->getKey())->update(['currency_code' => 'USD']);
        $r = $this->readiness();
        $cash = collect($r['report']['rows'])->firstWhere('id', $this->cash->getKey());
        $this->assertContains('binding_currency_mismatch', $cash['issues']);
    }

    public function test_bad_profile_hash_is_reviewed_without_repair(): void
    {
        DB::table('accounting_mapping_versions')->where('company_id', $this->company->getKey())->update(['payload_hash' => str_repeat('0', 64)]);
        $before = $this->readDigest();
        $r = $this->readiness();
        $this->assertFalse($r['report']['profile']['verified']);
        $this->assertContains('effective_posting_profile_review', $r['report']['issues']);
        $this->assertSame($before, $this->readDigest());
    }

    public function test_filter_and_page_do_not_hide_company_summary_or_issues(): void
    {
        $this->cash->update(['status' => 'inactive']);
        $r = $this->readiness(['kind' => 'bank', 'per_page' => 1, 'page' => 1]);
        $this->assertCount(1, $r['report']['rows']);
        $this->assertSame('bank', $r['report']['rows'][0]['kind']);
        $this->assertSame(2, $r['report']['summary']['total_bindings']);
        $this->assertSame(1, $r['report']['summary']['inactive_bindings']);
        $this->assertSame(1, $r['report']['pagination']['total']);
    }

    public function test_label_role_and_gl_code_search_is_scoped(): void
    {
        $r = $this->readiness(['q' => 'cash:MAIN']);
        $this->assertCount(1, $r['report']['rows']);
        $this->assertSame((int) $this->cash->getKey(), $r['report']['rows'][0]['id']);
    }

    public function test_unknown_filter_invalid_size_and_kind_are_rejected(): void
    {
        $this->actingAs($this->actor);
        foreach (['bank_account_number=123', 'per_page=0', 'per_page=101', 'kind=employee', 'q[]=invalid'] as $query) {
            $this->getJson('/erp/accounting/cash-bank?company_id='.$this->company->getKey().'&'.$query)->assertUnprocessable();
        }
    }

    public function test_usage_counts_reference_native_payment_history_without_balances(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->refund($this->approvedReverse($p));
        $r = $this->readiness();
        $cash = collect($r['report']['rows'])->firstWhere('id', $this->cash->getKey());
        $this->assertSame(2, $cash['usage']['supplier_payment_documents']);
        $this->assertSame(2, $cash['usage']['posted_journal_lines']);
        $this->assertArrayNotHasKey('balance', $cash);
        $this->assertSame('usage_counts_only_not_financial_recognition_or_balances', $r['report']['source_verification']);
    }

    public function test_reading_and_refreshing_does_not_change_native_or_financial_state(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b));
        $before = $this->readDigest();
        $this->readiness();
        $this->readiness(['kind' => 'cash']);
        $this->actingAs($this->actor)->get('/erp/accounting/cash-bank?company_id='.$this->company->getKey())->assertOk();
        $this->assertSame($before, $this->readDigest());
    }

    public function test_new_permission_does_not_grant_existing_status_mutation(): void
    {
        $user = $this->staff(['erp.cash-accounts.view']);
        $this->actingAs($user)->post('/erp/accounting/mappings/cash-accounts/'.$this->cash->getKey().'/status', ['company_id' => $this->company->getKey(), 'revision' => 1, 'status' => 'inactive', 'reason' => 'Should not grant writes'])->assertForbidden();
        $this->assertSame('active', $this->cash->fresh()->getAttribute('status'));
    }

    public function test_permission_seeding_is_idempotent(): void
    {
        $this->seed(AccountingCashBankPermissionSeeder::class);
        $this->seed(AccountingCashBankPermissionSeeder::class);
        $this->assertSame(1, Permission::query()->where('name', 'erp.cash-accounts.view')->where('guard_name', 'web')->count());
    }

    public function test_receipt_usage_is_counted_without_exposing_customer_or_payment_details(): void
    {
        $customer = Customer::query()->create(['company_id' => $this->company->getKey(), 'name' => 'PRIVATE-CUSTOMER-NAME', 'phone' => '01700000000', 'credit_limit' => '100000.00', 'is_active' => true]);
        $service = app(CustomerReceiptService::class);
        $receipt = $service->save($this->actor, ['company_id' => $this->company->getKey(), 'customer_id' => $customer->getKey(), 'document_date' => '2026-10-16', 'amount' => '60.00', 'payment_method' => 'bank_transfer', 'cash_account_id' => $this->bank->getKey(), 'payment_reference' => 'PRIVATE-BANK-REFERENCE', 'notes' => 'PRIVATE-RECEIPT-NOTES']);
        $service->postReceipt($this->actor, (int) $receipt->getKey(), ['revision' => (int) $receipt->getAttribute('revision'), 'idempotency_key' => (string) Str::uuid(), 'allocations' => [], 'reverse_allocation_ids' => [], 'reason' => 'Receipt usage fixture']);
        $before = $this->readDigest();
        $r = $this->readiness();
        $bank = collect($r['report']['rows'])->firstWhere('id', $this->bank->getKey());
        $this->assertSame(1, $bank['usage']['receipt_documents']);
        $this->assertSame(1, $bank['usage']['posted_journal_lines']);
        $json = json_encode($r, JSON_THROW_ON_ERROR);
        foreach (['PRIVATE-CUSTOMER-NAME', 'PRIVATE-BANK-REFERENCE', 'PRIVATE-RECEIPT-NOTES', 'customer_snapshot', 'payment_reference', 'idempotency_key', 'payload_hash', 'bank_account_number'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        } $this->assertSame($before, $this->readDigest());
    }

    public function test_usage_of_inactive_binding_remains_historical_and_visible(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b));
        $this->cash->update(['status' => 'inactive']);
        $r = $this->readiness(['status' => 'inactive']);
        $this->assertCount(1, $r['report']['rows']);
        $this->assertSame(1, $r['report']['rows'][0]['usage']['supplier_payment_documents']);
        $this->assertSame(1, $r['report']['rows'][0]['usage']['posted_journal_lines']);
        $this->assertSame('inactive', $r['report']['rows'][0]['setup_status']);
    }

    public function test_unbound_cash_lines_are_flagged_without_silent_binding_backfill(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b));
        DB::table('journal_entry_lines')->where('account_id', $this->mapping['cash:MAIN'])->update(['cash_account_id' => null]);
        $before = $this->readDigest();
        $r = $this->readiness();
        $this->assertSame(1, $r['report']['unbound_posted_cash_lines']);
        $this->assertContains('unbound_posted_cash_lines', $r['report']['issues']);
        $this->assertSame($before, $this->readDigest());
    }

    public function test_future_activation_date_is_not_ready_for_source_checks(): void
    {
        $this->settings(['activation_date' => '2026-10-17']);
        $r = $this->readiness();
        $this->assertContains('activation_requires_review', $r['report']['issues']);
        $this->assertSame('review_required', $r['report']['status']);
    }

    public function test_invalid_timezone_is_reported_without_guessing_a_business_date(): void
    {
        DB::table('companies')->where('id', $this->company->getKey())->update(['timezone' => 'Invalid/Zone']);
        $r = $this->readiness();
        $this->assertNull($r['report']['as_of']);
        $this->assertContains('company_timezone_review', $r['report']['issues']);
    }

    public function test_readiness_permission_cannot_initialize_bindings(): void
    {
        $before = $this->readDigest();
        $this->actingAs($this->staff(['erp.cash-accounts.view']))->post('/erp/accounting/mappings/cash-accounts', ['company_id' => $this->company->getKey(), 'reason' => 'Unauthorized initializer'])->assertForbidden();
        $this->assertSame($before, $this->readDigest());
    }

    public function test_unmapped_cash_gl_lines_are_included_in_unbound_line_review(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b));
        $old = Account::query()->findOrFail($this->mapping['cash:MAIN']);
        $extra = Account::query()->create(['company_id' => $this->company->getKey(), 'account_group_id' => $old->getAttribute('account_group_id'), 'code' => '9969', 'name' => 'Unmapped cash account', 'normal_side' => 'debit', 'is_postable' => true, 'control_role' => null, 'statement_role' => $old->getAttribute('statement_role'), 'cash_flow_role' => 'cash', 'status' => 'active', 'revision' => 1]);
        DB::table('journal_entry_lines')->where('account_id', $this->mapping['cash:MAIN'])->update(['account_id' => $extra->getKey(), 'cash_account_id' => null]);
        $before = $this->readDigest();
        $r = $this->readiness();
        $this->assertSame(1, $r['report']['unbound_posted_cash_lines']);
        $this->assertContains('unbound_posted_cash_lines', $r['report']['issues']);
        $this->assertSame($before, $this->readDigest());
    }
}
