<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Sales;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\SalesCreditNote;
use App\Models\SalesCreditNoteItem;
use App\Models\SalesDocumentEvent;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\User;
use App\Services\ERP\Sales\SalesCompanyAccess;
use App\Services\ERP\Sales\SalesCreditNoteLedger;
use App\Services\ERP\Sales\SalesCreditNoteService;
use App\Services\ERP\Sales\SalesInvoiceBalanceService;
use App\Services\ERP\Sales\SalesOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class SalesCreditNoteController extends Controller
{
    public function __construct(private readonly SalesCreditNoteService $notes, private readonly SalesOrderService $orders, private readonly SalesCompanyAccess $access, private readonly SalesCreditNoteLedger $ledger, private readonly SalesInvoiceBalanceService $balances) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $filters = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', 'in:draft,issued,cancelled']]);
        $companies = $this->orders->companies($actor, 'erp.sales-credit-notes.view')->orderBy('name')->get(['id', 'name']);
        if (! empty($filters['company_id'])) {
            $this->access->authorize($actor, (int) $filters['company_id'], 'erp.sales-credit-notes.view');
        }
        $rows = SalesCreditNote::query()->whereIn('company_id', $companies->modelKeys())->when(! empty($filters['company_id']), fn ($q) => $q->where('company_id', $filters['company_id']))->when(! empty($filters['search']), fn ($q) => $q->where('number', 'like', '%'.$filters['search'].'%'))->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))->latest('id')->paginate(20)->withQueryString()->through(fn (SalesCreditNote $note): array => $this->header($note));

        return Inertia::render('ERP/Sales/CreditNotes/Index', ['notes' => $rows, 'companies' => $companies, 'filters' => $filters]);
    }

    public function create(Request $request): Response
    {
        return $this->form($request);
    }

    public function edit(Request $request, int $note): Response
    {
        return $this->form($request, $note);
    }

    public function store(Request $request): RedirectResponse
    {
        $note = $this->notes->save($this->actor($request), $request->all());

        return to_route('erp.sales.credit-notes.show', $note->getKey());
    }

    public function update(Request $request, int $note): RedirectResponse
    {
        $this->notes->save($this->actor($request), $request->all(), $note);

        return to_route('erp.sales.credit-notes.show', $note);
    }

    public function issue(Request $request, int $note): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'string', 'max:100']]);
        $this->notes->issue($this->actor($request), $note, (int) $data['revision'], (string) $data['idempotency_key']);

        return to_route('erp.sales.credit-notes.show', $note);
    }

    public function cancel(Request $request, int $note): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u']]);
        $this->notes->cancel($this->actor($request), $note, (int) $data['revision'], (string) $data['reason']);

        return to_route('erp.sales.credit-notes.show', $note);
    }

    public function show(Request $request, int $note): Response
    {
        return $this->display($request, $note, false);
    }

    public function print(Request $request, int $note): Response
    {
        return $this->display($request, $note, true);
    }

    private function form(Request $request, ?int $id = null): Response
    {
        $actor = $this->actor($request);
        $permission = 'erp.sales-credit-notes.'.($id === null ? 'create' : 'edit');
        abort_unless($actor->can('erp.sales-invoices.view'), 403);
        $note = $id === null ? null : $this->notes->find($actor, $id, $permission);
        if ($note !== null) {
            abort_unless($note->getAttribute('status') === 'draft', 403);
        }
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'sales_invoice_id' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:120']]);
        $companies = $this->orders->companies($actor, $permission)->where('status', 'active')->orderBy('name')->get(['id', 'name']);
        $companyId = $note?->getAttribute('company_id') ?? ($data['company_id'] ?? $companies->first()?->getKey());
        $invoiceId = $note?->getAttribute('sales_invoice_id') ?? ($data['sales_invoice_id'] ?? null);
        $options = [];
        $sourceItems = [];
        $today = now()->toDateString();
        if ($companyId !== null) {
            $company = $this->access->authorize($actor, (int) $companyId, $permission);
            $today = now((string) $company->getAttribute('timezone'))->toDateString();
            $query = SalesInvoice::query()->where('company_id', $companyId)->where('status', 'issued')->whereNotNull('issued_at');
            $rows = (clone $query)->when(! empty($data['search']), fn ($q) => $q->where('number', 'like', '%'.$data['search'].'%'))->latest('id')->limit(100)->get();
            if ($invoiceId !== null) {
                $invoice = (clone $query)->findOrFail((int) $invoiceId);
                $rows = $rows->merge([$invoice])->unique('id')->values();
                $sourceItems = DB::transaction(function () use ($actor, $company, $invoice): array {
                    Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
                    $this->balances->snapshot($invoice);
                    $p = $this->ledger->progress($invoice, true);
                    $result = [];
                    foreach (SalesInvoiceItem::query()->where('sales_invoice_id', $invoice->getKey())->orderBy('id')->get() as $line) {
                        $returns = [];
                        if ($actor->can('erp.sales-returns.view')) {
                            foreach (SalesReturnItem::query()->where('sales_delivery_item_id', $line->getAttribute('sales_delivery_item_id'))->whereIn('sales_return_id', SalesReturn::query()->where('company_id', $company->getKey())->where('customer_id', $invoice->getAttribute('customer_id'))->where('currency_code', $invoice->getAttribute('currency_code'))->where('status', 'posted')->whereNotNull('posted_at')->select('id'))->get() as $ri) {
                                $rh = SalesReturn::query()->findOrFail($ri->getAttribute('sales_return_id'));
                                $remaining = bcsub((string) $ri->getAttribute('credit_review_quantity'), $this->ledger->returnUsed((int) $ri->getKey()), 4);
                                if (bccomp($remaining, '0', 4) > 0) {
                                    $returns[] = ['id' => $ri->getKey(), 'return_id' => $rh->getKey(), 'number' => $rh->getAttribute('number'), 'available' => $remaining];
                                }
                            }
                        }
                        $result[] = ['id' => $line->getKey(), 'product_snapshot' => $line->getAttribute('product_snapshot'), 'remaining_quantity' => bcsub((string) $line->getAttribute('quantity'), $p['items'][(int) $line->getKey()]['quantity'], 4), 'remaining_amount' => bcsub((string) $line->getAttribute('line_total'), $p['items'][(int) $line->getKey()]['amount'], 2), 'returns' => $returns];
                    }

                    return $result;
                }, 3);
            }
            $options = $rows->map(fn (SalesInvoice $row): array => ['id' => $row->getKey(), 'number' => $row->getAttribute('number'), 'customer_name' => ($row->getAttribute('customer_snapshot') ?? [])['name'] ?? ''])->all();
        }

        return Inertia::render('ERP/Sales/CreditNotes/Form', ['note' => $note === null ? null : $this->header($note), 'savedItems' => $note === null ? [] : $this->items($note), 'companies' => $companies, 'invoices' => $options, 'sourceItems' => $sourceItems, 'defaultCompanyId' => $companyId === null ? null : (int) $companyId, 'defaultInvoiceId' => $invoiceId === null ? null : (int) $invoiceId, 'today' => $today, 'search' => $data['search'] ?? '']);
    }

    /** @return array<string, mixed> */
    private function header(SalesCreditNote $note): array
    {
        $terms = $note->getAttribute('terms_snapshot') ?? [];

        return [...$note->only(['id', 'company_id', 'customer_id', 'sales_invoice_id', 'sales_return_id', 'number', 'status', 'revision', 'currency_code', 'customer_snapshot', 'reason', 'notes', 'subtotal', 'discount_total', 'tax_total', 'grand_total']), 'document_date' => $note->getAttribute('document_date')->format('Y-m-d'), 'issued_at' => $note->getAttribute('issued_at')?->toIso8601String(), 'issuer' => $terms['issuer'] ?? null, 'source' => $terms['credit_source'] ?? null];
    }

    /** @return list<array<string, mixed>> */
    private function items(SalesCreditNote $note): array
    {
        return SalesCreditNoteItem::query()->where('sales_credit_note_id', $note->getKey())->orderBy('line_number')->get()->map(fn (SalesCreditNoteItem $item): array => $item->only(['id', 'sales_invoice_item_id', 'sales_return_item_id', 'product_snapshot', 'description', 'quantity', 'credit_type', 'unit_price', 'discount_percent', 'tax_percent', 'subtotal', 'discount_amount', 'tax_amount', 'line_total']))->all();
    }

    private function display(Request $request, int $id, bool $print): Response
    {
        $actor = $this->actor($request);
        $note = $this->notes->find($actor, $id);
        $invoice = SalesInvoice::query()->where('company_id', $note->getAttribute('company_id'))->findOrFail($note->getAttribute('sales_invoice_id'));
        DB::transaction(function () use ($note, $invoice): void {
            Company::query()->whereKey($note->getAttribute('company_id'))->lockForUpdate()->firstOrFail();
            $this->ledger->progress($invoice);
        }, 3);
        $events = $print ? [] : SalesDocumentEvent::query()->where('company_id', $note->getAttribute('company_id'))->where('document_type', 'credit_note')->where('document_id', $id)->orderBy('id')->get(['id', 'action', 'actor_snapshot', 'reason', 'occurred_at']);

        return Inertia::render('ERP/Sales/CreditNotes/Show', ['note' => $this->header($note), 'items' => $this->items($note), 'events' => $events, 'printMode' => $print, 'canIssue' => $actor->can('erp.sales-credit-notes.issue') && $note->getAttribute('created_by') !== null && (int) $note->getAttribute('created_by') !== (int) $actor->getKey() && (int) $note->getAttribute('updated_by') !== (int) $actor->getKey(), 'invoiceUrl' => $actor->can('erp.sales-invoices.view') ? route('erp.sales.invoices.show', $invoice->getKey()) : null, 'creditUrl' => $actor->can('erp.customer-receivables.view') ? route('erp.sales.customer-credits.index', ['company_id' => $note->getAttribute('company_id'), 'customer_id' => $note->getAttribute('customer_id')]) : null]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
