<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingMappingVersion;
use App\Models\Accounting\AccountingSalesEvent;
use App\Models\Accounting\AccountingSourceEffect;
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
use App\Models\SalesCreditNote;
use App\Models\SalesCreditNoteItem;
use App\Models\SalesDelivery;
use App\Models\SalesDeliveryItem;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
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
use App\Services\ERP\Sales\CustomerCreditAllocationService;
use App\Services\ERP\Sales\CustomerReceiptService;
use App\Services\ERP\Sales\SalesCreditNoteService;
use App\Services\ERP\Sales\SalesDeliveryService;
use App\Services\ERP\Sales\SalesInvoiceBalanceService;
use App\Services\ERP\Sales\SalesInvoiceService;
use App\Services\ERP\Sales\SalesOrderService;
use App\Services\ERP\Sales\SalesQuotationService;
use App\Services\ERP\Sales\SalesReservationService;
use App\Services\ERP\Sales\SalesReturnService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AccountingSalesCorrectionPostingTest extends TestCase
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

    private function inspected(SalesDelivery $delivery, string $accepted = '2', ?string $restocked = null): SalesReturn
    {
        $service = app(SalesReturnService::class);
        $di = $this->deliveryLine($delivery);
        $r = $service->save($this->creator, ['company_id' => $this->company->getKey(), 'sales_delivery_id' => $delivery->getKey(), 'document_date' => now('Asia/Dhaka')->toDateString(), 'reason' => 'PRIVATE-RETURN-REASON', 'notes' => 'PRIVATE-RETURN-NOTES', 'items' => [['sales_delivery_item_id' => $di->getKey(), 'quantity' => $accepted]]]);
        $item = SalesReturnItem::query()->where('sales_return_id', $r->getKey())->firstOrFail();

        return $service->inspect($this->approver, (int) $r->getKey(), ['revision' => $r->getAttribute('revision'), 'reason' => 'Inspect accepted quantity', 'items' => [['id' => $item->getKey(), 'accepted_quantity' => $accepted, 'restocked_quantity' => $restocked ?? $accepted]]]);
    }

    private function postReturn(SalesReturn $r, ?string $key = null): SalesReturn
    {
        return app(SalesReturnService::class)->postReturn($this->creator, (int) $r->getKey(), (int) $r->getAttribute('revision'), $key ?? (string) Str::uuid());
    }

    private function credit(SalesInvoice $invoice, string $amount = '20.00', ?SalesReturn $return = null): SalesCreditNote
    {
        $il = SalesInvoiceItem::query()->where('sales_invoice_id', $invoice->getKey())->firstOrFail();
        $item = $return === null ? ['sales_invoice_item_id' => $il->getKey(), 'credit_type' => 'value', 'amount' => $amount] : ['sales_invoice_item_id' => $il->getKey(), 'sales_return_item_id' => SalesReturnItem::query()->where('sales_return_id', $return->getKey())->firstOrFail()->getKey(), 'credit_type' => 'quantity', 'quantity' => $amount];

        return app(SalesCreditNoteService::class)->save($this->creator, ['company_id' => $this->company->getKey(), 'sales_invoice_id' => $invoice->getKey(), 'sales_return_id' => $return?->getKey(), 'document_date' => now('Asia/Dhaka')->toDateString(), 'reason' => 'PRIVATE-CREDIT-REASON', 'notes' => 'PRIVATE-CREDIT-NOTES', 'items' => [$item]]);
    }

    private function issueCredit(SalesCreditNote $note, ?string $key = null): SalesCreditNote
    {
        return app(SalesCreditNoteService::class)->issue($this->approver, (int) $note->getKey(), (int) $note->getAttribute('revision'), $key ?? (string) Str::uuid());
    }

    /** @return array{SalesInvoice, SalesInvoice} */
    private function creditPair(): array
    {
        $this->balance->update(['quantity_on_hand' => '20.0000']);
        $source = $this->invoice();
        $target = $this->invoice();
        $this->postReceipt($this->receipt(), [['sales_invoice_id' => (int) $source->getKey(), 'amount' => '60.00']]);
        $this->issueCredit($this->credit($source));

        return [$source, $target];
    }

    /** @return array<string, mixed> */
    private function transferData(SalesInvoice $source, SalesInvoice $target): array
    {
        return ['company_id' => $this->company->getKey(), 'source_sales_invoice_id' => $source->getKey(), 'sales_invoice_id' => $target->getKey(), 'amount' => '20.00', 'reason' => 'PRIVATE-TRANSFER-REASON', 'idempotency_key' => (string) Str::uuid()];
    }

    private function effect(string $kind, int $id): AccountingSourceEffect
    {
        return AccountingSourceEffect::query()->where('company_id', $this->company->getKey())->where('source_kind', $kind)->where('source_id', $id)->firstOrFail();
    }

    public function test_unbilled_restock_restores_inventory_and_releases_dispatch_without_revenue(): void
    {
        $d = $this->fulfillment($this->order());
        $this->postReturn($this->inspected($d));
        $this->assertSame('10.00', $this->role('inventory', 'debit'));
        $this->assertSame('10.00', $this->role('dispatched_inventory', 'credit'));
        $this->assertSame('0.00', $this->role('trade_ar', 'credit'));
        $this->assertSame('0.00', $this->role('cogs', 'credit'));
        $this->assertDatabaseCount('journal_entries', 2);
    }

    public function test_unbilled_damaged_return_expenses_original_dispatch_without_stock_intake(): void
    {
        $d = $this->fulfillment($this->order());
        $this->postReturn($this->inspected($d, '2', '0'));
        $this->assertSame('10.00', $this->role('inventory_loss', 'debit'));
        $this->assertSame('10.00', $this->role('dispatched_inventory', 'credit'));
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_billed_restock_restores_inventory_and_cogs_without_commercial_credit(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $this->issueInvoice($this->bill($o, $d));
        $this->postReturn($this->inspected($d));
        $this->assertSame('10.00', $this->role('inventory', 'debit'));
        $this->assertSame('10.00', $this->role('cogs', 'credit'));
        $this->assertSame('0.00', $this->role('trade_ar', 'credit'));
        $this->assertDatabaseCount('journal_entries', 4);
    }

    public function test_billed_damaged_return_has_verified_zero_gl_and_preserves_cogs(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $this->issueInvoice($this->bill($o, $d));
        $r = $this->postReturn($this->inspected($d, '2', '0'));
        $effect = $this->effect('sales_return', (int) $r->getKey());
        $this->assertSame('verified_no_journal', $effect->getAttribute('outcome'));
        $this->assertNull($effect->getAttribute('journal_entry_id'));
        $this->assertSame('0.00', $this->role('cogs', 'credit'));
        $this->assertDatabaseCount('journal_entries', 3);
    }

    public function test_later_invoice_consumes_only_remaining_dispatch_after_unbilled_return(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $this->postReturn($this->inspected($d));
        $this->issueInvoice($this->bill($o, $d, '4'));
        $this->assertSame('30.00', $this->role('dispatched_inventory', 'credit'));
        $this->assertSame('20.00', $this->role('cogs', 'debit'));
        $this->assertDatabaseCount('journal_entries', 4);
    }

    public function test_partial_invoice_mixed_return_splits_unbilled_and_billed_cost(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $this->issueInvoice($this->bill($o, $d, '4'));
        $this->postReturn($this->inspected($d, '3', '3'));
        $this->assertSame('15.00', $this->role('inventory', 'debit'));
        $this->assertSame('30.00', $this->role('dispatched_inventory', 'credit'));
        $this->assertSame('5.00', $this->role('cogs', 'credit'));
    }

    public function test_restock_is_assigned_to_unbilled_units_first_and_damage_stays_in_cogs(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $this->issueInvoice($this->bill($o, $d, '4'));
        $this->postReturn($this->inspected($d, '3', '1'));
        $this->assertSame('5.00', $this->role('inventory', 'debit'));
        $this->assertSame('5.00', $this->role('inventory_loss', 'debit'));
        $this->assertSame('0.00', $this->role('cogs', 'credit'));
    }

    public function test_multiple_returns_release_original_cost_without_double_inventory(): void
    {
        $d = $this->fulfillment($this->order());
        $this->postReturn($this->inspected($d, '2'));
        $this->postReturn($this->inspected($d, '4'));
        $this->assertSame('30.00', $this->role('inventory', 'debit'));
        $this->assertSame('30.00', $this->role('dispatched_inventory', 'credit'));
        $this->assertSame('10.0000', $this->balance->refresh()->getAttribute('quantity_on_hand'));
    }

    public function test_return_replay_survives_disabled_accounting_and_closed_period(): void
    {
        $d = $this->fulfillment($this->order());
        $r = $this->postReturn($this->inspected($d));
        $this->settings(['state' => 'disabled']);
        DB::table('accounting_periods')->update(['state' => 'hard_closed']);
        $this->postReturn($r, (string) $r->getAttribute('idempotency_key'));
        $this->assertDatabaseCount('journal_entries', 2);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_missing_return_effect_cannot_be_recreated_by_retry(): void
    {
        $d = $this->fulfillment($this->order());
        $r = $this->postReturn($this->inspected($d));
        DB::table('accounting_source_effects')->where('source_kind', 'sales_return')->delete();
        $this->invalid(fn () => $this->postReturn($r, (string) $r->getAttribute('idempotency_key')));
        $this->assertDatabaseCount('journal_entries', 2);
    }

    public function test_closed_period_rolls_back_return_stock_and_counters(): void
    {
        $d = $this->fulfillment($this->order());
        $r = $this->inspected($d);
        DB::table('accounting_periods')->update(['state' => 'hard_closed']);
        $this->invalid(fn () => $this->postReturn($r));
        $this->assertSame('inspected', $r->refresh()->getAttribute('status'));
        $this->assertSame('0.0000', $this->deliveryLine($d)->getAttribute('returned_quantity'));
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_return_missing_mapping_rolls_back_intake(): void
    {
        $d = $this->fulfillment($this->order());
        $r = $this->inspected($d);
        Account::query()->whereKey($this->mapping['inventory'])->update(['status' => 'inactive']);
        $this->invalid(fn () => $this->postReturn($r));
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame('4.0000', $this->balance->refresh()->getAttribute('quantity_on_hand'));
    }

    public function test_legacy_dispatch_return_requires_verified_coverage(): void
    {
        $this->settings(['state' => 'disabled']);
        $d = $this->fulfillment($this->order());
        $r = $this->inspected($d);
        $this->settings(['state' => 'enabled']);
        $this->invalid(fn () => $this->postReturn($r));
        $this->assertDatabaseCount('accounting_sales_events', 0);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_value_credit_posts_allowance_tax_and_ar_without_inventory(): void
    {
        $i = $this->invoice();
        $this->issueCredit($this->credit($i));
        $this->assertSame('20.00', $this->role('sales_allowances', 'debit'));
        $this->assertSame('20.00', $this->role('trade_ar', 'credit'));
        $this->assertSame('0.00', $this->role('cogs', 'credit'));
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('journal_entries', 4);
    }

    public function test_return_linked_quantity_credit_does_not_restore_inventory_twice(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $i = $this->issueInvoice($this->bill($o, $d));
        $r = $this->postReturn($this->inspected($d));
        $this->issueCredit($this->credit($i, '2', $r));
        $this->assertSame('10.00', $this->role('inventory', 'debit'));
        $this->assertSame('10.00', $this->role('cogs', 'credit'));
        $this->assertSame('20.00', $this->role('trade_ar', 'credit'));
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_quantity_credit_without_return_is_commercial_only(): void
    {
        $i = $this->invoice();
        $il = SalesInvoiceItem::query()->where('sales_invoice_id', $i->getKey())->firstOrFail();
        $n = app(SalesCreditNoteService::class)->save($this->creator, ['company_id' => $this->company->getKey(), 'sales_invoice_id' => $i->getKey(), 'document_date' => now('Asia/Dhaka')->toDateString(), 'reason' => 'Quantity price correction', 'items' => [['sales_invoice_item_id' => $il->getKey(), 'credit_type' => 'quantity', 'quantity' => '2']]]);
        $this->issueCredit($n);
        $this->assertSame('20.00', $this->role('trade_ar', 'credit'));
        $this->assertSame('0.00', $this->role('inventory', 'debit'));
        $this->assertSame('0.00', $this->role('cogs', 'credit'));
    }

    public function test_credit_requires_independent_issuer(): void
    {
        $n = $this->credit($this->invoice());
        $this->expectException(AuthorizationException::class);
        app(SalesCreditNoteService::class)->issue($this->creator, (int) $n->getKey(), (int) $n->getAttribute('revision'), (string) Str::uuid());
    }

    public function test_credit_replay_does_not_append_journals(): void
    {
        $n = $this->issueCredit($this->credit($this->invoice()));
        $this->issueCredit($n, (string) $n->getAttribute('idempotency_key'));
        $this->assertDatabaseCount('journal_entries', 4);
    }

    public function test_credit_failure_rolls_back_native_credited_counters_and_status(): void
    {
        $i = $this->invoice();
        $n = $this->credit($i);
        Account::query()->whereKey($this->mapping['sales_allowances'])->update(['status' => 'inactive']);
        $this->invalid(fn () => $this->issueCredit($n));
        $this->assertSame('draft', $n->refresh()->getAttribute('status'));
        $this->assertSame('0.00', SalesInvoiceItem::query()->where('sales_invoice_id', $i->getKey())->firstOrFail()->getAttribute('credited_amount'));
        $this->assertDatabaseCount('journal_entries', 3);
    }

    public function test_legacy_invoice_credit_is_rejected_without_financial_backfill(): void
    {
        $this->settings(['state' => 'disabled']);
        $i = $this->invoice();
        $n = $this->credit($i);
        $this->settings(['state' => 'enabled']);
        $this->invalid(fn () => $this->issueCredit($n));
        $this->assertSame('draft', $n->refresh()->getAttribute('status'));
        $this->assertDatabaseCount('accounting_sales_events', 0);
    }

    public function test_same_customer_credit_transfer_records_zero_gl_with_dependencies(): void
    {
        [$source, $target] = $this->creditPair();
        $count = JournalEntry::query()->count();
        $a = app(CustomerCreditAllocationService::class)->allocate($this->creator, $this->transferData($source, $target));
        $this->assertSame('verified_no_journal', $this->effect('customer_credit_allocation', (int) $a->getKey())->getAttribute('outcome'));
        $this->assertDatabaseCount('journal_entries', $count);
        $this->assertSame('20.00', $a->getAttribute('amount'));
    }

    public function test_credit_transfer_reversal_records_compensation_without_cash(): void
    {
        [$source, $target] = $this->creditPair();
        $service = app(CustomerCreditAllocationService::class);
        $a = $service->allocate($this->creator, $this->transferData($source, $target));
        $count = JournalEntry::query()->count();
        $undo = $service->reverse($this->creator, (int) $a->getKey(), ['reason' => 'Compensate credit allocation', 'idempotency_key' => (string) Str::uuid()]);
        $this->assertSame('verified_no_journal', $this->effect('customer_credit_allocation', (int) $undo->getKey())->getAttribute('outcome'));
        $this->assertDatabaseCount('journal_entries', $count);
        $this->assertSame('60.00', $this->role('cash:MAIN', 'debit'));
        $this->assertSame('0.00', $this->role('cash:MAIN', 'credit'));
    }

    public function test_credit_transfer_native_retries_verify_original_evidence(): void
    {
        [$source, $target] = $this->creditPair();
        $data = $this->transferData($source, $target);
        $service = app(CustomerCreditAllocationService::class);
        $a = $service->allocate($this->creator, $data);
        $count = AccountingSourceEffect::query()->count();
        $service->allocate($this->creator, $data);
        $this->assertDatabaseCount('accounting_source_effects', $count);
        $this->assertDatabaseCount('customer_credit_allocations', 1);
    }

    public function test_missing_transfer_effect_is_not_repaired_on_retry(): void
    {
        [$source, $target] = $this->creditPair();
        $data = $this->transferData($source, $target);
        $service = app(CustomerCreditAllocationService::class);
        $service->allocate($this->creator, $data);
        DB::table('accounting_source_effects')->where('source_kind', 'customer_credit_allocation')->delete();
        $this->invalid(fn () => $service->allocate($this->creator, $data));
        $this->assertDatabaseCount('customer_credit_allocations', 1);
    }

    public function test_closed_period_blocks_even_zero_gl_new_transfer(): void
    {
        [$source, $target] = $this->creditPair();
        DB::table('accounting_periods')->update(['state' => 'hard_closed']);
        $this->invalid(fn () => app(CustomerCreditAllocationService::class)->allocate($this->creator, $this->transferData($source, $target)));
        $this->assertDatabaseCount('customer_credit_allocations', 0);
    }

    public function test_legacy_credit_note_cannot_supply_live_credit_transfer(): void
    {
        $this->balance->update(['quantity_on_hand' => '20.0000']);
        $source = $this->invoice();
        $target = $this->invoice();
        $this->postReceipt($this->receipt(), [['sales_invoice_id' => (int) $source->getKey(), 'amount' => '60.00']]);
        $this->settings(['state' => 'disabled']);
        $this->issueCredit($this->credit($source));
        $this->settings(['state' => 'enabled']);
        $this->invalid(fn () => app(CustomerCreditAllocationService::class)->allocate($this->creator, $this->transferData($source, $target)));
        $this->assertDatabaseCount('customer_credit_allocations', 0);
    }

    public function test_disabled_accounting_keeps_native_corrections_without_markers(): void
    {
        $this->settings(['state' => 'disabled']);
        $o = $this->order();
        $d = $this->fulfillment($o);
        $i = $this->issueInvoice($this->bill($o, $d));
        $this->postReturn($this->inspected($d));
        $this->issueCredit($this->credit($i));
        $this->assertDatabaseCount('accounting_sales_events', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_private_correction_notes_reasons_and_snapshots_are_excluded(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $i = $this->issueInvoice($this->bill($o, $d));
        $this->postReturn($this->inspected($d));
        $this->issueCredit($this->credit($i));
        foreach (AccountingSalesEvent::query()->get() as $event) {
            $json = json_encode($event->getAttribute('payload'), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('PRIVATE-', $json);
            $this->assertStringNotContainsString('customer_snapshot', $json);
        }
    }

    public function test_source_staff_can_credit_without_manual_journal_permission(): void
    {
        $n = $this->credit($this->invoice());
        $staff = $this->staff(['erp.sales-credit-notes.issue']);
        $this->assertFalse($staff->can('erp.accounting-journals.post'));
        app(SalesCreditNoteService::class)->issue($staff, (int) $n->getKey(), (int) $n->getAttribute('revision'), (string) Str::uuid());
        $this->assertDatabaseCount('journal_entries', 4);
    }

    public function test_foreign_actor_cannot_post_return(): void
    {
        $d = $this->fulfillment($this->order());
        $r = $this->inspected($d);
        $staff = $this->staff([]);
        $this->expectException(AuthorizationException::class);
        app(SalesReturnService::class)->postReturn($staff, (int) $r->getKey(), (int) $r->getAttribute('revision'), (string) Str::uuid());
    }

    public function test_return_rounding_residual_is_explicit_and_dispatch_is_exhausted_exactly(): void
    {
        $this->balance->update(['average_cost' => '0.3333']);
        $d = $this->fulfillment($this->order());
        foreach (['1', '1', '4'] as $q) {
            $this->postReturn($this->inspected($d, $q));
        }
        $this->assertSame('2.00', $this->role('dispatched_inventory', 'credit'));
        $this->assertSame('1.99', $this->role('inventory', 'debit'));
        $this->assertSame('0.01', bcsub($this->role('inventory_loss', 'debit'), $this->role('inventory_loss', 'credit'), 2));
    }

    public function test_zero_cost_return_records_verified_outcome_without_empty_journal(): void
    {
        $this->balance->update(['average_cost' => '0.0000']);
        $d = $this->fulfillment($this->order());
        $r = $this->postReturn($this->inspected($d));
        $this->assertSame('verified_no_journal', $this->effect('sales_return', (int) $r->getKey())->getAttribute('outcome'));
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_partial_credit_preserves_exact_saved_discount_and_tax_components(): void
    {
        $o = $this->order([['product_id' => $this->product->getKey(), 'quantity' => '6', 'unit_price' => '10', 'discount_percent' => '10', 'tax_percent' => '15']]);
        $i = $this->issueInvoice($this->bill($o, $this->fulfillment($o)));
        $n = $this->issueCredit($this->credit($i, '10.00'));
        $line = SalesCreditNoteItem::query()->where('sales_credit_note_id', $n->getKey())->firstOrFail();
        $this->assertSame($line->getAttribute('tax_amount'), $this->role('output_tax', 'debit'));
        $this->assertSame(bcsub((string) $line->getAttribute('subtotal'), (string) $line->getAttribute('discount_amount'), 2), $this->role('sales_allowances', 'debit'));
        $this->assertSame('10.00', $this->role('trade_ar', 'credit'));
    }

    public function test_credit_replay_survives_closed_period_and_inactive_accounts(): void
    {
        $n = $this->issueCredit($this->credit($this->invoice()));
        DB::table('accounting_periods')->update(['state' => 'hard_closed']);
        $this->settings(['state' => 'disabled']);
        Account::query()->whereKey($this->mapping['sales_allowances'])->update(['status' => 'inactive']);
        $this->issueCredit($n, (string) $n->getAttribute('idempotency_key'));
        $this->assertDatabaseCount('journal_entries', 4);
    }

    public function test_damaged_credit_admission_is_rejected_on_retry(): void
    {
        $n = $this->issueCredit($this->credit($this->invoice()));
        DB::table('accounting_sales_events')->where('source_kind', 'sales_credit_note')->update(['payload_hash' => str_repeat('0', 64)]);
        $this->invalid(fn () => $this->issueCredit($n, (string) $n->getAttribute('idempotency_key')));
        $this->assertDatabaseCount('journal_entries', 4);
    }

    public function test_damaged_return_movement_rejects_replay_without_new_stock(): void
    {
        $d = $this->fulfillment($this->order());
        $r = $this->postReturn($this->inspected($d));
        $item = SalesReturnItem::query()->where('sales_return_id', $r->getKey())->firstOrFail();
        DB::table('stock_movements')->where('id', $item->getAttribute('stock_movement_id'))->update(['total_cost' => '11.0000']);
        $this->invalid(fn () => $this->postReturn($r, (string) $r->getAttribute('idempotency_key')));
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_transfer_replay_after_reversal_remains_idempotent(): void
    {
        [$source, $target] = $this->creditPair();
        $data = $this->transferData($source, $target);
        $service = app(CustomerCreditAllocationService::class);
        $a = $service->allocate($this->creator, $data);
        $reverse = ['reason' => 'Compensate transfer', 'idempotency_key' => (string) Str::uuid()];
        $service->reverse($this->creator, (int) $a->getKey(), $reverse);
        $service->allocate($this->creator, $data);
        $service->reverse($this->creator, (int) $a->getKey(), $reverse);
        $this->assertDatabaseCount('customer_credit_allocations', 2);
        $this->assertSame(2, AccountingSourceEffect::query()->where('source_kind', 'customer_credit_allocation')->count());
    }

    public function test_legacy_credit_blocks_new_receipt_allocation_atomically(): void
    {
        $i = $this->invoice();
        $this->settings(['state' => 'disabled']);
        $this->issueCredit($this->credit($i));
        $this->settings(['state' => 'enabled']);
        $r = $this->receipt('40.00');
        $this->invalid(fn () => $this->postReceipt($r, [['sales_invoice_id' => (int) $i->getKey(), 'amount' => '40.00']]));
        $this->assertSame('draft', $r->refresh()->getAttribute('status'));
        $this->assertDatabaseCount('customer_receipt_allocations', 0);
        $this->assertDatabaseCount('journal_entries', 3);
    }

    public function test_return_writer_failure_after_native_intake_rolls_back_every_member(): void
    {
        $d = $this->fulfillment($this->order());
        $r = $this->inspected($d);
        $armed = true;
        Event::listen('eloquent.created: '.JournalEntry::class, static function (JournalEntry $journal) use (&$armed): void {
            if ($armed && str_starts_with((string) $journal->getAttribute('description'), 'sales_return ')) {
                throw ValidationException::withMessages(['fixture_failure' => 'Injected journal failure']);
            }
        });
        try {
            $this->invalid(fn () => $this->postReturn($r), 'fixture_failure');
        } finally {
            $armed = false;
        }
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertSame('inspected', $r->refresh()->getAttribute('status'));
        $this->assertSame('0.0000', $this->deliveryLine($d)->getAttribute('returned_quantity'));
    }

    public function test_credit_transfer_failure_rolls_back_native_allocation(): void
    {
        [$source, $target] = $this->creditPair();
        $armed = true;
        Event::listen('eloquent.creating: '.AccountingSourceEffect::class, static function (AccountingSourceEffect $effect) use (&$armed): void {
            if ($armed && $effect->getAttribute('source_kind') === 'customer_credit_allocation') {
                throw ValidationException::withMessages(['fixture_failure' => 'Injected no-journal effect failure']);
            }
        });
        try {
            $this->invalid(fn () => app(CustomerCreditAllocationService::class)->allocate($this->creator, $this->transferData($source, $target)), 'fixture_failure');
        } finally {
            $armed = false;
        }
        $this->assertDatabaseCount('customer_credit_allocations', 0);
        $this->assertSame(0, AccountingSalesEvent::query()->where('source_kind', 'customer_credit_allocation')->count());
    }
}
