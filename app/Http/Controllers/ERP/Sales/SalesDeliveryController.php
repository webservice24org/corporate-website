<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Sales;

use App\Http\Controllers\Controller;
use App\Models\SalesDelivery;
use App\Models\SalesDeliveryItem;
use App\Models\SalesDocumentEvent;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesStockReservation;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ERP\Sales\SalesCompanyAccess;
use App\Services\ERP\Sales\SalesDeliveryService;
use App\Services\ERP\Sales\SalesOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SalesDeliveryController extends Controller
{
    public function __construct(private readonly SalesDeliveryService $deliveries, private readonly SalesOrderService $orders, private readonly SalesCompanyAccess $access) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $filters = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'sales_order_id' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', 'in:draft,posted,cancelled']]);
        $companies = $this->orders->companies($actor, 'erp.sales-deliveries.view')->orderBy('name')->get(['id', 'name']);
        if (! empty($filters['company_id'])) {
            $this->access->authorize($actor, (int) $filters['company_id'], 'erp.sales-deliveries.view');
        }
        if (! empty($filters['sales_order_id'])) {
            SalesOrder::query()->whereIn('company_id', $companies->modelKeys())->findOrFail((int) $filters['sales_order_id']);
        }
        $rows = SalesDelivery::query()->whereIn('company_id', $companies->modelKeys())
            ->when(! empty($filters['company_id']), fn ($q) => $q->where('company_id', $filters['company_id']))
            ->when(! empty($filters['sales_order_id']), fn ($q) => $q->where('sales_order_id', $filters['sales_order_id']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['search']), fn ($q) => $q->where('number', 'like', '%'.$filters['search'].'%'))->latest('id')->paginate(20)->withQueryString()->through(fn (SalesDelivery $delivery): array => [...$delivery->only(['id', 'number', 'status', 'sales_order_id', 'customer_snapshot']), 'document_date' => $delivery->getAttribute('document_date')->format('Y-m-d')]);

        return Inertia::render('ERP/Sales/Deliveries/Index', ['deliveries' => $rows, 'companies' => $companies, 'filters' => $filters]);
    }

    public function create(Request $request): Response
    {
        return $this->form($request);
    }

    public function edit(Request $request, int $delivery): Response
    {
        return $this->form($request, $delivery);
    }

    public function store(Request $request): RedirectResponse
    {
        $delivery = $this->deliveries->save($this->actor($request), $request->all());

        return redirect()->route('erp.sales.deliveries.show', $delivery->getKey());
    }

    public function update(Request $request, int $delivery): RedirectResponse
    {
        $document = $this->deliveries->save($this->actor($request), $request->all(), $delivery);

        return redirect()->route('erp.sales.deliveries.show', $document->getKey());
    }

    public function show(Request $request, int $delivery): Response
    {
        return $this->display($request, $delivery, false);
    }

    public function print(Request $request, int $delivery): Response
    {
        return $this->display($request, $delivery, true);
    }

    public function post(Request $request, int $delivery): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'string', 'max:100']]);
        $this->deliveries->post($this->actor($request), $delivery, (int) $data['revision'], (string) $data['idempotency_key']);

        return redirect()->route('erp.sales.deliveries.show', $delivery)->with('success', 'Fulfillment posted.');
    }

    public function cancel(Request $request, int $delivery): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000']]);
        $this->deliveries->cancel($this->actor($request), $delivery, (int) $data['revision'], (string) $data['reason']);

        return redirect()->route('erp.sales.deliveries.show', $delivery)->with('success', 'Draft cancelled.');
    }

    private function form(Request $request, ?int $id = null): Response
    {
        $actor = $this->actor($request);
        $permission = 'erp.sales-deliveries.'.($id === null ? 'create' : 'edit');
        $delivery = $id === null ? null : $this->deliveries->find($actor, $id, $permission);
        abort_unless($actor->can('erp.sales-orders.view'), 403);
        if ($delivery !== null) {
            abort_unless($delivery->getAttribute('status') === 'draft', 403);
        }
        $input = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'sales_order_id' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:120']]);
        $companies = $this->orders->companies($actor, $permission)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'timezone']);
        $companyId = $delivery?->getAttribute('company_id') ?? ($input['company_id'] ?? $companies->first()?->getKey());
        $orderId = $delivery?->getAttribute('sales_order_id') ?? ($input['sales_order_id'] ?? null);
        $order = null;
        $options = [];
        if ($companyId !== null) {
            $company = $this->access->authorize($actor, (int) $companyId, $permission);
            $query = SalesOrder::query()->where('company_id', $companyId)->whereNotNull('confirmed_at')->whereIn('status', ['confirmed', 'partially_delivered']);
            $rows = (clone $query)->when(! empty($input['search']), fn ($q) => $q->where('number', 'like', '%'.$input['search'].'%'))->latest('id')->limit(100)->get();
            if ($orderId !== null) {
                $order = (clone $query)->findOrFail((int) $orderId);
                $this->orders->find($actor, (int) $order->getKey());
                $rows = $rows->merge([$order])->unique('id')->values();
            }
            $options = $rows->map(fn (SalesOrder $row): array => ['id' => $row->getKey(), 'number' => $row->getAttribute('number'), 'customer_name' => ($row->getAttribute('customer_snapshot') ?? [])['name'] ?? ''])->values()->all();
            $today = now((string) $company->getAttribute('timezone'))->toDateString();
        } else {
            $today = now()->toDateString();
        }

        return Inertia::render('ERP/Sales/Deliveries/Form', [
            'delivery' => $delivery === null ? null : $this->header($delivery), 'companies' => $companies, 'defaultCompanyId' => $companyId === null ? null : (int) $companyId,
            'orders' => $options, 'sourceOrder' => $order === null ? null : ['id' => $order->getKey(), 'number' => $order->getAttribute('number'), 'customer_name' => ($order->getAttribute('customer_snapshot') ?? [])['name'] ?? ''],
            'sourceItems' => $order === null ? [] : $this->sourceItems($order), 'savedItems' => $delivery === null ? [] : $this->items($delivery), 'today' => $today, 'search' => $input['search'] ?? '',
        ]);
    }

    /** @return array<string, mixed> */
    private function header(SalesDelivery $delivery): array
    {
        $terms = $delivery->getAttribute('terms_snapshot') ?? [];

        return [...$delivery->only(['id', 'company_id', 'sales_order_id', 'number', 'status', 'revision', 'notes', 'customer_snapshot', 'billing_address_snapshot', 'shipping_address_snapshot']), 'terms_snapshot' => array_intersect_key($terms, array_flip(['issuer', 'sales_delivery_source', 'delivery_warehouse'])), 'document_date' => $delivery->getAttribute('document_date')->format('Y-m-d'), 'posted_at' => $delivery->getAttribute('posted_at')?->toIso8601String()];
    }

    /** @return array<int, array<string, mixed>> */
    private function sourceItems(SalesOrder $order): array
    {
        $lines = SalesOrderItem::query()->where('sales_order_id', $order->getKey())->orderBy('line_number')->get();
        $reservations = SalesStockReservation::query()->where('company_id', $order->getAttribute('company_id'))->whereIn('sales_order_item_id', $lines->modelKeys())->get();
        $warehouses = Warehouse::query()->where('company_id', $order->getAttribute('company_id'))->where('is_active', true)->whereIn('id', $reservations->pluck('warehouse_id'))->get(['id', 'name', 'code'])->keyBy('id');

        return $lines->map(function (SalesOrderItem $line) use ($order, $reservations, $warehouses): array {
            $physical = false;
            foreach (($order->getAttribute('terms_snapshot') ?? [])['sales_order_source']['configuration'] ?? [] as $configuration) {
                if ((int) ($configuration['source_item_id'] ?? 0) === (int) $line->getAttribute('sales_quotation_item_id')) {
                    $physical = ($configuration['product_type'] ?? null) === 'physical' && (bool) ($configuration['track_inventory'] ?? false);
                }
            }
            $options = [];
            foreach ($reservations->where('sales_order_item_id', $line->getKey()) as $reservation) {
                $warehouse = $warehouses->get((int) $reservation->getAttribute('warehouse_id'));
                $active = bcsub(bcsub((string) $reservation->getAttribute('allocated_quantity'), (string) $reservation->getAttribute('released_quantity'), 4), (string) $reservation->getAttribute('consumed_quantity'), 4);
                if ($warehouse instanceof Warehouse && bccomp($active, '0', 4) > 0) {
                    $options[] = ['id' => $reservation->getKey(), 'warehouse_name' => $warehouse->getAttribute('name'), 'warehouse_code' => $warehouse->getAttribute('code'), 'active' => $active];
                }
            }

            return ['id' => $line->getKey(), 'product_snapshot' => $line->getAttribute('product_snapshot'), 'remaining' => bcsub(bcsub((string) $line->getAttribute('quantity'), (string) $line->getAttribute('delivered_quantity'), 4), (string) $line->getAttribute('cancelled_quantity'), 4), 'physical' => $physical, 'reservations' => $options];
        })->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function items(SalesDelivery $delivery): array
    {
        return SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->orderBy('line_number')->get()->map(fn (SalesDeliveryItem $item): array => $item->only(['id', 'line_number', 'sales_order_item_id', 'sales_stock_reservation_id', 'warehouse_id', 'product_snapshot', 'description', 'quantity', 'fulfillment_type', 'invoiced_quantity', 'returned_quantity']))->all();
    }

    private function display(Request $request, int $id, bool $print): Response
    {
        $actor = $this->actor($request);
        $delivery = $this->deliveries->find($actor, $id);
        $events = $print ? [] : SalesDocumentEvent::query()->where('company_id', $delivery->getAttribute('company_id'))->where('document_type', 'delivery')->where('document_id', $id)->orderBy('id')->get(['id', 'action', 'actor_snapshot', 'reason', 'occurred_at']);

        return Inertia::render('ERP/Sales/Deliveries/Show', ['delivery' => $this->header($delivery), 'items' => $this->items($delivery), 'printMode' => $print, 'events' => $events, 'orderUrl' => $actor->can('erp.sales-orders.view') ? route('erp.sales.orders.show', $delivery->getAttribute('sales_order_id')) : null]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
