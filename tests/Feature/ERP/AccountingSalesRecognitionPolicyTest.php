<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingMappingVersion;
use App\Models\Accounting\AccountingSalesCostSlice;
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
use App\Models\SalesDelivery;
use App\Models\SalesDeliveryItem;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\SalesStockReservation;
use App\Models\StockMovement;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AccountingSalesRecognitionPolicyTest extends TestCase
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

    private function netRole(string $role): string
    {
        return bcsub($this->role($role, 'debit'), $this->role($role, 'credit'), 2);
    }

    private function assertValuationConserved(): void
    {
        $sum = '0.00';
        foreach (['inventory', 'dispatched_inventory', 'cogs', 'inventory_loss'] as $role) {
            $sum = bcadd($sum, $this->netRole($role), 2);
        }
        $this->assertSame('0.00', $sum, 'Inventory, dispatched goods, recognized COGS and return losses must conserve original value.');
    }

    private function assertNoValuationChange(callable $action): void
    {
        $before = [];
        foreach (['inventory', 'dispatched_inventory', 'cogs', 'inventory_loss'] as $role) {
            $before[$role] = [$this->role($role, 'debit'), $this->role($role, 'credit')];
        }
        $movements = StockMovement::query()->count();
        $action();
        foreach ($before as $role => $values) {
            $this->assertSame($values, [$this->role($role, 'debit'), $this->role($role, 'credit')]);
        }
        $this->assertDatabaseCount('stock_movements', $movements);
        $this->assertValuationConserved();
    }

    public function test_partial_dispatch_is_an_asset_transfer_before_any_invoice(): void
    {
        $o = $this->order();
        $this->fulfillment($o, '2');
        $this->assertSame('-10.00', $this->netRole('inventory'));
        $this->assertSame('10.00', $this->netRole('dispatched_inventory'));
        $this->assertSame('0.00', $this->netRole('cogs'));
        $this->assertSame('0.00', $this->netRole('sales_revenue'));
        $this->assertSame('0.00', $this->netRole('trade_ar'));
        $this->assertValuationConserved();
    }

    public function test_partial_invoices_leave_only_uninvoiced_cost_in_dispatched_assets(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $this->issueInvoice($this->bill($o, $d, '2'));
        $this->assertSame('20.00', $this->netRole('dispatched_inventory'));
        $this->assertSame('10.00', $this->netRole('cogs'));
        $this->assertValuationConserved();
        $this->issueInvoice($this->bill($o, $d, '4'));
        $this->assertSame('0.00', $this->netRole('dispatched_inventory'));
        $this->assertSame('30.00', $this->netRole('cogs'));
        $this->assertSame('60.00', $this->netRole('trade_ar'));
        $this->assertValuationConserved();
    }

    public function test_one_invoice_can_release_cost_from_two_partial_deliveries(): void
    {
        $o = $this->order();
        $a = $this->fulfillment($o, '2');
        $this->balance->refresh()->update(['average_cost' => '7.0000']);
        $b = $this->fulfillment($o, '4');
        $i = app(SalesInvoiceService::class)->save($this->creator, $this->invoicePayload($o, [['sales_delivery_item_id' => $this->deliveryLine($a)->getKey(), 'quantity' => '2'], ['sales_delivery_item_id' => $this->deliveryLine($b)->getKey(), 'quantity' => '4']]));
        $this->issueInvoice($i);
        $this->assertSame('38.00', $this->netRole('cogs'));
        $this->assertSame('0.00', $this->netRole('dispatched_inventory'));
        $this->assertSame('-38.00', $this->netRole('inventory'));
        $this->assertValuationConserved();
    }

    public function test_return_then_partial_invoices_exhaust_only_remaining_original_cost(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $this->postReturn($this->inspected($d, '2'));
        $this->issueInvoice($this->bill($o, $d, '2'));
        $this->assertSame('10.00', $this->netRole('dispatched_inventory'));
        $this->assertValuationConserved();
        $this->issueInvoice($this->bill($o, $d, '2'));
        $this->assertSame('0.00', $this->netRole('dispatched_inventory'));
        $this->assertSame('20.00', $this->netRole('cogs'));
        $this->assertSame('-20.00', $this->netRole('inventory'));
        $this->assertSame('40.00', $this->netRole('trade_ar'));
        $this->assertValuationConserved();
    }

    public function test_unbilled_damage_is_loss_and_cannot_be_invoiced_later(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $this->postReturn($this->inspected($d, '2', '0'));
        $this->issueInvoice($this->bill($o, $d, '4'));
        $this->assertSame('10.00', $this->netRole('inventory_loss'));
        $this->assertSame('20.00', $this->netRole('cogs'));
        $this->assertSame('0.00', $this->netRole('dispatched_inventory'));
        $this->assertValuationConserved();
        $this->invalid(fn () => $this->bill($o, $d, '1'));
    }

    public function test_billed_damage_retains_cogs_after_full_commercial_credit(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $i = $this->issueInvoice($this->bill($o, $d));
        $r = $this->postReturn($this->inspected($d, '6', '0'));
        $this->issueCredit($this->credit($i, '6', $r));
        $this->assertSame('30.00', $this->netRole('cogs'));
        $this->assertSame('0.00', $this->netRole('trade_ar'));
        $this->assertSame('60.00', $this->netRole('sales_allowances'));
        $this->assertSame('-30.00', $this->netRole('inventory'));
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertValuationConserved();
    }

    public function test_billed_restock_and_full_credit_restore_cost_without_erasing_original_revenue(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $i = $this->issueInvoice($this->bill($o, $d));
        $r = $this->postReturn($this->inspected($d, '6'));
        $this->issueCredit($this->credit($i, '6', $r));
        $this->assertSame('0.00', $this->netRole('inventory'));
        $this->assertSame('0.00', $this->netRole('cogs'));
        $this->assertSame('0.00', $this->netRole('trade_ar'));
        $this->assertSame('60.00', $this->role('sales_revenue', 'credit'));
        $this->assertSame('60.00', $this->role('sales_allowances', 'debit'));
        $this->assertValuationConserved();
    }

    public function test_billed_damaged_units_are_not_restored_again_by_later_restock(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $this->issueInvoice($this->bill($o, $d));
        $this->postReturn($this->inspected($d, '2', '0'));
        $this->postReturn($this->inspected($d, '4'));
        $this->assertSame('10.00', $this->netRole('cogs'));
        $this->assertSame('-10.00', $this->netRole('inventory'));
        $this->assertSame('20.00', $this->role('cogs', 'credit'));
        $this->assertValuationConserved();
    }

    public function test_mixed_return_keeps_unbilled_loss_separate_from_billed_cogs(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $this->issueInvoice($this->bill($o, $d, '4'));
        $this->postReturn($this->inspected($d, '3', '1'));
        $this->postReturn($this->inspected($d, '3'));
        $this->assertSame('5.00', $this->netRole('inventory_loss'));
        $this->assertSame('5.00', $this->netRole('cogs'));
        $this->assertSame('-10.00', $this->netRole('inventory'));
        $this->assertSame('0.00', $this->netRole('dispatched_inventory'));
        $this->assertValuationConserved();
    }

    public function test_value_only_credit_changes_commercial_amounts_without_cost_or_stock(): void
    {
        $i = $this->invoice();
        $this->assertNoValuationChange(fn () => $this->issueCredit($this->credit($i, '15.00')));
        $this->assertSame('45.00', $this->netRole('trade_ar'));
        $this->assertSame('15.00', $this->netRole('sales_allowances'));
    }

    public function test_later_return_after_value_credit_changes_stock_and_cogs_without_second_ar_credit(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $i = $this->issueInvoice($this->bill($o, $d));
        $this->issueCredit($this->credit($i, '10.00'));
        $ar = $this->netRole('trade_ar');
        $this->postReturn($this->inspected($d));
        $this->assertSame($ar, $this->netRole('trade_ar'));
        $this->assertSame('20.00', $this->netRole('cogs'));
        $this->assertSame('-20.00', $this->netRole('inventory'));
        $this->assertValuationConserved();
    }

    public function test_nonstock_service_completion_has_commercial_revenue_but_no_inventory_cost(): void
    {
        $p = $this->product('Consulting', 'service', false);
        $o = $this->order([['product_id' => $p->getKey(), 'quantity' => '2', 'unit_price' => '20', 'discount_percent' => '0', 'tax_percent' => '0']]);
        $d = $this->postDelivery(app(SalesDeliveryService::class)->save($this->creator, $this->payload($o, [['sales_order_item_id' => $this->line($o, $p)->getKey(), 'quantity' => '2']])));
        $this->issueInvoice($this->bill($o, $d, '2'));
        $this->assertSame('40.00', $this->netRole('trade_ar'));
        $this->assertSame('0.00', $this->netRole('cogs'));
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(2, AccountingSourceEffect::query()->where('no_journal_reason', 'nonstock_completion')->count());
        $this->assertValuationConserved();
    }

    public function test_zero_cost_stock_still_records_commercial_invoice_and_physical_return(): void
    {
        $this->balance->update(['average_cost' => '0.0000']);
        $o = $this->order();
        $d = $this->fulfillment($o);
        $i = $this->issueInvoice($this->bill($o, $d));
        $this->postReturn($this->inspected($d));
        $this->assertSame('60.00', $this->netRole('trade_ar'));
        $this->assertSame('0.00', $this->netRole('cogs'));
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertValuationConserved();
    }

    public function test_current_average_cost_does_not_revalue_original_return_or_invoice(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $this->balance->refresh()->update(['average_cost' => '50.0000']);
        $this->postReturn($this->inspected($d));
        $this->issueInvoice($this->bill($o, $d, '4'));
        $returnMovement = StockMovement::query()->where('reference_type', SalesReturn::class)->firstOrFail();
        $this->assertSame('5.0000', $returnMovement->getAttribute('unit_cost'));
        $this->assertSame('20.00', $this->netRole('cogs'));
        $this->assertValuationConserved();
    }

    public function test_partial_return_rounding_and_later_invoice_conserve_every_original_cent(): void
    {
        $this->balance->update(['average_cost' => '0.3333']);
        $o = $this->order();
        $d = $this->fulfillment($o);
        $this->postReturn($this->inspected($d, '1'));
        $this->issueInvoice($this->bill($o, $d, '1'));
        $this->postReturn($this->inspected($d, '1'));
        $this->issueInvoice($this->bill($o, $d, '3'));
        $this->assertSame('2.00', $this->role('dispatched_inventory', 'credit'));
        $this->assertSame('0.00', $this->netRole('dispatched_inventory'));
        $this->assertSame('0.66', $this->role('inventory', 'debit'));
        $this->assertValuationConserved();
    }

    public function test_full_billed_return_rounding_keeps_inventory_and_cogs_bridge_explicit(): void
    {
        $this->balance->update(['average_cost' => '0.3333']);
        $o = $this->order();
        $d = $this->fulfillment($o);
        $this->issueInvoice($this->bill($o, $d));
        foreach (['1', '1', '4'] as $q) {
            $this->postReturn($this->inspected($d, $q));
        }
        $this->assertSame('0.00', $this->netRole('cogs'));
        $this->assertSame('0.01', $this->netRole('inventory_loss'));
        $this->assertSame('-0.01', $this->netRole('inventory'));
        $this->assertValuationConserved();
    }

    public function test_cash_receipt_allocation_and_full_reversal_do_not_change_cost_recognition(): void
    {
        $i = $this->invoice();
        $r = $this->receipt();
        $this->assertNoValuationChange(fn () => $this->postReceipt($r, [['sales_invoice_id' => (int) $i->getKey(), 'amount' => '60.00']]));
        $this->assertNoValuationChange(fn () => app(CustomerReceiptService::class)->reverseReceipt($this->creator, (int) $r->getKey(), ['revision' => (int) $r->refresh()->getAttribute('revision'), 'idempotency_key' => (string) Str::uuid(), 'reason' => 'Reverse cash without changing fulfillment']));
        $this->assertSame('60.00', $this->netRole('trade_ar'));
        $this->assertSame('30.00', $this->netRole('cogs'));
    }

    public function test_retries_of_complete_return_and_credit_cycle_preserve_original_trace(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $i = $this->issueInvoice($this->bill($o, $d));
        $r = $this->postReturn($this->inspected($d));
        $n = $this->issueCredit($this->credit($i, '2', $r));
        $counts = [JournalEntry::query()->count(), StockMovement::query()->count(), AccountingSalesCostSlice::query()->count(), AccountingSourceEffect::query()->count()];
        $this->postDelivery($d, (string) $d->getAttribute('idempotency_key'));
        $this->issueInvoice($i, (string) $i->getAttribute('idempotency_key'));
        $this->postReturn($r, (string) $r->getAttribute('idempotency_key'));
        $this->issueCredit($n, (string) $n->getAttribute('idempotency_key'));
        $this->assertSame($counts, [JournalEntry::query()->count(), StockMovement::query()->count(), AccountingSalesCostSlice::query()->count(), AccountingSourceEffect::query()->count()]);
        $this->assertValuationConserved();
        $returnEffect = $this->effect('sales_return', (int) $r->getKey());
        $creditEffect = $this->effect('sales_credit_note', (int) $n->getKey());
        $this->assertNotSame((int) $returnEffect->getAttribute('journal_entry_id'), (int) $creditEffect->getAttribute('journal_entry_id'));
        $this->assertSame('valuation', $returnEffect->getAttribute('effect_kind'));
        $this->assertSame('commercial', $creditEffect->getAttribute('effect_kind'));
    }
}
