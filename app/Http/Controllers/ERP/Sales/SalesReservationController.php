<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Sales;

use App\Http\Controllers\Controller;
use App\Models\InventoryBalance;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesReservationEvent;
use App\Models\SalesStockReservation;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ERP\Sales\SalesCompanyAccess;
use App\Services\ERP\Sales\SalesOrderService;
use App\Services\ERP\Sales\SalesReservationService;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SalesReservationController extends Controller
{
    public function __construct(private readonly SalesOrderService $orders, private readonly SalesReservationService $reservations, private readonly SalesCompanyAccess $access) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $filters = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:120']]);
        $companies = $this->orders->companies($actor, 'erp.sales-reservations.view')->orderBy('name')->get(['id', 'name']);
        if (! empty($filters['company_id'])) {
            $this->access->authorize($actor, (int) $filters['company_id'], 'erp.sales-reservations.view');
        }
        $orders = SalesOrder::query()->whereIn('company_id', $companies->modelKeys())->whereNotNull('confirmed_at')
            ->when(! empty($filters['company_id']), fn ($q) => $q->where('company_id', $filters['company_id']))
            ->when(! empty($filters['search']), fn ($q) => $q->where('number', 'like', '%'.$filters['search'].'%'))
            ->latest('id')->paginate(20)->withQueryString()->through(fn (SalesOrder $order): array => [
                'id' => $order->getKey(), 'number' => $order->getAttribute('number'), 'status' => $order->getAttribute('status'),
                'customer_name' => ($order->getAttribute('customer_snapshot') ?? [])['name'] ?? '', 'document_date' => $order->getAttribute('document_date')?->format('Y-m-d'),
            ]);

        return Inertia::render('ERP/Sales/Reservations/Index', ['orders' => $orders, 'companies' => $companies, 'filters' => $filters]);
    }

    public function show(Request $request, int $order): Response
    {
        $actor = $this->actor($request);
        $document = $this->orders->find($actor, $order, 'erp.sales-reservations.view');
        $companyId = (int) $document->getAttribute('company_id');
        $lines = SalesOrderItem::query()->where('sales_order_id', $order)->orderBy('line_number')->get();
        $rows = SalesStockReservation::query()->where('company_id', $companyId)->whereIn('sales_order_item_id', $lines->modelKeys())->orderBy('id')->get();
        $warehouses = Warehouse::query()->withoutGlobalScope(SoftDeletingScope::class)->where('company_id', $companyId)->orderBy('name')->get(['id', 'name', 'code', 'is_active', 'deleted_at']);
        $balances = InventoryBalance::query()->where('company_id', $companyId)->whereIn('warehouse_id', $warehouses->modelKeys())->whereIn('product_id', $lines->pluck('product_id'))->get();
        $items = $lines->map(function (SalesOrderItem $line) use ($rows, $document): array {
            $active = '0.0000';
            foreach ($rows->where('sales_order_item_id', $line->getKey()) as $row) {
                $active = bcadd($active, bcsub(bcsub((string) $row->getAttribute('allocated_quantity'), (string) $row->getAttribute('released_quantity'), 4), (string) $row->getAttribute('consumed_quantity'), 4), 4);
            }
            $remaining = bcsub(bcsub((string) $line->getAttribute('quantity'), (string) $line->getAttribute('delivered_quantity'), 4), (string) $line->getAttribute('cancelled_quantity'), 4);
            $requiresStock = false;
            foreach (($document->getAttribute('terms_snapshot') ?? [])['sales_order_source']['configuration'] ?? [] as $configuration) {
                if ((int) ($configuration['source_item_id'] ?? 0) === (int) $line->getAttribute('sales_quotation_item_id')) {
                    $requiresStock = ($configuration['product_type'] ?? null) === 'physical' && (bool) ($configuration['track_inventory'] ?? false);
                }
            }

            return ['id' => $line->getKey(), 'product_id' => $line->getAttribute('product_id'), 'variant_key' => (int) ($line->getAttribute('product_variant_id') ?? 0),
                'name' => ($line->getAttribute('product_snapshot') ?? [])['name'] ?? '', 'variant_name' => ($line->getAttribute('product_snapshot') ?? [])['variant_name'] ?? null,
                'unit' => ($line->getAttribute('product_snapshot') ?? [])['unit'] ?? '', 'quantity' => $line->getAttribute('quantity'), 'remaining' => $remaining, 'reserved' => $active,
                'unreserved' => bcsub($remaining, $active, 4), 'requires_stock' => $requiresStock];
        })->values()->all();
        $reservationRows = $rows->map(fn (SalesStockReservation $row): array => [
            'id' => $row->getKey(), 'sales_order_item_id' => $row->getAttribute('sales_order_item_id'), 'warehouse_id' => $row->getAttribute('warehouse_id'),
            'allocated' => $row->getAttribute('allocated_quantity'), 'released' => $row->getAttribute('released_quantity'), 'consumed' => $row->getAttribute('consumed_quantity'),
            'active' => bcsub(bcsub((string) $row->getAttribute('allocated_quantity'), (string) $row->getAttribute('released_quantity'), 4), (string) $row->getAttribute('consumed_quantity'), 4),
        ])->values()->all();
        $events = SalesReservationEvent::query()->where('company_id', $companyId)->whereIn('sales_stock_reservation_id', $rows->modelKeys())->latest('id')->get(['id', 'sales_stock_reservation_id', 'action', 'quantity', 'reason', 'actor_id', 'occurred_at']);

        $actors = User::query()->whereIn('id', $events->pluck('actor_id')->filter())->get(['id', 'name'])->keyBy('id');

        return Inertia::render('ERP/Sales/Reservations/Show', [
            'order' => ['id' => $document->getKey(), 'number' => $document->getAttribute('number'), 'status' => $document->getAttribute('status'), 'revision' => $document->getAttribute('revision'), 'confirmed_at' => $document->getAttribute('confirmed_at'), 'customer_name' => ($document->getAttribute('customer_snapshot') ?? [])['name'] ?? ''],
            'items' => $items, 'reservations' => $reservationRows, 'warehouses' => $warehouses,
            'events' => $events->map(fn (SalesReservationEvent $event): array => ['id' => $event->getKey(), 'sales_stock_reservation_id' => $event->getAttribute('sales_stock_reservation_id'), 'action' => $event->getAttribute('action'), 'quantity' => $event->getAttribute('quantity'), 'reason' => $event->getAttribute('reason'), 'actor_name' => $actors->get((int) $event->getAttribute('actor_id'))?->getAttribute('name') ?? 'Recorded actor', 'occurred_at' => $event->getAttribute('occurred_at')?->toIso8601String()])->values()->all(),
            'balances' => $balances->map(fn (InventoryBalance $balance): array => ['warehouse_id' => $balance->getAttribute('warehouse_id'), 'product_id' => $balance->getAttribute('product_id'), 'variant_key' => $balance->getAttribute('variant_key'), 'on_hand' => $balance->getAttribute('quantity_on_hand'), 'reserved' => $balance->getAttribute('quantity_reserved'), 'available' => bcsub((string) $balance->getAttribute('quantity_on_hand'), (string) $balance->getAttribute('quantity_reserved'), 4)])->values()->all(),
            'orderUrl' => $actor->can('erp.sales-orders.view') ? route('erp.sales.orders.show', $order) : null,
        ]);
    }

    public function allocate(Request $request, int $order): RedirectResponse
    {
        $this->reservations->allocate($this->actor($request), $order, $request->all());

        return redirect()->route('erp.sales.reservations.show', $order)->with('success', 'Stock allocated.');
    }

    public function release(Request $request, int $order): RedirectResponse
    {
        $this->reservations->release($this->actor($request), $order, $request->all());

        return redirect()->route('erp.sales.reservations.show', $order)->with('success', 'Reservation released.');
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
