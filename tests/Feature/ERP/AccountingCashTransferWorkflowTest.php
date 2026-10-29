<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingCashTransfer;
use App\Models\Accounting\AccountingCashTransferAuditEvent;
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
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferContract;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferIntent;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferWorkflow;
use App\Services\ERP\Purchase\GoodsReceiptService;
use App\Services\ERP\Purchase\PurchaseReturnService;
use App\Services\ERP\Purchase\SupplierBillService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingCashBankPermissionSeeder;
use Database\Seeders\AccountingCashTransferPermissionSeeder;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\AccountingPurchasePermissionSeeder;
use Database\Seeders\AccountingSupplierPaymentPermissionSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class AccountingCashTransferWorkflowTest extends TestCase
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
        $this->seed(AccountingCashTransferPermissionSeeder::class);
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

    private function transfers(): AccountingCashTransferWorkflow
    {
        return app(AccountingCashTransferWorkflow::class);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function transferData(array $overrides = []): array
    {
        return ['company_id' => (int) $this->company->getKey(), 'document_date' => '2026-10-16', 'source_cash_account_id' => (int) $this->cash->getKey(), 'destination_cash_account_id' => (int) $this->bank->getKey(), 'amount' => '100.00', 'purpose' => 'PRIVATE-TRANSFER-PURPOSE', 'notes' => 'PRIVATE-TRANSFER-NOTES', 'key' => (string) Str::uuid(), ...$overrides];
    }

    /** @param array<string, mixed> $overrides */
    private function draftTransfer(array $overrides = []): AccountingCashTransfer
    {
        return $this->transfers()->save($this->actor, $this->transferData($overrides));
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function editData(AccountingCashTransfer $row, array $overrides = []): array
    {
        $data = $this->transferData();
        unset($data['key']);

        return [...$data, 'revision' => (int) $row->getAttribute('revision'), ...$overrides];
    }

    private function submittedTransfer(): AccountingCashTransfer
    {
        $row = $this->draftTransfer();

        return $this->transfers()->submit($this->actor, (int) $row->getKey(), 1);
    }

    private function transferDigest(): string
    {
        $parts = ['existing' => $this->readDigest()];
        foreach (['accounting_cash_transfers', 'accounting_cash_transfer_sequences', 'accounting_cash_transfer_audit_events'] as $table) {
            $parts[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }

        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    private function rejectTransfer(callable $action): void
    {
        $before = $this->transferDigest();
        try {
            $action();
            $this->fail('Expected rejected transfer request.');
        } catch (ValidationException|AuthorizationException|ModelNotFoundException|\LogicException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        } $this->assertSame($before, $this->transferDigest());
    }

    public function test_create_submit_approve_and_cancel_do_not_move_money(): void
    {
        $before = $this->readDigest();
        $row = $this->draftTransfer();
        $this->assertSame('CT-2026-000001', $row->getAttribute('number'));
        $row = $this->transfers()->submit($this->actor, (int) $row->getKey(), 1);
        $row = $this->transfers()->approve($this->reviewer, (int) $row->getKey(), 2);
        $this->assertSame('approved', $row->getAttribute('status'));
        $this->assertSame(3, $row->getAttribute('revision'));
        $row = $this->transfers()->cancel($this->actor, (int) $row->getKey(), 3, 'Not required');
        $this->assertSame('cancelled', $row->getAttribute('status'));
        $this->assertSame($before, $this->readDigest());
        $this->assertDatabaseCount('accounting_cash_transfer_audit_events', 4);
    }

    public function test_same_creation_request_replays_without_new_rows_or_number(): void
    {
        $data = $this->transferData();
        $row = $this->transfers()->save($this->actor, $data);
        $before = $this->transferDigest();
        $again = $this->transfers()->save($this->actor, $data);
        $this->assertSame($row->getKey(), $again->getKey());
        $this->assertSame($before, $this->transferDigest());
    }

    public function test_creation_key_conflict_is_rejected(): void
    {
        $data = $this->transferData();
        $this->transfers()->save($this->actor, $data);
        $this->rejectTransfer(fn () => $this->transfers()->save($this->actor, [...$data, 'amount' => '101.00']));
    }

    public function test_creation_key_cannot_be_claimed_by_another_maker(): void
    {
        $data = $this->transferData();
        $this->transfers()->save($this->actor, $data);
        $other = $this->staff(['erp.cash-transfers.create']);
        $this->rejectTransfer(fn () => $this->transfers()->save($other, $data));
    }

    public function test_creation_replay_survives_cancelled_record_and_cutover_lock(): void
    {
        $data = $this->transferData();
        $row = $this->transfers()->save($this->actor, $data);
        $this->transfers()->cancel($this->actor, (int) $row->getKey(), 1, 'Cancelled');
        $this->settings(['cutover_locked_at' => now()]);
        $before = $this->transferDigest();
        $again = $this->transfers()->save($this->actor, $data);
        $this->assertSame('cancelled', $again->getAttribute('status'));
        $this->assertSame($before, $this->transferDigest());
    }

    public function test_draft_edit_changes_revision_and_private_approval_intent(): void
    {
        $row = $this->draftTransfer();
        $hash = app(AccountingCashTransferIntent::class)->hash($row);
        $row = $this->transfers()->save($this->actor, $this->editData($row, ['amount' => '75.00', 'notes' => 'Changed private context']), (int) $row->getKey());
        $this->assertSame(2, $row->getAttribute('revision'));
        $this->assertNotSame($hash, app(AccountingCashTransferIntent::class)->hash($row));
    }

    public function test_numbered_date_cannot_change(): void
    {
        $row = $this->draftTransfer();
        $this->rejectTransfer(fn () => $this->transfers()->save($this->actor, $this->editData($row, ['document_date' => '2026-10-15']), (int) $row->getKey()));
    }

    public function test_maker_cannot_approve_own_transfer(): void
    {
        $row = $this->submittedTransfer();
        $this->rejectTransfer(fn () => $this->transfers()->approve($this->actor, (int) $row->getKey(), 2));
    }

    public function test_last_editor_cannot_approve_transfer(): void
    {
        $row = $this->draftTransfer();
        $row = $this->transfers()->save($this->reviewer, $this->editData($row), (int) $row->getKey());
        $row = $this->transfers()->submit($this->actor, (int) $row->getKey(), 2);
        $this->rejectTransfer(fn () => $this->transfers()->approve($this->reviewer, (int) $row->getKey(), 3));
    }

    public function test_submitter_cannot_approve_even_when_not_maker_or_editor(): void
    {
        $row = $this->draftTransfer();
        $user = $this->staff(['erp.cash-transfers.submit', 'erp.cash-transfers.approve']);
        $row = $this->transfers()->submit($user, (int) $row->getKey(), 1);
        $this->rejectTransfer(fn () => $this->transfers()->approve($user, (int) $row->getKey(), 2));
    }

    public function test_reasoned_rejection_clears_stamps_and_allows_fresh_review(): void
    {
        $row = $this->submittedTransfer();
        $row = $this->transfers()->reject($this->reviewer, (int) $row->getKey(), 2, 'Review destination');
        $this->assertSame('draft', $row->getAttribute('status'));
        $this->assertNull($row->getAttribute('submitted_payload_hash'));
        $row = $this->transfers()->save($this->actor, $this->editData($row, ['amount' => '50.00']), (int) $row->getKey());
        $row = $this->transfers()->submit($this->actor, (int) $row->getKey(), 4);
        $row = $this->transfers()->approve($this->reviewer, (int) $row->getKey(), 5);
        $this->assertSame('approved', $row->getAttribute('status'));
    }

    public function test_submitted_and_approved_records_cannot_be_edited(): void
    {
        $row = $this->submittedTransfer();
        $this->rejectTransfer(fn () => $this->transfers()->save($this->actor, $this->editData($row), (int) $row->getKey()));
        $row = $this->transfers()->approve($this->reviewer, (int) $row->getKey(), 2);
        $this->rejectTransfer(fn () => $this->transfers()->save($this->actor, $this->editData($row), (int) $row->getKey()));
    }

    public function test_stale_revision_is_rejected(): void
    {
        $row = $this->draftTransfer();
        $this->rejectTransfer(fn () => $this->transfers()->submit($this->actor, (int) $row->getKey(), 99));
    }

    public function test_cancelled_record_cannot_be_resubmitted(): void
    {
        $row = $this->draftTransfer();
        $row = $this->transfers()->cancel($this->actor, (int) $row->getKey(), 1, 'No longer needed');
        $this->rejectTransfer(fn () => $this->transfers()->submit($this->actor, (int) $row->getKey(), 2));
    }

    public function test_inactive_source_or_destination_blocks_new_intent(): void
    {
        $this->cash->update(['status' => 'inactive']);
        $this->rejectTransfer(fn () => $this->draftTransfer());
        $this->cash->update(['status' => 'active']);
        $this->bank->update(['status' => 'inactive']);
        $this->rejectTransfer(fn () => $this->draftTransfer());
    }

    public function test_binding_deactivation_after_submission_blocks_approval(): void
    {
        $row = $this->submittedTransfer();
        $this->bank->update(['status' => 'inactive']);
        $this->rejectTransfer(fn () => $this->transfers()->approve($this->reviewer, (int) $row->getKey(), 2));
    }

    public function test_same_binding_zero_float_and_future_date_are_rejected(): void
    {
        foreach ([['destination_cash_account_id' => (int) $this->cash->getKey()], ['amount' => '0.00'], ['amount' => 1.5], ['document_date' => '2026-10-17']] as $override) {
            $this->rejectTransfer(fn () => $this->draftTransfer($override));
        }
    }

    public function test_currency_drift_is_not_treated_as_fx(): void
    {
        DB::table('accounting_cash_accounts')->where('id', $this->bank->getKey())->update(['currency_code' => 'USD']);
        $this->rejectTransfer(fn () => $this->draftTransfer());
    }

    public function test_disabled_accounting_allows_preparation_but_does_not_post(): void
    {
        $this->settings(['state' => 'disabled']);
        $before = $this->readDigest();
        $row = $this->submittedTransfer();
        $row = $this->transfers()->approve($this->reviewer, (int) $row->getKey(), 2);
        $this->assertSame('approved', $row->getAttribute('status'));
        $this->assertSame($before, $this->readDigest());
    }

    public function test_cutover_lock_blocks_new_native_changes(): void
    {
        $this->settings(['cutover_locked_at' => now()]);
        $this->rejectTransfer(fn () => $this->draftTransfer());
    }

    public function test_missing_permission_blocks_creation_and_approval(): void
    {
        $user = $this->staff(['erp.cash-transfers.view']);
        $this->rejectTransfer(fn () => $this->transfers()->save($user, $this->transferData()));
        $row = $this->submittedTransfer();
        $this->rejectTransfer(fn () => $this->transfers()->approve($user, (int) $row->getKey(), 2));
    }

    public function test_foreign_company_actor_cannot_view_or_approve(): void
    {
        $row = $this->submittedTransfer();
        $other = $this->newCompany('TRANSFER-FOREIGN');
        $user = $this->staff(['erp.cash-transfers.view', 'erp.cash-transfers.approve']);
        DB::table('employees')->where('user_id', $user->getKey())->update(['company_id' => $other->getKey()]);
        $this->rejectTransfer(fn () => $this->transfers()->find($user, (int) $row->getKey()));
        $this->rejectTransfer(fn () => $this->transfers()->approve($user, (int) $row->getKey(), 2));
    }

    public function test_transfer_roles_do_not_require_manual_journal_permission(): void
    {
        $maker = $this->staff(['erp.cash-transfers.create', 'erp.cash-transfers.submit']);
        $approver = $this->staff(['erp.cash-transfers.approve']);
        $this->assertFalse($maker->can('erp.journal-entries.create'));
        $row = $this->transfers()->save($maker, $this->transferData());
        $row = $this->transfers()->submit($maker, (int) $row->getKey(), 1);
        $row = $this->transfers()->approve($approver, (int) $row->getKey(), 2);
        $this->assertSame('approved', $row->getAttribute('status'));
    }

    public function test_tampered_private_intent_is_not_approved_or_repaired(): void
    {
        $row = $this->submittedTransfer();
        DB::table('accounting_cash_transfers')->where('id', $row->getKey())->update(['notes' => 'Changed without review']);
        $this->rejectTransfer(fn () => $this->transfers()->approve($this->reviewer, (int) $row->getKey(), 2));
    }

    public function test_foundation_model_cannot_claim_posted_state(): void
    {
        $row = $this->draftTransfer();
        $this->expectException(\LogicException::class);
        DB::transaction(fn () => $row->forceFill(['status' => 'posted', 'revision' => 2])->save());
    }

    public function test_cancelled_header_and_audit_history_are_immutable(): void
    {
        $row = $this->draftTransfer();
        $row = $this->transfers()->cancel($this->actor, (int) $row->getKey(), 1, 'Cancelled');
        $this->rejectTransfer(fn () => DB::transaction(fn () => $row->forceFill(['notes' => 'Mutated', 'revision' => 3])->save()));
        $event = AccountingCashTransferAuditEvent::query()->firstOrFail();
        $this->rejectTransfer(fn () => DB::transaction(fn () => $event->update(['reason' => 'Mutated'])));
    }

    public function test_headers_cannot_be_deleted(): void
    {
        $row = $this->draftTransfer();
        $this->rejectTransfer(fn () => DB::transaction(fn () => $row->delete()));
    }

    public function test_private_context_is_not_copied_to_financial_plan_or_audit_snapshots(): void
    {
        $row = $this->draftTransfer();
        $plan = app(AccountingCashTransferContract::class)->plan((int) $row->getAttribute('company_id'), 'BDT', $row->getAttribute('amount'), $row->getAttribute('source_snapshot'), $row->getAttribute('destination_snapshot'));
        $audit = AccountingCashTransferAuditEvent::query()->firstOrFail();
        $json = json_encode([$plan, $audit->getAttribute('after_payload')], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('PRIVATE-TRANSFER', $json);
        $this->assertStringNotContainsString('creation_key_hash', $json);
    }

    public function test_foreign_binding_injection_is_rejected_before_numbering(): void
    {
        $foreign = $this->newCompany('TRANSFER-BINDING');
        app(AccountingSetupService::class)->initialize($this->actor, (int) $foreign->getKey());
        $map = $this->chart($foreign);
        $binding = AccountingCashAccount::query()->create(['company_id' => $foreign->getKey(), 'role' => 'cash:MAIN', 'kind' => 'cash', 'name' => 'Foreign cash', 'currency_code' => 'BDT', 'account_id' => $map['cash:MAIN'], 'status' => 'active', 'revision' => 1]);
        $this->rejectTransfer(fn () => $this->draftTransfer(['destination_cash_account_id' => (int) $binding->getKey()]));
    }

    public function test_numbering_is_company_year_scoped_and_does_not_need_a_posting_period(): void
    {
        $a = $this->draftTransfer();
        $b = $this->draftTransfer();
        $this->assertSame('CT-2026-000001', $a->getAttribute('number'));
        $this->assertSame('CT-2026-000002', $b->getAttribute('number'));
        $this->travelTo(CarbonImmutable::parse('2027-01-16 12:00:00', 'Asia/Dhaka'));
        $c = $this->draftTransfer(['document_date' => '2027-01-16']);
        $this->assertSame('CT-2027-000001', $c->getAttribute('number'));
    }

    public function test_audit_failure_rolls_back_header_and_number_then_same_key_can_retry(): void
    {
        $data = $this->transferData();
        $before = $this->transferDigest();
        $breakAudit = true;
        Event::listen('eloquent.creating: '.AccountingCashTransferAuditEvent::class, function () use (&$breakAudit): void {
            if ($breakAudit) {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        try {
            $this->transfers()->save($this->actor, $data);
            $this->fail('Expected synthetic audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
        } finally {
            $breakAudit = false;
        }
        $this->assertSame($before, $this->transferDigest());
        $row = $this->transfers()->save($this->actor, $data);
        $this->assertSame('CT-2026-000001', $row->getAttribute('number'));
    }

    public function test_unknown_financial_or_posting_fields_are_rejected(): void
    {
        foreach ([['status' => 'posted'], ['posted_at' => now()->toDateTimeString()], ['fee' => '1.00'], ['currency_code' => 'USD']] as $field) {
            $this->rejectTransfer(fn () => $this->draftTransfer($field));
        }
    }

    public function test_blank_reject_or_cancel_reason_is_rejected(): void
    {
        $row = $this->submittedTransfer();
        $this->rejectTransfer(fn () => $this->transfers()->reject($this->reviewer, (int) $row->getKey(), 2, ' '));
        $this->rejectTransfer(fn () => $this->transfers()->cancel($this->actor, (int) $row->getKey(), 2, ' '));
    }

    public function test_direct_submitted_private_mutation_is_blocked_by_model(): void
    {
        $row = $this->submittedTransfer();
        $this->rejectTransfer(fn () => DB::transaction(fn () => $row->forceFill(['notes' => 'Changed', 'revision' => 3])->save()));
    }

    public function test_migration_collision_is_reported_before_any_ddl(): void
    {
        $files = glob(database_path('migrations/*create_cash_transfer_workflow_foundation.php'));
        $this->assertIsArray($files);
        $this->assertCount(1, $files);
        $before = Schema::getTableListing();
        $migration = require $files[0];
        try {
            $migration->up();
            $this->fail('Expected collision report.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('No DDL was attempted', $exception->getMessage());
        } $this->assertSame($before, Schema::getTableListing());
    }

    public function test_permission_seed_is_idempotent_and_no_post_permission_is_granted(): void
    {
        $this->seed(AccountingCashTransferPermissionSeeder::class);
        $this->seed(AccountingCashTransferPermissionSeeder::class);
        foreach (AccountingCashTransferContract::PERMISSIONS as $name) {
            $this->assertSame(1, Permission::query()->where('name', $name)->where('guard_name', 'web')->count());
        } $this->assertFalse(method_exists($this->transfers(), 'post'));
    }

    public function test_creation_retry_cannot_accept_damaged_reviewed_binding_snapshot(): void
    {
        $data = $this->transferData();
        $row = $this->transfers()->save($this->actor, $data);
        $row = $this->transfers()->submit($this->actor, (int) $row->getKey(), 1);
        $row = $this->transfers()->approve($this->reviewer, (int) $row->getKey(), 2);
        $snapshot = $row->getAttribute('source_snapshot');
        $snapshot['account_id']++;
        DB::table('accounting_cash_transfers')->where('id', $row->getKey())->update(['source_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
        $this->rejectTransfer(fn () => $this->transfers()->save($this->actor, $data));
    }
}
