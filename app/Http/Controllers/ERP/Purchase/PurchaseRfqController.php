<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Purchase;

use App\Http\Controllers\Controller;
use App\Models\PurchaseRequisition;
use App\Models\Supplier;
use App\Models\SupplierQuotation;
use App\Models\SupplierQuotationItem;
use App\Models\User;
use App\Services\ERP\Purchase\PurchaseQuoteMath;
use App\Services\ERP\Purchase\PurchaseRfqService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class PurchaseRfqController extends Controller
{
    public function __construct(private readonly PurchaseRfqService $service, private readonly PurchaseQuoteMath $math) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'company_id' => ['nullable', 'integer', 'min:1'], 'status' => ['nullable', 'in:draft,issued,awarded']]);
        $query = $this->service->visible($actor)->with('company:id,name');
        if (! empty($filters['company_id'])) {
            $this->service->companies($actor, 'erp.purchase-rfqs.view')->findOrFail($filters['company_id']);
            $query->where('company_id', $filters['company_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $query->where('number', 'like', '%'.$filters['search'].'%');
        }

        return Inertia::render('ERP/Purchase/Rfqs/Index', ['rfqs' => $query->latest('id')->paginate(20)->withQueryString(),
            'companies' => $this->service->companies($actor, 'erp.purchase-rfqs.view')->orderBy('name')->get(['id', 'name']), 'filters' => $filters]);
    }

    public function create(Request $request): Response
    {
        $actor = $this->actor($request);
        $companies = $this->service->companies($actor, 'erp.purchase-rfqs.create')->orderBy('name')->get(['id', 'name']);
        $input = $request->validate(['company_id' => ['nullable', 'integer', 'min:1']]);
        $only = $companies->count() === 1 ? $companies->first() : null;
        $companyId = empty($input['company_id']) ? ($only === null ? null : (int) $only->getKey()) : (int) $input['company_id'];
        if ($companyId !== null) {
            $this->service->companies($actor, 'erp.purchase-rfqs.create')->findOrFail($companyId);
        }

        return Inertia::render('ERP/Purchase/Rfqs/Form', [
            'rfq' => null, 'companies' => $companies, 'companyId' => $companyId, 'selectedSuppliers' => [],
            'requisitions' => PurchaseRequisition::query()->where('company_id', $companyId ?? 0)->where('status', 'approved')->latest('id')->get(['id', 'number', 'currency_code']),
            'suppliers' => Supplier::query()->where('company_id', $companyId ?? 0)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code', 'currency_code']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([...$this->rfqRules(), 'company_id' => ['required', 'integer', 'min:1'], 'purchase_requisition_id' => ['required', 'integer', 'min:1']]);
        $rfq = $this->service->create($this->actor($request), $data);

        return redirect()->route('erp.purchase.rfqs.show', $rfq->getKey())->with('success', 'RFQ saved as draft.');
    }

    public function edit(Request $request, int $rfq): Response
    {
        $document = $this->service->find($this->actor($request), $rfq, 'erp.purchase-rfqs.edit');
        abort_unless($document->status === 'draft', 403, 'Only draft RFQs can be edited.');

        return Inertia::render('ERP/Purchase/Rfqs/Form', ['rfq' => $document->load('company:id,name'), 'companies' => [], 'companyId' => (int) $document->company_id,
            'selectedSuppliers' => DB::table('rfq_suppliers')->where('rfq_id', $rfq)->pluck('supplier_id'),
            'requisitions' => PurchaseRequisition::query()->where('company_id', $document->company_id)->whereKey($document->purchase_requisition_id)->get(['id', 'number', 'currency_code']),
            'suppliers' => Supplier::query()->where('company_id', $document->company_id)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code', 'currency_code'])]);
    }

    public function update(Request $request, int $rfq): RedirectResponse
    {
        $data = $request->validate([...$this->rfqRules(), 'revision' => ['required', 'integer', 'min:1'], 'company_id' => ['prohibited'], 'purchase_requisition_id' => ['prohibited']]);
        $this->service->update($this->actor($request), $rfq, $data);

        return redirect()->route('erp.purchase.rfqs.show', $rfq)->with('success', 'RFQ draft updated.');
    }

    public function show(Request $request, int $rfq): Response
    {
        $actor = $this->actor($request);
        $document = $this->service->find($actor, $rfq, 'erp.purchase-rfqs.view');
        $quotes = $actor->can('erp.supplier-quotations.view') || $actor->can('erp.supplier-quotations.compare')
            ? SupplierQuotation::query()->where('rfq_id', $rfq)->where('company_id', $document->company_id)->with('supplier:id,name')->orderBy('id')->get()
            : [];
        $history = DB::table('purchase_rfq_audit_logs')->leftJoin('users', 'users.id', '=', 'purchase_rfq_audit_logs.actor_id')
            ->where('rfq_id', $rfq)->where('purchase_rfq_audit_logs.company_id', $document->company_id)->latest('purchase_rfq_audit_logs.id')->limit(50)
            ->get(['purchase_rfq_audit_logs.id', 'action', 'comment', 'purchase_rfq_audit_logs.created_at', 'users.name as actor_name']);

        return Inertia::render('ERP/Purchase/Rfqs/Show', ['rfq' => $document->load('company:id,name'), 'items' => DB::table('rfq_items')->where('rfq_id', $rfq)->orderBy('id')->get(),
            'suppliers' => Supplier::query()->where('company_id', $document->company_id)->whereIn('id', DB::table('rfq_suppliers')->where('rfq_id', $rfq)->select('supplier_id'))->get(['id', 'name']),
            'quotations' => $quotes, 'history' => $history,
            'purchaseOrderId' => $document->getAttribute('awarded_quotation_id') === null ? null : DB::table('purchase_orders')->where('company_id', $document->company_id)->where('supplier_quotation_id', $document->getAttribute('awarded_quotation_id'))->value('id')]);
    }

    public function issue(Request $request, int $rfq): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $this->service->issue($this->actor($request), $rfq, (int) $data['revision']);

        return redirect()->route('erp.purchase.rfqs.show', $rfq)->with('success', 'RFQ issued internally. No emails were sent.');
    }

    public function createQuotation(Request $request, int $rfq): Response
    {
        return $this->quotationForm($request, $rfq);
    }

    public function editQuotation(Request $request, int $rfq, int $quotation): Response
    {
        return $this->quotationForm($request, $rfq, $quotation);
    }

    public function storeQuotation(Request $request, int $rfq): RedirectResponse
    {
        $this->service->quotation($this->actor($request), $rfq, $request->validate($this->quotationRules()));

        return redirect()->route('erp.purchase.rfqs.show', $rfq)->with('success', 'Supplier quotation recorded.');
    }

    public function updateQuotation(Request $request, int $rfq, int $quotation): RedirectResponse
    {
        $this->service->quotation($this->actor($request), $rfq, $request->validate([...$this->quotationRules(), 'revision' => ['required', 'integer', 'min:1']]), $quotation);

        return redirect()->route('erp.purchase.rfqs.show', $rfq)->with('success', 'Supplier quotation updated.');
    }

    public function compare(Request $request, int $rfq): Response
    {
        $document = $this->service->find($this->actor($request), $rfq, 'erp.supplier-quotations.compare');
        $quotes = SupplierQuotation::query()->where('rfq_id', $rfq)->where('company_id', $document->company_id)->with('supplier:id,name,status,currency_code')->get()
            ->sortBy(fn (SupplierQuotation $quote): int => $this->math->scaled((string) $quote->total_amount, 2, 1000000000000))->values();
        $rfqItems = DB::table('rfq_items')->where('rfq_id', $rfq)->orderBy('id')->get();
        $offers = $quotes->map(function (SupplierQuotation $quote) use ($document, $rfqItems): array {
            $expired = $quote->valid_until !== null && Carbon::parse((string) $quote->getRawOriginal('valid_until'))->endOfDay()->isPast();
            $supplier = $quote->supplier;
            $quoteItems = SupplierQuotationItem::query()->where('supplier_quotation_id', $quote->getKey())->orderBy('rfq_item_id')->get();
            $lineIds = $quoteItems->pluck('rfq_item_id')->map(fn ($id): int => (int) $id)->all();
            $complete = $quoteItems->count() === $rfqItems->count() && array_diff($lineIds, $rfqItems->pluck('id')->all()) === [];

            return [...$quote->toArray(), 'expired' => $expired, 'eligible' => $complete && ! $expired && $supplier instanceof Supplier && $supplier->status === 'active'
                && $supplier->currency_code === $document->getAttribute('currency_code') && $quote->status === 'submitted',
                'items' => $quoteItems];
        });

        return Inertia::render('ERP/Purchase/Rfqs/Compare', ['rfq' => $document, 'items' => $rfqItems, 'quotations' => $offers]);
    }

    public function award(Request $request, int $rfq): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'quotation_id' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000']]);
        $this->service->award($this->actor($request), $rfq, (int) $data['quotation_id'], (int) $data['revision'], $data['reason']);

        return redirect()->route('erp.purchase.rfqs.show', $rfq)->with('success', 'Quotation awarded. Purchase-order creation follows in milestone 4.5.');
    }

    private function quotationForm(Request $request, int $rfq, ?int $id = null): Response
    {
        $document = $this->service->find($this->actor($request), $rfq, $id === null ? 'erp.supplier-quotations.create' : 'erp.supplier-quotations.edit');
        abort_unless($document->status === 'issued', 403, 'Quotations can only be entered for issued RFQs.');
        $quote = $id === null ? null : SupplierQuotation::query()->where('rfq_id', $rfq)->where('company_id', $document->company_id)->findOrFail($id);
        $supplierQuery = Supplier::query()->where('company_id', $document->company_id)->whereIn('id', DB::table('rfq_suppliers')->where('rfq_id', $rfq)->select('supplier_id'));
        if ($id === null) {
            $supplierQuery->whereNotIn('id', SupplierQuotation::query()->where('rfq_id', $rfq)->select('supplier_id'));
        } else {
            $supplierQuery->whereKey($quote?->supplier_id);
        }

        return Inertia::render('ERP/Purchase/Rfqs/QuotationForm', ['rfq' => $document, 'quotation' => $quote,
            'items' => DB::table('rfq_items')->where('rfq_id', $rfq)->orderBy('id')->get(),
            'quotationItems' => $id === null ? [] : SupplierQuotationItem::query()->where('supplier_quotation_id', $id)->get(),
            'suppliers' => $supplierQuery->where('status', 'active')->where('currency_code', $document->getAttribute('currency_code'))->orderBy('name')->get(['id', 'name'])]);
    }

    /** @return array<string, mixed> */
    private function rfqRules(): array
    {
        return ['supplier_ids' => ['required', 'array', 'min:1', 'max:50'], 'supplier_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'deadline_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'], 'notes' => ['nullable', 'string', 'max:10000'],
            'status' => ['prohibited'], 'number' => ['prohibited'], 'currency_code' => ['prohibited'], 'created_by' => ['prohibited'], 'awarded_quotation_id' => ['prohibited']];
    }

    /** @return array<string, mixed> */
    private function quotationRules(): array
    {
        return ['rfq_revision' => ['required', 'integer', 'min:1'], 'supplier_id' => ['required', 'integer', 'min:1'],
            'quotation_number' => ['required', 'string', 'max:100'], 'quoted_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:quoted_at'], 'notes' => ['nullable', 'string', 'max:10000'],
            'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*' => ['array:rfq_item_id,unit_price,discount_amount,tax_amount'],
            'items.*.rfq_item_id' => ['required', 'integer', 'distinct', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:1000000', 'regex:/^[0-9]+(?:\.[0-9]{1,2})?$/D'],
            'items.*.discount_amount' => ['required', 'numeric', 'min:0', 'max:1000000000000', 'regex:/^[0-9]+(?:\.[0-9]{1,2})?$/D'],
            'items.*.tax_amount' => ['required', 'numeric', 'min:0', 'max:1000000000000', 'regex:/^[0-9]+(?:\.[0-9]{1,2})?$/D'],
            'company_id' => ['prohibited'], 'rfq_id' => ['prohibited'], 'status' => ['prohibited'], 'total_amount' => ['prohibited'], 'currency_code' => ['prohibited']];
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(403);
        }

        return $actor;
    }
}
