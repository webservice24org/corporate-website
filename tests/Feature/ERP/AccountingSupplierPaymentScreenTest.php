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
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ERP\Accounting\AccountingAccountService;
use App\Services\ERP\Accounting\AccountingCalendarService;
use App\Services\ERP\Accounting\AccountingCashAccountService;
use App\Services\ERP\Accounting\AccountingMappingCatalog;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\Source\AccountingReconciliationCatalog;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class AccountingSupplierPaymentScreenTest extends TestCase
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

    private function path(?SupplierPayment $payment = null, string $action = ''): string
    {
        return '/erp/accounting/supplier-payments'.($payment === null ? '' : '/'.$payment->getKey()).($action === '' ? '' : '/'.$action);
    }

    public function test_routes_are_mounted_once_at_expected_paths(): void
    {
        $expected = ['index' => 'GET', 'create' => 'GET', 'store' => 'POST', 'edit' => 'GET', 'update' => 'PUT', 'show' => 'GET', 'submit' => 'POST', 'approve' => 'POST', 'reject' => 'POST', 'cancel' => 'POST', 'post' => 'POST'];
        foreach ($expected as $name => $method) {
            $route = Route::getRoutes()->getByName('erp.accounting.supplier-payments.'.$name);
            $this->assertNotNull($route);
            $this->assertContains($method, $route->methods());
            $this->assertStringStartsWith('erp/accounting/supplier-payments', $route->uri());
        }
        $routes = array_filter(Route::getRoutes()->getRoutes(), static fn ($route): bool => str_starts_with((string) $route->getName(), 'erp.accounting.supplier-payments.'));
        $this->assertCount(23, $routes);
        foreach (['Index', 'Form', 'Show'] as $page) {
            $this->assertFileExists(resource_path('js/Pages/ERP/Accounting/SupplierPayments/'.$page.'.tsx'));
        }
    }

    public function test_register_requires_authentication_and_view_permission(): void
    {
        $this->get($this->path())->assertRedirect('/login');
        $this->actingAs($this->staff([]))->get($this->path())->assertForbidden();
    }

    public function test_register_is_scoped_and_omits_private_intent_and_evidence(): void
    {
        $bill = $this->liveBill();
        $payment = $this->payments->save($this->actor, $this->paymentData($bill));
        $this->actingAs($this->staff(['erp.supplier-payments.view']))->get($this->path())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/SupplierPayments/Index')->has('companies', 1)->has('payments.data', 1)->where('payments.data.0.id', (int) $payment->getKey())->missing('payments.data.0.notes')->missing('payments.data.0.payment_reference')->missing('payments.data.0.supplier_snapshot')->missing('payments.data.0.idempotency_key')->missing('payments.data.0.approved_payload_hash'));
    }

    public function test_explicit_foreign_company_filter_is_forbidden(): void
    {
        $foreign = $this->newCompany('FOREIGN');
        $this->actingAs($this->staff(['erp.supplier-payments.view']))->get($this->path().'?company_id='.$foreign->getKey())->assertForbidden();
    }

    public function test_foreign_payment_id_is_not_found(): void
    {
        $bill = $this->liveBill();
        $payment = $this->payments->save($this->actor, $this->paymentData($bill));
        $foreign = $this->newCompany('FOREIGN');
        $member = $this->staff(['erp.supplier-payments.view']);
        DB::table('employees')->where('user_id', $member->getKey())->update(['company_id' => $foreign->getKey()]);
        $this->actingAs($member)->get($this->path($payment))->assertNotFound();
    }

    public function test_register_filters_status_supplier_date_and_number(): void
    {
        $bill = $this->liveBill();
        $draft = $this->payments->save($this->actor, $this->paymentData($bill));
        $approved = $this->ready($bill);
        $this->actingAs($this->actor)->get($this->path().'?company_id='.$this->company->getKey().'&status=approved&supplier_id='.$bill->getAttribute('supplier_id').'&from=2026-10-16&to=2026-10-16&q='.$approved->getAttribute('number'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('payments.data', 1)->where('payments.data.0.id', (int) $approved->getKey()));
        $this->assertSame('draft', $draft->getAttribute('status'));
    }

    public function test_register_rejects_invalid_filter_dates(): void
    {
        $this->actingAs($this->actor)->get($this->path().'?from=2026-10-17&to=2026-10-16')->assertSessionHasErrors('to');
    }

    public function test_super_admin_selects_company_before_creating(): void
    {
        $this->newCompany('SECOND');
        $this->actingAs($this->actor)->get($this->path().'/create')->assertRedirect($this->path());
    }

    public function test_create_requires_its_own_permission(): void
    {
        $this->actingAs($this->staff(['erp.supplier-payments.view']))->get($this->path().'/create')->assertForbidden();
    }

    public function test_create_page_has_scoped_safe_supplier_cash_and_bill_choices(): void
    {
        $bill = $this->liveBill();
        $this->actingAs($this->actor)->get($this->path().'/create?company_id='.$this->company->getKey().'&supplier_id='.$bill->getAttribute('supplier_id'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/SupplierPayments/Form')->where('payment', null)->has('bills', 1)->where('bills.0.outstanding', '95.00')->has('cashAccounts', 2)->missing('suppliers.0.bank_account_number')->missing('suppliers.0.notes')->missing('suppliers.0.email')->missing('cashAccounts.0.account_id'));
    }

    public function test_foreign_supplier_cannot_be_used_to_load_choices(): void
    {
        $foreign = $this->newCompany('FOREIGN');
        $supplier = Supplier::query()->create(['company_id' => $foreign->getKey(), 'code' => 'F1', 'name' => 'Foreign supplier', 'currency_code' => 'BDT', 'status' => 'active']);
        $this->actingAs($this->actor)->get($this->path().'/create?company_id='.$this->company->getKey().'&supplier_id='.$supplier->getKey())->assertNotFound();
    }

    public function test_inactive_binding_is_excluded_from_new_payment_choices(): void
    {
        DB::table('accounting_cash_accounts')->where('id', $this->cash->getKey())->update(['status' => 'inactive']);
        $this->actingAs($this->actor)->get($this->path().'/create?company_id='.$this->company->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->has('cashAccounts', 1)->where('cashAccounts.0.id', (int) $this->bank->getKey()));
    }

    public function test_http_store_saves_draft_without_posting(): void
    {
        $bill = $this->liveBill();
        $this->actingAs($this->actor)->post($this->path(), $this->paymentData($bill))->assertRedirect();
        $payment = SupplierPayment::query()->firstOrFail();
        $this->assertSame('draft', $payment->getAttribute('status'));
        $this->assertDatabaseCount('journal_entries', 2);
        $this->assertSame('0.00', $this->paid($bill));
    }

    public function test_http_store_rejects_non_exact_amounts(): void
    {
        $bill = $this->liveBill();
        $this->actingAs($this->actor)->post($this->path(), $this->paymentData($bill, overrides: ['amount' => 95.0]))->assertSessionHasErrors();
        $this->assertDatabaseCount('supplier_payments', 0);
    }

    public function test_show_page_has_safe_history_and_saved_allocation_plan(): void
    {
        $bill = $this->liveBill();
        $payment = $this->ready($bill);
        $this->actingAs($this->actor)->get($this->path($payment))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/SupplierPayments/Show')->where('payment.payment_reference', 'PRIVATE-PAYMENT-REFERENCE')->has('targets', 1)->has('history', 3)->missing('history.0.old_values')->missing('history.0.new_values')->missing('payment.payload_hash')->missing('payment.idempotency_key')->missing('payment.supplier_snapshot')->where('capabilities.edit', false)->where('capabilities.post', true));
    }

    public function test_edit_draft_preserves_inactive_historical_binding_as_unselectable(): void
    {
        $bill = $this->liveBill();
        $payment = $this->payments->save($this->actor, $this->paymentData($bill));
        DB::table('accounting_cash_accounts')->where('id', $this->cash->getKey())->update(['status' => 'inactive']);
        $this->actingAs($this->actor)->get($this->path($payment, 'edit'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/SupplierPayments/Form')->where('payment.id', (int) $payment->getKey())->has('cashAccounts', 2)->where('cashAccounts', fn ($accounts) => collect($accounts)->contains(fn ($account) => $account['id'] === (int) $this->cash->getKey() && $account['selectable'] === false)));
    }

    public function test_submitted_payment_cannot_open_edit_page(): void
    {
        $bill = $this->liveBill();
        $payment = $this->payments->save($this->actor, $this->paymentData($bill));
        $payment = $this->payments->submit($this->actor, (int) $payment->getKey(), 1);
        $this->actingAs($this->actor)->get($this->path($payment, 'edit'))->assertForbidden();
    }

    public function test_http_update_respects_revision_and_immutable_supplier_date(): void
    {
        $bill = $this->liveBill();
        $payment = $this->payments->save($this->actor, $this->paymentData($bill));
        $data = $this->paymentData($bill, overrides: ['revision' => 1, 'notes' => 'Updated']);
        $this->actingAs($this->actor)->put($this->path($payment), $data)->assertRedirect();
        $this->assertSame(2, $payment->fresh()->getAttribute('revision'));
        $this->put($this->path($payment), $data)->assertSessionHasErrors();
        $this->put($this->path($payment), [...$data, 'revision' => 2, 'document_date' => '2026-10-15'])->assertSessionHasErrors();
    }

    public function test_submit_and_independent_approve_http_actions(): void
    {
        $bill = $this->liveBill();
        $payment = $this->payments->save($this->actor, $this->paymentData($bill));
        $this->actingAs($this->actor)->post($this->path($payment, 'submit'), ['revision' => 1])->assertRedirect();
        $this->post($this->path($payment, 'approve'), ['revision' => 2])->assertForbidden();
        $this->actingAs($this->reviewer)->post($this->path($payment, 'approve'), ['revision' => 2])->assertRedirect();
        $this->assertSame('approved', $payment->fresh()->getAttribute('status'));
        $this->assertDatabaseCount('journal_entries', 2);
    }

    public function test_maker_cannot_see_approve_but_independent_reviewer_can(): void
    {
        $bill = $this->liveBill();
        $payment = $this->payments->save($this->actor, $this->paymentData($bill));
        $payment = $this->payments->submit($this->actor, (int) $payment->getKey(), 1);
        $this->actingAs($this->actor)->get($this->path($payment))->assertInertia(fn (Assert $page) => $page->where('capabilities.approve', false));
        $this->actingAs($this->reviewer)->get($this->path($payment))->assertInertia(fn (Assert $page) => $page->where('capabilities.approve', true));
    }

    public function test_rejection_requires_reason_and_returns_to_draft(): void
    {
        $bill = $this->liveBill();
        $payment = $this->payments->save($this->actor, $this->paymentData($bill));
        $payment = $this->payments->submit($this->actor, (int) $payment->getKey(), 1);
        $this->actingAs($this->reviewer)->post($this->path($payment, 'reject'), ['revision' => 2])->assertSessionHasErrors('reason');
        $this->post($this->path($payment, 'reject'), ['revision' => 2, 'reason' => 'Correct payment reference'])->assertRedirect();
        $this->assertSame('draft', $payment->fresh()->getAttribute('status'));
    }

    public function test_cancel_is_reasoned_and_never_spends_cash(): void
    {
        $bill = $this->liveBill();
        $payment = $this->ready($bill);
        $this->actingAs($this->actor)->post($this->path($payment, 'cancel'), ['revision' => 3, 'reason' => 'Payment no longer needed'])->assertRedirect();
        $this->assertSame('cancelled', $payment->fresh()->getAttribute('status'));
        $this->assertDatabaseCount('journal_entries', 2);
    }

    public function test_post_http_action_records_whole_batch_and_retry_does_not_duplicate(): void
    {
        $bill = $this->liveBill();
        $payment = $this->ready($bill);
        $data = ['revision' => 3, 'key' => (string) Str::uuid()];
        $this->actingAs($this->actor)->post($this->path($payment, 'post'), $data)->assertRedirect();
        $before = $this->digestState();
        $this->post($this->path($payment, 'post'), $data)->assertRedirect();
        $this->assertSame($before, $this->digestState());
        $this->assertSame('95.00', $this->paid($bill));
        $this->get($this->path($payment))->assertInertia(fn (Assert $page) => $page->where('capabilities.post', false)->has('allocations', 1)->where('postingKey', null));
    }

    public function test_allocated_payment_post_requires_allocation_permission_in_ui_and_writer(): void
    {
        $bill = $this->liveBill();
        $payment = $this->ready($bill);
        $user = $this->staff(['erp.supplier-payments.view', 'erp.supplier-payments.post']);
        $this->actingAs($user)->get($this->path($payment))->assertInertia(fn (Assert $page) => $page->where('capabilities.post', false));
        $this->post($this->path($payment, 'post'), ['revision' => 3, 'key' => (string) Str::uuid()])->assertForbidden();
        $this->assertSame('0.00', $this->paid($bill));
    }

    public function test_cash_only_payment_post_does_not_require_allocation_or_manual_journal_permission(): void
    {
        $bill = $this->liveBill();
        $payment = $this->ready($bill, $this->paymentData($bill, '20.00', '0.00'));
        $user = $this->staff(['erp.supplier-payments.view', 'erp.supplier-payments.post']);
        $this->assertFalse($user->can('erp.journal-entries.post'));
        $this->actingAs($user)->get($this->path($payment))->assertInertia(fn (Assert $page) => $page->where('capabilities.post', true));
        $this->post($this->path($payment, 'post'), ['revision' => 3, 'key' => (string) Str::uuid()])->assertRedirect();
        $this->assertSame('0.00', $this->paid($bill));
    }

    public function test_disabled_accounting_retains_draft_ui_but_blocks_post_action(): void
    {
        $bill = $this->liveBill();
        $payment = $this->ready($bill);
        $this->settings(['state' => 'disabled']);
        $this->actingAs($this->actor)->get($this->path($payment))->assertInertia(fn (Assert $page) => $page->where('capabilities.post', false));
        $this->post($this->path($payment, 'post'), ['revision' => 3, 'key' => (string) Str::uuid()])->assertSessionHasErrors();
        $this->get($this->path().'/create?company_id='.$this->company->getKey())->assertOk();
    }

    public function test_cutover_lock_blocks_create_and_hides_workflow_actions(): void
    {
        $bill = $this->liveBill();
        $payment = $this->ready($bill);
        $this->settings(['cutover_locked_at' => now()]);
        $this->actingAs($this->actor)->get($this->path().'/create?company_id='.$this->company->getKey())->assertRedirect();
        $this->get($this->path($payment))->assertInertia(fn (Assert $page) => $page->where('writable', false)->where('capabilities.post', false)->where('capabilities.cancel', false));
    }

    public function test_view_only_user_cannot_call_write_routes(): void
    {
        $bill = $this->liveBill();
        $payment = $this->ready($bill);
        $this->actingAs($this->staff(['erp.supplier-payments.view']));
        foreach (['submit', 'approve', 'reject', 'cancel', 'post'] as $action) {
            $this->post($this->path($payment, $action), ['revision' => 3, 'reason' => 'Denied', 'key' => (string) Str::uuid()])->assertForbidden();
        }
        $this->post($this->path(), $this->paymentData($bill))->assertForbidden();
        $this->put($this->path($payment), $this->paymentData($bill, overrides: ['revision' => 3]))->assertForbidden();
    }

    public function test_detail_does_not_offer_inaccessible_bill_links(): void
    {
        $bill = $this->liveBill();
        $payment = $this->ready($bill);
        $this->actingAs($this->staff(['erp.supplier-payments.view']))->get($this->path($payment))->assertInertia(fn (Assert $page) => $page->where('canViewBills', false));
    }

    public function test_get_pages_do_not_write_payment_or_financial_history(): void
    {
        $bill = $this->liveBill();
        $payment = $this->payments->save($this->actor, $this->paymentData($bill));
        $before = $this->digestState();
        $this->actingAs($this->actor)->get($this->path().'?company_id='.$this->company->getKey())->assertOk();
        $this->get($this->path($payment))->assertOk();
        $this->get($this->path($payment, 'edit'))->assertOk();
        $this->assertSame($before, $this->digestState());
    }

    public function test_source_catalog_links_payment_header_under_its_own_view_permission(): void
    {
        $definitions = app(AccountingReconciliationCatalog::class)->definitions();
        $this->assertSame('erp.supplier-payments.view', $definitions['supplier_payment']['permission']);
        $this->assertSame('/erp/accounting/supplier-payments/', $definitions['supplier_payment']['path']);
        $this->assertNull($definitions['supplier_payment_allocation']['path']);
    }
}
