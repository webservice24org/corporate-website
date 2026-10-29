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
use App\Models\Customer;
use App\Models\CustomerReceipt;
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
use App\Services\ERP\Accounting\Books\AccountingCashBookReader;
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
use Database\Seeders\AccountingCashBookPermissionSeeder;
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

final class AccountingCashBookScreenTest extends TestCase
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
        $this->seed(AccountingCashBookPermissionSeeder::class);
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

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    private function book(array $filters = [], ?AccountingCashAccount $binding = null, ?User $actor = null): array
    {
        return app(AccountingCashBookReader::class)->book($actor ?? $this->actor, (int) $this->company->getKey(), (int) ($binding ?? $this->cash)->getKey(), $filters);
    }

    private function bankReceipt(): CustomerReceipt
    {
        $customer = Customer::query()->create(['company_id' => $this->company->getKey(), 'name' => 'PRIVATE-CUSTOMER', 'phone' => '01700000000', 'credit_limit' => '100000.00', 'is_active' => true]);
        $service = app(CustomerReceiptService::class);
        $r = $service->save($this->actor, ['company_id' => $this->company->getKey(), 'customer_id' => $customer->getKey(), 'document_date' => '2026-10-16', 'amount' => '120.00', 'payment_method' => 'bank_transfer', 'cash_account_id' => $this->bank->getKey(), 'payment_reference' => 'PRIVATE-BANK-REFERENCE', 'notes' => 'PRIVATE-RECEIPT-NOTES']);

        return $service->postReceipt($this->actor, (int) $r->getKey(), ['revision' => (int) $r->getAttribute('revision'), 'idempotency_key' => (string) Str::uuid(), 'allocations' => [], 'reverse_allocation_ids' => [], 'reason' => 'Book fixture']);
    }

    /** @param array<string, mixed> $filters */
    private function url(string $path = 'cashbook', ?AccountingCashAccount $binding = null, array $filters = []): string
    {
        return '/erp/accounting/'.$path.'?'.http_build_query(['company_id' => (int) $this->company->getKey(), ...($binding === null ? [] : ['binding_id' => (int) $binding->getKey()]), ...$filters]);
    }

    public function test_six_get_routes_exist_once_and_require_authentication(): void
    {
        $routes = array_filter(Route::getRoutes()->getRoutes(), static fn ($r): bool => str_starts_with((string) $r->getName(), 'erp.accounting.cashbook.') || str_starts_with((string) $r->getName(), 'erp.accounting.bankbook.'));
        $this->assertCount(6, $routes);
        $this->assertCount(6, array_unique(array_map(static fn ($r): string => $r->uri(), $routes)));
        foreach (['cashbook', 'bankbook'] as $path) {
            foreach (['', '/csv', '/print'] as $suffix) {
                $this->get('/erp/accounting/'.$path.$suffix)->assertRedirect('/login');
            }
        }
    }

    public function test_both_screens_resolve_without_silent_binding_selection(): void
    {
        $this->actingAs($this->actor);
        foreach (['cashbook' => 'cash', 'bankbook' => 'bank'] as $path => $kind) {
            $this->get($this->url($path))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/CashBooks/Book')->where('kind', $kind)->where('report', null)->has('bindings', 1)->where('bindings.0.kind', $kind));
        }
    }

    public function test_multiple_companies_require_selection_and_expose_no_bindings_yet(): void
    {
        $this->newCompany('BOOK-CHOICE');
        $this->actingAs($this->actor)->get('/erp/accounting/cashbook')->assertOk()->assertInertia(fn (Assert $page) => $page->where('company', null)->where('report', null)->has('bindings', 0)->has('companies', 2));
    }

    public function test_cash_screen_has_posted_and_source_verified_totals_and_paging(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->refund($this->approvedReverse($p));
        $this->actingAs($this->actor)->get($this->url(binding: $this->cash, filters: ['per_page' => 1, 'page' => 2]))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/CashBooks/Book')->has('report.rows', 1)->where('report.pagination.total', 2)->where('report.posted_gl.debits', '95.00')->where('report.posted_gl.credits', '95.00')->where('report.source_verified.closing', '0.00')->where('report.complete', true));
    }

    public function test_bank_screen_keeps_native_receipts_and_payments_separate_from_cash(): void
    {
        $this->bankReceipt();
        $b = $this->liveBill();
        $this->postPayment($this->ready($b, $this->paymentData($b, '40.00', '0.00', ['cash_account_id' => (int) $this->bank->getKey(), 'payment_method' => 'bank_transfer'])));
        $this->actingAs($this->actor)->get($this->url('bankbook', $this->bank))->assertOk()->assertInertia(fn (Assert $page) => $page->where('kind', 'bank')->where('report.posted_gl.closing', '80.00')->where('report.source_verified.closing', '80.00')->has('report.rows', 2));
    }

    public function test_view_only_staff_can_read_without_source_journal_or_readiness_permissions(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b));
        $this->actingAs($this->staff(['erp.cash-books.view']))->get($this->url(binding: $this->cash))->assertOk()->assertInertia(fn (Assert $page) => $page->has('companies', 1)->where('canExport', false)->where('canReadiness', false)->where('report.rows.0.source_url', null)->where('report.rows.0.journal_url', null));
    }

    public function test_readiness_view_alone_does_not_grant_book_screens(): void
    {
        $this->actingAs($this->staff(['erp.cash-accounts.view']))->get($this->url())->assertForbidden();
    }

    public function test_foreign_company_is_forbidden_for_both_screens_and_exports(): void
    {
        $foreign = $this->newCompany('BOOK-FOREIGN');
        $this->actingAs($this->staff(['erp.cash-books.view', 'erp.cash-books.export']));
        foreach (['cashbook', 'bankbook'] as $path) {
            foreach (['', '/csv', '/print'] as $suffix) {
                $this->get($this->url($path.$suffix, filters: ['company_id' => $foreign->getKey()]))->assertForbidden();
            }
        }
    }

    public function test_wrong_kind_binding_is_not_found_on_any_route(): void
    {
        $this->actingAs($this->actor);
        foreach (['', '/csv', '/print'] as $suffix) {
            $this->get($this->url('cashbook'.$suffix, $this->bank))->assertNotFound();
            $this->get($this->url('bankbook'.$suffix, $this->cash))->assertNotFound();
        }
    }

    public function test_foreign_binding_id_is_not_found_in_selected_company(): void
    {
        $foreign = $this->newCompany('BOOK-BINDING');
        $this->actingAs($this->actor)->get($this->url(binding: $this->cash, filters: ['company_id' => $foreign->getKey()]))->assertNotFound();
    }

    public function test_all_exports_need_separate_export_permission(): void
    {
        $this->actingAs($this->staff(['erp.cash-books.view']));
        foreach (['cashbook', 'bankbook'] as $path) {
            foreach (['csv', 'print'] as $format) {
                $this->get($this->url($path.'/'.$format))->assertForbidden();
            }
        }
    }

    public function test_export_permission_does_not_imply_view_permission(): void
    {
        $this->actingAs($this->staff(['erp.cash-books.export']))->get($this->url('cashbook/csv', $this->cash))->assertForbidden();
    }

    public function test_direct_export_reader_rechecks_export_authority(): void
    {
        $this->expectException(AuthorizationException::class);
        app(AccountingCashBookReader::class)->bookExport($this->staff(['erp.cash-books.view']), (int) $this->company->getKey(), (int) $this->cash->getKey());
    }

    public function test_cash_csv_contains_all_filtered_rows_from_later_page(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->refund($this->approvedReverse($p));
        $r = $this->actingAs($this->actor)->get($this->url('cashbook/csv', $this->cash, ['per_page' => 1, 'page' => 2]));
        $r->assertOk()->assertDownload('cashbook.csv');
        $csv = $r->streamedContent();
        foreach (['supplier_payment_paid', 'supplier_payment_refunded', 'Posted GL debits', 'Source verified credits', 'All filtered rows; pagination ignored', '95.00'] as $value) {
            $this->assertStringContainsString($value, $csv);
        }
    }

    public function test_bank_csv_has_fixed_filename_and_currency_scope(): void
    {
        $this->bankReceipt();
        $r = $this->actingAs($this->actor)->get($this->url('bankbook/csv', $this->bank));
        $r->assertOk()->assertDownload('bankbook.csv');
        $this->assertStringContainsString('BDT', $r->streamedContent());
        $this->assertStringContainsString('customer_receipt_received', $r->streamedContent());
    }

    public function test_full_print_contains_all_rows_and_separate_projections(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->refund($this->approvedReverse($p));
        $this->actingAs($this->actor)->get($this->url('cashbook/print', $this->cash, ['per_page' => 1, 'page' => 2]))->assertOk()->assertViewIs('erp.accounting.cash-book-print')->assertViewHas('document', fn (array $d): bool => count($d['rows']) === 2)->assertSee('Posted GL opening')->assertSee('Source verified opening')->assertSee('All filtered rows');
    }

    public function test_inactive_binding_remains_selectable_for_historical_reads(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b));
        $this->cash->update(['status' => 'inactive']);
        $this->actingAs($this->actor)->get($this->url(binding: $this->cash))->assertOk()->assertInertia(fn (Assert $page) => $page->where('bindings.0.status', 'inactive')->where('report.binding.status', 'inactive')->where('report.complete', true));
    }

    public function test_missing_manifest_review_survives_screen_csv_and_print_without_repair(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b));
        DB::table('accounting_supplier_payment_operations')->delete();
        $before = $this->readDigest();
        $this->actingAs($this->actor)->get($this->url(binding: $this->cash))->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.complete', false)->where('report.posted_gl.closing', '-95.00')->where('report.source_verified.closing', '0.00'));
        $r = $this->get($this->url('cashbook/csv', $this->cash))->assertOk();
        $this->assertStringContainsString('cash_source_integrity_or_adapter_review', $r->streamedContent());
        $this->get($this->url('cashbook/print', $this->cash))->assertOk()->assertSee('COVERAGE REVIEW REQUIRED');
        $this->assertSame($before, $this->readDigest());
    }

    public function test_excluded_gl_values_cannot_be_presented_as_complete(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b));
        DB::table('journal_entry_lines')->where('account_id', $this->mapping['cash:MAIN'])->update(['debit' => '-1.00']);
        $this->actingAs($this->actor)->get($this->url(binding: $this->cash))->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.complete', false)->where('report.posted_totals_complete', false)->where('report.excluded_posted_lines', 1));
        $this->get($this->url('cashbook/print', $this->cash))->assertOk()->assertSee('INCOMPLETE');
    }

    public function test_source_view_permission_does_not_grant_journal_link(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $user = $this->staff(['erp.cash-books.view', 'erp.supplier-payments.view']);
        $this->actingAs($user)->get($this->url(binding: $this->cash))->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.rows.0.source_url', '/erp/accounting/supplier-payments/'.$p->getKey())->where('report.rows.0.journal_url', null));
    }

    public function test_dated_export_preserves_opening_after_later_refund(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $this->refund($this->approvedReverse($p));
        $this->actingAs($this->actor)->get($this->url('cashbook/print', $this->cash, ['from' => '2026-10-17', 'to' => '2026-10-17']))->assertOk()->assertViewHas('document', fn (array $d): bool => count($d['rows']) === 1 && in_array(['Posted GL opening', '-95.00'], $d['totals'], true));
    }

    public function test_csv_and_print_exclude_private_receipt_fields(): void
    {
        $this->bankReceipt();
        $r = $this->actingAs($this->actor)->get($this->url('bankbook/csv', $this->bank))->assertOk();
        foreach (['PRIVATE-CUSTOMER', 'PRIVATE-BANK-REFERENCE', 'PRIVATE-RECEIPT-NOTES', 'payload_hash', 'source_snapshot', 'idempotency_key'] as $secret) {
            $this->assertStringNotContainsString($secret, $r->streamedContent());
        } $this->get($this->url('bankbook/print', $this->bank))->assertOk()->assertDontSee('PRIVATE-BANK-REFERENCE')->assertDontSee('PRIVATE-CUSTOMER');
    }

    public function test_formula_like_label_and_html_are_escaped(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b));
        $this->cash->update(['name' => '=1+1<script>alert(1)</script>']);
        $r = $this->actingAs($this->actor)->get($this->url('cashbook/csv', $this->cash))->assertOk();
        $this->assertStringContainsString("'=1+1", $r->streamedContent());
        $this->get($this->url('cashbook/print', $this->cash))->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_future_dates_unknown_filters_and_bad_page_sizes_are_rejected(): void
    {
        $this->actingAs($this->actor);
        foreach ([['to' => '2026-10-17'], ['bank_account_number' => '123'], ['per_page' => 101]] as $filters) {
            $this->getJson($this->url(binding: $this->cash, filters: $filters))->assertUnprocessable();
        }
    }

    public function test_export_without_required_account_or_company_returns_422(): void
    {
        $this->actingAs($this->actor)->get($this->url('cashbook/csv'))->assertUnprocessable();
        $this->newCompany('BOOK-NONE');
        $this->get('/erp/accounting/bankbook/print')->assertUnprocessable();
    }

    public function test_export_cache_and_content_type_headers_are_private(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b));
        $r = $this->actingAs($this->actor)->get($this->url('cashbook/csv', $this->cash))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        $this->assertStringContainsString('text/csv', (string) $r->headers->get('Content-Type'));
        $r = $this->get($this->url('cashbook/print', $this->cash))->assertOk();
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
    }

    public function test_all_screen_and_export_reads_leave_state_unchanged(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b));
        $this->bankReceipt();
        $before = $this->readDigest();
        $this->actingAs($this->actor);
        foreach (['cashbook' => $this->cash, 'bankbook' => $this->bank] as $path => $binding) {
            foreach (['', '/csv', '/print'] as $suffix) {
                $r = $this->get($this->url($path.$suffix, $binding))->assertOk();
                if ($suffix === '/csv') {
                    $r->streamedContent();
                }
            }
        } $this->assertSame($before, $this->readDigest());
    }

    public function test_permission_seed_is_idempotent_and_preserves_view_permission(): void
    {
        $this->seed(AccountingCashBookPermissionSeeder::class);
        $this->seed(AccountingCashBookPermissionSeeder::class);
        foreach (['erp.cash-books.view', 'erp.cash-books.export'] as $name) {
            $this->assertSame(1, Permission::query()->where('name', $name)->where('guard_name', 'web')->count());
        }
    }

    /** Clone deliberate unsupported posted GL evidence without altering original source history. */
    private function unsupportedCashJournal(string $kind, bool $offsetting = false): void
    {
        $original = AccountingSourceEffect::query()->where('source_kind', 'supplier_payment')->value('journal_entry_id');
        $header = (array) DB::table('journal_entries')->where('id', $original)->first();
        unset($header['id']);
        $header['number'] = 'BOOK-QA-'.Str::uuid();
        $header['entry_kind'] = $kind;
        $id = DB::table('journal_entries')->insertGetId($header);
        if ($offsetting) {
            $line = (array) DB::table('journal_entry_lines')->where('journal_entry_id', $original)->whereNotNull('cash_account_id')->first();
            unset($line['id']);
            $line = [...$line, 'journal_entry_id' => $id, 'line_number' => 1, 'debit' => '10.00', 'credit' => '0.00'];
            DB::table('journal_entry_lines')->insert($line);
            $line = [...$line, 'line_number' => 2, 'debit' => '0.00', 'credit' => '10.00'];
            DB::table('journal_entry_lines')->insert($line);
        } else {
            foreach (DB::table('journal_entry_lines')->where('journal_entry_id', $original)->get() as $source) {
                $line = (array) $source;
                unset($line['id']);
                $line['journal_entry_id'] = $id;
                [$line['debit'], $line['credit']] = [$line['credit'], $line['debit']];
                DB::table('journal_entry_lines')->insert($line);
            }
        }
    }

    public function test_unsupported_opening_stays_visible_with_review_in_screen_and_exports(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b));
        $this->unsupportedCashJournal('opening');
        $this->actingAs($this->actor)->get($this->url(binding: $this->cash))->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.complete', false)->where('report.posted_gl.closing', '0.00')->where('report.source_verified.closing', '-95.00'));
        $this->get($this->url('cashbook/print', $this->cash))->assertOk()->assertSee('COVERAGE REVIEW REQUIRED')->assertSee('Opening/manual/other adapters');
        $r = $this->get($this->url('cashbook/csv', $this->cash))->assertOk();
        $this->assertStringContainsString('cash_source_integrity_or_adapter_review', $r->streamedContent());
    }

    public function test_offsetting_unsupported_rows_do_not_become_verified_from_zero_closing_difference(): void
    {
        $b = $this->liveBill();
        $this->postPayment($this->ready($b));
        $this->unsupportedCashJournal('manual', true);
        $this->actingAs($this->actor)->get($this->url(binding: $this->cash))->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.complete', false)->where('report.difference.closing', '0.00')->where('report.difference.debits', '10.00')->where('report.difference.credits', '10.00'));
    }
}
