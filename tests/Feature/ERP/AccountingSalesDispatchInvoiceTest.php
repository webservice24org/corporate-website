<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingMappingVersion;
use App\Models\Accounting\AccountingSalesCostSlice;
use App\Models\Accounting\AccountingSalesEvent;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalEntryLine;
use App\Models\Company;
use App\Models\Customer;
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
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ERP\Accounting\AccountingAccountService;
use App\Services\ERP\Accounting\AccountingCalendarService;
use App\Services\ERP\Accounting\AccountingMappingCatalog;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\Source\AccountingSourceOrchestrator;
use App\Services\ERP\Sales\SalesDeliveryService;
use App\Services\ERP\Sales\SalesInvoiceService;
use App\Services\ERP\Sales\SalesOrderService;
use App\Services\ERP\Sales\SalesQuotationService;
use App\Services\ERP\Sales\SalesReservationService;
use App\Services\Inventory\InventoryService;
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

final class AccountingSalesDispatchInvoiceTest extends TestCase
{
    use RefreshDatabase;

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
        foreach ([...$catalog->roles(), 'cash:MAIN' => 'asset'] as $role => $category) {
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

    public function test_quote_order_and_reservation_do_not_create_financial_effects(): void
    {
        $order = $this->order();
        $this->reserve($order);
        $this->assertDatabaseCount('accounting_source_effects', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_dispatch_recognizes_dispatched_asset_without_revenue_or_cogs(): void
    {
        $d = $this->fulfillment($this->order());
        $this->assertSame('30.00', $this->role('dispatched_inventory', 'debit'));
        $this->assertSame('30.00', $this->role('inventory', 'credit'));
        $this->assertSame('0.00', $this->role('cogs', 'debit'));
        $this->assertSame('0.00', $this->role('trade_ar', 'debit'));
        $this->assertSame('0.00', $this->role('sales_revenue', 'credit'));
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertSame('replayed', $this->replay('sales_delivery', (int) $d->getKey())['mode']);
    }

    public function test_invoice_posts_complete_commercial_and_cost_bundle_once(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $invoice = $this->issueInvoice($this->bill($o, $d));
        $this->assertSame('60.00', $this->role('trade_ar', 'debit'));
        $this->assertSame('60.00', $this->role('sales_revenue', 'credit'));
        $this->assertSame('30.00', $this->role('cogs', 'debit'));
        $this->assertSame('30.00', $this->role('dispatched_inventory', 'credit'));
        $this->assertSame('30.00', $this->role('inventory', 'credit'));
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('accounting_source_effects', 3);
        $this->assertDatabaseCount('journal_entries', 3);
        $before = $this->replay('sales_invoice', (int) $invoice->getKey());
        $this->assertSame('replayed', $before['mode']);
        $this->assertCount(2, $before['effect_ids']);
        $this->assertDatabaseCount('journal_entries', 3);
    }

    public function test_tax_and_discount_follow_saved_invoice_components(): void
    {
        $o = $this->order([['product_id' => $this->product->getKey(), 'quantity' => '6', 'unit_price' => '10', 'discount_percent' => '10', 'tax_percent' => '5']]);
        $d = $this->fulfillment($o);
        $this->issueInvoice($this->bill($o, $d));
        $this->assertSame('54.00', $this->role('sales_revenue', 'credit'));
        $this->assertSame('2.70', $this->role('output_tax', 'credit'));
        $this->assertSame('56.70', $this->role('trade_ar', 'debit'));
        $this->assertSame('30.00', $this->role('cogs', 'debit'));
    }

    public function test_partial_invoice_costs_release_original_rounded_dispatch_value_exactly(): void
    {
        $this->balance->forceFill(['average_cost' => '0.3333'])->save();
        $o = $this->order([['product_id' => $this->product->getKey(), 'quantity' => '3', 'unit_price' => '1', 'discount_percent' => '0', 'tax_percent' => '0']]);
        $d = $this->fulfillment($o, '3');
        foreach (['0.33', '0.67', '1.00'] as $expected) {
            $this->issueInvoice($this->bill($o, $d, '1'));
            $this->assertSame($expected, $this->role('cogs', 'debit'));
        }
        $this->assertSame('1.00', $this->role('dispatched_inventory', 'debit'));
        $this->assertSame('1.00', $this->role('dispatched_inventory', 'credit'));
        $this->assertDatabaseCount('accounting_sales_cost_slices', 3);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_partial_invoice_keeps_uninvoiced_dispatch_cost_on_asset(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $this->issueInvoice($this->bill($o, $d, '2'));
        $this->assertSame('10.00', $this->role('cogs', 'debit'));
        $this->assertSame('20.00', bcsub($this->role('dispatched_inventory', 'debit'), $this->role('dispatched_inventory', 'credit'), 2));
    }

    public function test_invoice_uses_frozen_dispatch_cost_after_average_cost_changes(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $this->balance->refresh()->forceFill(['average_cost' => '50.0000'])->save();
        $this->issueInvoice($this->bill($o, $d));
        $this->assertSame('30.00', $this->role('cogs', 'debit'));
    }

    public function test_nonstock_completion_and_invoice_have_no_inventory_or_cogs_journal(): void
    {
        $p = $this->product('Consulting', 'service', false);
        $o = $this->order([['product_id' => $p->getKey(), 'quantity' => '2', 'unit_price' => '20', 'discount_percent' => '0', 'tax_percent' => '0']]);
        $d = app(SalesDeliveryService::class)->save($this->creator, $this->payload($o, [['sales_order_item_id' => $this->line($o, $p)->getKey(), 'quantity' => '2']]));
        $d = $this->postDelivery($d);
        $this->issueInvoice($this->bill($o, $d, '2'));
        $this->assertSame('40.00', $this->role('trade_ar', 'debit'));
        $this->assertSame('0.00', $this->role('inventory', 'credit'));
        $this->assertSame('0.00', $this->role('cogs', 'debit'));
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertSame(2, AccountingSourceEffect::query()->where('no_journal_reason', 'nonstock_completion')->count());
    }

    public function test_zero_cost_physical_goods_do_not_invent_cost_journals(): void
    {
        $this->balance->forceFill(['average_cost' => '0.0000'])->save();
        $o = $this->order();
        $d = $this->fulfillment($o);
        $this->issueInvoice($this->bill($o, $d));
        $this->assertSame('60.00', $this->role('trade_ar', 'debit'));
        $this->assertSame('0.00', $this->role('cogs', 'debit'));
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_zero_commercial_value_still_recognizes_real_dispatch_cost(): void
    {
        $o = $this->order([['product_id' => $this->product->getKey(), 'quantity' => '6', 'unit_price' => '0', 'discount_percent' => '0', 'tax_percent' => '0']]);
        $d = $this->fulfillment($o);
        $this->issueInvoice($this->bill($o, $d));
        $this->assertSame('0.00', $this->role('trade_ar', 'debit'));
        $this->assertSame('30.00', $this->role('cogs', 'debit'));
        $this->assertSame(1, AccountingSourceEffect::query()->where('no_journal_reason', 'zero_commercial_value')->count());
        $this->assertDatabaseCount('journal_entries', 2);
    }

    public function test_zero_cent_partial_release_is_explicit_and_later_portions_reconcile(): void
    {
        $this->balance->forceFill(['average_cost' => '0.0034'])->save();
        $o = $this->order([['product_id' => $this->product->getKey(), 'quantity' => '3', 'unit_price' => '1', 'discount_percent' => '0', 'tax_percent' => '0']]);
        $d = $this->fulfillment($o, '3');
        $this->issueInvoice($this->bill($o, $d, '1'));
        $this->assertSame('0.00', $this->role('cogs', 'debit'));
        $this->assertSame(1, AccountingSourceEffect::query()->where('no_journal_reason', 'zero_cost_release')->count());
        $this->issueInvoice($this->bill($o, $d, '1'));
        $this->issueInvoice($this->bill($o, $d, '1'));
        $this->assertSame('0.01', $this->role('cogs', 'debit'));
        $this->assertSame('0.01', $this->role('dispatched_inventory', 'credit'));
    }

    public function test_closed_period_rolls_back_dispatch_stock_reservation_and_order_progress(): void
    {
        $o = $this->order();
        $r = $this->reserve($o);
        $d = $this->draft($o, $r);
        DB::table('accounting_periods')->update(['state' => 'hard_closed']);
        $this->invalid(fn () => $this->postDelivery($d), 'accounting_date');
        $this->assertSame('draft', $d->refresh()->getAttribute('status'));
        $this->assertSame('10.0000', $this->balance->refresh()->getAttribute('quantity_on_hand'));
        $this->assertSame('6.0000', $this->balance->getAttribute('quantity_reserved'));
        $this->assertSame('0.0000', $r->refresh()->getAttribute('consumed_quantity'));
        $this->assertSame('0.0000', $this->line($o)->getAttribute('delivered_quantity'));
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('accounting_sales_events', 0);
    }

    public function test_invoice_cost_failure_after_commercial_journal_rolls_back_entire_bundle(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $i = $this->bill($o, $d);
        $seen = [];
        $armed = true;
        Event::listen('eloquent.created: '.JournalEntry::class, function (JournalEntry $journal) use (&$armed, &$seen): void {
            if (! $armed) {
                return;
            } $description = (string) $journal->getAttribute('description');
            $seen[] = $description;
            if (str_ends_with($description, ' cost')) {
                throw ValidationException::withMessages(['fixture_cost' => 'Injected cost writer failure']);
            }
        });
        try {
            $this->invalid(fn () => $this->issueInvoice($i), 'fixture_cost');
        } finally {
            $armed = false;
        }
        $this->assertCount(2, $seen);
        $this->assertStringEndsWith(' commercial', $seen[0]);
        $this->assertStringEndsWith(' cost', $seen[1]);
        $this->assertSame('draft', $i->refresh()->getAttribute('status'));
        $this->assertSame('0.0000', $this->deliveryLine($d)->getAttribute('invoiced_quantity'));
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('accounting_sales_events', 1);
        $this->assertDatabaseCount('accounting_sales_cost_slices', 0);
        $this->assertDatabaseCount('accounting_source_effects', 1);
        $this->assertSame('0.00', $this->role('trade_ar', 'debit'));
    }

    public function test_invoice_missing_mapping_rolls_back_native_issue_and_counters(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $i = $this->bill($o, $d);
        Account::query()->whereKey($this->mapping['trade_ar'])->update(['status' => 'inactive']);
        $this->invalid(fn () => $this->issueInvoice($i), 'mappings');
        $this->assertSame('draft', $i->refresh()->getAttribute('status'));
        $this->assertSame('0.0000', $this->deliveryLine($d)->getAttribute('invoiced_quantity'));
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_dispatch_and_invoice_native_retries_do_not_append_financial_effects(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $i = $this->issueInvoice($this->bill($o, $d));
        $audit = DB::table('accounting_audit_events')->count();
        $this->postDelivery($d, (string) $d->getAttribute('idempotency_key'));
        $this->issueInvoice($i, (string) $i->getAttribute('idempotency_key'));
        $this->assertDatabaseCount('journal_entries', 3);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame($audit, DB::table('accounting_audit_events')->count());
    }

    public function test_financial_replay_precedes_current_closed_period_disabled_state_and_account_checks(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $i = $this->issueInvoice($this->bill($o, $d));
        $this->settings(['state' => 'disabled', 'cutover_locked_at' => now()]);
        DB::table('accounting_periods')->update(['state' => 'hard_closed']);
        Account::query()->whereKey($this->mapping['cogs'])->update(['status' => 'inactive']);
        $this->issueInvoice($i, (string) $i->getAttribute('idempotency_key'));
        $this->postDelivery($d, (string) $d->getAttribute('idempotency_key'));
        $this->assertDatabaseCount('journal_entries', 3);
    }

    public function test_changed_dispatch_cost_or_movement_fails_before_invoice_finance(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        DB::table('stock_movements')->where('reference_type', SalesDelivery::class)->update(['total_cost' => '31.0000']);
        $this->invalid(fn () => $this->bill($o, $d));
        $this->assertDatabaseCount('sales_invoices', 0);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_old_disabled_dispatch_is_not_backfilled_by_retry_or_invoice_issue(): void
    {
        $this->settings(['state' => 'disabled']);
        $o = $this->order();
        $d = $this->fulfillment($o);
        $i = $this->bill($o, $d);
        $this->settings(['state' => 'enabled']);
        $this->invalid(fn () => $this->postDelivery($d, (string) $d->getAttribute('idempotency_key')), 'accounting_sales');
        $this->invalid(fn () => $this->issueInvoice($i), 'accounting_sales');
        $this->assertSame('draft', $i->refresh()->getAttribute('status'));
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_invoice_issued_while_disabled_cannot_be_ignored_by_cost_cursor(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $this->settings(['state' => 'disabled']);
        $this->issueInvoice($this->bill($o, $d, '2'));
        $this->settings(['state' => 'enabled']);
        $next = $this->bill($o, $d, '2');
        $this->invalid(fn () => $this->issueInvoice($next), 'accounting_sales');
        $this->assertSame('draft', $next->refresh()->getAttribute('status'));
        $this->assertDatabaseCount('accounting_source_effects', 1);
    }

    public function test_changed_cost_slice_blocks_later_invoice_issue(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $this->issueInvoice($this->bill($o, $d, '2'));
        DB::table('accounting_sales_cost_slices')->update(['amount' => '11.00']);
        $next = $this->bill($o, $d, '2');
        $this->invalid(fn () => $this->issueInvoice($next), 'accounting_sales');
        $this->assertSame('draft', $next->refresh()->getAttribute('status'));
        $this->assertDatabaseCount('journal_entries', 3);
    }

    public function test_partial_historical_invoice_bundle_is_never_filled_on_retry(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $i = $this->issueInvoice($this->bill($o, $d));
        DB::table('accounting_source_effects')->where('source_kind', 'sales_invoice')->where('effect_kind', 'cost')->delete();
        $this->invalid(fn () => $this->issueInvoice($i, (string) $i->getAttribute('idempotency_key')), 'source_bundle');
        $this->assertDatabaseCount('journal_entries', 3);
    }

    public function test_disabled_accounting_keeps_native_sales_behavior_without_financial_markers(): void
    {
        $this->settings(['state' => 'disabled']);
        $o = $this->order();
        $d = $this->fulfillment($o);
        $this->issueInvoice($this->bill($o, $d));
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('accounting_source_effects', 0);
        $this->assertDatabaseCount('accounting_sales_events', 0);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_cutover_lock_blocks_even_disabled_new_delivery(): void
    {
        $o = $this->order();
        $d = $this->draft($o, $this->reserve($o));
        $this->settings(['state' => 'disabled', 'cutover_locked_at' => now()]);
        $this->invalid(fn () => $this->postDelivery($d), 'accounting_source');
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame('draft', $d->refresh()->getAttribute('status'));
    }

    public function test_source_actor_does_not_need_manual_gl_post_permission(): void
    {
        $o = $this->order();
        $d = $this->draft($o, $this->reserve($o));
        $staff = $this->staff(['erp.sales-deliveries.post']);
        $this->assertFalse($staff->can('erp.journal-entries.post'));
        $d = app(SalesDeliveryService::class)->post($staff, (int) $d->getKey(), (int) $d->getAttribute('revision'), (string) Str::uuid());
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_foreign_company_actor_is_denied(): void
    {
        $o = $this->order();
        $d = $this->draft($o, $this->reserve($o));
        $company = $this->newCompany('FOREIGN');
        $u = User::factory()->create();
        Employee::query()->create(['company_id' => $company->getKey(), 'user_id' => $u->getKey(), 'employee_number' => 'FOREIGN', 'first_name' => 'Foreign', 'last_name' => 'User']);
        $u->givePermissionTo('erp.sales-deliveries.post');
        try {
            app(SalesDeliveryService::class)->post($u, (int) $d->getKey(), 1, (string) Str::uuid());
            $this->fail('Expected scoped rejection.');
        } catch (AuthorizationException|\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertDatabaseCount('stock_movements', 0);
        }
    }

    public function test_financial_projections_exclude_private_notes_and_customer_snapshots(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $this->issueInvoice($this->bill($o, $d));
        $json = json_encode(AccountingSalesEvent::query()->pluck('payload')->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Handle carefully', $json);
        $this->assertStringNotContainsString('Invoice terms', $json);
        $this->assertStringNotContainsString('01700000000', $json);
        $this->assertStringNotContainsString('customer_snapshot', $json);
    }

    public function test_event_and_cost_slice_records_are_append_only(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $this->issueInvoice($this->bill($o, $d));
        foreach ([AccountingSalesEvent::query()->firstOrFail(), AccountingSalesCostSlice::query()->firstOrFail()] as $row) {
            try {
                $row->delete();
                $this->fail('Expected immutable history guard.');
            } catch (\LogicException) {
                $this->assertTrue($row->exists);
            }
        }
    }

    public function test_invoice_source_staff_can_issue_without_manual_journal_permission(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $i = $this->bill($o, $d);
        $staff = $this->staff(['erp.sales-invoices.issue']);
        $this->assertFalse($staff->can('erp.journal-entries.post'));
        app(SalesInvoiceService::class)->issue($staff, (int) $i->getKey(), (int) $i->getAttribute('revision'), (string) Str::uuid());
        $this->assertDatabaseCount('journal_entries', 3);
    }

    public function test_ready_state_does_not_pretend_an_operational_source_is_recognized(): void
    {
        $this->settings(['state' => 'ready']);
        $o = $this->order();
        $d = $this->fulfillment($o);
        $this->issueInvoice($this->bill($o, $d));
        $this->assertDatabaseCount('accounting_source_effects', 0);
        $this->assertDatabaseCount('accounting_sales_events', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_source_context_is_cleared_when_dispatch_finance_fails(): void
    {
        $o = $this->order();
        $d = $this->draft($o, $this->reserve($o));
        DB::table('accounting_periods')->update(['state' => 'hard_closed']);
        $this->invalid(fn () => $this->postDelivery($d), 'accounting_date');
        $this->invalid(fn () => app(InventoryService::class)->stockOut($this->warehouse, $this->product, '1.0000', StockMovement::TYPE_SALE, '5.0000', referenceType: SalesDelivery::class, referenceId: (int) $d->getKey(), createdBy: (int) $this->creator->getKey()), 'accounting_source');
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_full_native_keys_are_bound_without_truncating_finance_identity(): void
    {
        $o = $this->order();
        $d = $this->draft($o, $this->reserve($o));
        $d = $this->postDelivery($d, str_repeat('d', 100));
        $invoice = $this->issueInvoice($this->bill($o, $d), str_repeat('i', 100));
        $this->assertSame('replayed', $this->replay('sales_invoice', (int) $invoice->getKey())['mode']);
        $this->assertSame(['original'], AccountingSourceEffect::query()->distinct()->pluck('event_token')->all());
        $this->assertDatabaseCount('journal_entries', 3);
    }

    public function test_control_account_remap_cannot_strand_original_dispatch_balance(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $original = Account::query()->findOrFail($this->mapping['dispatched_inventory']);
        $replacement = $original->replicate();
        $replacement->setAttribute('code', '99991');
        $replacement->setAttribute('name', 'Replacement dispatch control');
        $replacement->save();
        $mapping = $this->mapping;
        $mapping['dispatched_inventory'] = (int) $replacement->getKey();
        $version = AccountingMappingVersion::query()->where('company_id', $this->company->getKey())->latest('version')->firstOrFail();
        $this->publish(['base_version_id' => $version->getKey(), 'effective_from' => '2026-10-17', 'mappings' => $mapping]);
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $i = $this->bill($o, $d, '2');
        $this->invalid(fn () => $this->issueInvoice($i), 'accounting_sales');
        $this->assertSame('draft', $i->refresh()->getAttribute('status'));
        $this->assertSame('0.00', $this->role('cogs', 'debit'));
        $this->assertDatabaseCount('journal_entries', 1);
    }
}
