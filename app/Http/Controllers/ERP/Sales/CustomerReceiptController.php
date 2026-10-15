<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Sales;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\CustomerReceiptAllocation;
use App\Models\SalesDocumentEvent;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\ERP\Sales\CustomerReceiptService;
use App\Services\ERP\Sales\CustomerStatementService;
use App\Services\ERP\Sales\SalesCompanyAccess;
use App\Services\ERP\Sales\SalesInvoiceBalanceService;
use App\Services\ERP\Sales\SalesOrderService;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CustomerReceiptController extends Controller
{
    public function __construct(private readonly CustomerReceiptService $receipts, private readonly CustomerStatementService $statements, private readonly SalesOrderService $orders, private readonly SalesCompanyAccess $access, private readonly SalesInvoiceBalanceService $balances) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $filters = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'customer_id' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', 'in:draft,posted,cancelled']]);
        $companies = $this->orders->companies($actor, 'erp.customer-receipts.view')->orderBy('name')->get(['id', 'name']);
        if (! empty($filters['company_id'])) {
            $this->access->authorize($actor, (int) $filters['company_id'], 'erp.customer-receipts.view');
        }
        if (! empty($filters['customer_id'])) {
            Customer::query()->withoutGlobalScope(SoftDeletingScope::class)->whereIn('company_id', $companies->modelKeys())->findOrFail((int) $filters['customer_id']);
        }
        $rows = CustomerReceipt::query()->whereIn('company_id', $companies->modelKeys())
            ->when(! empty($filters['company_id']), fn ($q) => $q->where('company_id', $filters['company_id']))
            ->when(! empty($filters['customer_id']), fn ($q) => $q->where('customer_id', $filters['customer_id']))
            ->when(! empty($filters['search']), fn ($q) => $q->where('number', 'like', '%'.$filters['search'].'%'))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))->latest('id')->paginate(20)->withQueryString()
            ->through(fn (CustomerReceipt $receipt): array => [...$this->header($receipt), 'state' => $this->receipts->snapshot($actor, (int) $receipt->getKey())]);

        return Inertia::render('ERP/Sales/Receipts/Index', ['receipts' => $rows, 'companies' => $companies, 'filters' => $filters]);
    }

    public function create(Request $request): Response
    {
        return $this->form($request);
    }

    public function edit(Request $request, int $receipt): Response
    {
        return $this->form($request, $receipt);
    }

    public function store(Request $request): RedirectResponse
    {
        $receipt = $this->receipts->save($this->actor($request), $request->all());

        return redirect()->route('erp.sales.receipts.show', $receipt->getKey());
    }

    public function update(Request $request, int $receipt): RedirectResponse
    {
        $this->receipts->save($this->actor($request), $request->all(), $receipt);

        return redirect()->route('erp.sales.receipts.show', $receipt);
    }

    public function post(Request $request, int $receipt): RedirectResponse
    {
        $this->receipts->postReceipt($this->actor($request), $receipt, $request->all());

        return redirect()->route('erp.sales.receipts.show', $receipt)->with('success', 'Receipt posted.');
    }

    public function allocate(Request $request, int $receipt): RedirectResponse
    {
        $this->receipts->allocate($this->actor($request), $receipt, $request->all());

        return redirect()->route('erp.sales.receipts.show', $receipt)->with('success', 'Allocations updated.');
    }

    public function reverseAllocation(Request $request, int $receipt, int $allocation): RedirectResponse
    {
        $this->receipts->reverseAllocation($this->actor($request), $receipt, $allocation, $request->all());

        return redirect()->route('erp.sales.receipts.show', $receipt)->with('success', 'Allocation reversed.');
    }

    public function reverse(Request $request, int $receipt): RedirectResponse
    {
        $reversal = $this->receipts->reverseReceipt($this->actor($request), $receipt, $request->all());

        return redirect()->route('erp.sales.receipts.show', $reversal->getKey())->with('success', 'Receipt reversal recorded.');
    }

    public function cancel(Request $request, int $receipt): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000']]);
        $this->receipts->cancel($this->actor($request), $receipt, (int) $data['revision'], (string) $data['reason']);

        return redirect()->route('erp.sales.receipts.show', $receipt)->with('success', 'Draft cancelled.');
    }

    public function show(Request $request, int $receipt): Response
    {
        return $this->display($request, $receipt, false);
    }

    public function print(Request $request, int $receipt): Response
    {
        return $this->display($request, $receipt, true);
    }

    private function form(Request $request, ?int $id = null): Response
    {
        $actor = $this->actor($request);
        $permission = 'erp.customer-receipts.'.($id === null ? 'create' : 'edit');
        $receipt = $id === null ? null : $this->receipts->find($actor, $id, $permission);
        abort_unless($actor->can('erp.sales-customers.select'), 403);
        if ($receipt !== null) {
            abort_unless($receipt->getAttribute('status') === 'draft', 403);
        }
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'customer_id' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:120']]);
        $companies = $this->orders->companies($actor, $permission)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'currency_code', 'timezone']);
        $companyId = $receipt?->getAttribute('company_id') ?? ($data['company_id'] ?? $companies->first()?->getKey());
        $customerId = $receipt?->getAttribute('customer_id') ?? ($data['customer_id'] ?? null);
        $customers = [];
        $today = now()->toDateString();
        if ($companyId !== null) {
            $company = $this->access->authorize($actor, (int) $companyId, $permission);
            $today = now((string) $company->getAttribute('timezone'))->toDateString();
            $query = Customer::query()->withoutGlobalScope(SoftDeletingScope::class)->where('company_id', $companyId)->where(function ($q) use ($company): void {
                $q->where(fn ($active) => $active->where('is_active', true)->whereNull('deleted_at'))->orWhereIn('id', SalesInvoice::query()->where('company_id', $company->getKey())->where('currency_code', $company->getAttribute('currency_code'))->where('status', 'issued')->whereNotNull('issued_at')->select('customer_id'));
            });
            $rows = (clone $query)->when(! empty($data['search']), fn ($q) => $q->where('name', 'like', '%'.$data['search'].'%'))->orderBy('name')->limit(100)->get(['id', 'name']);
            if ($customerId !== null) {
                $selected = (clone $query)->findOrFail((int) $customerId, ['id', 'name']);
                $rows = $rows->merge([$selected])->unique('id')->values();
            }
            $customers = $rows;
        }

        return Inertia::render('ERP/Sales/Receipts/Form', ['receipt' => $receipt === null ? null : $this->header($receipt), 'companies' => $companies, 'customers' => $customers, 'defaultCompanyId' => $companyId === null ? null : (int) $companyId, 'defaultCustomerId' => $customerId === null ? null : (int) $customerId, 'today' => $today, 'methods' => CustomerReceiptService::METHODS, 'search' => $data['search'] ?? '']);
    }

    /** @return array<string, mixed> */
    private function header(CustomerReceipt $receipt): array
    {
        return [...$receipt->only(['id', 'company_id', 'customer_id', 'number', 'status', 'revision', 'currency_code', 'amount', 'payment_method', 'payment_reference', 'notes', 'customer_snapshot', 'reverses_receipt_id', 'reversal_reason']), 'document_date' => $receipt->getAttribute('document_date')->format('Y-m-d'), 'posted_at' => $receipt->getAttribute('posted_at')?->toIso8601String(), 'issuer' => ($receipt->getAttribute('terms_snapshot') ?? [])['issuer'] ?? null, 'reversal_source' => ($receipt->getAttribute('terms_snapshot') ?? [])['reversal_source'] ?? null];
    }

    private function display(Request $request, int $id, bool $print): Response
    {
        $actor = $this->actor($request);
        $receipt = $this->receipts->find($actor, $id);
        $state = $this->receipts->snapshot($actor, $id);
        $rows = CustomerReceiptAllocation::query()->where('company_id', $receipt->getAttribute('company_id'))->where('customer_receipt_id', $id)->orderBy('id')->get();
        $targets = SalesInvoice::query()->where('company_id', $receipt->getAttribute('company_id'))->whereIn('id', $rows->pluck('sales_invoice_id'))->get(['id', 'number'])->keyBy('id');
        $allocations = $rows->map(fn (CustomerReceiptAllocation $row): array => ['id' => $row->getKey(), 'sales_invoice_id' => $row->getAttribute('sales_invoice_id'), 'invoice_number' => $targets->get((int) $row->getAttribute('sales_invoice_id'))?->getAttribute('number'), 'amount' => $row->getAttribute('amount'), 'reverses_allocation_id' => $row->getAttribute('reverses_allocation_id'), 'active' => in_array((int) $row->getKey(), $state['active_ids'], true), 'reason' => $row->getAttribute('reason'), 'posted_at' => $row->getAttribute('posted_at')?->toIso8601String(), 'url' => $actor->can('erp.sales-invoices.view') ? route('erp.sales.invoices.show', $row->getAttribute('sales_invoice_id')) : null])->all();
        $invoices = [];
        if (! $print && $actor->can('erp.customer-receipts.allocate') && ! $state['reversed'] && ! $state['is_reversal'] && $receipt->getAttribute('status') !== 'cancelled') {
            foreach (SalesInvoice::query()->where('company_id', $receipt->getAttribute('company_id'))->where('customer_id', $receipt->getAttribute('customer_id'))->where('currency_code', $receipt->getAttribute('currency_code'))->where('status', 'issued')->whereNotNull('issued_at')->orderBy('due_date')->orderBy('id')->get() as $invoice) {
                $balance = $this->balances->snapshot($invoice);
                $invoices[] = ['id' => $invoice->getKey(), 'number' => $invoice->getAttribute('number'), 'due_date' => $invoice->getAttribute('due_date')->format('Y-m-d'), 'outstanding' => $balance['outstanding']];
            }
        }
        $events = $print ? [] : SalesDocumentEvent::query()->where('company_id', $receipt->getAttribute('company_id'))->where('document_type', 'receipt')->where('document_id', $id)->orderBy('id')->get(['id', 'action', 'actor_snapshot', 'reason', 'occurred_at']);

        return Inertia::render('ERP/Sales/Receipts/Show', ['receipt' => $this->header($receipt), 'state' => $state, 'allocations' => $print ? [] : $allocations, 'invoices' => $invoices, 'events' => $events, 'printMode' => $print,
            'statementUrl' => $actor->can('erp.customer-receivables.view') ? route('erp.sales.receipts.statement', ['company_id' => $receipt->getAttribute('company_id'), 'customer_id' => $receipt->getAttribute('customer_id')]) : null]);
    }

    public function statement(Request $request): Response
    {
        $actor = $this->actor($request);
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'customer_id' => ['nullable', 'integer', 'min:1'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'print' => ['nullable', 'boolean']]);
        $companies = $this->orders->companies($actor, 'erp.customer-receivables.view')->orderBy('name')->get(['id', 'name', 'timezone']);
        $companyId = $data['company_id'] ?? $companies->first()?->getKey();
        $customerId = $data['customer_id'] ?? null;
        $customers = [];
        $today = now()->toDateString();
        if ($companyId !== null) {
            $company = $this->access->authorize($actor, (int) $companyId, 'erp.customer-receivables.view');
            $today = now((string) $company->getAttribute('timezone'))->toDateString();
            $customers = Customer::query()->withoutGlobalScope(SoftDeletingScope::class)->where('company_id', $companyId)->where(function ($q) use ($companyId): void {
                $q->whereIn('id', SalesInvoice::query()->where('company_id', $companyId)->where('status', 'issued')->whereNotNull('issued_at')->select('customer_id'))
                    ->orWhereIn('id', CustomerReceipt::query()->where('company_id', $companyId)->where('status', 'posted')->whereNotNull('posted_at')->select('customer_id'));
            })->orderBy('name')->get(['id', 'name']);
            if ($customerId !== null) {
                abort_unless($customers->contains('id', (int) $customerId), 404);
            }
        }
        $filters = ['company_id' => $companyId === null ? null : (int) $companyId, 'customer_id' => $customerId === null ? null : (int) $customerId, 'from' => $data['from'] ?? substr($today, 0, 8).'01', 'to' => $data['to'] ?? $today];
        $statement = $companyId !== null && $customerId !== null ? $this->statements->read($actor, $filters) : null;

        return Inertia::render('ERP/Sales/Receipts/Statement', ['companies' => $companies, 'customers' => $customers, 'filters' => $filters, 'statement' => $statement, 'printMode' => (bool) ($data['print'] ?? false)]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
