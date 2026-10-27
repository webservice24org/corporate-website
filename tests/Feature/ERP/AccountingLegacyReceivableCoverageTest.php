<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingCutoverSource;
use App\Models\Accounting\AccountingManifestVersion;
use App\Models\Accounting\AccountingMappingVersion;
use App\Models\Accounting\AccountingOpeningWorkspace;
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
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingCashAccountService;
use App\Services\ERP\Accounting\AccountingDomainRegistry;
use App\Services\ERP\Accounting\AccountingMappingCatalog;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\AccountingOpeningEvidence;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\Source\AccountingSourceOrchestrator;
use App\Services\ERP\Accounting\Source\AccountingSourceReconciliation;
use App\Services\ERP\Accounting\Source\Sales\AccountingLegacyReceivableCoverage;
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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AccountingLegacyReceivableCoverageTest extends TestCase
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

    private function legacyInvoice(): SalesInvoice
    {
        $this->settings(['state' => 'disabled']);

        return $this->invoice();
    }

    private function verifiedOpening(SalesInvoice $invoice, string $amount = '60.00', ?int $party = null): JournalEntry
    {
        // Synthetic future-activation rehearsal ONLY. No production API creates recognized coverage in this package.
        return DB::transaction(function () use ($invoice, $amount, $party): JournalEntry {
            $plan = AccountingCutover::query()->where('company_id', $this->company->getKey())->firstOrFail();
            DB::table('accounting_cutovers')->where('id', $plan->getKey())->update(['status' => 'draft', 'cutover_date' => '2026-10-17', 'manifest_hash' => null, 'reconciliation_hash' => null]);
            $plan->refresh();
            $snapshot = app(AccountingLegacyReceivableCoverage::class)->snapshot($this->company, (int) $invoice->getKey(), '2026-10-17');
            $period = DB::table('accounting_periods')->where('company_id', $this->company->getKey())->first();
            $journal = JournalEntry::query()->create(['company_id' => $this->company->getKey(), 'accounting_period_id' => $period->id, 'number' => 'OPEN-LEGACY-AR', 'entry_kind' => 'opening', 'status' => 'draft', 'accounting_date' => '2026-10-17', 'document_date' => '2026-10-17', 'currency_code' => 'BDT', 'description' => 'Independently reconciled unpaid legacy AR fixture', 'created_by' => $this->creator->getKey(), 'updated_by' => $this->creator->getKey(), 'revision' => 1, 'contract_version' => AccountingDomainRegistry::VERSION]);
            JournalEntryLine::query()->create(['company_id' => $this->company->getKey(), 'journal_entry_id' => $journal->getKey(), 'line_number' => 1, 'account_id' => $this->mapping['trade_ar'], 'debit' => $amount, 'credit' => '0.00', 'party_type' => 'customer', 'party_id' => $party ?? $this->customer->getKey(), 'source_line_type' => 'sales_invoice', 'source_line_id' => $invoice->getKey()]);
            $inventory = bcmul((string) $this->balance->refresh()->getAttribute('quantity_on_hand'), (string) $this->balance->getAttribute('average_cost'), 2);
            JournalEntryLine::query()->create(['company_id' => $this->company->getKey(), 'journal_entry_id' => $journal->getKey(), 'line_number' => 2, 'account_id' => $this->mapping['inventory'], 'debit' => $inventory, 'credit' => '0.00', 'source_line_type' => 'inventory_balance', 'source_line_id' => $this->balance->getKey()]);
            JournalEntryLine::query()->create(['company_id' => $this->company->getKey(), 'journal_entry_id' => $journal->getKey(), 'line_number' => 3, 'account_id' => $this->mapping['retained_earnings'], 'debit' => '0.00', 'credit' => bcadd($amount, $inventory, 2)]);
            $workspace = AccountingOpeningWorkspace::query()->create(['company_id' => $this->company->getKey(), 'cutover_id' => $plan->getKey(), 'journal_entry_id' => $journal->getKey(), 'supporting_reference' => 'REHEARSAL-ONLY-UNPAID-AR']);
            $canonical = app(AccountingCanonicalPayload::class);
            $opening = app(AccountingOpeningEvidence::class);
            $hash = $canonical->hash($opening->payload($plan, $journal, (string) $workspace->getAttribute('supporting_reference'), $opening->lines($journal)));
            $journal->fill(['status' => 'posted', 'approved_by' => $this->approver->getKey(), 'approved_at' => now(), 'posted_by' => $this->creator->getKey(), 'posted_at' => now(), 'payload_hash' => $hash])->save();
            $source = AccountingCutoverSource::query()->create(['company_id' => $this->company->getKey(), 'cutover_id' => $plan->getKey(), 'opening_journal_entry_id' => $journal->getKey(), 'source_kind' => 'sales_invoice', 'source_id' => $invoice->getKey(), 'coverage_kind' => 'snapshot', 'covered_through_event' => 'issued.ar_opening.v1', 'source_payload_hash' => $canonical->hash($snapshot), 'opening_state' => ['recognition_state' => 'recognized', 'snapshot' => $snapshot]]);
            $row = ['source_kind' => 'sales_invoice', 'source_id' => (int) $invoice->getKey(), 'coverage_kind' => 'snapshot', 'covered_through_event' => 'issued.ar_opening.v1', 'source_payload_hash' => (string) $source->getAttribute('source_payload_hash'), 'opening_state' => $source->getAttribute('opening_state')];
            $reconciliation = str_repeat('c', 64);
            $payload = ['manifest_version' => 'reconciled.v1', 'contract_version' => AccountingDomainRegistry::VERSION, 'company_id' => (int) $this->company->getKey(), 'cutover_id' => (int) $plan->getKey(), 'cutover_revision' => (int) $plan->getAttribute('revision'), 'cutover_date' => '2026-10-17', 'strategy' => 'snapshot', 'opening_journal_entry_id' => (int) $journal->getKey(), 'opening_payload_hash' => $hash, 'recognition_state' => 'recognized', 'reconciliation_hash' => $reconciliation, 'coverage_catalog' => array_fill_keys(['sales_invoices', 'supplier_bills', 'inventory', 'dispatch', 'dated_allocations_and_credits', 'funds_and_advances', 'cash_and_bank'], 'reconciled'), 'rows' => [$row]];
            $manifestHash = $canonical->hash($payload);
            AccountingManifestVersion::query()->create(['company_id' => $this->company->getKey(), 'cutover_id' => $plan->getKey(), 'cutover_revision' => $plan->getAttribute('revision'), 'opening_journal_entry_id' => $journal->getKey(), 'payload' => $payload, 'payload_hash' => $manifestHash, 'created_by' => $this->creator->getKey()]);
            DB::table('accounting_cutovers')->where('id', $plan->getKey())->update(['status' => 'activated', 'manifest_hash' => $manifestHash, 'reconciliation_hash' => $reconciliation, 'approved_by' => $this->approver->getKey(), 'approved_at' => now(), 'activated_by' => $this->creator->getKey(), 'activated_at' => now()]);
            $this->settings(['state' => 'enabled', 'activation_date' => '2026-10-17', 'activated_by' => $this->creator->getKey(), 'activated_at' => now()]);
            $this->travelTo(CarbonImmutable::parse('2026-10-18 12:00:00', 'Asia/Dhaka'));

            return $journal;
        });
    }

    /** @return array{0: SalesInvoice, 1: JournalEntry} */
    private function covered(): array
    {
        $i = $this->legacyInvoice();
        $j = $this->verifiedOpening($i);

        return [$i, $j];
    }

    private function verifyLegacy(SalesInvoice $i): array
    {
        return DB::transaction(fn () => app(AccountingLegacyReceivableCoverage::class)->verify($this->company, (int) $i->getKey()));
    }

    private function settle(SalesInvoice $i, string $amount = '20.00'): CustomerReceipt
    {
        return $this->postReceipt($this->receipt($amount), [['sales_invoice_id' => (int) $i->getKey(), 'amount' => $amount]]);
    }

    public function test_source_linked_unpaid_opening_ar_verifies(): void
    {
        [$i, $j] = $this->covered();
        $proof = $this->verifyLegacy($i);
        $this->assertSame('60.00', $proof['amount']);
        $this->assertSame((int) $j->getKey(), $proof['opening_journal_id']);
        $this->assertSame('2026-10-16', $proof['original_date']);
    }

    public function test_new_cash_and_allocation_settle_opening_ar_without_original_revenue_or_cost(): void
    {
        [$i, $j] = $this->covered();
        $this->settle($i);
        $this->assertSame('20.00', $this->role('cash:MAIN', 'debit'));
        $this->assertSame('20.00', $this->role('trade_ar', 'credit'));
        $this->assertSame('0.00', $this->role('sales_revenue', 'credit'));
        $this->assertSame('0.00', $this->role('cogs', 'debit'));
        $this->assertDatabaseCount('journal_entries', 3);
        $this->assertSame('40.00', $this->due($i));
        $this->assertSame('40.00', bcsub($this->role('trade_ar', 'debit'), $this->role('trade_ar', 'credit'), 2));
        $this->assertSame(0, AccountingSourceEffect::query()->where('source_kind', 'sales_invoice')->count());
    }

    public function test_multiple_new_partial_receipts_cannot_exceed_native_open_ar(): void
    {
        [$i] = $this->covered();
        $this->settle($i, '20.00');
        $this->settle($i, '40.00');
        $this->assertSame('0.00', $this->due($i));
        $r = $this->receipt('1.00');
        $this->invalid(fn () => $this->postReceipt($r, [['sales_invoice_id' => (int) $i->getKey(), 'amount' => '1.00']]));
        $this->assertSame('draft', $r->refresh()->getAttribute('status'));
    }

    public function test_later_allocation_from_new_cash_receipt_uses_verified_opening(): void
    {
        [$i] = $this->covered();
        $r = $this->postReceipt($this->receipt());
        $this->allocate($r, $i, '30.00');
        $this->assertSame('30.00', $this->due($i));
        $this->assertSame('60.00', $this->role('cash:MAIN', 'debit'));
        $this->assertSame('30.00', $this->role('trade_ar', 'credit'));
    }

    public function test_compensation_restores_opening_ar_without_new_cash(): void
    {
        [$i] = $this->covered();
        $r = $this->settle($i);
        $this->undo($r, $this->originalAllocation($r));
        $this->assertSame('60.00', $this->due($i));
        $this->assertSame('20.00', $this->role('cash:MAIN', 'debit'));
        $this->assertSame('0.00', $this->role('cash:MAIN', 'credit'));
    }

    public function test_full_new_receipt_reversal_restores_original_opening_balance(): void
    {
        [$i] = $this->covered();
        $r = $this->settle($i);
        app(CustomerReceiptService::class)->reverseReceipt($this->creator, (int) $r->getKey(), $this->op($r));
        $this->assertSame('60.00', $this->due($i));
        $this->assertSame('20.00', $this->role('cash:MAIN', 'credit'));
        $this->assertSame('0.00', $this->role('sales_revenue', 'credit'));
    }

    public function test_receipt_replay_survives_closed_period_and_disabled_state(): void
    {
        [$i] = $this->covered();
        $r = $this->receipt('20.00');
        $op = $this->op($r, [['sales_invoice_id' => (int) $i->getKey(), 'amount' => '20.00']]);
        $this->postReceipt($r, operation: $op);
        DB::table('accounting_periods')->update(['state' => 'hard_closed']);
        $this->settings(['state' => 'disabled', 'cutover_locked_at' => now()]);
        $this->cash->update(['status' => 'inactive']);
        $this->postReceipt($r, operation: $op);
        $this->assertDatabaseCount('journal_entries', 3);
    }

    public function test_receipt_source_staff_do_not_need_manual_post_authority(): void
    {
        [$i] = $this->covered();
        $staff = $this->staff(['erp.customer-receipts.post', 'erp.customer-receipts.allocate']);
        $r = $this->receipt('20.00');
        app(CustomerReceiptService::class)->postReceipt($staff, (int) $r->getKey(), $this->op($r, [['sales_invoice_id' => (int) $i->getKey(), 'amount' => '20.00']]));
        $this->assertFalse($staff->can('erp.journal-entries.post'));
        $this->assertSame('40.00', $this->due($i));
    }

    public function test_missing_manifest_blocks_settlement_and_rolls_back_cash(): void
    {
        [$i] = $this->covered();
        DB::table('accounting_manifest_versions')->delete();
        $r = $this->receipt('20.00');
        $this->invalid(fn () => $this->postReceipt($r, [['sales_invoice_id' => (int) $i->getKey(), 'amount' => '20.00']]));
        $this->assertSame('draft', $r->refresh()->getAttribute('status'));
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('customer_receipt_allocations', 0);
    }

    public function test_bad_opening_amount_is_not_assumed_from_native_outstanding(): void
    {
        $i = $this->legacyInvoice();
        $this->verifiedOpening($i, '59.00');
        $this->invalid(fn () => $this->settle($i));
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_opening_party_must_match_original_invoice(): void
    {
        $i = $this->legacyInvoice();
        $other = Customer::query()->create(['company_id' => $this->company->getKey(), 'name' => 'Other', 'phone' => '01700000001', 'is_active' => true]);
        $this->verifiedOpening($i, '60.00', (int) $other->getKey());
        $this->invalid(fn () => $this->settle($i));
    }

    public function test_changed_invoice_is_rejected_without_reposting_original(): void
    {
        [$i] = $this->covered();
        DB::table('sales_invoices')->where('id', $i->getKey())->update(['grand_total' => '61.00']);
        $this->invalid(fn () => $this->verifyLegacy($i));
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_issued_native_hash_is_recomputed_before_snapshot_use(): void
    {
        [$i] = $this->covered();
        DB::table('sales_invoice_items')->where('sales_invoice_id', $i->getKey())->update(['description' => 'Changed after issue']);
        $this->invalid(fn () => $this->verifyLegacy($i));
    }

    public function test_original_live_invoice_cannot_also_claim_opening_coverage(): void
    {
        $i = $this->invoice();
        $this->invalid(fn () => DB::transaction(fn () => app(AccountingLegacyReceivableCoverage::class)->snapshot($this->company, (int) $i->getKey(), '2026-10-17')));
        $this->assertDatabaseCount('journal_entries', 3);
    }

    public function test_coverage_does_not_allow_backfilling_original_invoice_event(): void
    {
        [$i] = $this->covered();
        $this->invalid(fn () => DB::transaction(fn () => app(AccountingSourceOrchestrator::class)->execute($this->creator, (int) $this->company->getKey(), 'sales_invoice', (int) $i->getKey(), 'issued', 'original')));
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_wrong_covered_event_is_not_a_generic_issued_invoice_claim(): void
    {
        [$i] = $this->covered();
        DB::table('accounting_cutover_sources')->update(['covered_through_event' => 'issued']);
        $this->invalid(fn () => $this->verifyLegacy($i));
    }

    public function test_damaged_opening_journal_or_archived_manifest_is_rejected(): void
    {
        [$i, $j] = $this->covered();
        DB::table('journal_entry_lines')->where('journal_entry_id', $j->getKey())->where('debit', '>', 0)->update(['debit' => '59.00']);
        $this->invalid(fn () => $this->verifyLegacy($i));
    }

    public function test_prepared_manifest_cannot_be_used_as_recognized_opening(): void
    {
        [$i] = $this->covered();
        DB::table('accounting_cutover_sources')->update(['opening_state' => json_encode(['recognition_state' => 'pending_reconciliation'], JSON_THROW_ON_ERROR)]);
        $this->invalid(fn () => $this->settle($i));
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_damaged_manifest_hash_is_rejected(): void
    {
        [$i] = $this->covered();
        DB::table('accounting_manifest_versions')->update(['payload_hash' => str_repeat('0', 64)]);
        $this->invalid(fn () => $this->verifyLegacy($i));
    }

    public function test_pre_cutover_initial_cash_history_uses_distinct_dated_contract(): void
    {
        $i = $this->legacyInvoice();
        $this->postReceipt($this->receipt('20.00'), [['sales_invoice_id' => (int) $i->getKey(), 'amount' => '20.00']]);
        $snapshot = DB::transaction(fn () => app(AccountingLegacyReceivableCoverage::class)->snapshot($this->company, (int) $i->getKey(), '2026-10-17'));
        $this->assertSame('legacy-ar.dated-initial.v1', $snapshot['coverage_contract']);
        $this->assertSame('40.00', $snapshot['opening_ar']);
        $this->invalid(fn () => $this->verifyLegacy($i));
    }

    public function test_pre_cutover_credit_note_history_requires_separate_coverage(): void
    {
        $i = $this->legacyInvoice();
        $this->issueCredit($this->credit($i));
        $this->invalid(fn () => DB::transaction(fn () => app(AccountingLegacyReceivableCoverage::class)->snapshot($this->company, (int) $i->getKey(), '2026-10-17')));
    }

    public function test_missing_prior_new_allocation_finance_blocks_further_settlement(): void
    {
        [$i] = $this->covered();
        $this->settle($i);
        DB::table('accounting_source_effects')->where('source_kind', 'customer_receipt_allocation')->delete();
        $r = $this->receipt('20.00');
        $this->invalid(fn () => $this->postReceipt($r, [['sales_invoice_id' => (int) $i->getKey(), 'amount' => '20.00']]));
        $this->assertSame('draft', $r->refresh()->getAttribute('status'));
        $this->assertDatabaseCount('journal_entries', 3);
    }

    public function test_closed_original_opening_can_be_settled_in_current_open_period(): void
    {
        [$i] = $this->covered();
        $year = DB::table('fiscal_years')->where('company_id', $this->company->getKey())->first();
        app(AccountingCalendarService::class)->savePeriod($this->creator, (int) $year->id, ['company_id' => $this->company->getKey(), 'code' => 'NOV', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'reason' => 'Open current correction period']);
        DB::table('accounting_periods')->where('code', 'OCT')->update(['state' => 'hard_closed']);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:00', 'Asia/Dhaka'));
        $this->settle($i);
        $this->assertSame(2, JournalEntry::query()->where('accounting_date', '2026-11-02')->count());
        $this->assertSame('40.00', $this->due($i));
    }

    public function test_closed_current_period_blocks_new_legacy_settlement(): void
    {
        [$i] = $this->covered();
        DB::table('accounting_periods')->update(['state' => 'hard_closed']);
        $this->invalid(fn () => $this->settle($i));
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_source_reconciliation_labels_verified_ar_without_claiming_stock_coverage(): void
    {
        [$i, $j] = $this->covered();
        $page = app(AccountingSourceReconciliation::class)->page($this->creator, (int) $this->company->getKey(), ['kind' => 'sales_invoice']);
        $this->assertSame('verified_opening_ar', $page['rows'][0]['status']);
        $this->assertSame((int) $j->getKey(), $page['rows'][0]['coverage_opening_journal']['id']);
        $this->assertSame(0, $page['rows'][0]['admission_count']);
    }

    public function test_coverage_projection_excludes_personal_snapshots_and_raw_notes(): void
    {
        [$i] = $this->covered();
        $snapshot = DB::transaction(fn () => app(AccountingLegacyReceivableCoverage::class)->snapshot($this->company, (int) $i->getKey(), '2026-10-17'));
        $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
        foreach (['customer_snapshot', 'billing_address_snapshot', 'shipping_address_snapshot', 'notes', 'idempotency_key'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
    }

    public function test_settlement_is_bound_to_original_opening_ar_control_account(): void
    {
        [$i] = $this->covered();
        $replacement = Account::query()->create(['company_id' => $this->company->getKey(), 'account_group_id' => Account::query()->findOrFail($this->mapping['trade_ar'])->getAttribute('account_group_id'), 'code' => '9999', 'name' => 'New AR control', 'normal_side' => 'debit', 'is_postable' => true, 'control_role' => 'trade_ar', 'statement_role' => 'current_asset', 'status' => 'active', 'revision' => 1]);
        $map = $this->mapping;
        $map['trade_ar'] = (int) $replacement->getKey();
        $base = AccountingMappingVersion::query()->where('company_id', $this->company->getKey())->where('state', 'published')->firstOrFail();
        $this->publish(['base_version_id' => (int) $base->getKey(), 'effective_from' => '2026-10-18', 'mappings' => $map]);
        $this->invalid(fn () => $this->settle($i));
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_history_replay_survives_ar_account_deactivation(): void
    {
        [$i] = $this->covered();
        $r = $this->receipt('20.00');
        $op = $this->op($r, [['sales_invoice_id' => (int) $i->getKey(), 'amount' => '20.00']]);
        $this->postReceipt($r, operation: $op);
        Account::query()->whereKey($this->mapping['trade_ar'])->update(['status' => 'inactive']);
        $this->postReceipt($r, operation: $op);
        $this->assertDatabaseCount('journal_entries', 3);
    }

    public function test_new_legacy_settlement_still_requires_native_authority(): void
    {
        [$i] = $this->covered();
        $r = $this->receipt('20.00');
        $staff = $this->staff(['erp.accounting-reports.view']);
        $this->expectException(AuthorizationException::class);
        app(CustomerReceiptService::class)->postReceipt($staff, (int) $r->getKey(), $this->op($r, [['sales_invoice_id' => (int) $i->getKey(), 'amount' => '20.00']]));
    }

    public function test_coverage_reader_has_no_activation_or_recognition_write_side_effect(): void
    {
        [$i] = $this->covered();
        $before = [AccountingSalesEvent::query()->count(), AccountingSourceEffect::query()->count(), JournalEntry::query()->count(), DB::table('accounting_audit_events')->count()];
        $this->verifyLegacy($i);
        $this->verifyLegacy($i);
        $this->assertSame($before, [AccountingSalesEvent::query()->count(), AccountingSourceEffect::query()->count(), JournalEntry::query()->count(), DB::table('accounting_audit_events')->count()]);
    }

    public function test_covered_invoice_still_cannot_issue_unsupported_legacy_credit_note(): void
    {
        [$i] = $this->covered();
        $n = $this->credit($i);
        $this->invalid(fn () => $this->issueCredit($n));
        $this->assertSame('draft', $n->refresh()->getAttribute('status'));
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_activation_date_must_match_original_recognized_cutover(): void
    {
        [$i] = $this->covered();
        $this->settings(['activation_date' => '2026-10-18']);
        $this->invalid(fn () => $this->verifyLegacy($i));
    }

    public function test_opening_independent_approval_cannot_be_missing(): void
    {
        [$i, $j] = $this->covered();
        DB::table('journal_entries')->where('id', $j->getKey())->update(['approved_by' => $this->creator->getKey()]);
        $this->invalid(fn () => $this->verifyLegacy($i));
    }

    public function test_snapshot_requires_its_authorized_caller_transaction(): void
    {
        [$i] = $this->covered();
        $connection = DB::getFacadeRoot();
        try {
            DB::shouldReceive('transactionLevel')->once()->andReturn(0);
            $this->expectException(\LogicException::class);
            app(AccountingLegacyReceivableCoverage::class)->snapshot($this->company, (int) $i->getKey(), '2026-10-17');
        } finally {
            DB::swap($connection);
        }
    }

    public function test_unactivated_cutover_never_grants_new_legacy_settlement(): void
    {
        [$i] = $this->covered();
        DB::table('accounting_cutovers')->update(['status' => 'draft']);
        $this->invalid(fn () => $this->settle($i));
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_foreign_currency_original_cannot_use_functional_currency_ar_snapshot(): void
    {
        [$i] = $this->covered();
        DB::table('sales_invoices')->where('id', $i->getKey())->update(['currency_code' => 'USD']);
        $this->invalid(fn () => $this->verifyLegacy($i));
    }

    public function test_orphan_original_automatic_invoice_journal_prevents_second_opening_recognition(): void
    {
        $i = $this->invoice();
        DB::table('accounting_sales_cost_slices')->delete();
        DB::table('accounting_source_effects')->where('source_kind', 'sales_invoice')->delete();
        DB::table('accounting_sales_events')->where('source_kind', 'sales_invoice')->delete();
        $this->invalid(fn () => DB::transaction(fn () => app(AccountingLegacyReceivableCoverage::class)->snapshot($this->company, (int) $i->getKey(), '2026-10-17')));
        $this->assertDatabaseCount('journal_entries', 3);
    }
}
