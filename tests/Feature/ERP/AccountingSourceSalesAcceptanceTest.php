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
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingCashAccountService;
use App\Services\ERP\Accounting\AccountingDomainRegistry;
use App\Services\ERP\Accounting\AccountingJournalReversalService;
use App\Services\ERP\Accounting\AccountingMappingCatalog;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\AccountingOpeningEvidence;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\Source\AccountingSourceOrchestrator;
use App\Services\ERP\Accounting\Source\Sales\AccountingLegacyReceivableCoverage;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AccountingSourceSalesAcceptanceTest extends TestCase
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

    /** @return array{0: SalesInvoice, 1: SalesInvoice} */
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
            $source = AccountingCutoverSource::query()->create(['company_id' => $this->company->getKey(), 'cutover_id' => $plan->getKey(), 'opening_journal_entry_id' => $journal->getKey(), 'source_kind' => 'sales_invoice', 'source_id' => $invoice->getKey(), 'coverage_kind' => 'snapshot', 'covered_through_event' => $snapshot['covered_through_event'], 'source_payload_hash' => $canonical->hash($snapshot), 'opening_state' => ['recognition_state' => 'recognized', 'snapshot' => $snapshot]]);
            $row = ['source_kind' => 'sales_invoice', 'source_id' => (int) $invoice->getKey(), 'coverage_kind' => 'snapshot', 'covered_through_event' => $snapshot['covered_through_event'], 'source_payload_hash' => (string) $source->getAttribute('source_payload_hash'), 'opening_state' => $source->getAttribute('opening_state')];
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

    /** @return array{0: SalesInvoice, 1: CustomerReceipt} */
    private function oldPair(string $amount = '20.00'): array
    {
        $i = $this->legacyInvoice();
        $r = $this->postReceipt($this->receipt($amount), [['sales_invoice_id' => (int) $i->getKey(), 'amount' => $amount]]);

        return [$i, $r];
    }

    /** @return array{0: SalesInvoice, 1: CustomerReceipt, 2: JournalEntry} */
    private function partial(): array
    {
        $pair = $this->oldPair();
        $journal = $this->verifiedOpening($pair[0], '40.00');

        return [$pair[0], $pair[1], $journal];
    }

    /** @return array<string, mixed> */
    private function snapshotLegacy(SalesInvoice $i): array
    {
        return DB::transaction(fn () => app(AccountingLegacyReceivableCoverage::class)->snapshot($this->company, (int) $i->getKey(), '2026-10-17'));
    }

    /** @return array<string, int> */
    private function financialCounts(): array
    {
        return ['journals' => JournalEntry::query()->count(), 'admissions' => AccountingSalesEvent::query()->count(), 'effects' => AccountingSourceEffect::query()->count(), 'operations' => AccountingReceiptOperation::query()->count(), 'allocations' => CustomerReceiptAllocation::query()->count(), 'receipts' => CustomerReceipt::query()->count()];
    }

    private function november(): void
    {
        $year = FiscalYear::query()->where('company_id', $this->company->getKey())->firstOrFail();
        app(AccountingCalendarService::class)->savePeriod($this->creator, (int) $year->getKey(), ['company_id' => $this->company->getKey(), 'code' => 'NOV', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'reason' => 'Current-period correction acceptance']);
        DB::table('accounting_periods')->where('company_id', $this->company->getKey())->where('code', 'OCT')->update(['state' => 'hard_closed']);
        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00', 'Asia/Dhaka'));
    }

    private function newEffectsAreCurrent(int $after): void
    {
        $rows = AccountingSourceEffect::query()->where('company_id', $this->company->getKey())->where('id', '>', $after)->get();
        $this->assertNotEmpty($rows->all());
        foreach ($rows as $row) {
            $this->assertSame('2026-11-03', $row->getAttribute('accounting_date')->format('Y-m-d'));
            if ($row->getAttribute('journal_entry_id') !== null) {
                $this->assertSame('2026-11-03', JournalEntry::query()->findOrFail($row->getAttribute('journal_entry_id'))->getAttribute('accounting_date')->format('Y-m-d'));
            }
        }
    }

    private function lastEffect(): int
    {
        return (int) AccountingSourceEffect::query()->max('id');
    }

    /** @return array{journal: int, effect: int, movement: int, digest: string} */
    private function originalTrace(): array
    {
        $j = (int) JournalEntry::query()->max('id');
        $e = $this->lastEffect();
        $m = (int) StockMovement::query()->max('id');

        return ['journal' => $j, 'effect' => $e, 'movement' => $m, 'digest' => $this->traceDigest($j, $e, $m)];
    }

    private function traceDigest(int $journal, int $effect, int $movement): string
    {
        $parts = [];
        foreach (['journal_entries' => ['id', $journal], 'journal_entry_lines' => ['journal_entry_id', $journal], 'accounting_source_effects' => ['id', $effect], 'stock_movements' => ['id', $movement]] as $table => $bound) {
            $parts[$table] = DB::table($table)->where($bound[0], '<=', $bound[1])->orderBy('id')->get()->toArray();
        }

        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    /** @param array{journal: int, effect: int, movement: int, digest: string} $trace */
    private function assertOriginalTrace(array $trace): void
    {
        $this->assertSame($trace['digest'], $this->traceDigest($trace['journal'], $trace['effect'], $trace['movement']));
    }

    private function stateDigest(): string
    {
        $parts = [];
        foreach (['journal_entries', 'journal_entry_lines', 'accounting_sales_events', 'accounting_source_effects', 'accounting_sales_cost_slices', 'accounting_receipt_operations', 'customer_receipts', 'customer_receipt_allocations', 'customer_credit_allocations', 'sales_returns', 'sales_return_items', 'sales_credit_notes', 'sales_credit_note_items', 'sales_invoices', 'sales_invoice_items', 'sales_deliveries', 'sales_delivery_items', 'inventory_balances', 'stock_movements'] as $table) {
            $parts[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }

        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    private function failsAtomically(callable $action): void
    {
        $before = $this->stateDigest();
        $this->invalid($action);
        $this->assertSame($before, $this->stateDigest());
    }

    public function test_unbilled_return_uses_current_open_period_after_original_period_closes(): void
    {
        $d = $this->fulfillment($this->order());
        $trace = $this->originalTrace();
        $this->november();
        $after = $this->lastEffect();
        $this->postReturn($this->inspected($d));
        $this->newEffectsAreCurrent($after);
        $this->assertOriginalTrace($trace);
        $this->assertSame('10.00', $this->role('dispatched_inventory', 'credit'));
        $this->assertSame('0.00', $this->role('sales_revenue', 'credit'));
    }

    public function test_billed_return_uses_current_period_and_keeps_original_invoice(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $i = $this->issueInvoice($this->bill($o, $d));
        $trace = $this->originalTrace();
        $this->november();
        $after = $this->lastEffect();
        $this->postReturn($this->inspected($d));
        $this->newEffectsAreCurrent($after);
        $this->assertOriginalTrace($trace);
        $this->assertSame('60.00', $this->due($i));
        $this->assertSame('10.00', $this->role('cogs', 'credit'));
    }

    public function test_value_credit_uses_current_period_without_stock_correction(): void
    {
        $i = $this->invoice();
        $trace = $this->originalTrace();
        $movements = StockMovement::query()->count();
        $this->november();
        $after = $this->lastEffect();
        $this->issueCredit($this->credit($i));
        $this->newEffectsAreCurrent($after);
        $this->assertOriginalTrace($trace);
        $this->assertSame('40.00', $this->due($i));
        $this->assertDatabaseCount('stock_movements', $movements);
    }

    public function test_receipt_allocation_compensation_posts_in_current_period(): void
    {
        $i = $this->invoice();
        $r = $this->settle($i);
        $trace = $this->originalTrace();
        $this->november();
        $after = $this->lastEffect();
        $this->undo($r, $this->originalAllocation($r));
        $this->newEffectsAreCurrent($after);
        $this->assertOriginalTrace($trace);
        $this->assertSame('60.00', $this->due($i));
    }

    public function test_full_receipt_reversal_posts_all_compensations_and_cash_in_current_period(): void
    {
        $i = $this->invoice();
        $r = $this->settle($i);
        $trace = $this->originalTrace();
        $this->november();
        $after = $this->lastEffect();
        app(CustomerReceiptService::class)->reverseReceipt($this->creator, (int) $r->getKey(), $this->op($r));
        $this->newEffectsAreCurrent($after);
        $this->assertOriginalTrace($trace);
        $this->assertSame('60.00', $this->due($i));
        $this->assertSame('0.00', bcsub($this->role('cash:MAIN', 'debit'), $this->role('cash:MAIN', 'credit'), 2));
    }

    public function test_credit_transfer_compensation_uses_current_period_even_with_zero_gl(): void
    {
        $pair = $this->creditPair();
        $a = app(CustomerCreditAllocationService::class)->allocate($this->creator, $this->transferData($pair[0], $pair[1]));
        $trace = $this->originalTrace();
        $this->november();
        $after = $this->lastEffect();
        $undo = app(CustomerCreditAllocationService::class)->reverse($this->creator, (int) $a->getKey(), ['reason' => 'Current-period credit compensation', 'idempotency_key' => (string) Str::uuid()]);
        $this->newEffectsAreCurrent($after);
        $this->assertOriginalTrace($trace);
        $this->assertSame('verified_no_journal', $this->effect('customer_credit_allocation', (int) $undo->getKey())->getAttribute('outcome'));
    }

    public function test_physical_commercial_and_cash_corrections_remain_separate_in_current_period(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $i = $this->issueInvoice($this->bill($o, $d));
        $r = $this->settle($i);
        $trace = $this->originalTrace();
        $this->november();
        $after = $this->lastEffect();
        $this->postReturn($this->inspected($d));
        $this->assertSame('40.00', $this->due($i));
        $this->issueCredit($this->credit($i));
        $this->assertSame('20.00', $this->due($i));
        app(CustomerReceiptService::class)->reverseReceipt($this->creator, (int) $r->getKey(), $this->op($r));
        $this->assertSame('40.00', $this->due($i));
        $this->newEffectsAreCurrent($after);
        $this->assertOriginalTrace($trace);
    }

    public function test_current_closed_period_rolls_back_physical_return(): void
    {
        $d = $this->fulfillment($this->order());
        $this->november();
        $r = $this->inspected($d);
        DB::table('accounting_periods')->where('code', 'NOV')->update(['state' => 'hard_closed']);
        $this->failsAtomically(fn () => $this->postReturn($r));
    }

    public function test_current_closed_period_rolls_back_commercial_credit(): void
    {
        $i = $this->invoice();
        $this->november();
        $n = $this->credit($i);
        DB::table('accounting_periods')->where('code', 'NOV')->update(['state' => 'hard_closed']);
        $this->failsAtomically(fn () => $this->issueCredit($n));
    }

    public function test_current_closed_period_rolls_back_entire_receipt_reversal(): void
    {
        $i = $this->invoice();
        $r = $this->settle($i);
        $this->november();
        DB::table('accounting_periods')->where('code', 'NOV')->update(['state' => 'hard_closed']);
        $this->failsAtomically(fn () => app(CustomerReceiptService::class)->reverseReceipt($this->creator, (int) $r->getKey(), $this->op($r)));
    }

    public function test_current_closed_period_blocks_zero_gl_credit_compensation(): void
    {
        $pair = $this->creditPair();
        $a = app(CustomerCreditAllocationService::class)->allocate($this->creator, $this->transferData($pair[0], $pair[1]));
        $this->november();
        DB::table('accounting_periods')->where('code', 'NOV')->update(['state' => 'hard_closed']);
        $this->failsAtomically(fn () => app(CustomerCreditAllocationService::class)->reverse($this->creator, (int) $a->getKey(), ['reason' => 'Closed current period', 'idempotency_key' => (string) Str::uuid()]));
    }

    public function test_backdated_credit_is_not_silently_moved_into_current_period(): void
    {
        $i = $this->invoice();
        $this->november();
        $line = SalesInvoiceItem::query()->where('sales_invoice_id', $i->getKey())->firstOrFail();
        $n = app(SalesCreditNoteService::class)->save($this->creator, ['company_id' => $this->company->getKey(), 'sales_invoice_id' => $i->getKey(), 'document_date' => '2026-10-16', 'reason' => 'Backdated draft reviewed explicitly', 'items' => [['sales_invoice_item_id' => $line->getKey(), 'credit_type' => 'value', 'amount' => '20.00']]]);
        $before = $this->stateDigest();
        $this->invalid(fn () => $this->issueCredit($n), 'accounting_date');
        $this->assertSame($before, $this->stateDigest());
        $this->assertSame('2026-10-16', $n->refresh()->getAttribute('document_date')->format('Y-m-d'));
    }

    public function test_draft_delivery_cancellation_has_no_financial_or_stock_effect(): void
    {
        $o = $this->order();
        $r = $this->reserve($o);
        $d = $this->draft($o, $r);
        $counts = $this->financialCounts();
        $movements = StockMovement::query()->count();
        $d = app(SalesDeliveryService::class)->cancel($this->creator, (int) $d->getKey(), (int) $d->getAttribute('revision'), 'Unposted delivery cancelled');
        $this->assertSame('cancelled', $d->getAttribute('status'));
        $this->assertSame($counts, $this->financialCounts());
        $this->assertDatabaseCount('stock_movements', $movements);
    }

    public function test_draft_invoice_cancellation_does_not_reverse_dispatch(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $i = $this->bill($o, $d);
        $counts = $this->financialCounts();
        $trace = $this->originalTrace();
        $i = app(SalesInvoiceService::class)->cancel($this->creator, (int) $i->getKey(), (int) $i->getAttribute('revision'), 'Unissued invoice cancelled');
        $this->assertSame('cancelled', $i->getAttribute('status'));
        $this->assertSame($counts, $this->financialCounts());
        $this->assertOriginalTrace($trace);
    }

    public function test_unposted_return_cancellation_does_not_move_stock_or_finance(): void
    {
        $d = $this->fulfillment($this->order());
        $r = $this->inspected($d);
        $counts = $this->financialCounts();
        $trace = $this->originalTrace();
        $r = app(SalesReturnService::class)->cancel($this->creator, (int) $r->getKey(), (int) $r->getAttribute('revision'), 'Unposted return cancelled');
        $this->assertSame('cancelled', $r->getAttribute('status'));
        $this->assertSame($counts, $this->financialCounts());
        $this->assertOriginalTrace($trace);
    }

    public function test_unissued_credit_cancellation_does_not_change_invoice_balance(): void
    {
        $i = $this->invoice();
        $n = $this->credit($i);
        $counts = $this->financialCounts();
        $n = app(SalesCreditNoteService::class)->cancel($this->creator, (int) $n->getKey(), (int) $n->getAttribute('revision'), 'Unissued credit cancelled');
        $this->assertSame('cancelled', $n->getAttribute('status'));
        $this->assertSame('60.00', $this->due($i));
        $this->assertSame($counts, $this->financialCounts());
    }

    public function test_draft_receipt_cancellation_does_not_recognize_cash(): void
    {
        $r = $this->receipt();
        $counts = $this->financialCounts();
        $r = app(CustomerReceiptService::class)->cancel($this->creator, (int) $r->getKey(), (int) $r->getAttribute('revision'), 'Unposted cash cancelled');
        $this->assertSame('cancelled', $r->getAttribute('status'));
        $this->assertSame($counts, $this->financialCounts());
    }

    public function test_posted_delivery_cannot_be_cancelled(): void
    {
        $d = $this->fulfillment($this->order());
        $this->failsAtomically(fn () => app(SalesDeliveryService::class)->cancel($this->creator, (int) $d->getKey(), (int) $d->getAttribute('revision'), 'Attempt posted cancellation'));
    }

    public function test_issued_invoice_cannot_be_cancelled(): void
    {
        $i = $this->invoice();
        $this->failsAtomically(fn () => app(SalesInvoiceService::class)->cancel($this->creator, (int) $i->getKey(), (int) $i->getAttribute('revision'), 'Attempt issued cancellation'));
    }

    public function test_posted_return_cannot_be_cancelled(): void
    {
        $r = $this->postReturn($this->inspected($this->fulfillment($this->order())));
        $this->failsAtomically(fn () => app(SalesReturnService::class)->cancel($this->creator, (int) $r->getKey(), (int) $r->getAttribute('revision'), 'Attempt posted cancellation'));
    }

    public function test_issued_credit_cannot_be_cancelled(): void
    {
        $n = $this->issueCredit($this->credit($this->invoice()));
        $this->failsAtomically(fn () => app(SalesCreditNoteService::class)->cancel($this->creator, (int) $n->getKey(), (int) $n->getAttribute('revision'), 'Attempt issued cancellation'));
    }

    public function test_posted_receipt_cannot_be_cancelled(): void
    {
        $r = $this->postReceipt($this->receipt());
        $this->failsAtomically(fn () => app(CustomerReceiptService::class)->cancel($this->creator, (int) $r->getKey(), (int) $r->getAttribute('revision'), 'Attempt posted cancellation'));
    }

    public function test_automatic_sales_and_cash_journals_cannot_use_manual_reversal(): void
    {
        $i = $this->invoice();
        $this->settle($i);
        $journals = JournalEntry::query()->where('entry_kind', 'automatic')->get();
        $this->assertNotEmpty($journals->all());
        foreach ($journals as $journal) {
            $this->failsAtomically(fn () => app(AccountingJournalReversalService::class)->prepare($this->creator, (int) $this->company->getKey(), (int) $journal->getKey(), ['revision' => (int) $journal->getAttribute('revision'), 'accounting_date' => '2026-10-16', 'reason' => 'Source must own its correction']));
        }
    }

    public function test_current_period_return_and_credit_replay_survives_later_closure(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $i = $this->issueInvoice($this->bill($o, $d));
        $this->november();
        $r = $this->postReturn($this->inspected($d));
        $n = $this->issueCredit($this->credit($i));
        DB::table('accounting_periods')->update(['state' => 'hard_closed']);
        $this->settings(['state' => 'disabled']);
        $before = $this->stateDigest();
        $this->postReturn($r, (string) $r->getAttribute('idempotency_key'));
        $this->issueCredit($n, (string) $n->getAttribute('idempotency_key'));
        $this->assertSame($before, $this->stateDigest());
    }

    public function test_current_period_receipt_reversal_replay_survives_later_closure(): void
    {
        $i = $this->invoice();
        $r = $this->settle($i);
        $this->november();
        $operation = $this->op($r);
        app(CustomerReceiptService::class)->reverseReceipt($this->creator, (int) $r->getKey(), $operation);
        DB::table('accounting_periods')->update(['state' => 'hard_closed']);
        $this->settings(['state' => 'disabled']);
        $before = $this->stateDigest();
        app(CustomerReceiptService::class)->reverseReceipt($this->creator, (int) $r->getKey(), $operation);
        $this->assertSame($before, $this->stateDigest());
    }

    public function test_missing_current_reversal_member_is_not_recreated_on_retry(): void
    {
        $i = $this->invoice();
        $r = $this->settle($i);
        $this->november();
        $operation = $this->op($r);
        app(CustomerReceiptService::class)->reverseReceipt($this->creator, (int) $r->getKey(), $operation);
        $undo = CustomerReceiptAllocation::query()->whereNotNull('reverses_allocation_id')->firstOrFail();
        DB::table('accounting_source_effects')->where('source_kind', 'customer_receipt_allocation')->where('source_id', $undo->getKey())->delete();
        $this->failsAtomically(fn () => app(CustomerReceiptService::class)->reverseReceipt($this->creator, (int) $r->getKey(), $operation));
    }

    public function test_unrecognized_legacy_dispatch_cannot_be_returned_in_current_period(): void
    {
        $this->settings(['state' => 'disabled']);
        $d = $this->fulfillment($this->order());
        $this->settings(['state' => 'enabled']);
        $this->november();
        $r = $this->inspected($d);
        $this->failsAtomically(fn () => $this->postReturn($r));
    }

    public function test_verified_unpaid_legacy_ar_can_settle_and_compensate_in_current_period(): void
    {
        $pair = $this->covered();
        $trace = $this->originalTrace();
        $this->november();
        $after = $this->lastEffect();
        $r = $this->settle($pair[0]);
        $this->undo($r, $this->originalAllocation($r));
        $this->newEffectsAreCurrent($after);
        $this->assertOriginalTrace($trace);
        $this->assertSame('60.00', $this->due($pair[0]));
    }

    public function test_verified_dated_legacy_ar_can_settle_and_reverse_new_cash_in_current_period(): void
    {
        $p = $this->partial();
        $trace = $this->originalTrace();
        $this->november();
        $after = $this->lastEffect();
        $r = $this->settle($p[0]);
        app(CustomerReceiptService::class)->reverseReceipt($this->creator, (int) $r->getKey(), $this->op($r));
        $this->newEffectsAreCurrent($after);
        $this->assertOriginalTrace($trace);
        $this->assertSame('40.00', $this->due($p[0]));
    }

    public function test_old_covered_cash_remains_blocked_in_current_open_period(): void
    {
        $p = $this->partial();
        $this->november();
        $this->failsAtomically(fn () => app(CustomerReceiptService::class)->reverseReceipt($this->creator, (int) $p[1]->getKey(), $this->op($p[1])));
    }
}
