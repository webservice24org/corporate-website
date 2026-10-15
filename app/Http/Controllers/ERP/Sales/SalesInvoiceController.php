<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Sales;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\SalesDelivery;
use App\Models\SalesDeliveryItem;
use App\Models\SalesDocumentEvent;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\ERP\Sales\SalesCompanyAccess;
use App\Services\ERP\Sales\SalesInvoiceBalanceService;
use App\Services\ERP\Sales\SalesInvoiceService;
use App\Services\ERP\Sales\SalesOrderService;
use App\Services\ERP\Sales\SalesReturnService;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SalesInvoiceController extends Controller
{
    public function __construct(private readonly SalesInvoiceService $invoices, private readonly SalesInvoiceBalanceService $balances, private readonly SalesOrderService $orders, private readonly SalesCompanyAccess $access, private readonly SalesReturnService $returns) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $filters = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', 'in:draft,issued,cancelled'], 'sales_order_id' => ['nullable', 'integer', 'min:1']]);
        $companies = $this->orders->companies($actor, 'erp.sales-invoices.view')->orderBy('name')->get(['id', 'name']);
        if (! empty($filters['company_id'])) {
            $this->access->authorize($actor, (int) $filters['company_id'], 'erp.sales-invoices.view');
        }
        if (! empty($filters['sales_order_id'])) {
            SalesOrder::query()->whereIn('company_id', $companies->modelKeys())->findOrFail((int) $filters['sales_order_id']);
        }
        $this->balances->assertStates(! empty($filters['company_id']) ? [(int) $filters['company_id']] : $companies->modelKeys());
        $rows = SalesInvoice::query()->whereIn('company_id', $companies->modelKeys())
            ->when(! empty($filters['company_id']), fn ($q) => $q->where('company_id', $filters['company_id']))
            ->when(! empty($filters['sales_order_id']), fn ($q) => $q->where('sales_order_id', $filters['sales_order_id']))
            ->when(! empty($filters['search']), fn ($q) => $q->where('number', 'like', '%'.$filters['search'].'%'))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))->latest('id')->paginate(20)->withQueryString()
            ->through(fn (SalesInvoice $invoice): array => [...$this->header($invoice), 'balance' => $invoice->getAttribute('status') === 'issued' ? $this->balances->snapshot($invoice) : null]);

        return Inertia::render('ERP/Sales/Invoices/Index', ['invoices' => $rows, 'companies' => $companies, 'filters' => $filters]);
    }

    public function create(Request $request): Response
    {
        return $this->form($request);
    }

    public function edit(Request $request, int $invoice): Response
    {
        return $this->form($request, $invoice);
    }

    public function store(Request $request): RedirectResponse
    {
        $invoice = $this->invoices->save($this->actor($request), $request->all());

        return redirect()->route('erp.sales.invoices.show', $invoice->getKey());
    }

    public function update(Request $request, int $invoice): RedirectResponse
    {
        $this->invoices->save($this->actor($request), $request->all(), $invoice);

        return redirect()->route('erp.sales.invoices.show', $invoice);
    }

    public function issue(Request $request, int $invoice): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'string', 'max:100']]);
        $this->invoices->issue($this->actor($request), $invoice, (int) $data['revision'], (string) $data['idempotency_key']);

        return redirect()->route('erp.sales.invoices.show', $invoice)->with('success', 'Invoice issued.');
    }

    public function cancel(Request $request, int $invoice): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000']]);
        $this->invoices->cancel($this->actor($request), $invoice, (int) $data['revision'], (string) $data['reason']);

        return redirect()->route('erp.sales.invoices.show', $invoice)->with('success', 'Draft cancelled.');
    }

    public function show(Request $request, int $invoice): Response
    {
        return $this->display($request, $invoice, false);
    }

    public function print(Request $request, int $invoice): Response
    {
        return $this->display($request, $invoice, true);
    }

    private function form(Request $request, ?int $id = null): Response
    {
        $actor = $this->actor($request);
        $permission = 'erp.sales-invoices.'.($id === null ? 'create' : 'edit');
        abort_unless($actor->can('erp.sales-orders.view') && $actor->can('erp.sales-deliveries.view'), 403);
        $invoice = $id === null ? null : $this->invoices->find($actor, $id, $permission);
        if ($invoice !== null) {
            abort_unless($invoice->getAttribute('status') === 'draft', 403);
        }
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'sales_order_id' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:120']]);
        $companies = $this->orders->companies($actor, $permission)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'timezone']);
        $companyId = $invoice?->getAttribute('company_id') ?? ($data['company_id'] ?? $companies->first()?->getKey());
        $orderId = $invoice?->getAttribute('sales_order_id') ?? ($data['sales_order_id'] ?? null);
        $order = null;
        $options = [];
        $today = now()->toDateString();
        $dueDate = $today;
        if ($companyId !== null) {
            $company = $this->access->authorize($actor, (int) $companyId, $permission);
            $today = now((string) $company->getAttribute('timezone'))->toDateString();
            $dueDate = $today;
            $query = SalesOrder::query()->where('company_id', $companyId)->whereNotNull('confirmed_at')
                ->whereIn('id', SalesDelivery::query()->where('company_id', $companyId)->where('status', 'posted')->whereNotNull('posted_at')->select('sales_order_id'));
            $rows = (clone $query)->when(! empty($data['search']), fn ($q) => $q->where('number', 'like', '%'.$data['search'].'%'))->latest('id')->limit(100)->get();
            if ($orderId !== null) {
                $order = (clone $query)->findOrFail((int) $orderId);
                $this->orders->find($actor, (int) $order->getKey());
                $rows = $rows->merge([$order])->unique('id')->values();
                $days = Customer::query()->withoutGlobalScope(SoftDeletingScope::class)->where('company_id', $companyId)->whereKey($order->getAttribute('customer_id'))->value('payment_terms_days');
                $dueDate = now((string) $company->getAttribute('timezone'))->addDays(max(0, (int) $days))->toDateString();
            }
            $options = $rows->map(fn (SalesOrder $row): array => ['id' => $row->getKey(), 'number' => $row->getAttribute('number'), 'customer_name' => ($row->getAttribute('customer_snapshot') ?? [])['name'] ?? ''])->values()->all();
        }
        $sourceItems = [];
        if ($order !== null) {
            $headers = SalesDelivery::query()->where('company_id', $order->getAttribute('company_id'))->where('sales_order_id', $order->getKey())->where('status', 'posted')->whereNotNull('posted_at')->get()->keyBy('id');
            $returnProgress = [];
            foreach ($headers as $header) {
                $returnProgress += $this->returns->sourceProgress($actor, (int) $header->getKey(), $permission);
            }
            $sourceItems = SalesDeliveryItem::query()->whereIn('sales_delivery_id', $headers->modelKeys())->orderBy('id')->get()->map(fn (SalesDeliveryItem $item): array => [
                'id' => $item->getKey(), 'delivery_number' => $headers->get((int) $item->getAttribute('sales_delivery_id'))?->getAttribute('number'), 'product_snapshot' => $item->getAttribute('product_snapshot'),
                'available' => $returnProgress[(int) $item->getKey()]['available'],
            ])->all();
        }

        return Inertia::render('ERP/Sales/Invoices/Form', ['invoice' => $invoice === null ? null : $this->header($invoice), 'companies' => $companies, 'defaultCompanyId' => $companyId === null ? null : (int) $companyId, 'orders' => $options,
            'sourceOrder' => $order === null ? null : ['id' => $order->getKey(), 'number' => $order->getAttribute('number'), 'customer_name' => ($order->getAttribute('customer_snapshot') ?? [])['name'] ?? ''],
            'sourceItems' => $sourceItems, 'savedItems' => $invoice === null ? [] : $this->items($invoice), 'today' => $today, 'defaultDueDate' => $dueDate, 'search' => $data['search'] ?? '']);
    }

    /** @return array<string, mixed> */
    private function header(SalesInvoice $invoice): array
    {
        $terms = $invoice->getAttribute('terms_snapshot') ?? [];

        return [...$invoice->only(['id', 'company_id', 'customer_id', 'sales_order_id', 'number', 'status', 'revision', 'currency_code', 'notes', 'customer_snapshot', 'billing_address_snapshot', 'shipping_address_snapshot', 'subtotal', 'discount_total', 'tax_total', 'grand_total']),
            'terms_snapshot' => array_intersect_key($terms, array_flip(['issuer', 'sales_invoice_source'])), 'document_date' => $invoice->getAttribute('document_date')->format('Y-m-d'), 'due_date' => $invoice->getAttribute('due_date')->format('Y-m-d'), 'issued_at' => $invoice->getAttribute('issued_at')?->toIso8601String()];
    }

    /** @return array<int, array<string, mixed>> */
    private function items(SalesInvoice $invoice): array
    {
        return SalesInvoiceItem::query()->where('sales_invoice_id', $invoice->getKey())->orderBy('line_number')->get()->map(fn (SalesInvoiceItem $item): array => $item->only(['id', 'sales_delivery_item_id', 'product_snapshot', 'description', 'quantity', 'unit_price', 'discount_percent', 'tax_percent', 'subtotal', 'discount_amount', 'tax_amount', 'line_total']))->all();
    }

    private function display(Request $request, int $id, bool $print): Response
    {
        $actor = $this->actor($request);
        $invoice = $this->invoices->find($actor, $id);
        $events = $print ? [] : SalesDocumentEvent::query()->where('company_id', $invoice->getAttribute('company_id'))->where('document_type', 'invoice')->where('document_id', $id)->orderBy('id')->get(['id', 'action', 'actor_snapshot', 'reason', 'occurred_at']);

        return Inertia::render('ERP/Sales/Invoices/Show', ['invoice' => $this->header($invoice), 'items' => $this->items($invoice), 'events' => $events, 'printMode' => $print,
            'balance' => ! $print && $invoice->getAttribute('status') === 'issued' ? $this->balances->snapshot($invoice) : null,
            'orderUrl' => $actor->can('erp.sales-orders.view') ? route('erp.sales.orders.show', $invoice->getAttribute('sales_order_id')) : null]);
    }

    public function receivables(Request $request): Response
    {
        $actor = $this->actor($request);
        $permission = 'erp.customer-receivables.view';
        $filters = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'customer_id' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:120']]);
        $companies = $this->orders->companies($actor, $permission)->orderBy('name')->get(['id', 'name']);
        if (! empty($filters['company_id'])) {
            $this->access->authorize($actor, (int) $filters['company_id'], $permission);
        }
        $this->balances->assertStates(! empty($filters['company_id']) ? [(int) $filters['company_id']] : $companies->modelKeys());
        $query = SalesInvoice::query()->whereIn('company_id', $companies->modelKeys())->where('status', 'issued')->whereNotNull('issued_at')
            ->when(! empty($filters['company_id']), fn ($q) => $q->where('company_id', $filters['company_id']));
        $customers = Customer::query()->withoutGlobalScope(SoftDeletingScope::class)->whereIn('company_id', $companies->modelKeys())->whereIn('id', (clone $query)->select('customer_id'))->orderBy('name')->get(['id', 'name']);
        if (! empty($filters['customer_id'])) {
            abort_unless($customers->contains('id', (int) $filters['customer_id']), 404);
        }
        $rows = $query->when(! empty($filters['customer_id']), fn ($q) => $q->where('customer_id', $filters['customer_id']))
            ->when(! empty($filters['search']), fn ($q) => $q->where('number', 'like', '%'.$filters['search'].'%'))->orderBy('due_date')->orderBy('id')->paginate(20)->withQueryString()
            ->through(fn (SalesInvoice $invoice): array => ['id' => $invoice->getKey(), 'number' => $invoice->getAttribute('number'), 'customer_name' => ($invoice->getAttribute('customer_snapshot') ?? [])['name'] ?? '', 'currency_code' => $invoice->getAttribute('currency_code'),
                'document_date' => $invoice->getAttribute('document_date')->format('Y-m-d'), 'due_date' => $invoice->getAttribute('due_date')->format('Y-m-d'), 'balance' => $this->balances->snapshot($invoice), 'url' => $actor->can('erp.sales-invoices.view') ? route('erp.sales.invoices.show', $invoice->getKey()) : null]);
        $pageTotals = [];
        foreach ($rows->items() as $row) {
            $currency = (string) $row['currency_code'];
            $pageTotals[$currency] = bcadd($pageTotals[$currency] ?? '0.00', (string) $row['balance']['outstanding'], 2);
        }

        return Inertia::render('ERP/Sales/Receivables/Index', ['receivables' => $rows, 'companies' => $companies, 'customers' => $customers, 'filters' => $filters, 'pageTotals' => $pageTotals]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
