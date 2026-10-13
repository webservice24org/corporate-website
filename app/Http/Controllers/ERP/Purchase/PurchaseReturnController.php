<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Purchase;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\User;
use App\Services\ERP\Purchase\PurchaseReturnService;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class PurchaseReturnController extends Controller
{
    public function __construct(private readonly PurchaseReturnService $service) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:draft,approved,posted,cancelled'], 'company_id' => ['nullable', 'integer', 'min:1']]);
        $query = $this->service->visible($actor)->with(['company:id,name', 'goods_receipt:id,number', 'supplier:id,name']);
        if (! empty($filters['company_id'])) {
            $this->service->companies($actor, 'erp.purchase-returns.view')->findOrFail($filters['company_id']);
            $query->where('company_id', $filters['company_id']);
        }
        if (! empty($filters['search'])) {
            $query->where('number', 'like', '%'.$filters['search'].'%');
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return Inertia::render('ERP/Purchase/Returns/Index', ['returns' => $query->latest('id')->paginate(20)->withQueryString(),
            'companies' => $this->service->companies($actor, 'erp.purchase-returns.view')->orderBy('name')->get(['id', 'name']), 'filters' => $filters]);
    }

    public function create(Request $request): Response
    {
        $actor = $this->actor($request);
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'receipt_id' => ['nullable', 'integer', 'min:1']]);
        $companies = $this->service->companies($actor, 'erp.purchase-returns.create')->orderBy('name')->get(['id', 'name']);
        $only = $companies->count() === 1 ? $companies->first() : null;
        $companyId = empty($data['company_id']) ? ($only === null ? null : (int) $only->getKey()) : (int) $data['company_id'];
        $receipt = empty($data['receipt_id']) ? null : $this->service->sources($actor)->findOrFail((int) $data['receipt_id']);
        if ($receipt !== null) {
            if ($companyId !== null && $companyId !== (int) $receipt->company_id) {
                abort(404);
            }
            $companyId = (int) $receipt->company_id;
        }
        if ($companyId !== null) {
            $this->service->companies($actor, 'erp.purchase-returns.create')->findOrFail($companyId);
        }
        $receipts = $this->service->sources($actor)->where('company_id', $companyId ?? 0)->latest('id')->get(['id', 'number']);

        return $this->form(null, $receipt, $companies->toArray(), $receipts->toArray(), $companyId);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([...$this->rules(), 'goods_receipt_id' => ['required', 'integer', 'min:1']]);
        $record = $this->service->create($this->actor($request), $data);

        return redirect()->route('erp.purchase.returns.show', $record->getKey())->with('success', 'Return saved as draft. Stock has not changed.');
    }

    public function edit(Request $request, int $purchaseReturn): Response
    {
        $record = $this->service->find($this->actor($request), $purchaseReturn, 'erp.purchase-returns.edit');
        abort_unless($record->status === 'draft', 403);
        $receipt = GoodsReceipt::query()->where('company_id', $record->company_id)->findOrFail($record->goods_receipt_id);

        return $this->form($record, $receipt, [], [['id' => (int) $receipt->getKey(), 'number' => $receipt->number]], (int) $record->company_id);
    }

    public function update(Request $request, int $purchaseReturn): RedirectResponse
    {
        $data = $request->validate([...$this->rules(), 'revision' => ['required', 'integer', 'min:1'], 'goods_receipt_id' => ['prohibited']]);
        $this->service->update($this->actor($request), $purchaseReturn, $data);

        return redirect()->route('erp.purchase.returns.show', $purchaseReturn)->with('success', 'Return draft updated.');
    }

    public function show(Request $request, int $purchaseReturn): Response
    {
        $record = $this->service->find($this->actor($request), $purchaseReturn, 'erp.purchase-returns.view');
        $items = DB::table('purchase_return_items')->join('goods_receipt_items', 'goods_receipt_items.id', '=', 'purchase_return_items.goods_receipt_item_id')
            ->join('purchase_order_items', 'purchase_order_items.id', '=', 'goods_receipt_items.purchase_order_item_id')
            ->where('purchase_return_id', $purchaseReturn)->where('goods_receipt_items.goods_receipt_id', $record->goods_receipt_id)->where('purchase_order_items.purchase_order_id', $record->purchase_order_id)
            ->orderBy('purchase_return_items.id')->get(['purchase_return_items.id', 'purchase_return_items.goods_receipt_item_id', 'purchase_return_items.quantity', 'purchase_return_items.unit_cost', 'source_unit_cost',
                'purchase_return_items.is_stock_item', 'purchase_return_items.stock_movement_id', 'product_name', 'variant_name']);
        $history = DB::table('purchase_return_audit_logs')->leftJoin('users', 'users.id', '=', 'purchase_return_audit_logs.actor_id')
            ->where('purchase_return_id', $purchaseReturn)->where('purchase_return_audit_logs.company_id', $record->company_id)->latest('purchase_return_audit_logs.id')->limit(50)
            ->get(['purchase_return_audit_logs.id', 'action', 'comment', 'purchase_return_audit_logs.created_at', 'users.name as actor_name']);

        return Inertia::render('ERP/Purchase/Returns/Show', ['purchaseReturn' => $record->load(['company:id,name', 'supplier:id,name', 'goods_receipt:id,number', 'purchase_order:id,number,currency_code',
            'warehouse' => fn ($query) => $query->withoutGlobalScope(SoftDeletingScope::class)->select('id', 'name')]), 'items' => $items, 'history' => $history]);
    }

    public function approve(Request $request, int $purchaseReturn): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $this->service->approve($this->actor($request), $purchaseReturn, (int) $data['revision']);

        return redirect()->route('erp.purchase.returns.show', $purchaseReturn)->with('success', 'Return approved. Stock changes only when posted.');
    }

    public function post(Request $request, int $purchaseReturn): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $this->service->post($this->actor($request), $purchaseReturn, (int) $data['revision']);

        return redirect()->route('erp.purchase.returns.show', $purchaseReturn)->with('success', 'Return posted. Stock quantities have been updated.');
    }

    public function cancel(Request $request, int $purchaseReturn): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000']]);
        $this->service->cancel($this->actor($request), $purchaseReturn, (int) $data['revision'], $data['reason']);

        return redirect()->route('erp.purchase.returns.show', $purchaseReturn)->with('success', 'Return cancelled. No stock changed.');
    }

    /** @param array<int, array<string, mixed>> $companies @param array<int, array<string, mixed>> $receipts */
    private function form(?PurchaseReturn $record, ?GoodsReceipt $receipt, array $companies, array $receipts, ?int $companyId): Response
    {
        return Inertia::render('ERP/Purchase/Returns/Form', ['purchaseReturn' => $record, 'companies' => $companies, 'receipts' => $receipts, 'companyId' => $companyId,
            'receipt' => $receipt?->load(['warehouse:id,name', 'purchase_order:id,number,currency_code']), 'sourceLines' => $receipt === null ? [] : $this->service->sourceLines($receipt),
            'returnItems' => $record === null ? [] : PurchaseReturnItem::query()->where('purchase_return_id', $record->getKey())->get()]);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:2000', 'regex:/\S/u'], 'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*' => ['array:goods_receipt_item_id,quantity'],
            'items.*.goods_receipt_item_id' => ['required', 'integer', 'min:1', 'distinct'], 'items.*.quantity' => ['required', 'numeric', 'min:0', 'max:1000000', 'regex:/^[0-9]+(?:\.[0-9]{1,4})?$/D'],
            'company_id' => ['prohibited'], 'purchase_order_id' => ['prohibited'], 'supplier_id' => ['prohibited'], 'warehouse_id' => ['prohibited'], 'unit_cost' => ['prohibited'],
            'stock_movement_id' => ['prohibited'], 'number' => ['prohibited'], 'status' => ['prohibited'], 'approved_by' => ['prohibited'], 'posted_by' => ['prohibited'], 'created_by' => ['prohibited']];
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
