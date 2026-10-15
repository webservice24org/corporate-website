<?php

declare(strict_types=1);

namespace App\Services\ERP\Sales;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerCreditAllocation;
use App\Models\CustomerReceipt;
use App\Models\CustomerReceiptAllocation;
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
use App\Models\SalesQuotation;
use App\Models\SalesQuotationItem;
use App\Models\SalesReservationEvent;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\SalesStockReservation;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SalesReportService
{
    /** @var array<int, array<string, mixed>> */
    private array $financial = [];

    /** @var array<int, array<int, array<string, string>>> */
    private array $delivery = [];

    /** @var array<int, array<int, array<string, mixed>>> */
    private array $orderState = [];

    /** @var array<int, string> */
    private array $owners = [];

    public function __construct(private readonly SalesOrderService $orders, private readonly SalesCompanyAccess $access, private readonly SalesReportCatalog $catalog, private readonly SalesInvoiceBalanceService $balances, private readonly CustomerCreditLedger $credits, private readonly SalesReceiptLedger $receipts, private readonly SalesReturnLedger $returns, private readonly SalesDeliveryService $deliveries, private readonly SalesInvoiceMath $amounts, private readonly SalesCreditMath $money, private readonly SalesQuotationMath $quotationMath) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function filters(array $input, bool $dashboard = false): array
    {
        $data = Validator::make($input, ['type' => ['nullable', 'in:'.implode(',', array_keys($this->catalog->all()))], 'company_id' => ['nullable', 'integer', 'min:1'], 'customer_id' => ['nullable', 'integer', 'min:1'], 'created_by' => ['nullable', 'integer', 'min:1'], 'warehouse_id' => ['nullable', 'integer', 'min:1'], 'currency' => ['nullable', 'regex:/\A[A-Z]{3}\z/'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'status' => ['nullable', 'string', 'max:40'], 'search' => ['nullable', 'string', 'max:120'], 'page' => ['nullable', 'integer', 'min:1'], 'print' => ['nullable', 'boolean']])->validate();
        $data['_default_from'] = empty($data['from']);
        $data['_default_to'] = empty($data['to']);
        $data['type'] ??= 'invoices';
        $data['from'] ??= now()->startOfMonth()->toDateString();
        $data['to'] ??= now()->toDateString();
        if ($data['from'] > $data['to']) {
            $this->fail('Date range is reversed.');
        }
        if (! empty($data['status']) && ($dashboard || ! in_array($data['status'], $this->catalog->all()[$data['type']]['statuses'], true))) {
            $this->fail('Choose a status supported by this register; dashboard has no shared status filter.');
        }
        if (! empty($data['warehouse_id']) && ($dashboard || ! in_array($data['type'], ['deliveries', 'returns'], true))) {
            $this->fail('Warehouse filtering is supported only by physical delivery and return registers.');
        }

        return $data;
    }

    /** @param array<string, mixed> $filters @param list<string> $types @return array<string, mixed> */
    public function read(User $actor, array $filters, array $types): array
    {
        $this->financial = [];
        $this->delivery = [];
        $this->orderState = [];
        $this->owners = [];

        return DB::transaction(function () use ($actor, $filters, $types): array {
            $companies = $this->orders->companies($actor, 'erp.sales-reports.view')->orderBy('id')->get();
            if (! empty($filters['company_id'])) {
                $this->access->authorize($actor, (int) $filters['company_id'], 'erp.sales-reports.view');
                $companies = $companies->where('id', (int) $filters['company_id'])->values();
            }
            foreach ($companies as $company) {
                try {
                    new \DateTimeZone((string) $company->getAttribute('timezone'));
                } catch (\Exception $e) {
                    $this->fail('Company time zone requires review before period reporting.');
                }
            }
            $singleCompany = $companies->count() === 1 ? $companies->first() : null;
            if ($singleCompany !== null) {
                $today = now((string) $singleCompany->getAttribute('timezone'));
                if (! empty($filters['_default_from'])) {
                    $filters['from'] = $today->copy()->startOfMonth()->toDateString();
                } if (! empty($filters['_default_to'])) {
                    $filters['to'] = $today->toDateString();
                }
            }
            if ($filters['from'] > $filters['to']) {
                $this->fail('Date range is reversed.');
            }
            unset($filters['_default_from'], $filters['_default_to']);
            $ids = $companies->modelKeys();
            Company::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $customers = Customer::query()->withoutGlobalScope(SoftDeletingScope::class)->whereIn('company_id', $ids)->orderBy('name')->get(['id', 'company_id', 'name']);
            $warehouses = Warehouse::query()->withoutGlobalScope(SoftDeletingScope::class)->whereIn('company_id', $ids)->orderBy('name')->get(['id', 'company_id', 'name']);
            if (! empty($filters['customer_id'])) {
                abort_unless($customers->contains('id', (int) $filters['customer_id']), 404);
            }
            if (! empty($filters['warehouse_id'])) {
                abort_unless($warehouses->contains('id', (int) $filters['warehouse_id']), 404);
            }
            $creatorIds = [];
            $currencies = [];
            foreach ([SalesQuotation::class, SalesOrder::class, SalesDelivery::class, SalesInvoice::class, CustomerReceipt::class, SalesReturn::class, SalesCreditNote::class] as $class) {
                foreach ($class::query()->whereIn('company_id', $ids)->distinct()->get(['created_by', 'currency_code']) as $row) {
                    if ($row->getAttribute('created_by') !== null) {
                        $creatorIds[] = (int) $row->getAttribute('created_by');
                    } $currencies[] = (string) $row->getAttribute('currency_code');
                }
            }
            $creators = User::query()->whereIn('id', array_unique($creatorIds))->orderBy('name')->get(['id', 'name']);
            foreach ($creators as $creator) {
                $this->owners[(int) $creator->getKey()] = (string) $creator->getAttribute('name');
            }
            if (! empty($filters['created_by'])) {
                abort_unless($creators->contains('id', (int) $filters['created_by']), 404);
            }
            foreach ($companies as $company) {
                $currencies[] = (string) $company->getAttribute('currency_code');
            }
            $currencies = array_values(array_unique($currencies));
            sort($currencies);
            $reports = [];
            foreach ($types as $type) {
                abort_unless(isset($this->catalog->all()[$type]), 404);
                $rows = [];
                foreach ($companies as $company) {
                    $rows = [...$rows, ...$this->rows($actor, $company, $filters, $type)];
                }
                $rows = array_values(array_filter($rows, fn (array $r): bool => (empty($filters['status']) || $r['status'] === $filters['status']) && (empty($filters['search']) || mb_stripos(implode(' ', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', array_intersect_key($r, array_flip(['number', 'customer', 'product', 'source', 'target'])))), (string) $filters['search']) !== false)));
                usort($rows, static fn (array $a, array $b): int => [$b['date'], $b['sort_id']] <=> [$a['date'], $a['sort_id']]);
                $definition = $this->catalog->all()[$type];
                foreach ($rows as &$candidate) {
                    if ($candidate['integrity'] !== 'Verified') {
                        $candidate['url'] = null;
                        foreach ($definition['columns'] as $column) {
                            if ($column['kind'] !== 'text') {
                                unset($candidate[$column['key']]);
                            }
                        }
                    }
                } unset($candidate);
                $reports[$type] = [...$definition, 'rows' => $rows, 'totals' => $this->totals($rows, $definition['columns']), 'matching' => count($rows), 'review_count' => count(array_filter($rows, static fn (array $r): bool => $r['integrity'] !== 'Verified'))];
            }

            return ['filters' => $filters, 'reports' => $reports, 'companies' => $companies->map(fn (Company $c): array => $c->only(['id', 'name', 'timezone']))->all(), 'customers' => $customers, 'warehouses' => $warehouses, 'creators' => $creators, 'currencies' => $currencies, 'catalog' => $this->catalog->all(), 'generatedAt' => now()->toIso8601String()];
        }, 3);
    }

    /** @param array<string, mixed> $filters @param class-string<Model> $class @return Builder<Model> */
    private function query(string $class, Company $company, array $filters): Builder
    {
        return $class::query()->where('company_id', $company->getKey())->when(! empty($filters['customer_id']), fn ($q) => $q->where('customer_id', $filters['customer_id']))->when(! empty($filters['created_by']), fn ($q) => $q->where('created_by', $filters['created_by']))->when(! empty($filters['currency']), fn ($q) => $q->where('currency_code', $filters['currency']));
    }

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    private function rows(User $actor, Company $company, array $filters, string $type): array
    {
        $rows = [];
        $class = match ($type) {
            'quotations' => SalesQuotation::class, 'orders', 'commitments', 'shortages' => SalesOrder::class, 'deliveries' => SalesDelivery::class, 'invoices', 'receivables', 'customer_credits' => SalesInvoice::class, 'receipts', 'advances', 'receipt_allocations' => CustomerReceipt::class, 'credits' => SalesCreditNote::class, 'returns' => SalesReturn::class, 'credit_allocations' => SalesInvoice::class, default => $this->fail('Unsupported Sales report type.')
        };
        foreach ($this->query($class, $company, $filters)->orderBy('id')->get() as $model) {
            $status = (string) $model->getAttribute('status');
            $hasEvents = $type === 'receipt_allocations' ? CustomerReceiptAllocation::query()->where('customer_receipt_id', $model->getKey())->exists() : ($type === 'credit_allocations' ? CustomerCreditAllocation::query()->where('source_sales_invoice_id', $model->getKey())->exists() : false);
            if (in_array($type, ['receipt_allocations', 'credit_allocations'], true) && ! $hasEvents) {
                continue;
            }
            if (in_array($type, ['orders', 'commitments', 'shortages'], true) && $model->getAttribute('confirmed_at') === null && in_array($status, ['draft', 'submitted', 'approved', 'rejected', 'cancelled'], true)) {
                continue;
            }
            if (in_array($type, ['deliveries', 'returns', 'receipts', 'advances', 'receipt_allocations'], true) && $model->getAttribute('posted_at') === null && ! $hasEvents && in_array($status, ['draft', 'cancelled', 'inspected'], true)) {
                continue;
            }
            if (in_array($type, ['invoices', 'receivables', 'customer_credits', 'credits', 'credit_allocations'], true) && $model->getAttribute('issued_at') === null && ! $hasEvents && in_array($status, ['draft', 'cancelled'], true)) {
                continue;
            }
            $current = (bool) $this->catalog->all()[$type]['current'];
            $column = match ($type) {
                'quotations' => 'document_date', 'orders' => 'confirmed_at', 'deliveries', 'returns', 'receipts' => 'posted_at', 'invoices', 'credits' => 'issued_at', default => null
            };
            $row = $this->base($actor, $company, $model, $type, $current ? null : $column);
            if (in_array($type, ['receipt_allocations', 'credit_allocations'], true)) {
                $row['date'] = '';
                $row['event_at'] = 'Unknown allocation event scope — review source';
                $row['integrity'] = 'Review required: allocation source';
            }
            if ($column !== null && ! $this->inPeriod($row, $filters)) {
                continue;
            }
            try {
                $this->assertCustomer($model, $company);
                if ($type === 'quotations') {
                    if (! in_array($status, $this->catalog->all()[$type]['statuses'], true)) {
                        $this->fail('Quotation state requires review.');
                    }
                    $this->quotation($model);
                    $row += ['amount' => $this->money->money((string) $model->getAttribute('grand_total')), 'valid_until' => $model->getAttribute('valid_until')?->format('Y-m-d')];
                } elseif (in_array($type, ['orders', 'commitments', 'shortages'], true)) {
                    $state = $this->order($model);
                    if ($type === 'orders') {
                        $row['amount'] = $this->money->money((string) $model->getAttribute('grand_total'));
                    } else {
                        foreach (SalesOrderItem::query()->where('sales_order_id', $model->getKey())->orderBy('id')->get() as $line) {
                            $p = $state[(int) $line->getKey()];
                            $r = [...$row, ...$this->product($line), 'sort_id' => (int) $line->getKey(), 'number' => $row['number'].' / '.$line->getAttribute('line_number')];
                            if ($type === 'commitments') {
                                if (bccomp($p['unbilled'], '0', 4) === 0) {
                                    continue;
                                } $r += ['quantity' => $p['unbilled'], 'undelivered' => $p['undelivered'], 'amount' => $p['amount']];
                            } else {
                                if (! $p['physical'] || bccomp($p['undelivered'], '0', 4) === 0) {
                                    continue;
                                } $r += ['quantity' => $p['undelivered'], 'reserved' => $p['reserved'], 'shortage' => bcsub($p['undelivered'], $p['reserved'], 4)];
                                $r['status'] = bccomp($r['shortage'], '0', 4) > 0 ? 'shortage' : 'covered';
                            }
                            $rows[] = $r;
                        }

                        continue;
                    }
                } elseif ($type === 'deliveries') {
                    $this->proof($model);
                    foreach (SalesDeliveryItem::query()->where('sales_delivery_id', $model->getKey())->orderBy('id')->get() as $line) {
                        if (! empty($filters['warehouse_id']) && (int) $line->getAttribute('warehouse_id') !== (int) $filters['warehouse_id']) {
                            continue;
                        }
                        $rows[] = [...$row, ...$this->product($line), 'number' => $row['number'].' / '.$line->getAttribute('line_number'), 'sort_id' => (int) $line->getKey(), 'warehouse' => $this->warehouse($company, $line->getAttribute('warehouse_id')), 'fulfillment' => $line->getAttribute('fulfillment_type'), 'quantity' => $this->returns->quantity((string) $line->getAttribute('quantity'), true), 'amount' => $this->cost((string) $line->getAttribute('total_cost'))];
                    }

                    continue;
                } elseif (in_array($type, ['invoices', 'receivables', 'customer_credits'], true)) {
                    $financial = $this->finance((int) $company->getKey(), (int) $model->getAttribute('customer_id'));
                    $b = $financial['balances'][(int) $model->getKey()];
                    $p = $financial['state'][(int) $model->getKey()];
                    if ($type === 'invoices') {
                        $row += ['amount' => $b['gross'], 'due_date' => $model->getAttribute('due_date')->format('Y-m-d')];
                    } elseif ($type === 'receivables') {
                        $row += ['gross' => $b['gross'], 'paid' => $b['paid'], 'native_credit' => $b['native_credits'], 'incoming_credit' => $b['allocated_credit'], 'amount' => $b['outstanding'], 'overdue' => $b['overdue'] ? $b['outstanding'] : '0.00', 'due_date' => $model->getAttribute('due_date')->format('Y-m-d'), 'payment_status' => $b['payment_status'], 'credit_status' => $b['credit_status']];
                        $row['status'] = $b['overdue'] ? 'overdue' : (bccomp($b['outstanding'], '0', 2) > 0 ? 'open' : 'settled');
                    } else {
                        if (bccomp($p['capacity'], '0', 2) === 0 && bccomp($p['incoming'], '0', 2) === 0) {
                            continue;
                        } $row += ['capacity' => $p['capacity'], 'incoming_credit' => $p['incoming'], 'outgoing_credit' => $p['outgoing'], 'amount' => $p['available']];
                        $row['status'] = bccomp($p['available'], '0', 2) > 0 ? 'available' : (bccomp($p['outgoing'], '0', 2) > 0 ? 'allocated' : 'received');
                    }
                } elseif (in_array($type, ['receipts', 'advances', 'receipt_allocations'], true)) {
                    if (! $model instanceof CustomerReceipt) {
                        $this->fail('Receipt type requires review.');
                    }
                    $p = $this->receipts->state($model);
                    if ($model->getAttribute('idempotency_key') === null || $model->getAttribute('payload_hash') === null) {
                        $this->fail('Receipt operation proof is incomplete.');
                    } $this->finance((int) $company->getKey(), (int) $model->getAttribute('customer_id'));
                    if ($status !== 'posted') {
                        $this->fail('Receipt posting state requires review.');
                    }
                    if ($type === 'receipt_allocations') {
                        foreach (CustomerReceiptAllocation::query()->where('customer_receipt_id', $model->getKey())->orderBy('id')->get() as $a) {
                            $r = $this->base($actor, $company, $model, $type, 'posted_at', $a);
                            if (! $this->inPeriod($r, $filters)) {
                                continue;
                            } $target = SalesInvoice::query()->where('company_id', $company->getKey())->where('customer_id', $model->getAttribute('customer_id'))->findOrFail($a->getAttribute('sales_invoice_id'));
                            $undo = $a->getAttribute('reverses_allocation_id') !== null;
                            $r += ['source' => $model->getAttribute('number'), 'target' => $target->getAttribute('number'), 'reverses' => $a->getAttribute('reverses_allocation_id'), 'amount' => $undo ? bcsub('0', (string) $a->getAttribute('amount'), 2) : $this->money->money((string) $a->getAttribute('amount'), true)];
                            $r['status'] = $undo ? 'compensation' : (in_array((int) $a->getKey(), $p['active_ids'], true) ? 'active' : 'reversed');
                            $rows[] = $r;
                        }

continue;
                    }
                    if ($type === 'advances') {
                        if (bccomp($p['available'], '0', 2) <= 0) {
                            continue;
                        } $row += ['amount' => $p['available'], 'payment_method' => $model->getAttribute('payment_method')];
                        $row['status'] = 'available';
                    } else {
                        $row += ['amount' => $p['is_reversal'] ? bcsub('0', (string) $model->getAttribute('amount'), 2) : $this->money->money((string) $model->getAttribute('amount'), true), 'allocated' => $p['allocated'], 'available' => $p['available'], 'payment_method' => $model->getAttribute('payment_method'), 'reference' => $model->getAttribute('payment_reference')];
                        $row['status'] = $p['is_reversal'] ? 'reversal' : ($p['reversed'] ? 'reversed' : 'posted');
                    }
                } elseif ($type === 'credits') {
                    $this->finance((int) $company->getKey(), (int) $model->getAttribute('customer_id'));
                    if ($status !== 'issued') {
                        $this->fail('Credit issue state requires review.');
                    } $invoice = SalesInvoice::query()->where('company_id', $company->getKey())->where('customer_id', $model->getAttribute('customer_id'))->findOrFail($model->getAttribute('sales_invoice_id'));
                    $row += ['amount' => $this->money->money((string) $model->getAttribute('grand_total')), 'source' => $invoice->getAttribute('number')];
                } elseif ($type === 'credit_allocations') {
                    $this->finance((int) $company->getKey(), (int) $model->getAttribute('customer_id'));
                    foreach (CustomerCreditAllocation::query()->where('source_sales_invoice_id', $model->getKey())->orderBy('id')->get() as $a) {
                        $r = $this->base($actor, $company, $model, $type, 'posted_at', $a);
                        if (! $this->inPeriod($r, $filters)) {
                            continue;
                        } $target = SalesInvoice::query()->where('company_id', $company->getKey())->where('customer_id', $model->getAttribute('customer_id'))->findOrFail($a->getAttribute('sales_invoice_id'));
                        $undo = $a->getAttribute('reverses_allocation_id') !== null;
                        $r += ['source' => $model->getAttribute('number'), 'target' => $target->getAttribute('number'), 'reverses' => $a->getAttribute('reverses_allocation_id'), 'amount' => $undo ? bcsub('0', (string) $a->getAttribute('amount'), 2) : $this->money->money((string) $a->getAttribute('amount'), true)];
                        $r['status'] = $undo ? 'compensation' : (CustomerCreditAllocation::query()->where('reverses_allocation_id', $a->getKey())->exists() ? 'reversed' : 'active');
                        $rows[] = $r;
                    }

continue;
                } elseif ($type === 'returns') {
                    $delivery = SalesDelivery::query()->where('company_id', $company->getKey())->findOrFail($model->getAttribute('sales_delivery_id'));
                    $this->proof($delivery);
                    if ($status !== 'posted') {
                        $this->fail('Return posting state requires review.');
                    }
                    foreach (SalesReturnItem::query()->where('sales_return_id', $model->getKey())->orderBy('id')->get() as $line) {
                        if (! empty($filters['warehouse_id']) && (int) $line->getAttribute('warehouse_id') !== (int) $filters['warehouse_id']) {
                            continue;
                        } $rows[] = [...$row, ...$this->product($line), 'number' => $row['number'].' / '.$line->getAttribute('line_number'), 'sort_id' => (int) $line->getKey(), 'warehouse' => $this->warehouse($company, $line->getAttribute('warehouse_id')), 'quantity' => $line->getAttribute('accepted_quantity'), 'restocked' => $line->getAttribute('restocked_quantity'), 'damaged' => bcsub((string) $line->getAttribute('accepted_quantity'), (string) $line->getAttribute('restocked_quantity'), 4), 'rejected' => bcsub((string) $line->getAttribute('quantity'), (string) $line->getAttribute('accepted_quantity'), 4), 'unbilled' => $line->getAttribute('unbilled_quantity'), 'credit_review' => $line->getAttribute('credit_review_quantity'), 'amount' => $this->cost(bcmul((string) $line->getAttribute('restocked_quantity'), (string) $line->getAttribute('unit_cost'), 4))];
                    }

continue;
                }
                $rows[] = $row;
            } catch (ValidationException|ModelNotFoundException $e) {
                $row['integrity'] = 'Review required: source, ledger or balance proof is invalid';
                $row['url'] = null;
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function base(User $actor, Company $company, Model $document, string $type, ?string $column, ?Model $event = null): array
    {
        $event ??= $document;
        try {
            $time = $column === null ? now((string) $company->getAttribute('timezone')) : $event->getAttribute($column);
        } catch (InvalidFormatException $e) {
            $time = null;
        }
        $date = $time instanceof CarbonInterface ? ($column === 'document_date' ? $time->format('Y-m-d') : $time->copy()->setTimezone((string) $company->getAttribute('timezone'))->format('Y-m-d')) : '';
        $at = $time instanceof CarbonInterface ? ($column === 'document_date' ? $date : $time->copy()->setTimezone((string) $company->getAttribute('timezone'))->toIso8601String()) : 'Unknown — review';
        [$permission, $route] = match ($type) {
            'quotations' => ['erp.sales-quotations.view', 'erp.sales.quotations.show'], 'orders', 'commitments', 'shortages' => ['erp.sales-orders.view', 'erp.sales.orders.show'], 'deliveries' => ['erp.sales-deliveries.view', 'erp.sales.deliveries.show'], 'invoices', 'receivables', 'customer_credits', 'credit_allocations' => ['erp.sales-invoices.view', 'erp.sales.invoices.show'], 'receipts', 'advances', 'receipt_allocations' => ['erp.customer-receipts.view', 'erp.sales.receipts.show'], 'credits' => ['erp.sales-credit-notes.view', 'erp.sales.credit-notes.show'], 'returns' => ['erp.sales-returns.view', 'erp.sales.returns.show'], default => $this->fail('Unsupported Sales report type.')
        };

        return ['company_id' => (int) $company->getKey(), 'company' => (string) $company->getAttribute('name'), 'currency' => (string) $document->getAttribute('currency_code'), 'number' => $event === $document ? (string) $document->getAttribute('number') : '#'.$event->getKey(), 'customer' => (string) (($document->getAttribute('customer_snapshot') ?? [])['name'] ?? 'Unavailable source snapshot'), 'owner' => $this->owners[(int) $document->getAttribute('created_by')] ?? 'Unavailable actor', 'date' => $date, 'event_at' => $at, 'status' => (string) $document->getAttribute('status'), 'integrity' => $date === '' ? 'Review required: missing event timestamp' : 'Verified', 'sort_id' => (int) $event->getKey(), 'url' => $actor->can($permission) ? route($route, $document->getKey()) : null];
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $filters */
    private function inPeriod(array $row, array $filters): bool
    {
        return $row['date'] === '' || ($row['date'] >= $filters['from'] && $row['date'] <= $filters['to']);
    }

    private function assertCustomer(Model $model, Company $company): void
    {
        Customer::query()->withoutGlobalScope(SoftDeletingScope::class)->where('company_id', $company->getKey())->findOrFail($model->getAttribute('customer_id'));
        if (preg_match('/\A[A-Z]{3}\z/', (string) $model->getAttribute('currency_code')) !== 1) {
            $this->fail('Document currency requires review.');
        }
    }

    /** @return array<string, mixed> */
    private function finance(int $companyId, int $customerId): array
    {
        if (isset($this->financial[$customerId])) {
            if (isset($this->financial[$customerId]['invalid'])) {
                $this->fail('Customer financial proof requires review.');
            }

return $this->financial[$customerId];
        }
        try {
            $state = $this->credits->customer($companyId, $customerId);
            $balances = [];
            if (SalesCreditNote::query()->where('company_id', $companyId)->where('customer_id', $customerId)->where('status', 'issued')->where(fn ($q) => $q->whereNull('payload_hash')->orWhereNull('idempotency_key')->orWhereNull('issue_sequence'))->exists()) {
                $this->fail('Historical credit issue proof is incomplete.');
            }
            foreach (SalesInvoice::query()->where('company_id', $companyId)->where('customer_id', $customerId)->where('status', 'issued')->get() as $invoice) {
                if ($invoice->getAttribute('payload_hash') === null || $invoice->getAttribute('idempotency_key') === null) {
                    $this->fail('Invoice issue proof is incomplete.');
                }
                foreach (SalesInvoiceItem::query()->where('sales_invoice_id', $invoice->getKey())->get() as $item) {
                    $di = SalesDeliveryItem::query()->findOrFail($item->getAttribute('sales_delivery_item_id'));
                    $this->proof(SalesDelivery::query()->where('company_id', $companyId)->findOrFail($di->getAttribute('sales_delivery_id')));
                    $source = SalesOrderItem::query()->where('sales_order_id', $invoice->getAttribute('sales_order_id'))->findOrFail($di->getAttribute('sales_order_item_id'));
                    foreach (['unit_price', 'discount_percent', 'tax_percent', 'product_snapshot', 'description'] as $field) {
                        if ($item->getAttribute($field) !== $source->getAttribute($field)) {
                            $this->fail('Invoice line pricing differs from its source order.');
                        }
                    }
                }
                $balances[(int) $invoice->getKey()] = $this->balances->snapshot($invoice);
            }
            $this->financial[$customerId] = compact('state', 'balances');

            return $this->financial[$customerId];
        } catch (ValidationException|ModelNotFoundException $e) {
            $this->financial[$customerId] = ['invalid' => true];
            throw $e;
        }
    }

    /** @return array<int, array<string, string>> */
    private function proof(Model $model): array
    {
        if (! $model instanceof SalesDelivery) {
            $this->fail('Delivery source type requires review.');
        }
        $id = (int) $model->getKey();
        if (isset($this->delivery[$id])) {
            return $this->delivery[$id];
        }
        $this->deliveries->assertInvoiceSource($model);
        $order = SalesOrder::query()->where('company_id', $model->getAttribute('company_id'))->where('customer_id', $model->getAttribute('customer_id'))->where('currency_code', $model->getAttribute('currency_code'))->whereNotNull('confirmed_at')->findOrFail($model->getAttribute('sales_order_id'));
        foreach (SalesDeliveryItem::query()->where('sales_delivery_id', $id)->get() as $di) {
            $line = SalesOrderItem::query()->where('sales_order_id', $order->getKey())->findOrFail($di->getAttribute('sales_order_item_id'));
            $this->lineIdentity($line, (int) $model->getAttribute('company_id'));
            foreach (['product_id', 'product_variant_id', 'unit_id', 'product_snapshot', 'description'] as $field) {
                if ($di->getAttribute($field) !== $line->getAttribute($field)) {
                    $this->fail('Delivery source identity requires review.');
                }
            }
        }
        $this->delivery[$id] = $this->returns->progress($model);

        return $this->delivery[$id];
    }

    private function quotation(Model $model): void
    {
        if (! $model instanceof SalesQuotation) {
            $this->fail('Quotation type requires review.');
        }
        $items = SalesQuotationItem::query()->where('sales_quotation_id', $model->getKey())->get();
        if ($items->isEmpty()) {
            $this->fail('Quotation source lines are missing.');
        }
        $this->checkTotals($model, $items->all());
    }

    /** @param array<array-key, Model> $items */
    private function checkTotals(Model $header, array $items): void
    {
        $totals = ['subtotal' => '0.00', 'discount_total' => '0.00', 'tax_total' => '0.00', 'grand_total' => '0.00'];
        foreach ($items as $line) {
            $this->lineIdentity($line, (int) $header->getAttribute('company_id'));
            $this->returns->quantity((string) $line->getAttribute('quantity'), true);
            foreach ($this->quotationMath->line((string) $line->getAttribute('quantity'), (string) $line->getAttribute('unit_price'), (string) $line->getAttribute('discount_percent'), (string) $line->getAttribute('tax_percent')) as $field => $value) {
                if ($line->getAttribute($field) !== $value) {
                    $this->fail('Original price and rounded amounts differ.');
                }
            }
            foreach (['subtotal' => 'subtotal', 'discount_total' => 'discount_amount', 'tax_total' => 'tax_amount', 'grand_total' => 'line_total'] as $field => $source) {
                $totals[$field] = $this->money->money(bcadd($totals[$field], $this->money->money((string) $line->getAttribute($source)), 2));
            }
            if (bccomp((string) $line->getAttribute('discount_amount'), (string) $line->getAttribute('subtotal'), 2) > 0 || bccomp(bcadd(bcsub((string) $line->getAttribute('subtotal'), (string) $line->getAttribute('discount_amount'), 2), (string) $line->getAttribute('tax_amount'), 2), (string) $line->getAttribute('line_total'), 2) !== 0) {
                $this->fail('Line component budget requires review.');
            }
        }
        foreach ($totals as $field => $amount) {
            if ($header->getAttribute($field) !== $amount) {
                $this->fail('Header and line totals differ.');
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function order(Model $model): array
    {
        if (! $model instanceof SalesOrder || $model->getAttribute('confirmed_at') === null || ! in_array($model->getAttribute('status'), ['confirmed', 'partially_delivered', 'delivered', 'closed', 'cancelled'], true)) {
            $this->fail('Order confirmation state requires review.');
        }
        $id = (int) $model->getKey();
        if (isset($this->orderState[$id])) {
            return $this->orderState[$id];
        }
        $quote = SalesQuotation::query()->where('company_id', $model->getAttribute('company_id'))->where('customer_id', $model->getAttribute('customer_id'))->where('currency_code', $model->getAttribute('currency_code'))->findOrFail($model->getAttribute('sales_quotation_id'));
        $this->quotation($quote);
        $lines = SalesOrderItem::query()->where('sales_order_id', $id)->get();
        if ($lines->isEmpty()) {
            $this->fail('Order has no source lines.');
        } $this->checkTotals($model, $lines->all());
        $this->finance((int) $model->getAttribute('company_id'), (int) $model->getAttribute('customer_id'));
        $deliveryHeaders = SalesDelivery::query()->where('company_id', $model->getAttribute('company_id'))->where('sales_order_id', $id)->get()->keyBy('id');
        $progress = [];
        $delivered = [];
        $returned = [];
        foreach ($deliveryHeaders as $dh) {
            if (($dh->getAttribute('status') === 'posted') !== ($dh->getAttribute('posted_at') !== null) || ! in_array($dh->getAttribute('status'), ['draft', 'posted', 'cancelled'], true)) {
                $this->fail('Delivery state requires review.');
            }
            if ($dh->getAttribute('status') === 'posted') {
                $progress += $this->proof($dh);
            }
        }
        $diRows = SalesDeliveryItem::query()->whereIn('sales_delivery_id', $deliveryHeaders->modelKeys())->get()->keyBy('id');
        foreach ($diRows as $di) {
            if (! isset($progress[(int) $di->getKey()])) {
                continue;
            } $lineId = (int) $di->getAttribute('sales_order_item_id');
            $delivered[$lineId] = bcadd($delivered[$lineId] ?? '0.0000', (string) $di->getAttribute('quantity'), 4);
            $returned[$lineId] = bcadd($returned[$lineId] ?? '0.0000', $progress[(int) $di->getKey()]['unbilled'], 4);
        }
        $issued = SalesInvoice::query()->where('company_id', $model->getAttribute('company_id'))->where('sales_order_id', $id)->where('status', 'issued')->pluck('id');
        $billed = [];
        $billedAmount = [];
        foreach (SalesInvoiceItem::query()->whereIn('sales_invoice_id', $issued)->get() as $ii) {
            $di = $diRows->get((int) $ii->getAttribute('sales_delivery_item_id'));
            if (! $di instanceof SalesDeliveryItem) {
                $this->fail('Issued invoice refers to foreign delivery lines.');
            } $lineId = (int) $di->getAttribute('sales_order_item_id');
            $billed[$lineId] = bcadd($billed[$lineId] ?? '0.0000', (string) $ii->getAttribute('quantity'), 4);
            $billedAmount[$lineId] = bcadd($billedAmount[$lineId] ?? '0.00', (string) $ii->getAttribute('line_total'), 2);
        }
        $result = [];
        foreach ($lines as $line) {
            $lineId = (int) $line->getKey();
            $source = SalesQuotationItem::query()->where('sales_quotation_id', $quote->getKey())->findOrFail($line->getAttribute('sales_quotation_item_id'));
            foreach (['product_id', 'product_variant_id', 'unit_id', 'product_snapshot', 'description', 'quantity', 'unit_price', 'discount_percent', 'tax_percent', 'subtotal', 'discount_amount', 'tax_amount', 'line_total'] as $field) {
                if ($line->getAttribute($field) !== $source->getAttribute($field)) {
                    $this->fail('Confirmed line differs from its original quotation budget.');
                }
            }
            if ($line->getAttribute('delivered_quantity') !== ($delivered[$lineId] ?? '0.0000') || $line->getAttribute('invoiced_quantity') !== ($billed[$lineId] ?? '0.0000')) {
                $this->fail('Order progress counters require reconciliation.');
            }
            $qty = (string) $line->getAttribute('quantity');
            $cancelled = $this->returns->quantity((string) $line->getAttribute('cancelled_quantity'));
            $excluded = bcadd($cancelled, $returned[$lineId] ?? '0.0000', 4);
            $retained = bcsub($qty, $excluded, 4);
            $undelivered = bcsub(bcsub($qty, $cancelled, 4), (string) $line->getAttribute('delivered_quantity'), 4);
            $unbilled = bcsub($retained, (string) $line->getAttribute('invoiced_quantity'), 4);
            if (bccomp($undelivered, '0', 4) < 0 || bccomp($unbilled, '0', 4) < 0) {
                $this->fail('Returned/billed/cancelled quantities overlap.');
            }
            $retainedValue = bccomp($retained, '0', 4) === 0 ? '0.00' : $this->amounts->slice($line, '0', $retained)['line_total'];
            $amount = bcsub($retainedValue, $billedAmount[$lineId] ?? '0.00', 2);
            if (bccomp($amount, '0', 2) < 0) {
                $this->fail('Order billing exceeds retained value.');
            }
            $configuration = null;
            foreach (($model->getAttribute('terms_snapshot') ?? [])['sales_order_source']['configuration'] ?? [] as $config) {
                if ((int) ($config['source_item_id'] ?? 0) === (int) $source->getKey()) {
                    $configuration = $config;
                    break;
                }
            }
            if ($configuration === null) {
                $this->fail('Frozen fulfillment configuration is missing.');
            }
            $physical = ($configuration['product_type'] ?? null) === 'physical' && (bool) ($configuration['track_inventory'] ?? false);
            $reserved = $this->reservation($model, $line, $undelivered, $diRows->all(), $progress);
            $result[$lineId] = compact('amount', 'unbilled', 'undelivered', 'physical', 'reserved');
        }
        $this->orderState[$id] = $result;

        return $result;
    }

    /** @param array<array-key, SalesDeliveryItem> $deliveryItems @param array<int, array<string, string>> $posted */
    private function reservation(SalesOrder $order, SalesOrderItem $line, string $remaining, array $deliveryItems, array $posted): string
    {
        $active = '0.0000';
        foreach (SalesStockReservation::query()->where('sales_order_item_id', $line->getKey())->get() as $r) {
            if ((int) $r->getAttribute('company_id') !== (int) $order->getAttribute('company_id') || (int) $r->getAttribute('product_id') !== (int) $line->getAttribute('product_id') || (int) ($r->getAttribute('product_variant_id') ?? 0) !== (int) ($line->getAttribute('product_variant_id') ?? 0) || (int) $r->getAttribute('variant_key') !== (int) ($line->getAttribute('product_variant_id') ?? 0)) {
                $this->fail('Owned reservation identity requires review.');
            }
            Warehouse::query()->withoutGlobalScope(SoftDeletingScope::class)->where('company_id', $order->getAttribute('company_id'))->findOrFail($r->getAttribute('warehouse_id'));
            $events = ['allocate' => '0.0000', 'release' => '0.0000', 'consume' => '0.0000'];
            foreach (SalesReservationEvent::query()->where('sales_stock_reservation_id', $r->getKey())->get() as $event) {
                $action = (string) $event->getAttribute('action');
                if (! isset($events[$action]) || (int) $event->getAttribute('company_id') !== (int) $order->getAttribute('company_id') || $event->getAttribute('occurred_at') === null) {
                    $this->fail('Reservation event proof requires review.');
                } $events[$action] = bcadd($events[$action], $this->returns->quantity((string) $event->getAttribute('quantity'), true), 4);
            }
            foreach (['allocated_quantity' => 'allocate', 'released_quantity' => 'release', 'consumed_quantity' => 'consume'] as $counter => $action) {
                if ($r->getAttribute($counter) !== $events[$action]) {
                    $this->fail('Reservation counters do not match event history.');
                }
            }
            $consumed = '0.0000';
            foreach ($deliveryItems as $di) {
                if (isset($posted[(int) $di->getKey()]) && (int) $di->getAttribute('sales_stock_reservation_id') === (int) $r->getKey()) {
                    $consumed = bcadd($consumed, (string) $di->getAttribute('quantity'), 4);
                }
            }
            if ($consumed !== $events['consume']) {
                $this->fail('Consumed reservations differ from posted delivery quantities.');
            }
            $owned = bcsub(bcsub($events['allocate'], $events['release'], 4), $events['consume'], 4);
            if (bccomp($owned, '0', 4) < 0) {
                $this->fail('Reservation active quantity is negative.');
            } $active = bcadd($active, $owned, 4);
            if (bccomp($owned, '0', 4) > 0) {
                $balance = InventoryBalance::query()->where('company_id', $order->getAttribute('company_id'))->where('warehouse_id', $r->getAttribute('warehouse_id'))->where('product_id', $line->getAttribute('product_id'))->where('variant_key', $r->getAttribute('variant_key'))->firstOrFail();
                $ownedAll = '0.0000';
                foreach (SalesStockReservation::query()->where('company_id', $order->getAttribute('company_id'))->where('warehouse_id', $r->getAttribute('warehouse_id'))->where('product_id', $line->getAttribute('product_id'))->where('variant_key', $r->getAttribute('variant_key'))->get() as $other) {
                    $otherActive = bcsub(bcsub((string) $other->getAttribute('allocated_quantity'), (string) $other->getAttribute('released_quantity'), 4), (string) $other->getAttribute('consumed_quantity'), 4);
                    if (bccomp($otherActive, '0', 4) < 0) {
                        $this->fail('Another Sales reservation has invalid active quantity.');
                    } $ownedAll = bcadd($ownedAll, $otherActive, 4);
                }
                if (bccomp($ownedAll, (string) $balance->getAttribute('quantity_reserved'), 4) > 0) {
                    $this->fail('Owned Sales allocations exceed inventory reserved quantity.');
                }
            }
        }
        if (bccomp($active, $remaining, 4) > 0) {
            $this->fail('Owned reservations exceed undelivered order quantity.');
        }

        return $active;
    }

    private function lineIdentity(Model $line, int $companyId): void
    {
        $product = Product::query()->withoutGlobalScope(SoftDeletingScope::class)->where('company_id', $companyId)->findOrFail($line->getAttribute('product_id'));
        Unit::query()->withoutGlobalScope(SoftDeletingScope::class)->where('company_id', $companyId)->findOrFail($line->getAttribute('unit_id'));
        if ($line->getAttribute('product_variant_id') !== null) {
            ProductVariant::query()->withoutGlobalScope(SoftDeletingScope::class)->where('product_id', $product->getKey())->findOrFail($line->getAttribute('product_variant_id'));
        }
    }

    /** @return array<string, mixed> */
    private function product(Model $line): array
    {
        $snapshot = $line->getAttribute('product_snapshot') ?? [];

        return ['unit_id' => (int) $line->getAttribute('unit_id'), 'unit' => (string) ($snapshot['unit'] ?? 'Unit #'.$line->getAttribute('unit_id')), 'product' => (string) ($snapshot['name'] ?? 'Product #'.$line->getAttribute('product_id')), 'variant' => (string) ($snapshot['variant_name'] ?? '')];
    }

    private function warehouse(Company $company, mixed $id): string
    {
        if ($id === null) {
            return 'Non-stock completion';
        }

return (string) Warehouse::query()->withoutGlobalScope(SoftDeletingScope::class)->where('company_id', $company->getKey())->findOrFail($id)->getAttribute('name');
    }

    private function cost(string $value): string
    {
        if (preg_match('/\A[0-9]{1,14}(?:\.[0-9]{1,4})?\z/', $value) !== 1) {
            $this->fail('Stock cost requires review.');
        }

return bcadd($value, '0', 4);
    }

    /** @param list<array<string, mixed>> $rows @param list<array<string, mixed>> $columns @return array<string, mixed> */
    private function totals(array $rows, array $columns): array
    {
        $money = [];
        $quantities = [];
        $statuses = [];
        foreach ($rows as $row) {
            if ($row['integrity'] !== 'Verified') {
                continue;
            }
            $mkey = $row['company_id'].'|'.$row['currency'];
            $statuses[$row['status']] = ($statuses[$row['status']] ?? 0) + 1;
            foreach ($columns as $column) {
                $key = $column['key'];
                if (! isset($row[$key]) || $column['kind'] === 'text') {
                    continue;
                }
                if ($column['kind'] === 'money') {
                    $money[$mkey] ??= ['company' => $row['company'], 'company_id' => $row['company_id'], 'currency' => $row['currency'], 'values' => []];
                    $money[$mkey]['values'][$key] = bcadd($money[$mkey]['values'][$key] ?? '0', (string) $row[$key], (int) ($column['scale'] ?? 2));
                } else {
                    $qkey = $row['company_id'].'|'.($row['unit_id'] ?? 0);
                    $quantities[$qkey] ??= ['company' => $row['company'], 'company_id' => $row['company_id'], 'unit' => $row['unit'], 'unit_id' => $row['unit_id'], 'values' => []];
                    $quantities[$qkey]['values'][$key] = bcadd($quantities[$qkey]['values'][$key] ?? '0.0000', (string) $row[$key], 4);
                }
            }
        }
        ksort($money);
        ksort($quantities);
        ksort($statuses);

        return ['money' => array_values($money), 'quantities' => array_values($quantities), 'statuses' => $statuses];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['report' => $message]);
    }
}
