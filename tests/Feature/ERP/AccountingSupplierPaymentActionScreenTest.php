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

final class AccountingSupplierPaymentActionScreenTest extends TestCase
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

    private function path(SupplierPayment $p, string $suffix = ''): string
    {
        return '/erp/accounting/supplier-payments/'.$p->getKey().($suffix === '' ? '' : '/'.$suffix);
    }

    private function reviewPath(SupplierPaymentReversalRequest $row, string $suffix = ''): string
    {
        return '/erp/accounting/supplier-payments/reversal-requests/'.$row->getKey().($suffix === '' ? '' : '/'.$suffix);
    }

    /** @return array<string, mixed> */
    private function prepareInput(): array
    {
        return ['document_date' => now('Asia/Dhaka')->toDateString(), 'reason' => 'PRIVATE-HTTP-REFUND-REASON'];
    }

    public function test_all_action_routes_are_mounted_once_and_components_exist(): void
    {
        $expected = ['allocations.edit' => 'GET', 'allocations.store' => 'POST', 'reversal-requests.create' => 'GET', 'reversal-requests.store' => 'POST', 'reversal-requests.edit' => 'GET', 'reversal-requests.update' => 'PUT', 'reversal-requests.show' => 'GET', 'reversal-requests.submit' => 'POST', 'reversal-requests.approve' => 'POST', 'reversal-requests.reject' => 'POST', 'reversal-requests.cancel' => 'POST', 'reversal-requests.post' => 'POST'];
        foreach ($expected as $name => $method) {
            $route = Route::getRoutes()->getByName('erp.accounting.supplier-payments.'.$name);
            $this->assertNotNull($route);
            $this->assertContains($method, $route->methods());
            $this->assertStringStartsWith('erp/accounting/supplier-payments/', $route->uri());
        }
        $routes = array_filter(Route::getRoutes()->getRoutes(), static fn ($route): bool => str_starts_with((string) $route->getName(), 'erp.accounting.supplier-payments.'));
        $this->assertCount(23, $routes);
        foreach (['Index', 'Form', 'Show', 'Allocations', 'ReversalForm', 'ReversalShow'] as $page) {
            $this->assertFileExists(resource_path('js/Pages/ERP/Accounting/SupplierPayments/'.$page.'.tsx'));
        }
    }

    public function test_action_pages_require_authentication_and_view_plus_action_authority(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->get($this->path($p, 'allocations/edit'))->assertRedirect('/login');
        $this->actingAs($this->staff(['erp.supplier-payments.view']))->get($this->path($p, 'allocations/edit'))->assertForbidden();
        $this->get($this->path($p, 'reversal-requests/create'))->assertForbidden();
    }

    public function test_allocation_page_uses_verified_position_and_scoped_bills(): void
    {
        $b = $this->liveBill();
        $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $this->actingAs($this->actor)->get($this->path($p, 'allocations/edit'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/SupplierPayments/Allocations')->where('position.available', '100.00')->where('financeVerified', true)->has('bills', 1)->where('bills.0.id', (int) $b->getKey())->where('bills.0.selectable', true)->missing('payment.payload_hash')->missing('payment.idempotency_key')->missing('bills.0.notes'));
    }

    public function test_allocate_only_staff_with_view_can_use_page_and_post_without_manual_authority(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $member = $this->staff(['erp.supplier-payments.view', 'erp.supplier-payments.allocate']);
        $data = $this->requestFor($p, [$this->target($b, '20.00')]);
        $this->actingAs($member)->get($this->path($p, 'allocations/edit'))->assertOk();
        $this->post($this->path($p, 'allocations'), $data)->assertRedirect($this->path($p));
        $this->assertSame('20.00', $this->paid($b));
        $this->assertFalse($member->can('erp.journal-entries.post'));
    }

    public function test_http_compensation_and_reallocation_is_atomic_and_retry_is_read_only(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $data = $this->requestFor($p, [$this->target($b, '40.00')], [$this->position($p)['active'][0]['id']]);
        $this->actingAs($this->actor)->post($this->path($p, 'allocations'), $data)->assertRedirect();
        $before = $this->digestState();
        $this->post($this->path($p, 'allocations'), $data)->assertRedirect();
        $this->assertSame($before, $this->digestState());
        $this->assertSame('40.00', $this->paid($b));
    }

    public function test_detail_labels_compensated_originals_and_signed_corrections(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $original = $this->position($p)['active'][0]['id'];
        $this->change($p, $this->requestFor($p, [], [$original]));
        $this->actingAs($this->actor)->get($this->path($p))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/SupplierPayments/Show')->where('payment.kind', 'payment')->where('position.available', '95.00')->where('allocations.0.kind', 'allocation')->where('allocations.0.is_active', false)->where('allocations.1.kind', 'compensation')->where('allocations.1.original_allocation_id', $original));
    }

    public function test_float_money_and_unsupported_cash_fields_are_rejected_without_writes(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $data = $this->requestFor($p, [$this->target($b, '20.00')]);
        $before = $this->digestState();
        $this->actingAs($this->actor)->post($this->path($p, 'allocations'), [...$data, 'cash_account_id' => $this->bank->getKey()])->assertSessionHasErrors('operation');
        $this->post($this->path($p, 'allocations'), [...$data, 'targets' => [['supplier_bill_id' => $b->getKey(), 'amount' => 20.0]]])->assertSessionHasErrors();
        $this->assertSame($before, $this->digestState());
    }

    public function test_http_target_ids_are_normalized_without_converting_money(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $data = $this->requestFor($p, [$this->target($b, '20.00')]);
        $data['revision'] = (string) $data['revision'];
        $data['targets'][0]['supplier_bill_id'] = (string) $b->getKey();
        $this->actingAs($this->actor)->post($this->path($p, 'allocations'), $data)->assertRedirect();
        $this->assertSame('20.00', $this->paid($b));
    }

    public function test_stale_history_token_is_rejected_by_http_writer(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $stale = $this->requestFor($p, [$this->target($b, '10.00')]);
        $this->change($p, $this->requestFor($p, [$this->target($b, '20.00')]));
        $before = $this->digestState();
        $this->actingAs($this->actor)->post($this->path($p, 'allocations'), $stale)->assertSessionHasErrors();
        $this->assertSame($before, $this->digestState());
    }

    public function test_foreign_actor_cannot_read_or_write_owning_company_action_pages(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $data = $this->requestFor($p, [$this->target($b, '10.00')]);
        $user = $this->staff(['erp.supplier-payments.view', 'erp.supplier-payments.allocate', 'erp.supplier-payments.reverse']);
        $foreign = $this->newCompany('HTTP-FOREIGN');
        DB::table('employees')->where('user_id', $user->getKey())->update(['company_id' => $foreign->getKey()]);
        $this->actingAs($user)->get($this->path($p, 'allocations/edit'))->assertNotFound();
        $this->post($this->path($p, 'allocations'), $data)->assertNotFound();
        $this->get($this->path($p, 'reversal-requests/create'))->assertNotFound();
    }

    public function test_unrecognized_legacy_ap_is_visible_but_not_selectable(): void
    {
        $this->settings(['state' => 'disabled']);
        $b = $this->liveBill();
        $this->settings(['state' => 'enabled']);
        $p = $this->postPayment($this->ready($b, $this->paymentData($b, '100.00', '0.00')));
        $this->actingAs($this->actor)->get($this->path($p, 'allocations/edit'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('bills', 1)->where('bills.0.selectable', false));
    }

    public function test_disabled_accounting_hides_new_allocation_but_allows_reversal_review_preparation(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->settings(['state' => 'disabled']);
        $this->actingAs($this->actor)->get($this->path($p))->assertInertia(fn (Assert $page) => $page->where('actionCapabilities.allocate', false)->where('actionCapabilities.prepareReversal', true));
        $this->get($this->path($p, 'allocations/edit'))->assertRedirect($this->path($p));
        $this->get($this->path($p, 'reversal-requests/create'))->assertOk();
    }

    public function test_preparation_form_and_review_detail_exist_and_omit_private_native_evidence(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->actingAs($this->actor)->get($this->path($p, 'reversal-requests/create'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/SupplierPayments/ReversalForm')->where('reversalRequest', null));
        $r = $this->prepareReverse($p);
        $this->get($this->reviewPath($r))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/SupplierPayments/ReversalShow')->where('reversalRequest.id', (int) $r->getKey())->missing('reversalRequest.history_hash')->missing('reversalRequest.original_payload_hash')->missing('reversalRequest.approved_payload_hash')->missing('reversalRequest.idempotency_key'));
    }

    public function test_http_prepare_creates_only_review_draft_and_reserves_original_history(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->actingAs($this->actor)->post($this->path($p, 'reversal-requests'), $this->prepareInput())->assertRedirect();
        $r = SupplierPaymentReversalRequest::query()->firstOrFail();
        $this->assertSame('draft', $r->getAttribute('status'));
        $this->assertDatabaseCount('supplier_payments', 1);
        $this->get($this->path($p))->assertInertia(fn (Assert $page) => $page->where('pendingReversalId', (int) $r->getKey())->where('actionCapabilities.allocate', false));
    }

    public function test_reversal_draft_can_edit_reason_and_current_business_date_with_revision(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->prepareReverse($p);
        $this->actingAs($this->actor)->get($this->reviewPath($r, 'edit'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/SupplierPayments/ReversalForm'));
        $this->put($this->reviewPath($r), [...$this->prepareInput(), 'revision' => '1'])->assertRedirect($this->reviewPath($r));
        $this->assertSame(2, $r->fresh()->getAttribute('revision'));
    }

    public function test_submitted_review_cannot_open_edit_screen(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->prepareReverse($p);
        $r = $this->reversals()->submit($this->actor, (int) $r->getKey(), 1);
        $this->actingAs($this->actor)->get($this->reviewPath($r, 'edit'))->assertForbidden();
    }

    public function test_submit_and_independent_approve_actions_are_wired_without_cash_changes(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->prepareReverse($p);
        $this->actingAs($this->actor)->post($this->reviewPath($r, 'submit'), ['revision' => 1])->assertRedirect();
        $this->get($this->reviewPath($r))->assertInertia(fn (Assert $page) => $page->where('reversalCapabilities.approve', false));
        $this->post($this->reviewPath($r, 'approve'), ['revision' => 2])->assertForbidden();
        $this->actingAs($this->reviewer)->get($this->reviewPath($r))->assertInertia(fn (Assert $page) => $page->where('reversalCapabilities.approve', true));
        $this->post($this->reviewPath($r, 'approve'), ['revision' => 2])->assertRedirect();
        $this->assertDatabaseCount('journal_entries', 4);
    }

    public function test_approve_only_staff_with_view_can_review_without_reverse_authority(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->prepareReverse($p);
        $r = $this->reversals()->submit($this->actor, (int) $r->getKey(), 1);
        $user = $this->staff(['erp.supplier-payments.view', 'erp.supplier-payments.approve']);
        $this->actingAs($user)->get($this->reviewPath($r))->assertOk()->assertInertia(fn (Assert $page) => $page->where('reversalCapabilities.approve', true)->where('reversalCapabilities.post', false));
        $this->post($this->reviewPath($r, 'approve'), ['revision' => 2])->assertRedirect();
    }

    public function test_http_rejection_requires_reason_and_returns_to_draft(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->prepareReverse($p);
        $r = $this->reversals()->submit($this->actor, (int) $r->getKey(), 1);
        $this->actingAs($this->reviewer)->post($this->reviewPath($r, 'reject'), ['revision' => 2])->assertSessionHasErrors('reason');
        $this->post($this->reviewPath($r, 'reject'), ['revision' => 2, 'reason' => 'Review the source evidence'])->assertRedirect();
        $this->assertSame('draft', $r->fresh()->getAttribute('status'));
    }

    public function test_http_cancel_releases_pending_reservation(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->approvedReverse($p);
        $this->actingAs($this->actor)->post($this->reviewPath($r, 'cancel'), ['revision' => 3, 'reason' => 'Retain original payment'])->assertRedirect();
        $this->get($this->path($p))->assertInertia(fn (Assert $page) => $page->where('pendingReversalId', null)->where('actionCapabilities.allocate', true)->where('reversalRequests.0.status', 'cancelled'));
    }

    public function test_http_full_reversal_posts_exact_batch_and_retry_has_no_new_writes(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->approvedReverse($p);
        $data = ['revision' => 3, 'key' => (string) Str::uuid()];
        $this->actingAs($this->actor)->post($this->reviewPath($r, 'post'), $data)->assertRedirect($this->reviewPath($r));
        $before = $this->digestState();
        $this->post($this->reviewPath($r, 'post'), $data)->assertRedirect();
        $this->assertSame($before, $this->digestState());
        $this->assertSame('0.00', $this->paid($b));
    }

    public function test_original_and_reversal_detail_pages_link_each_other_and_show_zero_remaining_cash(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->refund($this->approvedReverse($p));
        $cash = SupplierPayment::query()->findOrFail((int) $r->getAttribute('reversal_payment_id'));
        $this->actingAs($this->actor)->get($this->path($p))->assertOk()->assertInertia(fn (Assert $page) => $page->where('linkedReversal.id', (int) $cash->getKey())->where('position.available', '0.00')->where('position.cash_reversed', true)->where('actionCapabilities.allocate', false));
        $this->get($this->path($cash))->assertOk()->assertInertia(fn (Assert $page) => $page->where('payment.kind', 'reversal')->where('originalPayment.id', (int) $p->getKey())->where('actionCapabilities.prepareReversal', false));
    }

    public function test_register_kind_filter_distinguishes_full_reversal_documents(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->refund($this->approvedReverse($p));
        $this->actingAs($this->actor)->get('/erp/accounting/supplier-payments?company_id='.$this->company->getKey().'&kind=reversal')->assertOk()->assertInertia(fn (Assert $page) => $page->has('payments.data', 1)->where('payments.data.0.id', (int) $r->getAttribute('reversal_payment_id'))->where('payments.data.0.kind', 'reversal'));
    }

    public function test_view_only_request_page_exposes_no_write_capabilities_or_native_post_key(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->approvedReverse($p);
        $this->actingAs($this->staff(['erp.supplier-payments.view']))->get($this->reviewPath($r))->assertOk()->assertInertia(fn (Assert $page) => $page->where('reversalCapabilities.post', false)->where('reversalCapabilities.edit', false)->where('reversalPostingKey', null)->missing('reversalRequest.idempotency_key'));
        $this->post($this->reviewPath($r, 'post'), ['revision' => 3, 'key' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_expired_approved_date_hides_post_but_retains_cancel_action(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->approvedReverse($p);
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $this->actingAs($this->actor)->get($this->reviewPath($r))->assertOk()->assertInertia(fn (Assert $page) => $page->where('reversalDateCurrent', false)->where('reversalCapabilities.post', false)->where('reversalCapabilities.cancel', true));
    }

    public function test_disabled_settings_prevent_posted_cash_reversal_from_http(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->approvedReverse($p);
        $this->settings(['state' => 'disabled']);
        $before = $this->digestState();
        $this->actingAs($this->actor)->get($this->reviewPath($r))->assertInertia(fn (Assert $page) => $page->where('reversalCapabilities.post', false));
        $this->post($this->reviewPath($r, 'post'), ['revision' => 3, 'key' => (string) Str::uuid()])->assertSessionHasErrors();
        $this->assertSame($before, $this->digestState());
    }

    public function test_inactive_original_binding_is_displayed_and_full_reversal_remains_available(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->approvedReverse($p);
        $this->cash->update(['status' => 'inactive']);
        $this->actingAs($this->actor)->get($this->reviewPath($r))->assertOk()->assertInertia(fn (Assert $page) => $page->where('cashAccount.status', 'inactive')->where('reversalCapabilities.post', true));
    }

    public function test_damage_disables_financial_actions_without_leaking_raw_errors(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        DB::table('accounting_source_effects')->where('source_kind', 'supplier_payment_allocation')->delete();
        $this->actingAs($this->actor)->get($this->path($p))->assertOk()->assertInertia(fn (Assert $page) => $page->where('financeVerified', false)->where('position', null)->where('actionCapabilities.allocate', false)->where('actionCapabilities.prepareReversal', false)->has('financeIssues', 1));
    }

    public function test_journal_links_require_journal_view_permission_and_raw_evidence_stays_hidden(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $this->actingAs($this->staff(['erp.supplier-payments.view']))->get($this->path($p))->assertOk()->assertInertia(fn (Assert $page) => $page->has('finance', 2)->where('finance.0.journal_url', null)->missing('finance.0.source_snapshot')->missing('finance.0.payload_hash')->missing('finance.0.journal_entry_id'));
        $this->actingAs($this->actor)->get($this->path($p))->assertInertia(fn (Assert $page) => $page->where('finance.0.journal_url', fn ($url) => is_string($url) && str_starts_with($url, '/erp/accounting/journals/')));
    }

    public function test_foreign_reversal_request_id_cannot_be_viewed_or_approved(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->prepareReverse($p);
        $user = $this->staff(['erp.supplier-payments.view', 'erp.supplier-payments.approve', 'erp.supplier-payments.reverse']);
        $foreign = $this->newCompany('REVIEW-FOREIGN');
        DB::table('employees')->where('user_id', $user->getKey())->update(['company_id' => $foreign->getKey()]);
        $this->actingAs($user)->get($this->reviewPath($r))->assertNotFound();
        $this->post($this->reviewPath($r, 'approve'), ['revision' => 1])->assertNotFound();
    }

    public function test_posted_review_and_reversal_header_offer_no_edit_or_new_financial_actions(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->refund($this->approvedReverse($p));
        $this->actingAs($this->actor)->get($this->reviewPath($r))->assertOk()->assertInertia(fn (Assert $page) => $page->where('reversalCapabilities.edit', false)->where('reversalCapabilities.post', false)->where('reversalPostingKey', null));
        $this->get($this->reviewPath($r, 'edit'))->assertForbidden();
        $this->get($this->path($p, 'reversal-requests/create'))->assertRedirect($this->path($p));
    }

    public function test_reversal_prepare_rejects_extra_amount_and_binding_fields(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $before = $this->digestState();
        $this->actingAs($this->actor)->post($this->path($p, 'reversal-requests'), [...$this->prepareInput(), 'amount' => '1.00', 'cash_account_id' => $this->bank->getKey()])->assertSessionHasErrors('operation');
        $this->assertSame($before, $this->digestState());
    }

    public function test_get_action_and_review_pages_do_not_write_native_or_financial_history(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $before = $this->digestState();
        $this->actingAs($this->actor)->get($this->path($p, 'allocations/edit'))->assertOk();
        $this->get($this->path($p, 'reversal-requests/create'))->assertOk();
        $this->assertSame($before, $this->digestState());
        $r = $this->approvedReverse($p);
        $before = $this->digestState();
        $this->get($this->reviewPath($r))->assertOk();
        $this->assertSame($before, $this->digestState());
    }

    public function test_cutover_lock_makes_review_and_payment_actions_read_only(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->approvedReverse($p);
        $this->settings(['cutover_locked_at' => now()]);
        $this->actingAs($this->actor)->get($this->reviewPath($r))->assertOk()->assertInertia(fn (Assert $page) => $page->where('reversalCapabilities.post', false)->where('reversalCapabilities.cancel', false));
        $this->get($this->path($p))->assertInertia(fn (Assert $page) => $page->where('actionCapabilities.allocate', false)->where('actionCapabilities.prepareReversal', false));
    }

    public function test_submitted_reversal_queue_is_visible_to_scoped_approvers_without_private_payloads(): void
    {
        $b = $this->liveBill();
        $p = $this->postPayment($this->ready($b));
        $r = $this->prepareReverse($p);
        $r = $this->reversals()->submit($this->actor, (int) $r->getKey(), 1);
        $this->actingAs($this->staff(['erp.supplier-payments.view', 'erp.supplier-payments.approve']))->get('/erp/accounting/supplier-payments?status=submitted')->assertOk()->assertInertia(fn (Assert $page) => $page->has('reversalQueue.data', 1)->where('reversalQueue.data.0.id', (int) $r->getKey())->where('reversalQueue.data.0.original_number', (string) $p->getAttribute('number'))->missing('reversalQueue.data.0.reason')->missing('reversalQueue.data.0.history_hash'));
    }
}
