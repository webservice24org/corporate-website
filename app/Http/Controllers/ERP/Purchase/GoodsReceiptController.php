<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Purchase;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ERP\Purchase\GoodsReceiptService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class GoodsReceiptController extends Controller
{
    public function __construct(private readonly GoodsReceiptService $service) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'company_id' => ['nullable', 'integer', 'min:1'], 'status' => ['nullable', 'in:draft,posted,cancelled']]);
        $query = $this->service->visible($actor)->with(['company:id,name', 'purchase_order:id,number', 'warehouse' => fn ($query) => $query->withoutGlobalScope(SoftDeletingScope::class)->select('id', 'name')]);
        if (! empty($filters['company_id'])) {
            $this->service->companies($actor, 'erp.goods-receipts.view')->findOrFail($filters['company_id']);
            $query->where('company_id', $filters['company_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $query->where('number', 'like', '%'.$filters['search'].'%');
        }

        return Inertia::render('ERP/Purchase/Receipts/Index', ['receipts' => $query->latest('id')->paginate(20)->withQueryString(),
            'companies' => $this->service->companies($actor, 'erp.goods-receipts.view')->orderBy('name')->get(['id', 'name']), 'filters' => $filters]);
    }

    public function create(Request $request): Response
    {
        $actor = $this->actor($request);
        $input = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'order_id' => ['nullable', 'integer', 'min:1']]);
        $companies = $this->service->companies($actor, 'erp.goods-receipts.create')->orderBy('name')->get(['id', 'name']);
        $only = $companies->count() === 1 ? $companies->first() : null;
        $companyId = empty($input['company_id']) ? ($only === null ? null : (int) $only->getKey()) : (int) $input['company_id'];
        if (! empty($input['order_id']) && $companyId === null) {
            $order = PurchaseOrder::query()->whereIn('company_id', $this->service->companies($actor, 'erp.goods-receipts.create')->select('id'))->findOrFail($input['order_id']);
            $companyId = (int) $order->company_id;
        }
        if ($companyId !== null) {
            $this->service->companies($actor, 'erp.goods-receipts.create')->findOrFail($companyId);
        }
        $orders = PurchaseOrder::query()->where('company_id', $companyId ?? 0)->whereIn('status', ['ordered', 'partially_received'])
            ->with('supplier:id,name')->latest('id')->get(['id', 'company_id', 'supplier_id', 'number', 'currency_code', 'status']);
        $selected = empty($input['order_id']) ? null : $orders->firstWhere('id', (int) $input['order_id']);
        if (! empty($input['order_id'])) {
            abort_unless($selected !== null, 404, 'The selected order is not available for receiving.');
        }

        return $this->form(null, $orders->toArray(), $companies->toArray(), $companyId, $selected === null ? null : (int) $selected->getKey());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([...$this->rules(), 'company_id' => ['required', 'integer', 'min:1'], 'purchase_order_id' => ['required', 'integer', 'min:1']]);
        $receipt = $this->service->create($this->actor($request), $data);

        return redirect()->route('erp.purchase.receipts.show', $receipt->getKey())->with('success', 'Receipt saved as draft. Stock has not changed.');
    }

    public function edit(Request $request, int $receipt): Response
    {
        $record = $this->service->find($this->actor($request), $receipt, 'erp.goods-receipts.edit');
        abort_unless($record->status === 'draft', 403, 'Only draft receipts can be edited.');
        $order = PurchaseOrder::query()->where('company_id', $record->company_id)->with('supplier:id,name')->findOrFail($record->purchase_order_id);

        return $this->form($record, [$order->only(['id', 'number', 'company_id', 'currency_code', 'status']) + ['supplier' => $order->getRelation('supplier')]], [], (int) $record->company_id, (int) $order->getKey());
    }

    public function update(Request $request, int $receipt): RedirectResponse
    {
        $data = $request->validate([...$this->rules(), 'revision' => ['required', 'integer', 'min:1'], 'company_id' => ['prohibited'], 'purchase_order_id' => ['prohibited']]);
        $this->service->update($this->actor($request), $receipt, $data);

        return redirect()->route('erp.purchase.receipts.show', $receipt)->with('success', 'Receipt draft updated.');
    }

    public function show(Request $request, int $receipt): Response
    {
        $record = $this->service->find($this->actor($request), $receipt, 'erp.goods-receipts.view');
        $items = DB::table('goods_receipt_items')->join('purchase_order_items', 'purchase_order_items.id', '=', 'goods_receipt_items.purchase_order_item_id')
            ->where('goods_receipt_id', $receipt)->where('purchase_order_items.purchase_order_id', $record->purchase_order_id)->orderBy('goods_receipt_items.id')
            ->get(['goods_receipt_items.id', 'purchase_order_item_id', 'quantity_received', 'quantity_rejected', 'unit_cost', 'is_stock_item', 'stock_movement_id', 'product_name', 'variant_name']);
        $history = DB::table('goods_receipt_audit_logs')->leftJoin('users', 'users.id', '=', 'goods_receipt_audit_logs.actor_id')
            ->where('goods_receipt_id', $receipt)->where('goods_receipt_audit_logs.company_id', $record->company_id)->latest('goods_receipt_audit_logs.id')->limit(50)
            ->get(['goods_receipt_audit_logs.id', 'action', 'comment', 'goods_receipt_audit_logs.created_at', 'users.name as actor_name']);

        return Inertia::render('ERP/Purchase/Receipts/Show', ['receipt' => $record->load(['company:id,name', 'purchase_order:id,number,status,currency_code',
            'warehouse' => fn ($query) => $query->withoutGlobalScope(SoftDeletingScope::class)->select('id', 'name')]), 'items' => $items, 'history' => $history,
            'timezone' => config('app.timezone'),
            'receivedAtDisplay' => Carbon::parse((string) $record->getRawOriginal('received_at'))->format('Y-m-d H:i'),
            'postedAtDisplay' => $record->posted_at === null ? null : Carbon::parse((string) $record->getRawOriginal('posted_at'))->format('Y-m-d H:i')]);
    }

    public function post(Request $request, int $receipt): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $this->service->post($this->actor($request), $receipt, (int) $data['revision']);

        return redirect()->route('erp.purchase.receipts.show', $receipt)->with('success', 'Receipt posted. Accepted stock quantities and order progress are updated.');
    }

    public function cancel(Request $request, int $receipt): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000']]);
        $this->service->cancel($this->actor($request), $receipt, (int) $data['revision'], $data['reason']);

        return redirect()->route('erp.purchase.receipts.show', $receipt)->with('success', 'Draft receipt cancelled. No stock changed.');
    }

    /** @param array<int, array<string, mixed>> $orders @param array<int, array<string, mixed>> $companies */
    private function form(?GoodsReceipt $receipt, array $orders, array $companies, ?int $companyId, ?int $orderId): Response
    {
        $lines = $orderId === null ? [] : PurchaseOrderItem::query()->where('purchase_order_id', $orderId)->orderBy('id')->get()->map(fn (PurchaseOrderItem $item): array => [
            ...$item->only(['id', 'product_id', 'product_name', 'variant_name', 'quantity', 'received_quantity']),
            'remaining' => bcsub((string) $item->quantity, (string) $item->received_quantity, 4),
        ]);

        return Inertia::render('ERP/Purchase/Receipts/Form', ['receipt' => $receipt, 'orders' => $orders, 'companies' => $companies, 'companyId' => $companyId, 'orderId' => $orderId,
            'warehouses' => Warehouse::query()->where('company_id', $companyId ?? 0)->where('is_active', true)->orderBy('name')->get(['id', 'name']), 'orderItems' => $lines,
            'receiptItems' => $receipt === null ? [] : GoodsReceiptItem::query()->where('goods_receipt_id', $receipt->getKey())->get(),
            'receivedAtDefault' => now()->format('Y-m-d\TH:i'), 'timezone' => config('app.timezone'),
            'receiptReceivedAt' => $receipt === null ? null : Carbon::parse((string) $receipt->getRawOriginal('received_at'))->format('Y-m-d\TH:i')]);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return ['warehouse_id' => ['required', 'integer', 'min:1'], 'received_at' => ['required', 'date_format:Y-m-d\TH:i', 'before_or_equal:now'], 'notes' => ['nullable', 'string', 'max:10000'],
            'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*' => ['array:purchase_order_item_id,quantity_received,quantity_rejected'],
            'items.*.purchase_order_item_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.quantity_received' => ['required', 'numeric', 'min:0', 'max:1000000', 'regex:/^[0-9]+(?:\.[0-9]{1,4})?$/D'],
            'items.*.quantity_rejected' => ['required', 'numeric', 'min:0', 'max:1000000', 'regex:/^[0-9]+(?:\.[0-9]{1,4})?$/D'],
            'status' => ['prohibited'], 'number' => ['prohibited'], 'unit_cost' => ['prohibited'], 'stock_movement_id' => ['prohibited'], 'posted_by' => ['prohibited'], 'created_by' => ['prohibited']];
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
