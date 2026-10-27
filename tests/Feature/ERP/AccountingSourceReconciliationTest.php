<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingMappingVersion;
use App\Models\Accounting\AccountingSourceEffect;
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
use App\Services\ERP\Accounting\AccountingCashAccountService;
use App\Services\ERP\Accounting\AccountingMappingCatalog;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\Source\AccountingSourceOrchestrator;
use App\Services\ERP\Accounting\Source\AccountingSourceReconciliation;
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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AccountingSourceReconciliationTest extends TestCase
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

    private function scan(array $filters = [], ?User $actor = null): array
    {
        return app(AccountingSourceReconciliation::class)->page($actor ?? $this->creator, (int) $this->company->getKey(), $filters);
    }

    private function inspectedRow(string $kind, int $id, ?User $actor = null): array
    {
        $rows = $this->scan(['kind' => $kind, 'limit' => 50], $actor)['rows'];
        foreach ($rows as $row) {
            if ($row['source_id'] === $id) {
                return $row;
            }
        } $this->fail('Expected scoped source candidate.');
    }

    private function observation(SalesInvoice $invoice, string $state = 'pending_reconciliation'): void
    {
        // Raw fixture simulates previously captured evidence. The report must never promote it to recognized finance.
        DB::table('accounting_cutover_sources')->insert(['company_id' => $this->company->getKey(), 'cutover_id' => AccountingCutover::query()->where('company_id', $this->company->getKey())->firstOrFail()->getKey(), 'source_kind' => 'sales_invoice', 'source_id' => $invoice->getKey(), 'coverage_kind' => 'snapshot', 'covered_through_event' => $state === 'recognized' ? 'issued' : null, 'source_payload_hash' => str_repeat('a', 64), 'opening_state' => json_encode(['recognition_state' => $state, 'snapshot' => ['id' => $invoice->getKey()]], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function counts(): array
    {
        $out = [];
        foreach (['journal_entries', 'journal_entry_lines', 'accounting_source_effects', 'accounting_sales_events', 'accounting_receipt_operations', 'stock_movements', 'accounting_audit_events', 'accounting_cutover_sources', 'accounting_company_settings'] as $table) {
            $out[$table] = DB::table($table)->count();
        }

return $out;
    }

    public function test_page_requires_authentication(): void
    {
        $this->get('/erp/accounting/source-reconciliation')->assertRedirect('/login');
    }

    public function test_page_requires_report_permission(): void
    {
        $this->actingAs($this->staff([]))->get('/erp/accounting/source-reconciliation')->assertForbidden();
    }

    public function test_service_requires_report_permission(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->scan([], $this->staff([]));
    }

    public function test_page_component_resolves_with_company_scope(): void
    {
        $i = $this->invoice();
        $staff = $this->staff(['erp.accounting-reports.view']);
        $this->newCompany('FOREIGN');
        $this->actingAs($staff)->get('/erp/accounting/source-reconciliation')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/SourceReconciliation/Index')->has('companies', 1)->where('companyId', (int) $this->company->getKey())->where('report.total_candidates', 2));
    }

    public function test_foreign_company_filter_is_forbidden(): void
    {
        $other = $this->newCompany('FOREIGN');
        $staff = $this->staff(['erp.accounting-reports.view']);
        $this->actingAs($staff)->get('/erp/accounting/source-reconciliation?company_id='.$other->getKey())->assertForbidden();
    }

    public function test_foreign_company_service_scope_is_forbidden(): void
    {
        $other = $this->newCompany('FOREIGN');
        $staff = $this->staff(['erp.accounting-reports.view']);
        $this->expectException(AuthorizationException::class);
        app(AccountingSourceReconciliation::class)->page($staff, (int) $other->getKey());
    }

    public function test_live_invoice_verifies_complete_commercial_and_cost_bundle(): void
    {
        $i = $this->invoice();
        $row = $this->inspectedRow('sales_invoice', (int) $i->getKey());
        $this->assertSame('verified_live', $row['status']);
        $this->assertCount(2, $row['effects']);
        $this->assertSame('60.00', $row['document_amount']);
    }

    public function test_finance_reader_needs_no_manual_or_source_post_authority(): void
    {
        $i = $this->invoice();
        $staff = $this->staff(['erp.accounting-reports.view']);
        $this->assertFalse($staff->can('erp.sales-invoices.issue'));
        $this->assertFalse($staff->can('erp.journal-entries.post'));
        $row = $this->inspectedRow('sales_invoice', (int) $i->getKey(), $staff);
        $this->assertSame('verified_live', $row['status']);
        $this->assertNull($row['source_url']);
        foreach ($row['effects'] as $effect) {
            $this->assertNull($effect['url']);
        }
    }

    public function test_links_require_separate_source_and_journal_view_permissions(): void
    {
        $i = $this->invoice();
        $staff = $this->staff(['erp.accounting-reports.view', 'erp.sales-invoices.view', 'erp.journal-entries.view']);
        $row = $this->inspectedRow('sales_invoice', (int) $i->getKey(), $staff);
        $this->assertStringContainsString('/erp/sales/invoices/', $row['source_url']);
        foreach ($row['effects'] as $effect) {
            $this->assertStringContainsString('/erp/accounting/journals/', $effect['url']);
        }
    }

    public function test_legacy_source_before_activation_is_pending_not_verified(): void
    {
        $this->settings(['state' => 'disabled']);
        $i = $this->invoice();
        $this->settings(['state' => 'enabled', 'activation_date' => '2026-10-17']);
        $row = $this->inspectedRow('sales_invoice', (int) $i->getKey());
        $this->assertSame('legacy_pending', $row['status']);
        $this->assertSame(0, $row['admission_count']);
    }

    public function test_post_activation_missing_finance_is_an_exception(): void
    {
        $this->settings(['state' => 'disabled']);
        $i = $this->invoice();
        $this->settings(['state' => 'enabled']);
        $this->assertSame('missing_finance', $this->inspectedRow('sales_invoice', (int) $i->getKey())['status']);
    }

    public function test_disabled_operational_source_is_not_mislabelled_as_live(): void
    {
        $this->settings(['state' => 'disabled']);
        $i = $this->invoice();
        $this->assertSame('operational_only', $this->inspectedRow('sales_invoice', (int) $i->getKey())['status']);
    }

    public function test_prepared_manifest_observation_is_not_recognized_coverage(): void
    {
        $this->settings(['state' => 'disabled']);
        $i = $this->invoice();
        $this->observation($i);
        $row = $this->inspectedRow('sales_invoice', (int) $i->getKey());
        $this->assertSame('legacy_pending', $row['status']);
        $this->assertTrue($row['coverage_observed']);
        $this->assertFalse($row['coverage_declared']);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_declared_coverage_without_adapter_verification_stays_in_review(): void
    {
        $this->settings(['state' => 'disabled']);
        $i = $this->invoice();
        $this->observation($i, 'recognized');
        $this->assertSame('coverage_review', $this->inspectedRow('sales_invoice', (int) $i->getKey())['status']);
    }

    public function test_live_and_declared_opening_coverage_conflict_is_visible(): void
    {
        $i = $this->invoice();
        $this->observation($i, 'recognized');
        $this->assertSame('coverage_conflict', $this->inspectedRow('sales_invoice', (int) $i->getKey())['status']);
    }

    public function test_missing_invoice_cost_member_is_an_incomplete_bundle(): void
    {
        $i = $this->invoice();
        DB::table('accounting_source_effects')->where('source_kind', 'sales_invoice')->where('effect_kind', 'cost')->delete();
        $before = $this->counts();
        $this->assertSame('incomplete_finance', $this->inspectedRow('sales_invoice', (int) $i->getKey())['status']);
        $this->assertSame($before, $this->counts());
    }

    public function test_missing_admission_is_not_repaired_by_report(): void
    {
        $i = $this->invoice();
        $admission = DB::table('accounting_sales_events')
            ->where('company_id', $i->getAttribute('company_id'))
            ->where('source_kind', 'sales_invoice')
            ->where('source_id', $i->getKey());
        $admissionId = $admission->value('id');
        $this->assertNotNull($admissionId);
        // Damage only this fixture, in foreign-key order; retain its financial effects.
        DB::table('accounting_sales_cost_slices')->where('sales_event_id', $admissionId)->delete();
        $this->assertSame(1, $admission->delete());
        $before = $this->counts();
        $this->assertSame('incomplete_finance', $this->inspectedRow('sales_invoice', (int) $i->getKey())['status']);
        $this->assertSame($before, $this->counts());
    }

    public function test_damaged_journal_is_an_integrity_exception_without_raw_error_leak(): void
    {
        $i = $this->invoice();
        $effect = $this->effect('sales_invoice', (int) $i->getKey());
        DB::table('journal_entry_lines')->where('journal_entry_id', $effect->getAttribute('journal_entry_id'))->where('debit', '>', 0)->update(['debit' => '99.00']);
        $row = $this->inspectedRow('sales_invoice', (int) $i->getKey());
        $this->assertSame('integrity_error', $row['status']);
        $this->assertStringNotContainsString('SQLSTATE', $row['message']);
    }

    public function test_missing_cost_slice_is_reported_as_integrity_failure(): void
    {
        $i = $this->invoice();
        DB::table('accounting_sales_cost_slices')->delete();
        $this->assertSame('integrity_error', $this->inspectedRow('sales_invoice', (int) $i->getKey())['status']);
    }

    public function test_changed_source_date_is_reported_as_integrity_failure(): void
    {
        $i = $this->invoice();
        DB::table('sales_invoices')->where('id', $i->getKey())->update(['document_date' => '2026-10-15']);
        $this->assertSame('integrity_error', $this->inspectedRow('sales_invoice', (int) $i->getKey())['status']);
    }

    public function test_closed_period_disabled_state_and_inactive_accounts_do_not_hide_verified_history(): void
    {
        $i = $this->invoice();
        DB::table('accounting_periods')->update(['state' => 'hard_closed']);
        $this->settings(['state' => 'disabled', 'cutover_locked_at' => now()]);
        Account::query()->whereKey($this->mapping['cogs'])->update(['status' => 'inactive']);
        $this->assertSame('verified_live', $this->inspectedRow('sales_invoice', (int) $i->getKey())['status']);
    }

    public function test_valid_receipt_manifest_and_initial_allocation_verify_together(): void
    {
        $i = $this->invoice();
        $r = $this->postReceipt($this->receipt(), [['sales_invoice_id' => (int) $i->getKey(), 'amount' => '30.00']]);
        $a = $this->originalAllocation($r);
        $this->assertSame('verified_live', $this->inspectedRow('customer_receipt', (int) $r->getKey())['status']);
        $this->assertSame('verified_live', $this->inspectedRow('customer_receipt_allocation', (int) $a->getKey())['status']);
    }

    public function test_receipt_later_allocation_compensation_and_reversal_manifest_members_verify(): void
    {
        $i = $this->invoice();
        $r = $this->postReceipt($this->receipt());
        $this->allocate($r, $i, '20.00');
        $this->undo($r, $this->originalAllocation($r));
        $this->allocate($r, $i, '30.00');
        $reverse = app(CustomerReceiptService::class)->reverseReceipt($this->creator, (int) $r->getKey(), $this->op($r));
        $this->assertSame('verified_live', $this->inspectedRow('customer_receipt', (int) $r->getKey())['status']);
        $this->assertSame('verified_live', $this->inspectedRow('customer_receipt', (int) $reverse->getKey())['status']);
    }

    public function test_missing_receipt_manifest_is_not_green_even_when_cash_journal_exists(): void
    {
        $r = $this->postReceipt($this->receipt());
        DB::table('accounting_receipt_operations')->delete();
        $this->assertSame('integrity_error', $this->inspectedRow('customer_receipt', (int) $r->getKey())['status']);
        $this->assertDatabaseCount('accounting_receipt_operations', 0);
    }

    public function test_receipt_report_detects_missing_financial_allocation_member(): void
    {
        $i = $this->invoice();
        $r = $this->postReceipt($this->receipt(), [['sales_invoice_id' => (int) $i->getKey(), 'amount' => '20.00']]);
        DB::table('accounting_source_effects')->where('source_kind', 'customer_receipt_allocation')->delete();
        $this->assertSame('integrity_error', $this->inspectedRow('customer_receipt', (int) $r->getKey())['status']);
    }

    public function test_damaged_receipt_manifest_hash_is_reported(): void
    {
        $r = $this->postReceipt($this->receipt());
        DB::table('accounting_receipt_operations')->update(['payload_hash' => str_repeat('0', 64)]);
        $this->assertSame('integrity_error', $this->inspectedRow('customer_receipt', (int) $r->getKey())['status']);
    }

    public function test_zero_gl_damaged_return_is_verified_without_inventing_journal(): void
    {
        $o = $this->order();
        $d = $this->fulfillment($o);
        $this->issueInvoice($this->bill($o, $d));
        $r = $this->postReturn($this->inspected($d, '2', '0'));
        $row = $this->inspectedRow('sales_return', (int) $r->getKey());
        $this->assertSame('verified_live', $row['status']);
        $this->assertNull($row['effects'][0]['journal_id']);
        $this->assertDatabaseCount('journal_entries', 3);
    }

    public function test_unknown_source_effect_contract_is_reported_as_unsupported(): void
    {
        $i = $this->invoice();
        $record = DB::table('accounting_source_effects')->first();
        $copy = (array) $record;
        unset($copy['id']);
        $copy['source_kind'] = 'uninstalled_source';
        $copy['source_id'] = 999;
        DB::table('accounting_source_effects')->insert($copy);
        $rows = $this->scan()['rows'];
        $unknown = array_values(array_filter($rows, fn (array $row): bool => $row['source_kind'] === 'uninstalled_source'));
        $this->assertCount(1, $unknown);
        $this->assertSame('unsupported_source', $unknown[0]['status']);
    }

    public function test_orphan_admission_with_missing_native_source_is_visible(): void
    {
        $this->invoice();
        $record = (array) DB::table('accounting_sales_events')->where('source_kind', 'sales_invoice')->first();
        unset($record['id']);
        $record['source_id'] = 999999;
        DB::table('accounting_sales_events')->insert($record);
        $this->assertSame('missing_source', $this->inspectedRow('sales_invoice', 999999)['status']);
    }

    public function test_nonfinal_native_source_with_financial_evidence_is_a_conflict(): void
    {
        $i = $this->invoice();
        DB::table('sales_invoices')->where('id', $i->getKey())->update(['status' => 'draft']);
        $this->assertSame('source_state_conflict', $this->inspectedRow('sales_invoice', (int) $i->getKey())['status']);
    }

    public function test_foreign_currency_source_requires_review(): void
    {
        $i = $this->invoice();
        DB::table('sales_invoices')->where('id', $i->getKey())->update(['currency_code' => 'USD']);
        $this->assertSame('currency_review', $this->inspectedRow('sales_invoice', (int) $i->getKey())['status']);
    }

    public function test_report_does_not_return_private_notes_or_raw_financial_evidence(): void
    {
        $i = $this->invoice();
        $r = $this->postReceipt($this->receipt());
        $json = json_encode($this->scan(), JSON_THROW_ON_ERROR);
        foreach (['PRIVATE-', 'customer_snapshot', 'source_snapshot', 'idempotency_key', 'opening_state', 'native_payload_hash'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
    }

    public function test_report_and_repeated_refresh_are_read_only(): void
    {
        $i = $this->invoice();
        $this->postReceipt($this->receipt(), [['sales_invoice_id' => (int) $i->getKey(), 'amount' => '30.00']]);
        $before = $this->counts();
        $settings = (array) DB::table('accounting_company_settings')->first();
        $this->scan();
        $this->scan();
        $this->assertSame($before, $this->counts());
        $this->assertSame($settings, (array) DB::table('accounting_company_settings')->first());
    }

    public function test_batch_counts_do_not_pretend_to_be_full_result_verification_totals(): void
    {
        $this->invoice();
        $a = $this->scan(['limit' => 1]);
        $this->assertSame(2, $a['total_candidates']);
        $this->assertCount(1, $a['rows']);
        $this->assertSame(1, array_sum($a['page_counts']));
        $this->assertTrue($a['has_more']);
        $b = $this->scan(['limit' => 1, ...$a['next']]);
        $this->assertCount(1, $b['rows']);
        $this->assertNotSame($a['rows'][0]['source_kind'], $b['rows'][0]['source_kind']);
        $this->assertFalse($b['has_more']);
    }

    public function test_kind_filter_and_cursor_reset_are_scoped(): void
    {
        $this->invoice();
        $report = $this->scan(['kind' => 'sales_invoice']);
        $this->assertSame(1, $report['total_candidates']);
        $this->assertCount(1, $report['rows']);
        $this->assertSame('sales_invoice', $report['rows'][0]['source_kind']);
    }

    public function test_invalid_kind_and_cursor_are_rejected(): void
    {
        $this->invalid(fn () => $this->scan(['kind' => 'customers']), 'kind');
        $this->invalid(fn () => $this->scan(['after_kind' => 'sales_invoice']), 'after_id');
        $this->invalid(fn () => $this->scan(['limit' => 500]), 'limit');
    }

    public function test_draft_native_sources_without_evidence_are_not_candidates(): void
    {
        $this->receipt();
        $o = $this->order();
        $this->draft($o, $this->reserve($o));
        $this->assertSame(0, $this->scan()['total_candidates']);
    }

    public function test_workspace_has_no_posting_or_repair_endpoint(): void
    {
        $this->actingAs($this->creator)->post('/erp/accounting/source-reconciliation', ['action' => 'repair'])->assertStatus(405);
        $this->assertDatabaseCount('accounting_source_effects', 0);
    }
}
