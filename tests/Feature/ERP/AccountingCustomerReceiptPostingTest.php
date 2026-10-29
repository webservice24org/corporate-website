<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingMappingVersion;
use App\Models\Accounting\AccountingReceiptOperation;
use App\Models\Accounting\AccountingSalesEvent;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalEntryLine;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\CustomerReceiptAllocation;
use App\Models\Employee;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesDelivery;
use App\Models\SalesDeliveryItem;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesStockReservation;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ERP\Accounting\AccountingAccountService;
use App\Services\ERP\Accounting\AccountingCalendarService;
use App\Services\ERP\Accounting\AccountingCashAccountService;
use App\Services\ERP\Accounting\AccountingMappingCatalog;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\Source\AccountingSourceOrchestrator;
use App\Services\ERP\Sales\CustomerReceiptService;
use App\Services\ERP\Sales\SalesDeliveryService;
use App\Services\ERP\Sales\SalesInvoiceBalanceService;
use App\Services\ERP\Sales\SalesInvoiceService;
use App\Services\ERP\Sales\SalesOrderService;
use App\Services\ERP\Sales\SalesQuotationService;
use App\Services\ERP\Sales\SalesReservationService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class AccountingCustomerReceiptPostingTest extends TestCase
{
    use RefreshDatabase;

    private AccountingCashAccount $cash;

    private AccountingCashAccount $bank;

    private Company $company;

    private Customer $customer;

    private Unit $unit;

    private Product $product;

    private Warehouse $warehouse;

    private InventoryBalance $balance;

    private User $creator;

    private User $approver;

    private User $actor;

    private AccountingMappingService $service;

    /** @var array<string, int> */
    private array $mapping;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(SalesPermissionSeeder::class);
        $this->seed(AccountingPermissionSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00:00', 'Asia/Dhaka'));
        $this->company = $this->newCompany('SALE-A');
        $this->creator = $this->admin();
        $this->approver = $this->admin();
        $this->actor = $this->creator;
        $this->customer = Customer::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Sales customer', 'phone' => '01700000000', 'credit_limit' => '100000.00', 'is_active' => true]);
        $this->unit = Unit::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Piece', 'short_name' => 'pc', 'is_active' => true]);
        $this->product = $this->product('Physical');
        $this->warehouse = $this->warehouse('DISPATCH-WH');
        // Synthetic initial stock and activation are isolated test prerequisites, not a production opening/cutover procedure.
        $this->balance = $this->stock($this->product);
        app(AccountingSetupService::class)->initialize($this->actor, (int) $this->company->getKey());
        $this->service = app(AccountingMappingService::class);
        $this->mapping = $this->chart($this->company);
        $this->publish();
        app(AccountingCashAccountService::class)->initialize($this->creator, (int) $this->company->getKey(), 'Receipt fixture finance account setup');
        $this->cash = AccountingCashAccount::query()->where('company_id', $this->company->getKey())->where('role', 'cash:MAIN')->firstOrFail();
        $this->bank = AccountingCashAccount::query()->where('company_id', $this->company->getKey())->where('role', 'bank:CURRENT')->firstOrFail();
        $calendar = app(AccountingCalendarService::class);
        $year = $calendar->saveYear($this->actor, ['company_id' => $this->company->getKey(), 'code' => 'FY26', 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'reason' => 'Sales integration fixture']);
        $calendar->savePeriod($this->actor, (int) $year->getKey(), ['company_id' => $this->company->getKey(), 'code' => 'OCT', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'reason' => 'Sales integration fixture']);
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['state' => 'enabled', 'activation_date' => '2026-10-01', 'activated_by' => $this->actor->getKey(), 'activated_at' => now()]);
        AccountingCutover::query()->create(['company_id' => $this->company->getKey(), 'cutover_date' => '2026-10-01', 'strategy' => 'snapshot', 'status' => 'activated', 'created_by' => $this->actor->getKey(), 'approved_by' => $this->approver->getKey(), 'approved_at' => now(), 'activated_by' => $this->actor->getKey(), 'activated_at' => now(), 'manifest_hash' => str_repeat('a', 64), 'reconciliation_hash' => str_repeat('b', 64), 'revision' => 1]);
    }

    private function admin(): User
    {
        $actor = User::factory()->create();
        $actor->assignRole('super-admin');

        return $actor;
    }

    private function product(string $name, string $type = 'physical', bool $tracked = true): Product
    {
        return Product::query()->create(['company_id' => $this->company->getKey(), 'unit_id' => $this->unit->getKey(), 'name' => $name, 'product_type' => $type, 'track_inventory' => $tracked, 'has_variants' => false, 'is_active' => true, 'selling_price' => '10.00']);
    }

    private function warehouse(string $code): Warehouse
    {
        return Warehouse::query()->create(['company_id' => $this->company->getKey(), 'code' => $code, 'name' => $code, 'is_active' => true]);
    }

    private function stock(Product $product, ?Warehouse $warehouse = null, ?ProductVariant $variant = null): InventoryBalance
    {
        return InventoryBalance::query()->create(['company_id' => $this->company->getKey(), 'warehouse_id' => ($warehouse ?? $this->warehouse)->getKey(), 'product_id' => $product->getKey(), 'product_variant_id' => $variant?->getKey(), 'variant_key' => $variant?->getKey() ?? 0, 'quantity_on_hand' => '10.0000', 'quantity_reserved' => '0.0000', 'average_cost' => '5.0000']);
    }

    /** @param list<array<string, mixed>>|null $items */
    private function order(?array $items = null, bool $confirmed = true): SalesOrder
    {
        $today = now('Asia/Dhaka')->toDateString();
        $quotes = app(SalesQuotationService::class);
        $quote = $quotes->save($this->creator, ['company_id' => $this->company->getKey(), 'customer_id' => $this->customer->getKey(), 'document_date' => $today, 'valid_until' => now('Asia/Dhaka')->addDays(30)->toDateString(), 'notes' => 'Source quotation terms', 'items' => $items ?? [['product_id' => $this->product->getKey(), 'quantity' => '6', 'unit_price' => '10', 'discount_percent' => '0', 'tax_percent' => '0']]]);
        foreach ([['submit', $this->creator], ['approve', $this->approver], ['send', $this->creator], ['accept', $this->creator]] as [$action, $actor]) {
            $quote = $quotes->transition($actor, (int) $quote->getKey(), $action, (int) $quote->getAttribute('revision'));
        }
        $orders = app(SalesOrderService::class);
        $order = $orders->create($this->creator, ['company_id' => $this->company->getKey(), 'sales_quotation_id' => $quote->getKey(), 'document_date' => $today]);
        if ($confirmed) {
            foreach ([['submit', $this->creator], ['approve', $this->approver], ['confirm', $this->creator]] as [$action, $actor]) {
                $order = $orders->transition($actor, (int) $order->getKey(), $action, (int) $order->getAttribute('revision'));
            }
        }

        return $order;
    }

    private function line(SalesOrder $order, ?Product $product = null): SalesOrderItem
    {
        return SalesOrderItem::query()->where('sales_order_id', $order->getKey())->where('product_id', ($product ?? $this->product)->getKey())->firstOrFail();
    }

    private function reserve(SalesOrder $order, string $quantity = '6', ?Product $product = null, ?Warehouse $warehouse = null): SalesStockReservation
    {
        $line = $this->line($order, $product);
        app(SalesReservationService::class)->allocate($this->creator, (int) $order->getKey(), ['sales_order_item_id' => $line->getKey(), 'warehouse_id' => ($warehouse ?? $this->warehouse)->getKey(), 'quantity' => $quantity, 'revision' => $order->fresh()->getAttribute('revision'), 'idempotency_key' => (string) Str::uuid()]);

        return SalesStockReservation::query()->where('sales_order_item_id', $line->getKey())->where('warehouse_id', ($warehouse ?? $this->warehouse)->getKey())->firstOrFail();
    }

    /** @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function payload(SalesOrder $order, array $items): array
    {
        return ['company_id' => $this->company->getKey(), 'sales_order_id' => $order->getKey(), 'document_date' => now('Asia/Dhaka')->toDateString(), 'notes' => 'Handle carefully', 'items' => $items];
    }

    private function draft(SalesOrder $order, SalesStockReservation $reservation, string $quantity = '6'): SalesDelivery
    {
        return app(SalesDeliveryService::class)->save($this->creator, $this->payload($order, [['sales_order_item_id' => $reservation->getAttribute('sales_order_item_id'), 'quantity' => $quantity, 'sales_stock_reservation_id' => $reservation->getKey()]]));
    }

    private function postDelivery(SalesDelivery $delivery, ?string $key = null): SalesDelivery
    {
        return app(SalesDeliveryService::class)->post($this->creator, (int) $delivery->getKey(), (int) $delivery->getAttribute('revision'), $key ?? (string) Str::uuid());
    }

    private function invalid(callable $operation, ?string $field = null): void
    {
        try {
            $operation();
            $this->fail('Expected a validation rejection.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
            if ($field !== null) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
    }

    private function fulfillment(SalesOrder $order, string $quantity = '6'): SalesDelivery
    {
        return $this->postDelivery($this->draft($order, $this->reserve($order, $quantity), $quantity));
    }

    /** @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function invoicePayload(SalesOrder $order, array $items): array
    {
        return ['company_id' => $this->company->getKey(), 'sales_order_id' => $order->getKey(), 'document_date' => now('Asia/Dhaka')->toDateString(), 'due_date' => now('Asia/Dhaka')->addDays(14)->toDateString(), 'notes' => 'Invoice terms', 'items' => $items];
    }

    private function bill(SalesOrder $order, SalesDelivery $delivery, string $quantity = '6'): SalesInvoice
    {
        $source = SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->firstOrFail();

        return app(SalesInvoiceService::class)->save($this->creator, $this->invoicePayload($order, [['sales_delivery_item_id' => $source->getKey(), 'quantity' => $quantity]]));
    }

    private function issueInvoice(SalesInvoice $invoice, ?string $key = null): SalesInvoice
    {
        return app(SalesInvoiceService::class)->issue($this->creator, (int) $invoice->getKey(), (int) $invoice->getAttribute('revision'), $key ?? (string) Str::uuid());
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function data(array $overrides = []): array
    {
        return ['company_id' => (int) $this->company->getKey(), 'base_version_id' => null, 'effective_from' => '2026-10-01', 'mappings' => $this->mapping, 'reason' => 'Approve initial company posting profile', ...$overrides];
    }

    /** @param array<string, mixed> $overrides */
    private function publish(array $overrides = []): AccountingMappingVersion
    {
        return $this->service->publish($this->actor, $this->data($overrides));
    }

    private function settings(array $changes): void
    {
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update($changes);
    }

    private function role(string $role, string $side): string
    {
        return bcadd((string) JournalEntryLine::query()->where('account_id', $this->mapping[$role])->sum($side), '0', 2);
    }

    private function deliveryLine(SalesDelivery $delivery): SalesDeliveryItem
    {
        return SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->firstOrFail();
    }

    private function replay(string $kind, int $id): array
    {
        return DB::transaction(fn () => app(AccountingSourceOrchestrator::class)->execute($this->creator, (int) $this->company->getKey(), $kind, $id, $kind === 'sales_delivery' ? 'posted' : 'issued', 'original'));
    }

    private function invoice(): SalesInvoice
    {
        $order = $this->order();

        return $this->issueInvoice($this->bill($order, $this->fulfillment($order)));
    }

    private function receipt(string $amount = '60.00', ?int $cashId = null, string $method = 'cash'): CustomerReceipt
    {
        return app(CustomerReceiptService::class)->save($this->creator, ['company_id' => $this->company->getKey(), 'customer_id' => $this->customer->getKey(), 'document_date' => now('Asia/Dhaka')->toDateString(), 'amount' => $amount, 'payment_method' => $method, 'cash_account_id' => $cashId ?? $this->cash->getKey(), 'payment_reference' => 'PRIVATE-BANK-REFERENCE', 'notes' => 'PRIVATE-RECEIPT-NOTES']);
    }

    private function op(CustomerReceipt $r, array $targets = [], array $undo = [], ?string $key = null): array
    {
        return ['revision' => (int) $r->refresh()->getAttribute('revision'), 'idempotency_key' => $key ?? (string) Str::uuid(), 'allocations' => $targets, 'reverse_allocation_ids' => $undo, 'reason' => 'Approved settlement correction'];
    }

    private function postReceipt(CustomerReceipt $r, array $targets = [], ?array $operation = null): CustomerReceipt
    {
        return app(CustomerReceiptService::class)->postReceipt($this->creator, (int) $r->getKey(), $operation ?? $this->op($r, $targets));
    }

    private function allocate(CustomerReceipt $r, SalesInvoice $i, string $amount): CustomerReceipt
    {
        return app(CustomerReceiptService::class)->allocate($this->creator, (int) $r->getKey(), $this->op($r, [['sales_invoice_id' => (int) $i->getKey(), 'amount' => $amount]]));
    }

    private function undo(CustomerReceipt $r, CustomerReceiptAllocation $a): CustomerReceipt
    {
        return app(CustomerReceiptService::class)->reverseAllocation($this->creator, (int) $r->getKey(), (int) $a->getKey(), $this->op($r));
    }

    private function originalAllocation(CustomerReceipt $r): CustomerReceiptAllocation
    {
        return CustomerReceiptAllocation::query()->where('customer_receipt_id', $r->getKey())->whereNull('reverses_allocation_id')->firstOrFail();
    }

    private function due(SalesInvoice $i): string
    {
        return DB::transaction(fn () => app(SalesInvoiceBalanceService::class)->snapshot($i)['outstanding']);
    }

    public function test_unapplied_receipt_posts_cash_and_customer_funds_only(): void
    {
        $r = $this->postReceipt($this->receipt());
        $this->assertSame('60.00', $this->role('cash:MAIN', 'debit'));
        $this->assertSame('60.00', $this->role('unapplied_customer_funds', 'credit'));
        $this->assertSame('0.00', $this->role('trade_ar', 'credit'));
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('accounting_receipt_operations', 1);
        $line = JournalEntryLine::query()->where('account_id', $this->mapping['cash:MAIN'])->firstOrFail();
        $this->assertSame((int) $this->cash->getKey(), (int) $line->getAttribute('cash_account_id'));
        $this->assertSame('60.00', app(CustomerReceiptService::class)->snapshot($this->creator, (int) $r->getKey())['available']);
    }

    public function test_initial_allocation_recognizes_cash_once_and_settles_ar(): void
    {
        $i = $this->invoice();
        $r = $this->postReceipt($this->receipt(), [['sales_invoice_id' => (int) $i->getKey(), 'amount' => '40.00']]);
        $this->assertSame('60.00', $this->role('cash:MAIN', 'debit'));
        $this->assertSame('40.00', $this->role('trade_ar', 'credit'));
        $this->assertSame('40.00', $this->role('unapplied_customer_funds', 'debit'));
        $this->assertSame('20.00', $this->due($i));
        $this->assertSame('20.00', app(CustomerReceiptService::class)->snapshot($this->creator, (int) $r->getKey())['available']);
        $this->assertDatabaseCount('accounting_receipt_operations', 1);
        $this->assertDatabaseCount('journal_entries', 5);
    }

    public function test_later_allocation_does_not_create_second_cash_or_revenue(): void
    {
        $i = $this->invoice();
        $r = $this->postReceipt($this->receipt());
        $this->allocate($r, $i, '30.00');
        $this->assertSame('60.00', $this->role('cash:MAIN', 'debit'));
        $this->assertSame('0.00', $this->role('cash:MAIN', 'credit'));
        $this->assertSame('60.00', $this->role('sales_revenue', 'credit'));
        $this->assertSame('30.00', $this->due($i));
        $this->assertDatabaseCount('accounting_receipt_operations', 2);
    }

    public function test_allocation_compensation_restores_ar_and_unapplied_funds(): void
    {
        $i = $this->invoice();
        $r = $this->postReceipt($this->receipt(), [['sales_invoice_id' => (int) $i->getKey(), 'amount' => '30.00']]);
        $this->undo($r, $this->originalAllocation($r));
        $this->assertSame('60.00', $this->due($i));
        $this->assertSame('60.00', $this->role('cash:MAIN', 'debit'));
        $this->assertSame('0.00', $this->role('cash:MAIN', 'credit'));
        $this->assertSame('30.00', $this->role('unapplied_customer_funds', 'debit'));
        $this->assertSame('90.00', $this->role('unapplied_customer_funds', 'credit'));
        $this->assertDatabaseCount('customer_receipt_allocations', 2);
    }

    public function test_reallocation_compensates_old_target_and_settles_new_target_atomically(): void
    {
        $this->balance->forceFill(['quantity_on_hand' => '20.0000'])->save();
        $a = $this->invoice();
        $b = $this->invoice();
        $r = $this->postReceipt($this->receipt(), [['sales_invoice_id' => (int) $a->getKey(), 'amount' => '60.00']]);
        $old = $this->originalAllocation($r);
        app(CustomerReceiptService::class)->allocate($this->creator, (int) $r->getKey(), $this->op($r, [['sales_invoice_id' => (int) $b->getKey(), 'amount' => '60.00']], [(int) $old->getKey()]));
        $this->assertSame('60.00', $this->due($a));
        $this->assertSame('0.00', $this->due($b));
        $this->assertSame('60.00', $this->role('cash:MAIN', 'debit'));
        $this->assertSame('0.00', $this->role('cash:MAIN', 'credit'));
    }

    public function test_full_receipt_reversal_compensates_allocations_and_reverses_original_cash_account(): void
    {
        $i = $this->invoice();
        $r = $this->postReceipt($this->receipt(), [['sales_invoice_id' => (int) $i->getKey(), 'amount' => '60.00']]);
        $reverse = app(CustomerReceiptService::class)->reverseReceipt($this->creator, (int) $r->getKey(), $this->op($r));
        $this->assertSame((int) $this->cash->getKey(), (int) $reverse->getAttribute('cash_account_id'));
        $this->assertSame('60.00', $this->due($i));
        $this->assertSame('60.00', $this->role('cash:MAIN', 'debit'));
        $this->assertSame('60.00', $this->role('cash:MAIN', 'credit'));
        $this->assertSame('0.00', bcsub($this->role('unapplied_customer_funds', 'credit'), $this->role('unapplied_customer_funds', 'debit'), 2));
        $this->assertDatabaseCount('customer_receipts', 2);
        $this->assertDatabaseCount('accounting_receipt_operations', 2);
    }

    public function test_bank_transfer_uses_concrete_bank_binding(): void
    {
        $this->postReceipt($this->receipt('25.00', (int) $this->bank->getKey(), 'bank_transfer'));
        $this->assertSame('25.00', $this->role('bank:CURRENT', 'debit'));
        $this->assertSame('0.00', $this->role('cash:MAIN', 'debit'));
    }

    public function test_cash_binding_is_required_for_enabled_new_receipts(): void
    {
        $this->invalid(fn () => app(CustomerReceiptService::class)->save($this->creator, ['company_id' => $this->company->getKey(), 'customer_id' => $this->customer->getKey(), 'document_date' => now('Asia/Dhaka')->toDateString(), 'amount' => '10.00', 'payment_method' => 'cash']), 'cash_account_id');
        $this->assertDatabaseCount('customer_receipts', 0);
    }

    public function test_receipt_method_must_match_finance_account_kind(): void
    {
        $this->invalid(fn () => $this->receipt('10.00', (int) $this->bank->getKey(), 'cash'), 'cash_account_id');
        $this->invalid(fn () => $this->receipt('10.00', (int) $this->cash->getKey(), 'bank_transfer'), 'cash_account_id');
    }

    public function test_inactive_cash_binding_cannot_receive_new_money(): void
    {
        $r = $this->receipt();
        $this->cash->fill(['status' => 'inactive'])->save();
        $this->invalid(fn () => $this->postReceipt($r), 'cash_account_id');
        $this->assertSame('draft', $r->refresh()->getAttribute('status'));
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('accounting_receipt_operations', 0);
    }

    public function test_cash_only_receipt_finance_does_not_require_manual_journal_permission(): void
    {
        $r = $this->receipt();
        $staff = $this->staff(['erp.customer-receipts.post']);
        $this->assertFalse($staff->can('erp.journal-entries.post'));
        app(CustomerReceiptService::class)->postReceipt($staff, (int) $r->getKey(), $this->op($r));
        $this->assertSame('60.00', $this->role('cash:MAIN', 'debit'));
    }

    public function test_same_post_request_replays_entire_operation_without_new_writes(): void
    {
        $i = $this->invoice();
        $r = $this->receipt();
        $data = $this->op($r, [['sales_invoice_id' => (int) $i->getKey(), 'amount' => '30.00']]);
        $this->postReceipt($r, operation: $data);
        $count = DB::table('accounting_audit_events')->count();
        $this->postReceipt($r, operation: $data);
        $this->assertDatabaseCount('journal_entries', 5);
        $this->assertDatabaseCount('accounting_receipt_operations', 1);
        $this->assertSame($count, DB::table('accounting_audit_events')->count());
    }

    public function test_allocation_and_reversal_native_retries_replay_complete_financial_batch(): void
    {
        $i = $this->invoice();
        $r = $this->postReceipt($this->receipt());
        $data = $this->op($r, [['sales_invoice_id' => (int) $i->getKey(), 'amount' => '30.00']]);
        $service = app(CustomerReceiptService::class);
        $service->allocate($this->creator, (int) $r->getKey(), $data);
        $service->allocate($this->creator, (int) $r->getKey(), $data);
        $reverse = $this->op($r);
        $a = $service->reverseReceipt($this->creator, (int) $r->getKey(), $reverse);
        $b = $service->reverseReceipt($this->creator, (int) $r->getKey(), $reverse);
        $this->assertSame($a->getKey(), $b->getKey());
        $this->assertDatabaseCount('accounting_receipt_operations', 3);
        $this->assertSame('60.00', $this->role('cash:MAIN', 'credit'));
    }

    public function test_replay_survives_binding_deactivation_closed_period_and_disabled_settings(): void
    {
        $r = $this->receipt();
        $data = $this->op($r);
        $this->postReceipt($r, operation: $data);
        $this->cash->fill(['status' => 'inactive'])->save();
        Account::query()->whereKey($this->mapping['cash:MAIN'])->update(['status' => 'inactive']);
        DB::table('accounting_periods')->update(['state' => 'hard_closed']);
        $this->settings(['state' => 'disabled', 'cutover_locked_at' => now()]);
        $this->postReceipt($r, operation: $data);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_missing_financial_member_is_rejected_without_reposting_on_retry(): void
    {
        $i = $this->invoice();
        $r = $this->receipt();
        $data = $this->op($r, [['sales_invoice_id' => (int) $i->getKey(), 'amount' => '30.00']]);
        $this->postReceipt($r, operation: $data);
        DB::table('accounting_source_effects')->where('source_kind', 'customer_receipt_allocation')->delete();
        $this->invalid(fn () => $this->postReceipt($r, operation: $data), 'accounting_receipt');
        $this->assertDatabaseCount('journal_entries', 5);
    }

    public function test_missing_operation_manifest_is_not_recreated_on_retry(): void
    {
        $r = $this->receipt();
        $data = $this->op($r);
        $this->postReceipt($r, operation: $data);
        DB::table('accounting_receipt_operations')->delete();
        $this->invalid(fn () => $this->postReceipt($r, operation: $data), 'accounting_receipt');
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('accounting_receipt_operations', 0);
    }

    public function test_corrupt_operation_manifest_fails_replay(): void
    {
        $r = $this->receipt();
        $data = $this->op($r);
        $this->postReceipt($r, operation: $data);
        DB::table('accounting_receipt_operations')->update(['payload_hash' => str_repeat('0', 64)]);
        $this->invalid(fn () => $this->postReceipt($r, operation: $data), 'accounting_receipt');
    }

    public function test_cash_and_native_post_roll_back_if_initial_allocation_finance_fails(): void
    {
        $i = $this->invoice();
        $r = $this->receipt();
        $armed = true;
        $seenCash = false;
        Event::listen('eloquent.created: '.JournalEntry::class, function (JournalEntry $j) use (&$armed, &$seenCash): void {
            if (! $armed) {
                return;
            } $description = (string) $j->getAttribute('description');
            if (str_starts_with($description, 'customer_receipt ')) {
                $seenCash = true;
            } if (str_starts_with($description, 'customer_receipt_allocation ')) {
                throw ValidationException::withMessages(['fixture_allocation' => 'Injected second-member failure']);
            }
        });
        try {
            $this->invalid(fn () => $this->postReceipt($r, [['sales_invoice_id' => (int) $i->getKey(), 'amount' => '30.00']]), 'fixture_allocation');
        } finally {
            $armed = false;
        }
        $this->assertTrue($seenCash);
        $this->assertSame('draft', $r->refresh()->getAttribute('status'));
        $this->assertSame('60.00', $this->due($i));
        $this->assertDatabaseCount('customer_receipt_allocations', 0);
        $this->assertDatabaseCount('accounting_receipt_operations', 0);
        $this->assertDatabaseCount('journal_entries', 3);
        $this->assertSame('0.00', $this->role('cash:MAIN', 'debit'));
    }

    public function test_closed_period_blocks_new_cash_and_rolls_back_native_post(): void
    {
        $r = $this->receipt();
        DB::table('accounting_periods')->update(['state' => 'hard_closed']);
        $this->invalid(fn () => $this->postReceipt($r), 'accounting_date');
        $this->assertSame('draft', $r->refresh()->getAttribute('status'));
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('accounting_sales_events', 0);
    }

    public function test_receipt_reversal_failure_rolls_back_allocation_compensations_and_native_reversal(): void
    {
        $i = $this->invoice();
        $r = $this->postReceipt($this->receipt(), [['sales_invoice_id' => (int) $i->getKey(), 'amount' => '60.00']]);
        $this->cash->fill(['status' => 'inactive'])->save();
        $this->invalid(fn () => app(CustomerReceiptService::class)->reverseReceipt($this->creator, (int) $r->getKey(), $this->op($r)), 'cash_account_id');
        $this->assertSame('0.00', $this->due($i));
        $this->assertDatabaseCount('customer_receipts', 1);
        $this->assertDatabaseCount('customer_receipt_allocations', 1);
        $this->assertDatabaseCount('journal_entries', 5);
    }

    public function test_legacy_unrecognized_cash_receipt_cannot_fund_new_live_settlement(): void
    {
        $i = $this->invoice();
        $this->settings(['state' => 'disabled']);
        $r = $this->postReceipt($this->receipt());
        $this->settings(['state' => 'enabled']);
        $this->invalid(fn () => $this->allocate($r, $i, '30.00'), 'accounting_receipt');
        $this->assertDatabaseCount('customer_receipt_allocations', 0);
        $this->assertSame('60.00', $this->due($i));
    }

    public function test_legacy_unrecognized_invoice_cannot_be_settled_with_live_cash(): void
    {
        $this->settings(['state' => 'disabled']);
        $i = $this->invoice();
        $this->settings(['state' => 'enabled']);
        $r = $this->postReceipt($this->receipt());
        $this->invalid(fn () => $this->allocate($r, $i, '30.00'));
        $this->assertDatabaseCount('customer_receipt_allocations', 0);
        $this->assertSame('60.00', $this->role('cash:MAIN', 'debit'));
    }

    public function test_disabled_accounting_preserves_native_receipt_and_reversal_without_financial_markers(): void
    {
        $this->settings(['state' => 'disabled']);
        $i = $this->invoice();
        $r = $this->postReceipt($this->receipt(), [['sales_invoice_id' => (int) $i->getKey(), 'amount' => '30.00']]);
        app(CustomerReceiptService::class)->reverseReceipt($this->creator, (int) $r->getKey(), $this->op($r));
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('accounting_receipt_operations', 0);
        $this->assertDatabaseCount('customer_receipts', 2);
    }

    public function test_soft_deleted_customer_can_settle_existing_recognized_invoice_without_new_advance(): void
    {
        $i = $this->invoice();
        $this->customer->delete();
        $r = $this->postReceipt($this->receipt(), [['sales_invoice_id' => (int) $i->getKey(), 'amount' => '60.00']]);
        $this->assertSame('0.00', $this->due($i));
        $this->assertSame('0.00', app(CustomerReceiptService::class)->snapshot($this->creator, (int) $r->getKey())['available']);
    }

    public function test_cash_setup_is_idempotent_and_keeps_binding_identity_fixed(): void
    {
        $this->assertSame(0, app(AccountingCashAccountService::class)->initialize($this->creator, (int) $this->company->getKey(), 'Repeat setup'));
        $this->assertDatabaseCount('accounting_cash_accounts', 2);
        try {
            $this->cash->fill(['role' => 'cash:OTHER'])->save();
            $this->fail('Expected immutable cash identity.');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
    }

    public function test_cash_setup_requires_mapping_publication_permission(): void
    {
        $staff = $this->staff(['erp.accounting-mappings.view']);
        $this->expectException(AuthorizationException::class);
        app(AccountingCashAccountService::class)->initialize($staff, (int) $this->company->getKey(), 'Unauthorized initialization');
    }

    public function test_private_payment_reference_notes_and_customer_data_are_not_copied_to_finance_evidence(): void
    {
        $this->postReceipt($this->receipt());
        $json = json_encode(AccountingSalesEvent::query()->pluck('payload')->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('PRIVATE-', $json);
        $this->assertStringNotContainsString('01700000000', $json);
        $this->assertStringNotContainsString('customer_snapshot', $json);
    }

    public function test_receipt_operation_history_is_append_only(): void
    {
        $this->postReceipt($this->receipt());
        $operation = AccountingReceiptOperation::query()->firstOrFail();
        $this->expectException(\LogicException::class);
        $operation->delete();
    }

    public function test_cash_setup_and_receipt_selector_pages_resolve_with_scoped_safe_choices(): void
    {
        $this->actingAs($this->creator)->get('/erp/accounting/mappings/cash-accounts?company_id='.$this->company->getKey())->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('ERP/Accounting/CashAccounts/Index')->has('accounts', 2));
        $this->get('/erp/sales/receipts/create?company_id='.$this->company->getKey())->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('ERP/Sales/Receipts/Form')->has('cashAccounts', 2)->where('accountingEnabled', true)->missing('cashAccounts.0.account_id'));
    }

    public function test_foreign_cash_binding_cannot_be_selected_for_own_receipt(): void
    {
        $foreign = $this->newCompany('FOREIGN-CASH');
        app(AccountingSetupService::class)->initialize($this->creator, (int) $foreign->getKey());
        $mapping = $this->chart($foreign);
        $this->service->publish($this->creator, ['company_id' => $foreign->getKey(), 'base_version_id' => null, 'effective_from' => '2026-10-01', 'mappings' => $mapping, 'reason' => 'Foreign fixture profile']);
        app(AccountingCashAccountService::class)->initialize($this->creator, (int) $foreign->getKey(), 'Foreign fixture cash');
        $cash = AccountingCashAccount::query()->where('company_id', $foreign->getKey())->where('kind', 'cash')->firstOrFail();
        $this->invalid(fn () => $this->receipt('10.00', (int) $cash->getKey()), 'cash_account_id');
        $this->assertDatabaseCount('customer_receipts', 0);
    }

    public function test_allocation_cannot_settle_another_customers_invoice(): void
    {
        $own = $this->customer;
        $this->customer = Customer::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Other customer', 'phone' => '01800000000', 'credit_limit' => '100000.00', 'is_active' => true]);
        $invoice = $this->invoice();
        $this->customer = $own;
        $r = $this->postReceipt($this->receipt());
        $this->invalid(fn () => $this->allocate($r, $invoice, '30.00'), 'allocations');
        $this->assertDatabaseCount('customer_receipt_allocations', 0);
        $this->assertSame('0.00', $this->role('trade_ar', 'credit'));
    }

    public function test_compensation_of_closed_originals_posts_in_current_open_period(): void
    {
        $invoice = $this->invoice();
        $r = $this->postReceipt($this->receipt(), [['sales_invoice_id' => (int) $invoice->getKey(), 'amount' => '60.00']]);
        DB::table('accounting_periods')->update(['state' => 'hard_closed']);
        $year = FiscalYear::query()->where('company_id', $this->company->getKey())->firstOrFail();
        app(AccountingCalendarService::class)->savePeriod($this->creator, (int) $year->getKey(), ['company_id' => $this->company->getKey(), 'code' => 'NOV', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'reason' => 'Current correction period']);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:00', 'Asia/Dhaka'));
        app(CustomerReceiptService::class)->reverseReceipt($this->creator, (int) $r->getKey(), $this->op($r));
        $this->assertSame('60.00', $this->due($invoice));
        $this->assertSame(2, JournalEntry::query()->whereDate('accounting_date', '2026-11-02')->count());
        $this->assertSame(5, JournalEntry::query()->whereDate('accounting_date', '2026-10-16')->count());
    }

    public function test_replay_does_not_recreate_damaged_native_batch_rows(): void
    {
        $invoice = $this->invoice();
        $r = $this->receipt();
        $data = $this->op($r, [['sales_invoice_id' => (int) $invoice->getKey(), 'amount' => '30.00']]);
        $this->postReceipt($r, operation: $data);
        DB::table('customer_receipt_allocations')->update(['amount' => '31.00']);
        $this->invalid(fn () => $this->postReceipt($r, operation: $data), 'accounting_receipt');
        $this->assertSame('60.00', $this->role('cash:MAIN', 'debit'));
        $this->assertDatabaseCount('journal_entries', 5);
    }

    public function test_json_object_key_reordering_preserves_dependency_verification_and_batch_replay(): void
    {
        $invoice = $this->invoice();
        $reorder = function (mixed $value) use (&$reorder): mixed {
            if (! is_array($value)) {
                return $value;
            } foreach ($value as $key => $child) {
                $value[$key] = $reorder($child);
            } if (! array_is_list($value)) {
                krsort($value, SORT_STRING);
            }

            return $value;
        };
        foreach (AccountingSourceEffect::query()->get() as $effect) {
            DB::table('accounting_source_effects')->where('id', $effect->getKey())->update(['source_snapshot' => json_encode($reorder($effect->getAttribute('source_snapshot')), JSON_THROW_ON_ERROR)]);
        }
        $r = $this->receipt();
        $data = $this->op($r, [['sales_invoice_id' => (int) $invoice->getKey(), 'amount' => '30.00']]);
        $this->postReceipt($r, operation: $data);
        $operation = AccountingReceiptOperation::query()->firstOrFail();
        DB::table('accounting_receipt_operations')->where('id', $operation->getKey())->update(['members' => json_encode($reorder($operation->getAttribute('members')), JSON_THROW_ON_ERROR)]);
        $this->postReceipt($r, operation: $data);
        $this->assertSame('30.00', $this->due($invoice));
        $this->assertDatabaseCount('journal_entries', 5);
    }

    public function test_missing_manifest_is_still_rejected_when_recorded_accounting_is_now_disabled(): void
    {
        $r = $this->receipt();
        $data = $this->op($r);
        $this->postReceipt($r, operation: $data);
        DB::table('accounting_receipt_operations')->delete();
        $this->settings(['state' => 'disabled']);
        $this->invalid(fn () => $this->postReceipt($r, operation: $data), 'accounting_receipt');
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_existing_funds_can_be_allocated_after_cash_binding_is_deactivated(): void
    {
        $invoice = $this->invoice();
        $r = $this->postReceipt($this->receipt());
        $this->cash->fill(['status' => 'inactive'])->save();
        $this->allocate($r, $invoice, '30.00');
        $this->assertSame('30.00', $this->due($invoice));
        $this->assertSame('60.00', $this->role('cash:MAIN', 'debit'));
    }

    public function test_original_post_batch_can_replay_after_its_allocations_and_cash_are_reversed(): void
    {
        $invoice = $this->invoice();
        $r = $this->receipt();
        $data = $this->op($r, [['sales_invoice_id' => (int) $invoice->getKey(), 'amount' => '60.00']]);
        $this->postReceipt($r, operation: $data);
        app(CustomerReceiptService::class)->reverseReceipt($this->creator, (int) $r->getKey(), $this->op($r));
        $this->postReceipt($r, operation: $data);
        $this->assertSame('60.00', $this->due($invoice));
        $this->assertDatabaseCount('journal_entries', 7);
        $this->assertDatabaseCount('accounting_receipt_operations', 2);
    }

    public function test_funds_control_remap_blocks_settlement_and_preserves_original_liability(): void
    {
        $invoice = $this->invoice();
        $r = $this->postReceipt($this->receipt());
        $old = Account::query()->findOrFail($this->mapping['unapplied_customer_funds']);
        $replacement = $old->replicate();
        $replacement->setAttribute('code', '99992');
        $replacement->setAttribute('name', 'Replacement funds control');
        $replacement->save();
        $mapping = $this->mapping;
        $mapping['unapplied_customer_funds'] = (int) $replacement->getKey();
        $version = AccountingMappingVersion::query()->where('company_id', $this->company->getKey())->latest('version')->firstOrFail();
        $this->publish(['base_version_id' => $version->getKey(), 'effective_from' => '2026-10-17', 'mappings' => $mapping]);
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $this->invalid(fn () => $this->allocate($r, $invoice, '30.00'), 'accounting_receipt');
        $this->assertSame('60.00', $this->due($invoice));
        $this->assertDatabaseCount('customer_receipt_allocations', 0);
        $this->assertDatabaseCount('journal_entries', 4);
    }
}
