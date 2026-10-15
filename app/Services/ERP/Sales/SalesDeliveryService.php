<?php

declare(strict_types=1);

namespace App\Services\ERP\Sales;

use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesDelivery;
use App\Models\SalesDeliveryItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesStockReservation;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SalesDeliveryService
{
    public function __construct(
        private readonly SalesCompanyAccess $access,
        private readonly SalesOrderService $orders,
        private readonly SalesDocumentGuard $guard,
        private readonly SalesSourceGuard $sources,
        private readonly SalesQuotationMath $math,
        private readonly SalesReservationService $reservations,
        private readonly InventoryService $inventory,
        private readonly SalesDocumentNumberService $numbers,
        private readonly SalesAuditService $audit,
    ) {}

    public function find(User $actor, int $id, string $permission = 'erp.sales-deliveries.view'): SalesDelivery
    {
        $delivery = SalesDelivery::query()->whereIn('company_id', $this->orders->companies($actor, $permission)->select('id'))->findOrFail($id);
        $this->access->authorize($actor, (int) $delivery->getAttribute('company_id'), $permission);

        return $delivery;
    }

    /** @param array<string, mixed> $input */
    public function save(User $actor, array $input, ?int $id = null): SalesDelivery
    {
        $data = Validator::make($input, [
            'company_id' => ['required', 'integer', 'min:1'], 'sales_order_id' => ['required', 'integer', 'min:1'],
            'document_date' => ['required', 'date_format:Y-m-d'], 'notes' => ['nullable', 'string', 'max:5000'],
            'revision' => [$id === null ? 'nullable' : 'required', 'integer', 'min:1'],
            'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*.sales_order_item_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.quantity' => ['required', 'regex:/\A[0-9]{1,5}(?:\.[0-9]{1,4})?\z/'],
            'items.*.sales_stock_reservation_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        return DB::transaction(function () use ($actor, $data, $id): SalesDelivery {
            $permission = 'erp.sales-deliveries.'.($id === null ? 'create' : 'edit');
            $companyId = (int) $data['company_id'];
            $company = $this->access->authorize($actor, $companyId, $permission, true);
            $existing = $id === null ? null : $this->find($actor, $id, $permission);
            if ($existing !== null && ((int) $existing->getAttribute('company_id') !== $companyId || (int) $existing->getAttribute('sales_order_id') !== (int) $data['sales_order_id'])) {
                $this->fail('sales_order_id', 'A delivery draft cannot change its company or source order.');
            }
            $found = $this->orders->find($actor, (int) $data['sales_order_id']);
            $order = SalesOrder::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($found->getKey());
            $delivery = $existing === null ? null : SalesDelivery::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            if ($delivery !== null) {
                $this->assertDraft($delivery);
                $this->guard->assertRevision($delivery, (int) $data['revision']);
                if ($data['document_date'] !== $delivery->getAttribute('document_date')->format('Y-m-d')) {
                    $this->fail('document_date', 'Delivery date cannot change after number allocation.');
                }
            }
            $this->assertOrder($order);
            $this->sources->currency((string) $company->getAttribute('currency_code'), (string) $order->getAttribute('currency_code'));
            $this->sources->customer($companyId, (int) $order->getAttribute('customer_id'));
            $date = (string) $data['document_date'];
            if ($date < $order->getAttribute('document_date')->format('Y-m-d') || $date > now((string) $company->getAttribute('timezone'))->toDateString()) {
                $this->fail('document_date', 'Delivery date cannot precede its order or be in the future.');
            }
            $lines = SalesOrderItem::query()->where('sales_order_id', $order->getKey())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $reservationIds = array_filter(array_column($data['items'], 'sales_stock_reservation_id'));
            $reservationRows = SalesStockReservation::query()->where('company_id', $companyId)->whereIn('id', $reservationIds)->whereIn('sales_order_item_id', $lines->modelKeys())->get()->keyBy('id');
            $this->prelock($companyId, $lines->all(), $reservationRows->all());
            $prepared = [];
            $warehouseId = null;
            foreach ($data['items'] as $inputLine) {
                $line = $lines->get((int) $inputLine['sales_order_item_id']);
                abort_unless($line instanceof SalesOrderItem, 404);
                $quantity = $this->quantity((string) $inputLine['quantity']);
                if (bccomp($quantity, $this->remaining($line), 4) > 0) {
                    $this->fail('items', 'A delivery quantity exceeds its gross unfulfilled order remainder.');
                }
                $physical = $this->configuration($order, $line);
                $reservationId = empty($inputLine['sales_stock_reservation_id']) ? null : (int) $inputLine['sales_stock_reservation_id'];
                $reservation = $reservationId === null ? null : $reservationRows->get($reservationId);
                $lineWarehouse = null;
                if ($physical) {
                    abort_unless($reservation instanceof SalesStockReservation, 404);
                    $this->owned($order, $line, $reservation, $quantity);
                    $lineWarehouse = (int) $reservation->getAttribute('warehouse_id');
                    if ($warehouseId !== null && $warehouseId !== $lineWarehouse) {
                        $this->fail('items', 'Use a separate delivery note for each physical warehouse.');
                    }
                    $warehouseId = $lineWarehouse;
                } elseif ($reservationId !== null) {
                    $this->fail('items', 'Non-stock completion cannot use a stock reservation.');
                }
                $prepared[] = [
                    'sales_order_item_id' => $line->getKey(), 'product_id' => $line->getAttribute('product_id'), 'product_variant_id' => $line->getAttribute('product_variant_id'),
                    'unit_id' => $line->getAttribute('unit_id'), 'product_snapshot' => $line->getAttribute('product_snapshot'), 'description' => $line->getAttribute('description'),
                    'quantity' => $quantity, 'warehouse_id' => $lineWarehouse, 'sales_stock_reservation_id' => $reservationId, 'fulfillment_type' => $physical ? 'physical' : 'completion',
                ];
            }
            $terms = $order->getAttribute('terms_snapshot') ?? [];
            $terms['sales_delivery_source'] = ['id' => $order->getKey(), 'number' => $order->getAttribute('number')];
            if ($warehouseId !== null) {
                $warehouse = Warehouse::query()->where('company_id', $companyId)->findOrFail($warehouseId);
                $terms['delivery_warehouse'] = $warehouse->only(['id', 'name', 'code', 'address']);
            }
            $before = $delivery === null ? [] : ['status' => $delivery->getAttribute('status'), 'revision' => $delivery->getAttribute('revision')];
            if ($delivery === null) {
                $delivery = SalesDelivery::query()->create([
                    ...$order->only(['customer_id', 'currency_code', 'customer_snapshot', 'billing_address_snapshot', 'shipping_address_snapshot']),
                    'terms_snapshot' => $terms, 'company_id' => $companyId, 'sales_order_id' => $order->getKey(), 'number' => $this->numbers->next($companyId, 'delivery', $date),
                    'status' => 'draft', 'revision' => 1, 'document_date' => $date, 'notes' => $data['notes'] ?? null, 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
                ]);
            } else {
                $delivery->forceFill(['document_date' => $date, 'notes' => $data['notes'] ?? null, 'terms_snapshot' => $terms, 'revision' => (int) $data['revision'] + 1, 'updated_by' => $actor->getKey()])->save();
                SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->delete();
            }
            foreach ($prepared as $index => $attributes) {
                SalesDeliveryItem::query()->create([...$attributes, 'sales_delivery_id' => $delivery->getKey(), 'line_number' => $index + 1]);
            }
            $this->audit->record($actor, $companyId, 'delivery', (int) $delivery->getKey(), $id === null ? 'create' : 'edit', $before, ['revision' => $delivery->getAttribute('revision'), 'sales_order_id' => $order->getKey(), 'line_count' => count($prepared)]);

            return $delivery;
        }, 3);
    }

    public function post(User $actor, int $id, int $revision, string $key): SalesDelivery
    {
        Validator::make(['revision' => $revision, 'idempotency_key' => $key], ['revision' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'string', 'regex:/\A[a-zA-Z0-9_-]{16,100}\z/']])->validate();

        return DB::transaction(function () use ($actor, $id, $revision, $key): SalesDelivery {
            [$order, $delivery] = $this->locked($actor, $id, 'erp.sales-deliveries.post');
            $items = SalesDeliveryItem::query()->where('sales_delivery_id', $id)->orderBy('line_number')->lockForUpdate()->get();
            $hash = $this->fingerprint($delivery, $items);
            if ($delivery->getAttribute('status') === 'posted') {
                if ($delivery->getAttribute('posted_at') === null || $delivery->getAttribute('idempotency_key') !== $key || ! hash_equals((string) $delivery->getAttribute('payload_hash'), $hash)) {
                    $this->fail('idempotency_key', 'The posted delivery does not match this posting retry.');
                }

                $this->assertPosted($delivery, $items);

                return $delivery;
            }
            $this->assertDraft($delivery);
            $this->guard->assertRevision($delivery, $revision);
            $this->assertOrder($order);
            $companyId = (int) $order->getAttribute('company_id');
            if (SalesDelivery::query()->where('company_id', $companyId)->where('idempotency_key', $key)->where('id', '<>', $id)->exists()) {
                $this->fail('idempotency_key', 'This posting key already belongs to another delivery.');
            }
            $company = $this->access->authorize($actor, $companyId, 'erp.sales-deliveries.post', true);
            $date = $delivery->getAttribute('document_date')->format('Y-m-d');
            if ($date < $order->getAttribute('document_date')->format('Y-m-d') || $date > now((string) $company->getAttribute('timezone'))->toDateString()) {
                $this->fail('document_date', 'Review the delivery date before posting.');
            }
            $this->sources->currency((string) $company->getAttribute('currency_code'), (string) $order->getAttribute('currency_code'));
            $this->sources->customer($companyId, (int) $order->getAttribute('customer_id'));
            foreach (['customer_id', 'currency_code', 'customer_snapshot', 'billing_address_snapshot', 'shipping_address_snapshot'] as $field) {
                if ($this->canonical($delivery->getAttribute($field)) !== $this->canonical($order->getAttribute($field))) {
                    $this->fail('source', 'Delivery customer and commercial snapshots must match its order.');
                }
            }
            $terms = $delivery->getAttribute('terms_snapshot') ?? [];
            $source = $terms['sales_delivery_source'] ?? [];
            if ((int) ($source['id'] ?? 0) !== (int) $order->getKey() || ($source['number'] ?? null) !== $order->getAttribute('number')) {
                $this->fail('source', 'Delivery order trace is inconsistent.');
            }
            $warehouseTrace = $terms['delivery_warehouse'] ?? null;
            unset($terms['sales_delivery_source'], $terms['delivery_warehouse']);
            if ($this->canonical($terms) !== $this->canonical($order->getAttribute('terms_snapshot') ?? [])) {
                $this->fail('source', 'Delivery terms do not match its confirmed order.');
            }
            if ($items->isEmpty() || $items->count() > 100) {
                $this->fail('items', 'Delivery needs between one and 100 fulfillment lines.');
            }
            $lines = SalesOrderItem::query()->where('sales_order_id', $order->getKey())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $reservationRows = SalesStockReservation::query()->where('company_id', $companyId)->whereIn('sales_order_item_id', $lines->modelKeys())->whereIn('id', $items->pluck('sales_stock_reservation_id')->filter())->get()->keyBy('id');
            $this->prelock($companyId, $lines->all(), $reservationRows->all());
            $seen = [];
            $warehouseId = null;
            foreach ($items as $item) {
                $line = $lines->get((int) $item->getAttribute('sales_order_item_id'));
                abort_unless($line instanceof SalesOrderItem, 404);
                if (isset($seen[(int) $line->getKey()])) {
                    $this->fail('items', 'Each order item may appear only once in a delivery note.');
                }
                $seen[(int) $line->getKey()] = true;
                $quantity = $this->quantity((string) $item->getAttribute('quantity'));
                if (bccomp($quantity, $this->remaining($line), 4) > 0 || $item->getAttribute('stock_movement_id') !== null || bccomp((string) $item->getAttribute('invoiced_quantity'), '0', 4) !== 0 || bccomp((string) $item->getAttribute('returned_quantity'), '0', 4) !== 0) {
                    $this->fail('quantity', 'Delivery quantity or existing posting counters require review.');
                }
                foreach (['product_id', 'product_variant_id', 'unit_id', 'product_snapshot', 'description'] as $field) {
                    if ($this->canonical($item->getAttribute($field)) !== $this->canonical($line->getAttribute($field))) {
                        $this->fail('source', 'Delivery line source identity and snapshots must match the order.');
                    }
                }
                $physical = $this->configuration($order, $line);
                if ($item->getAttribute('fulfillment_type') !== ($physical ? 'physical' : 'completion')) {
                    $this->fail('items', 'The saved fulfillment type does not match the confirmed product configuration.');
                }
                if ($physical) {
                    $reservation = $reservationRows->get((int) $item->getAttribute('sales_stock_reservation_id'));
                    abort_unless($reservation instanceof SalesStockReservation, 404);
                    $this->owned($order, $line, $reservation, $quantity);
                    $itemWarehouse = (int) $reservation->getAttribute('warehouse_id');
                    if ((int) $item->getAttribute('warehouse_id') !== $itemWarehouse || ($warehouseId !== null && $warehouseId !== $itemWarehouse)) {
                        $this->fail('items', 'Delivery physical lines must use their owned reservation in one warehouse.');
                    }
                    $warehouseId = $itemWarehouse;
                    $warehouse = Warehouse::query()->where('company_id', $companyId)->findOrFail($itemWarehouse);
                    $product = Product::query()->where('company_id', $companyId)->findOrFail($line->getAttribute('product_id'));
                    $variantId = $line->getAttribute('product_variant_id');
                    $variant = $variantId === null ? null : ProductVariant::query()->where('product_id', $product->getKey())->findOrFail($variantId);
                    $balance = InventoryBalance::query()->where('company_id', $companyId)->where('warehouse_id', $itemWarehouse)->where('product_id', $product->getKey())->where('variant_key', (int) ($variantId ?? 0))->firstOrFail();
                    $cost = (string) $balance->getAttribute('average_cost');
                    $this->reservations->consumeForDelivery($actor, (int) $item->getKey());
                    $movement = $this->inventory->stockOut($warehouse, $product, $quantity, StockMovement::TYPE_SALE, unitCost: $cost, variant: $variant, referenceType: SalesDelivery::class, referenceId: $id, referenceNumber: (string) $delivery->getAttribute('number'), createdBy: (int) $actor->getKey());
                    if (bccomp((string) $movement->getAttribute('unit_cost'), $cost, 4) !== 0 || bccomp((string) $movement->getAttribute('quantity'), $quantity, 4) !== 0) {
                        $this->fail('stock', 'Inventory movement decimal values require review.');
                    }
                    $item->forceFill(['stock_movement_id' => $movement->getKey(), 'unit_cost' => $movement->getAttribute('unit_cost'), 'total_cost' => $movement->getAttribute('total_cost')])->save();
                } else {
                    if ($item->getAttribute('warehouse_id') !== null || $item->getAttribute('sales_stock_reservation_id') !== null) {
                        $this->fail('items', 'Non-stock completion must not use a warehouse or reservation.');
                    }
                    $item->forceFill(['unit_cost' => '0.0000', 'total_cost' => '0.0000'])->save();
                }
                $line->setAttribute('delivered_quantity', bcadd((string) $line->getAttribute('delivered_quantity'), $quantity, 4));
                $line->save();
            }
            if (($warehouseId !== null && (! is_array($warehouseTrace) || (int) ($warehouseTrace['id'] ?? 0) !== $warehouseId)) || ($warehouseId === null && $warehouseTrace !== null)) {
                $this->fail('source', 'The saved dispatch warehouse trace does not match its fulfillment lines.');
            }
            $remaining = '0.0000';
            foreach ($lines as $line) {
                $remaining = bcadd($remaining, $this->remaining($line), 4);
            }
            $before = ['status' => 'draft', 'revision' => $revision];
            $order->forceFill(['status' => bccomp($remaining, '0', 4) === 0 ? 'delivered' : 'partially_delivered', 'revision' => (int) $order->getAttribute('revision') + 1, 'updated_by' => $actor->getKey()])->save();
            $delivery->forceFill(['status' => 'posted', 'revision' => $revision + 1, 'posted_at' => now()->toDateTimeString(), 'posted_by' => $actor->getKey(), 'updated_by' => $actor->getKey(), 'idempotency_key' => $key, 'payload_hash' => $hash])->save();
            $this->audit->record($actor, $companyId, 'delivery', $id, 'post', $before, ['status' => 'posted', 'revision' => $revision + 1, 'sales_order_id' => $order->getKey(), 'order_status' => $order->getAttribute('status'), 'movement_ids' => $items->pluck('stock_movement_id')->filter()->values()->all()]);

            return $delivery;
        }, 3);
    }

    /** Trusted invoice preflight; caller has authorized and locked its company/order. */
    public function assertInvoiceSource(SalesDelivery $delivery): void
    {
        $this->access->requireTransaction();
        $items = SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->orderBy('line_number')->lockForUpdate()->get();
        if ($delivery->getAttribute('status') !== 'posted' || $delivery->getAttribute('posted_at') === null || $delivery->getAttribute('idempotency_key') === null
            || ! hash_equals((string) $delivery->getAttribute('payload_hash'), $this->fingerprint($delivery, $items))) {
            $this->fail('source', 'Posted delivery source metadata or fingerprint requires review.');
        }
        $this->assertPosted($delivery, $items);
    }

    public function cancel(User $actor, int $id, int $revision, string $reason): SalesDelivery
    {
        return DB::transaction(function () use ($actor, $id, $revision, $reason): SalesDelivery {
            [, $delivery] = $this->locked($actor, $id, 'erp.sales-deliveries.cancel');
            $this->assertDraft($delivery);
            $this->guard->assertRevision($delivery, $revision);
            $delivery->forceFill(['status' => 'cancelled', 'revision' => $revision + 1, 'updated_by' => $actor->getKey()])->save();
            $this->audit->record($actor, (int) $delivery->getAttribute('company_id'), 'delivery', $id, 'cancel', ['status' => 'draft', 'revision' => $revision], ['status' => 'cancelled', 'revision' => $revision + 1], $reason);

            return $delivery;
        }, 3);
    }

    /** @return array{SalesOrder, SalesDelivery} */
    private function locked(User $actor, int $id, string $permission): array
    {
        $found = $this->find($actor, $id, $permission);
        $companyId = (int) $found->getAttribute('company_id');
        $this->access->authorize($actor, $companyId, $permission, true);
        $order = SalesOrder::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($found->getAttribute('sales_order_id'));
        $delivery = SalesDelivery::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
        if ((int) $delivery->getAttribute('sales_order_id') !== (int) $order->getKey()) {
            $this->fail('source', 'Delivery source changed while posting.');
        }

        return [$order, $delivery];
    }

    /** @param array<array-key, SalesOrderItem> $lineModels
     * @param  array<array-key, SalesStockReservation>  $reservationModels
     */
    private function prelock(int $companyId, array $lineModels, array $reservationModels): void
    {
        $lines = collect($lineModels);
        $reservations = collect($reservationModels);
        Warehouse::query()->where('company_id', $companyId)->whereIn('id', $reservations->pluck('warehouse_id'))->orderBy('id')->lockForUpdate()->get(['id']);
        Product::query()->where('company_id', $companyId)->whereIn('id', $lines->pluck('product_id'))->orderBy('id')->lockForUpdate()->get(['id']);
        ProductVariant::query()->whereIn('product_id', $lines->pluck('product_id'))->whereIn('id', $lines->pluck('product_variant_id')->filter())->orderBy('id')->lockForUpdate()->get(['id']);
        Unit::query()->where('company_id', $companyId)->whereIn('id', $lines->pluck('unit_id'))->orderBy('id')->lockForUpdate()->get(['id']);
        InventoryBalance::query()->where('company_id', $companyId)->whereIn('warehouse_id', $reservations->pluck('warehouse_id'))->whereIn('product_id', $lines->pluck('product_id'))->orderBy('warehouse_id')->orderBy('product_id')->orderBy('variant_key')->lockForUpdate()->get(['id']);
        SalesStockReservation::query()->where('company_id', $companyId)->whereIn('id', $reservations->pluck('id'))->orderBy('id')->lockForUpdate()->get(['id']);
    }

    private function assertOrder(SalesOrder $order): void
    {
        if ($order->getAttribute('confirmed_at') === null || ! in_array($order->getAttribute('status'), ['confirmed', 'partially_delivered'], true)) {
            $this->fail('sales_order_id', 'Fulfillment requires a confirmed order with an unfulfilled remainder.');
        }
    }

    private function assertDraft(SalesDelivery $delivery): void
    {
        if ($delivery->getAttribute('status') !== 'draft' || $delivery->getAttribute('posted_at') !== null || $delivery->getAttribute('idempotency_key') !== null) {
            $this->fail('status', 'Only an unposted delivery draft can be changed or posted.');
        }
    }

    /** Return the confirmed physical-stock policy after checking current masters. */
    private function configuration(SalesOrder $order, SalesOrderItem $line): bool
    {
        $companyId = (int) $order->getAttribute('company_id');
        $variantId = $line->getAttribute('product_variant_id');
        $product = $this->sources->product($companyId, (int) $line->getAttribute('product_id'), $variantId === null ? null : (int) $variantId);
        $unit = Unit::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($line->getAttribute('unit_id'));
        if (! $unit->getAttribute('is_active')) {
            $this->fail('unit_id', 'Fulfillment requires an active same-company unit.');
        }
        $expected = null;
        foreach (($order->getAttribute('terms_snapshot') ?? [])['sales_order_source']['configuration'] ?? [] as $configuration) {
            if ((int) ($configuration['source_item_id'] ?? 0) === (int) $line->getAttribute('sales_quotation_item_id')) {
                $expected = $configuration;
            }
        }
        if (! is_array($expected) || (int) ($expected['product_id'] ?? 0) !== (int) $product->getKey() || ($expected['product_type'] ?? null) !== $product->getAttribute('product_type')
            || (bool) ($expected['track_inventory'] ?? false) !== (bool) $product->getAttribute('track_inventory') || (bool) ($expected['has_variants'] ?? false) !== (bool) $product->getAttribute('has_variants')
            || (int) ($expected['unit_id'] ?? 0) !== (int) $unit->getKey() || (int) $product->getAttribute('unit_id') !== (int) $unit->getKey()) {
            $this->fail('product_id', 'Product configuration changed after order confirmation; review before fulfillment.');
        }

        return ($expected['product_type'] ?? null) === 'physical' && (bool) ($expected['track_inventory'] ?? false);
    }

    private function owned(SalesOrder $order, SalesOrderItem $line, SalesStockReservation $reservation, string $quantity): void
    {
        if ((int) $reservation->getAttribute('company_id') !== (int) $order->getAttribute('company_id') || (int) $reservation->getAttribute('sales_order_item_id') !== (int) $line->getKey()
            || (int) $reservation->getAttribute('product_id') !== (int) $line->getAttribute('product_id') || (int) ($reservation->getAttribute('product_variant_id') ?? 0) !== (int) ($line->getAttribute('product_variant_id') ?? 0)
            || (int) $reservation->getAttribute('variant_key') !== (int) ($line->getAttribute('product_variant_id') ?? 0)) {
            $this->fail('items', 'Reservation must belong to the exact order item and variant.');
        }
        $this->sources->warehouse((int) $order->getAttribute('company_id'), (int) $reservation->getAttribute('warehouse_id'));
        $this->reservations->assertForDelivery($order, $line, $reservation, $quantity);
        $active = bcsub(bcsub((string) $reservation->getAttribute('allocated_quantity'), (string) $reservation->getAttribute('released_quantity'), 4), (string) $reservation->getAttribute('consumed_quantity'), 4);
        if (bccomp($quantity, $active, 4) > 0) {
            $this->fail('quantity', 'Delivery quantity exceeds its unused owned reservation.');
        }
    }

    private function remaining(SalesOrderItem $line): string
    {
        return bcsub(bcsub((string) $line->getAttribute('quantity'), (string) $line->getAttribute('delivered_quantity'), 4), (string) $line->getAttribute('cancelled_quantity'), 4);
    }

    private function quantity(string $value): string
    {
        $quantity = $this->math->scaled($value, 10000);
        if ($quantity <= 0) {
            $this->fail('quantity', 'Fulfillment quantity must be greater than zero.');
        }

        return $this->math->format($quantity, 4);
    }

    /** @param Collection<int, SalesDeliveryItem> $items */
    private function assertPosted(SalesDelivery $delivery, Collection $items): void
    {
        if ($items->isEmpty()) {
            $this->fail('stock', 'Posted delivery has no fulfillment lines.');
        }
        foreach ($items as $item) {
            if ($item->getAttribute('fulfillment_type') === 'physical') {
                $movement = StockMovement::query()->where('company_id', $delivery->getAttribute('company_id'))->find($item->getAttribute('stock_movement_id'));
                if (! $movement instanceof StockMovement || $movement->getAttribute('movement_type') !== StockMovement::TYPE_SALE || $movement->getAttribute('direction') !== StockMovement::DIRECTION_OUT
                    || (int) $movement->getAttribute('warehouse_id') !== (int) $item->getAttribute('warehouse_id') || (int) $movement->getAttribute('product_id') !== (int) $item->getAttribute('product_id')
                    || (int) ($movement->getAttribute('product_variant_id') ?? 0) !== (int) ($item->getAttribute('product_variant_id') ?? 0)
                    || $movement->getAttribute('reference_type') !== SalesDelivery::class || (int) $movement->getAttribute('reference_id') !== (int) $delivery->getKey() || $movement->getAttribute('reference_number') !== $delivery->getAttribute('number')
                    || bccomp((string) $movement->getAttribute('quantity'), (string) $item->getAttribute('quantity'), 4) !== 0 || bccomp((string) $movement->getAttribute('unit_cost'), (string) $item->getAttribute('unit_cost'), 4) !== 0 || bccomp((string) $movement->getAttribute('total_cost'), (string) $item->getAttribute('total_cost'), 4) !== 0) {
                    $this->fail('stock', 'Posted delivery movement references require review.');
                }
            } elseif ($item->getAttribute('fulfillment_type') !== 'completion' || $item->getAttribute('stock_movement_id') !== null || $item->getAttribute('warehouse_id') !== null || $item->getAttribute('sales_stock_reservation_id') !== null
                || bccomp((string) $item->getAttribute('unit_cost'), '0', 4) !== 0 || bccomp((string) $item->getAttribute('total_cost'), '0', 4) !== 0) {
                $this->fail('stock', 'Posted non-stock completion references require review.');
            }
        }
    }

    /** @param Collection<int, SalesDeliveryItem> $items */
    private function fingerprint(SalesDelivery $delivery, Collection $items): string
    {
        return hash('sha256', json_encode($this->canonical([
            'header' => [...$delivery->only(['id', 'number', 'company_id', 'sales_order_id', 'customer_id', 'currency_code', 'customer_snapshot', 'billing_address_snapshot', 'shipping_address_snapshot', 'terms_snapshot', 'notes']), 'document_date' => $delivery->getAttribute('document_date')->format('Y-m-d')],
            'items' => $items->map(fn (SalesDeliveryItem $item): array => $item->only(['id', 'line_number', 'sales_order_item_id', 'product_id', 'product_variant_id', 'unit_id', 'product_snapshot', 'description', 'quantity', 'warehouse_id', 'sales_stock_reservation_id', 'fulfillment_type']))->values()->all(),
        ]), JSON_THROW_ON_ERROR));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $entry): mixed => $this->canonical($entry), $value);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
