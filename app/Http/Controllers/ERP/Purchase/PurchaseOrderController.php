<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Purchase;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrderItem;
use App\Models\SupplierQuotation;
use App\Models\User;
use App\Services\ERP\Purchase\GoodsReceiptService;
use App\Services\ERP\Purchase\PurchaseOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class PurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchaseOrderService $service, private readonly GoodsReceiptService $receiving) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'company_id' => ['nullable', 'integer', 'min:1'], 'status' => ['nullable', 'in:draft,submitted,approved,rejected,ordered,partially_received,received,closed']]);
        $query = $this->service->visible($actor)->with(['company:id,name', 'supplier:id,name']);
        if (! empty($filters['company_id'])) {
            $this->service->companies($actor, 'erp.purchase-orders.view')->findOrFail($filters['company_id']);
            $query->where('company_id', $filters['company_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $query->where('number', 'like', '%'.$filters['search'].'%');
        }

        return Inertia::render('ERP/Purchase/Orders/Index', ['orders' => $query->latest('id')->paginate(20)->withQueryString(),
            'companies' => $this->service->companies($actor, 'erp.purchase-orders.view')->orderBy('name')->get(['id', 'name']), 'filters' => $filters]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $actor = $this->actor($request);
        $input = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'quotation_id' => ['nullable', 'integer', 'min:1']]);
        $companies = $this->service->companies($actor, 'erp.purchase-orders.create')->orderBy('name')->get(['id', 'name']);
        $only = $companies->count() === 1 ? $companies->first() : null;
        $companyId = empty($input['company_id']) ? ($only === null ? null : (int) $only->getKey()) : (int) $input['company_id'];
        if (! empty($input['quotation_id']) && $companyId === null) {
            $quote = SupplierQuotation::query()->whereIn('company_id', $this->service->companies($actor, 'erp.purchase-orders.create')->select('id'))->findOrFail($input['quotation_id']);
            $companyId = (int) $quote->company_id;
        }
        if ($companyId !== null) {
            $this->service->companies($actor, 'erp.purchase-orders.create')->findOrFail($companyId);
        }
        if (! empty($input['quotation_id'])) {
            $existing = $this->service->visible($actor, 'erp.purchase-orders.create')->where('company_id', $companyId)->where('supplier_quotation_id', $input['quotation_id'])->first();
            if ($existing !== null) {
                return redirect()->route('erp.purchase.orders.show', $existing->getKey());
            }
        }
        $quotes = SupplierQuotation::query()->where('company_id', $companyId ?? 0)->where('status', 'accepted')
            ->whereHas('rfq', fn ($query) => $query->where('status', 'awarded')->whereColumn('rfqs.company_id', 'supplier_quotations.company_id')->whereColumn('rfqs.awarded_quotation_id', 'supplier_quotations.id'))
            ->whereHas('supplier', fn ($query) => $query->where('status', 'active')->whereColumn('suppliers.company_id', 'supplier_quotations.company_id')->whereColumn('suppliers.currency_code', 'supplier_quotations.currency_code'))
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', now()->toDateString()))
            ->whereNotIn('id', DB::table('purchase_orders')->whereNotNull('supplier_quotation_id')->select('supplier_quotation_id'))
            ->with(['supplier:id,name', 'rfq:id,number'])->latest('id')->get();
        $selected = empty($input['quotation_id']) ? null : $quotes->firstWhere('id', (int) $input['quotation_id']);
        if (! empty($input['quotation_id'])) {
            abort_unless($selected !== null, 404, 'No eligible awarded quotation is available.');
        }

        return Inertia::render('ERP/Purchase/Orders/Form', ['order' => null, 'companies' => $companies, 'companyId' => $companyId, 'quotations' => $quotes,
            'selectedQuotationId' => $selected?->getKey(), 'items' => []]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([...$this->draftRules(), 'company_id' => ['required', 'integer', 'min:1'], 'supplier_quotation_id' => ['required', 'integer', 'min:1']]);
        $order = $this->service->create($this->actor($request), $data);

        return redirect()->route('erp.purchase.orders.show', $order->getKey())->with('success', 'Purchase order draft is ready.');
    }

    public function edit(Request $request, int $order): Response
    {
        $record = $this->service->find($this->actor($request), $order, 'erp.purchase-orders.edit');
        abort_unless($record->status === 'draft', 403, 'Only draft orders can be edited.');

        return Inertia::render('ERP/Purchase/Orders/Form', ['order' => $record->load(['company:id,name', 'supplier:id,name']), 'companies' => [],
            'companyId' => (int) $record->company_id, 'quotations' => [], 'selectedQuotationId' => $record->supplier_quotation_id,
            'items' => PurchaseOrderItem::query()->where('purchase_order_id', $order)->orderBy('id')->get()]);
    }

    public function update(Request $request, int $order): RedirectResponse
    {
        $data = $request->validate([...$this->draftRules(), 'revision' => ['required', 'integer', 'min:1'], 'company_id' => ['prohibited'], 'supplier_quotation_id' => ['prohibited']]);
        $this->service->update($this->actor($request), $order, $data);

        return redirect()->route('erp.purchase.orders.show', $order)->with('success', 'Order delivery date and notes updated.');
    }

    public function show(Request $request, int $order): Response
    {
        $record = $this->service->find($this->actor($request), $order, 'erp.purchase-orders.view');
        $history = DB::table('purchase_workflow_events')->leftJoin('users', 'users.id', '=', 'purchase_workflow_events.actor_id')
            ->where('purchase_workflow_events.company_id', $record->company_id)->where('document_type', 'order')->where('document_id', $order)
            ->latest('purchase_workflow_events.id')->limit(50)->get(['purchase_workflow_events.id', 'from_status', 'to_status', 'comment', 'purchase_workflow_events.created_at', 'users.name as actor_name']);
        $changes = DB::table('purchase_order_audit_logs')->leftJoin('users', 'users.id', '=', 'purchase_order_audit_logs.actor_id')
            ->where('purchase_order_audit_logs.company_id', $record->company_id)->where('purchase_order_id', $order)->latest('purchase_order_audit_logs.id')->limit(50)
            ->get(['purchase_order_audit_logs.id', 'action', 'comment', 'purchase_order_audit_logs.created_at', 'users.name as actor_name']);

        return Inertia::render('ERP/Purchase/Orders/Show', ['order' => $record->load(['company:id,name', 'supplier:id,name']),
            'items' => PurchaseOrderItem::query()->where('purchase_order_id', $order)->orderBy('id')->get(),
            'requester' => User::query()->find($record->created_by, ['id', 'name']), 'approver' => $record->approved_by === null ? null : User::query()->find($record->approved_by, ['id', 'name']),
            'history' => $history, 'changes' => $changes,
            'receipts' => $this->actor($request)->can('erp.goods-receipts.view') ? DB::table('goods_receipts')->where('company_id', $record->company_id)->where('purchase_order_id', $order)->latest('id')->get(['id', 'number', 'status']) : [],
            'sourceQuotation' => $record->supplier_quotation_id === null ? null : SupplierQuotation::query()->where('company_id', $record->company_id)->find($record->supplier_quotation_id, ['id', 'quotation_number', 'notes'])]);
    }

    public function submit(Request $request, int $order): RedirectResponse
    {
        return $this->transition($request, $order, 'submit');
    }

    public function approve(Request $request, int $order): RedirectResponse
    {
        return $this->transition($request, $order, 'approve');
    }

    public function reject(Request $request, int $order): RedirectResponse
    {
        return $this->transition($request, $order, 'reject');
    }

    public function order(Request $request, int $order): RedirectResponse
    {
        return $this->transition($request, $order, 'order');
    }

    public function revise(Request $request, int $order): RedirectResponse
    {
        return $this->transition($request, $order, 'revise');
    }

    public function close(Request $request, int $order): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'comment' => ['required', 'string', 'max:2000']]);
        $this->receiving->closeOrder($this->actor($request), $order, (int) $data['revision'], $data['comment']);

        return redirect()->route('erp.purchase.orders.show', $order)->with('success', 'Fully received purchase order closed.');
    }

    private function transition(Request $request, int $id, string $action): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'comment' => [in_array($action, ['reject', 'revise'], true) ? 'required' : 'nullable', 'string', 'max:2000']]);
        $this->service->transition($this->actor($request), $id, $action, (int) $data['revision'], $data['comment'] ?? null);

        return redirect()->route('erp.purchase.orders.show', $id)->with('success', 'Purchase order action completed.');
    }

    /** @return array<string, mixed> */
    private function draftRules(): array
    {
        return ['expected_delivery_at' => ['nullable', 'date_format:Y-m-d'], 'notes' => ['nullable', 'string', 'max:10000'],
            'items' => ['prohibited'], 'supplier_id' => ['prohibited'], 'purchase_requisition_id' => ['prohibited'], 'currency_code' => ['prohibited'],
            'number' => ['prohibited'], 'status' => ['prohibited'], 'created_by' => ['prohibited'], 'approved_by' => ['prohibited'],
            'subtotal' => ['prohibited'], 'discount_total' => ['prohibited'], 'tax_total' => ['prohibited'], 'grand_total' => ['prohibited']];
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
