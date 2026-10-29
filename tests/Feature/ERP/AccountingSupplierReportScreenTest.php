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
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class AccountingSupplierReportScreenTest extends TestCase
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

    /** @param array<string, mixed> $filters */
    private function url(string $path = 'supplier-statements', ?SupplierBill $bill = null, array $filters = []): string
    {
        return '/erp/accounting/'.$path.'?'.http_build_query(['company_id' => (int) $this->company->getKey(), ...($bill === null ? [] : ['supplier_id' => (int) $bill->getAttribute('supplier_id')]), ...$filters]);
    }

    public function test_all_nine_routes_exist_once_and_guest_is_redirected(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(static fn ($r): bool => str_starts_with((string) $r->getName(), 'erp.accounting.supplier-statements.') || str_starts_with((string) $r->getName(), 'erp.accounting.payable-register.') || str_starts_with((string) $r->getName(), 'erp.accounting.payable-reconciliation.'));
        $this->assertCount(9, $routes);
        $this->assertCount(9, $routes->pluck('uri')->unique());
        foreach (['supplier-statements', 'payable-register', 'payable-reconciliation'] as $path) {
            foreach (['', '/csv', '/print'] as $suffix) {
                $this->get('/erp/accounting/'.$path.$suffix)->assertRedirect('/login');
            }
        }
    }

    public function test_no_company_selection_resolves_all_three_components(): void
    {
        $this->newCompany('REPORT-CHOICE');
        $this->actingAs($this->actor);
        foreach (['supplier-statements' => 'Statement', 'payable-register' => 'Payables', 'payable-reconciliation' => 'Reconciliation'] as $path => $component) {
            $this->get('/erp/accounting/'.$path)->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/SupplierReports/'.$component)->where('company', null)->where('report', null)->has('companies', 2));
        }
    }

    public function test_statement_page_requires_selection_and_does_not_pick_a_supplier_silently(): void
    {
        $this->liveBill();
        $this->actingAs($this->actor)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/SupplierReports/Statement')->where('report', null)->has('suppliers', 1));
    }

    public function test_statement_component_includes_dated_rows_and_full_totals(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b));
        $this->actingAs($this->actor)->get($this->url(bill: $b, filters: ['per_page' => 1, 'page' => 3]))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/SupplierReports/Statement')->has('report.rows', 1)->where('report.pagination.total', 3)->where('report.closing.ap', '0.00')->where('report.closing.cash_paid', '95.00')->where('report.reconciliation.reconciled', true));
    }

    public function test_payable_register_component_is_dated_and_scope_safe(): void
    {
        $b = $this->liveBill();
        $this->anotherBill($b);
        $this->actingAs($this->actor)->get($this->url('payable-register', filters: ['per_page' => 1, 'page' => 2]))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/SupplierReports/Payables')->has('report.rows', 1)->where('report.pagination.total', 2)->where('report.totals.ap_signed', '190.00')->where('report.due_date_basis', 'current_native_terms'));
    }

    public function test_company_reconciliation_component_separates_controls(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $this->actingAs($this->actor)->get($this->url('payable-reconciliation'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/SupplierReports/Reconciliation')->where('report.source_position.ap', '95.00')->where('report.source_position.advance', '100.00')->where('report.source_position.net', '-5.00')->where('report.reconciliation.reconciled', true));
    }

    public function test_statement_only_staff_can_open_statement_and_register_without_journal_authority(): void
    {
        $b = $this->liveBill();
        $user = $this->staff(['erp.supplier-statements.view']);
        $this->actingAs($user)->get($this->url(bill: $b))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canExport', false)->where('canReconcile', false)->where('report.rows.0.source_url', null)->where('report.rows.0.journal_url', null)->has('companies', 1));
        $this->get($this->url('payable-register'))->assertOk();
    }

    public function test_without_statement_view_all_screens_are_forbidden(): void
    {
        $this->actingAs($this->staff([]));
        foreach (['supplier-statements', 'payable-register', 'payable-reconciliation'] as $path) {
            $this->get($this->url($path))->assertForbidden();
        }
    }

    public function test_statement_view_alone_cannot_open_company_reconciliation(): void
    {
        $this->actingAs($this->staff(['erp.supplier-statements.view']))->get($this->url('payable-reconciliation'))->assertForbidden();
    }

    public function test_cross_company_selection_is_forbidden_on_screens_and_exports(): void
    {
        $b = $this->liveBill();
        $other = $this->newCompany('FOREIGN-REPORT');
        $user = $this->staff(['erp.supplier-statements.view', 'erp.supplier-statements.export', 'erp.accounting-reports.view', 'erp.accounting-reports.export']);
        $this->actingAs($user);
        foreach (['supplier-statements', 'payable-register', 'payable-reconciliation'] as $path) {
            foreach (['', '/csv', '/print'] as $suffix) {
                $this->get($this->url($path.$suffix, $path === 'supplier-statements' ? $b : null, ['company_id' => $other->getKey()]))->assertForbidden();
            }
        }
    }

    public function test_foreign_supplier_cannot_be_injected_into_statement_or_register(): void
    {
        $b = $this->liveBill();
        $other = $this->newCompany('FOREIGN-SUPPLIER');
        DB::table('suppliers')->where('id', $b->getAttribute('supplier_id'))->update(['company_id' => $other->getKey()]);
        $this->actingAs($this->actor);
        foreach (['supplier-statements', 'payable-register'] as $path) {
            foreach (['', '/csv', '/print'] as $suffix) {
                $this->get($this->url($path.$suffix, $b))->assertNotFound();
            }
        }
    }

    public function test_all_exports_require_statement_export_permission(): void
    {
        $b = $this->liveBill();
        $this->actingAs($this->staff(['erp.supplier-statements.view', 'erp.accounting-reports.view', 'erp.accounting-reports.export']));
        foreach (['supplier-statements', 'payable-register', 'payable-reconciliation'] as $path) {
            foreach (['csv', 'print'] as $format) {
                $this->get($this->url($path.'/'.$format, $path === 'supplier-statements' ? $b : null))->assertForbidden();
            }
        }
    }

    public function test_reconciliation_exports_also_require_accounting_export_permission(): void
    {
        $this->actingAs($this->staff(['erp.supplier-statements.view', 'erp.supplier-statements.export', 'erp.accounting-reports.view']));
        foreach (['csv', 'print'] as $format) {
            $this->get($this->url('payable-reconciliation/'.$format))->assertForbidden();
        }
    }

    public function test_export_permission_does_not_grant_view_permission(): void
    {
        $this->actingAs($this->staff(['erp.supplier-statements.export', 'erp.accounting-reports.export']));
        foreach (['supplier-statements', 'payable-register', 'payable-reconciliation'] as $path) {
            $this->get($this->url($path.'/csv'))->assertForbidden();
        }
    }

    public function test_statement_csv_exports_all_rows_even_from_a_later_page(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b));
        $response = $this->actingAs($this->actor)->get($this->url('supplier-statements/csv', $b, ['per_page' => 1, 'page' => 3]));
        $response->assertOk()->assertDownload('supplier-statement.csv');
        $csv = $response->streamedContent();
        foreach (['bill_approved', 'cash_paid', 'allocated', 'All filtered rows; pagination ignored', 'Closing ap', 'Closing cash_paid', '95.00'] as $value) {
            $this->assertStringContainsString($value, $csv);
        }
    }

    public function test_register_csv_exports_all_filtered_bills(): void
    {
        $a = $this->liveBill();
        $b = $this->anotherBill($a);
        $response = $this->actingAs($this->actor)->get($this->url('payable-register/csv', filters: ['per_page' => 1, 'page' => 2]));
        $response->assertOk()->assertDownload('supplier-payables.csv');
        $csv = $response->streamedContent();
        $this->assertStringContainsString((string) $a->getAttribute('number'), $csv);
        $this->assertStringContainsString((string) $b->getAttribute('number'), $csv);
        $this->assertStringContainsString('190.00', $csv);
    }

    public function test_export_staff_does_not_need_payment_or_manual_journal_permission(): void
    {
        $b = $this->liveBill();
        $user = $this->staff(['erp.supplier-statements.view', 'erp.supplier-statements.export']);
        $this->assertFalse($user->can('erp.supplier-payments.view'));
        $this->actingAs($user)->get($this->url('supplier-statements/csv', $b))->assertOk();
        $this->get($this->url('payable-register/print'))->assertOk();
    }

    public function test_reconciliation_csv_exports_both_control_roles_and_review_status(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $r = $this->actingAs($this->actor)->get($this->url('payable-reconciliation/csv'));
        $r->assertOk()->assertDownload('supplier-reconciliation.csv');
        $csv = $r->streamedContent();
        foreach (['trade_ap', 'supplier_advances', '95.00', '100.00', 'reconciled_live'] as $value) {
            $this->assertStringContainsString($value, $csv);
        }
    }

    public function test_each_print_route_resolves_server_view_with_full_scope(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b));
        $this->actingAs($this->actor);
        foreach (['supplier-statements', 'payable-register', 'payable-reconciliation'] as $path) {
            $this->get($this->url($path.'/print', $path === 'supplier-statements' ? $b : null, $path === 'payable-reconciliation' ? [] : ['per_page' => 1, 'page' => 2]))->assertOk()->assertViewIs('erp.accounting.supplier-report-print')->assertSee('All filtered rows')->assertSee('Verified live sources only');
        }
    }

    public function test_unknown_legacy_balances_stay_unknown_in_screen_csv_and_print(): void
    {
        $this->settings(['state' => 'disabled']);
        $b = $this->liveBill();
        $this->settings(['state' => 'enabled']);
        $this->actingAs($this->actor)->get($this->url('payable-register'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.complete', false)->where('report.rows.0.outstanding_as_of', null));
        $r = $this->get($this->url('payable-register/csv'));
        $r->assertOk();
        $this->assertStringContainsString('Unknown — review', $r->streamedContent());
        $this->assertStringContainsString('bill_recognition_review', $r->streamedContent());
        $this->get($this->url('payable-register/print'))->assertOk()->assertSee('REVIEW REQUIRED')->assertSee('Unknown — review');
        $this->get($this->url('supplier-statements/print', $b))->assertOk()->assertSee('REVIEW REQUIRED');
    }

    public function test_damaged_payment_family_is_not_hidden_in_exports_or_repaired(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b));
        DB::table('accounting_supplier_payment_operations')->delete();
        $before = $this->digestState();
        $r = $this->actingAs($this->actor)->get($this->url('supplier-statements/csv', $b));
        $r->assertOk();
        $this->assertStringContainsString('payment_family_review', $r->streamedContent());
        $this->assertStringContainsString('REVIEW', $r->streamedContent());
        $this->assertSame($before, $this->digestState());
    }

    public function test_csv_private_fields_and_evidence_are_not_exposed(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b));
        $r = $this->actingAs($this->actor)->get($this->url('supplier-statements/csv', $b));
        $r->assertOk();
        foreach (['PRIVATE-PAYMENT-REFERENCE', 'PRIVATE-PAYMENT-NOTES', 'PRIVATE-INVOICE', 'payload_hash', 'source_snapshot', 'idempotency_key'] as $value) {
            $this->assertStringNotContainsString($value, $r->streamedContent());
        }
    }

    public function test_screen_and_export_filters_reject_future_dates(): void
    {
        $b = $this->liveBill();
        $this->actingAs($this->actor);
        $this->getJson($this->url(bill: $b, filters: ['to' => '2026-10-17']))->assertUnprocessable();
        $this->getJson($this->url('payable-register/csv', filters: ['as_of' => '2026-10-17']))->assertUnprocessable();
    }

    public function test_unknown_filter_and_supplier_narrowed_reconciliation_are_rejected(): void
    {
        $b = $this->liveBill();
        $this->actingAs($this->actor);
        $this->getJson($this->url(bill: $b, filters: ['include_private' => 1]))->assertUnprocessable();
        $this->getJson($this->url('payable-reconciliation', $b))->assertUnprocessable();
    }

    public function test_full_exports_without_required_selection_return_422(): void
    {
        $this->newCompany('MULTIPLE');
        $this->actingAs($this->actor);
        $this->get('/erp/accounting/payable-register/csv')->assertUnprocessable();
        $this->get($this->url('supplier-statements/print'))->assertUnprocessable();
    }

    public function test_export_dated_position_survives_later_refund(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $this->refund($this->approvedReverse($p));
        $old = $this->reader()->statementExport($this->actor, (int) $this->company->getKey(), (int) $b->getAttribute('supplier_id'), ['to' => '2026-10-16']);
        $this->assertSame('0.00', $old['closing']['ap']);
        $this->assertCount(3, $old['rows']);
        $this->actingAs($this->actor)->get($this->url('supplier-statements/print', $b, ['to' => '2026-10-16']))->assertOk()->assertViewHas('document', fn (array $d): bool => count($d['rows']) === 3);
    }

    public function test_csv_formula_prefix_and_print_html_are_safely_rendered(): void
    {
        $fixture = $this->order();
        Supplier::query()->whereKey($fixture[2]->getAttribute('supplier_id'))->update(['code' => '=1+1', 'name' => '<script>alert(1)</script>']);
        $b = $this->approveBill($this->draftBill($this->receive($this->draftReceipt($fixture)), '10.0000'));
        $r = $this->actingAs($this->actor)->get($this->url('supplier-statements/csv', $b));
        $r->assertOk();
        $this->assertStringContainsString("'=1+1", $r->streamedContent());
        $this->get($this->url('supplier-statements/print', $b))->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_exports_are_private_and_not_cached(): void
    {
        $b = $this->liveBill();
        $r = $this->actingAs($this->actor)->get($this->url('supplier-statements/csv', $b));
        $r->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        $r = $this->get($this->url('supplier-statements/print', $b));
        $r->assertOk();
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
    }

    public function test_screen_and_both_exports_leave_financial_state_unchanged(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b));
        $before = $this->digestState();
        $this->actingAs($this->actor);
        foreach (['supplier-statements', 'payable-register', 'payable-reconciliation'] as $path) {
            foreach (['', '/csv', '/print'] as $suffix) {
                $r = $this->get($this->url($path.$suffix, $path === 'supplier-statements' ? $b : null));
                $r->assertOk();
                if ($suffix === '/csv') {
                    $r->streamedContent();
                }
            }
        } $this->assertSame($before, $this->digestState());
    }

    public function test_export_reader_requires_permission_without_controller(): void
    {
        $b = $this->liveBill();
        $user = $this->staff(['erp.supplier-statements.view']);
        $this->expectException(AuthorizationException::class);
        $this->reader()->statementExport($user, (int) $this->company->getKey(), (int) $b->getAttribute('supplier_id'));
    }

    public function test_all_export_rows_preserve_supplier_and_date_filters(): void
    {
        $a = $this->liveBill();
        $b = $this->liveBill();
        $r = $this->reader()->payablesExport($this->actor, (int) $this->company->getKey(), ['supplier_id' => (int) $a->getAttribute('supplier_id'), 'as_of' => '2026-10-16', 'page' => 99]);
        $this->assertCount(1, $r['rows']);
        $this->assertSame((int) $a->getKey(), $r['rows'][0]['id']);
        $this->assertNotSame((int) $b->getKey(), $r['rows'][0]['id']);
        $this->assertSame('95.00', $r['totals']['ap_signed']);
    }
}
