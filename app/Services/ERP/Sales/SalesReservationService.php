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
use App\Models\SalesReservationEvent;
use App\Models\SalesStockReservation;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SalesReservationService
{
    public function __construct(
        private readonly SalesCompanyAccess $access,
        private readonly SalesOrderService $orders,
        private readonly SalesDocumentGuard $guard,
        private readonly SalesQuotationMath $math,
        private readonly SalesSourceGuard $sources,
    ) {}

    /** @param array<string, mixed> $input */
    public function allocate(User $actor, int $orderId, array $input): SalesOrder
    {
        $data = $this->validate($input, false);

        return DB::transaction(function () use ($actor, $orderId, $data): SalesOrder {
            $order = $this->lockOrder($actor, $orderId, 'erp.sales-reservations.allocate');
            $line = $this->line($order, (int) $data['sales_order_item_id']);
            $quantity = $this->quantity((string) $data['quantity']);
            $hash = $this->hash(['allocate', $orderId, $line->getKey(), (int) $data['warehouse_id'], $quantity]);
            if ($this->replay($order, (string) $data['idempotency_key'], $hash)) {
                return $order;
            }
            $this->guard->assertRevision($order, (int) $data['revision']);
            $this->assertOpen($order);
            $this->sources->customer((int) $order->getAttribute('company_id'), (int) $order->getAttribute('customer_id'));
            [$warehouse, $product, $balance] = $this->resources($order, $line, (int) $data['warehouse_id'], true);
            $this->assertConfiguration($order, $line, $product);
            $rows = SalesStockReservation::query()->where('sales_order_item_id', $line->getKey())->orderBy('id')->lockForUpdate()->get();
            $active = '0.0000';
            foreach ($rows as $row) {
                $this->assertIdentity($order, $line, $row);
                $active = bcadd($active, $this->active($row), 4);
            }
            $remaining = bcsub(bcsub((string) $line->getAttribute('quantity'), (string) $line->getAttribute('delivered_quantity'), 4), (string) $line->getAttribute('cancelled_quantity'), 4);
            if (bccomp($quantity, bcsub($remaining, $active, 4), 4) > 0) {
                $this->fail('quantity', 'Allocation exceeds the unreserved order remainder.');
            }
            if (bccomp($quantity, bcsub((string) $balance->getAttribute('quantity_on_hand'), (string) $balance->getAttribute('quantity_reserved'), 4), 4) > 0) {
                $this->fail('quantity', 'Insufficient available stock. Enter a smaller partial allocation.');
            }
            $reservation = SalesStockReservation::query()->where('sales_order_item_id', $line->getKey())->where('warehouse_id', $warehouse->getKey())->where('product_id', $product->getKey())->where('variant_key', (int) ($line->getAttribute('product_variant_id') ?? 0))->lockForUpdate()->first();
            if ($reservation === null) {
                $reservation = SalesStockReservation::query()->create([
                    'company_id' => $order->getAttribute('company_id'), 'sales_order_item_id' => $line->getKey(),
                    'warehouse_id' => $warehouse->getKey(), 'product_id' => $product->getKey(),
                    'product_variant_id' => $line->getAttribute('product_variant_id'), 'variant_key' => (int) ($line->getAttribute('product_variant_id') ?? 0),
                    'allocated_quantity' => '0.0000', 'released_quantity' => '0.0000', 'consumed_quantity' => '0.0000',
                ]);
            }
            $reservation->setAttribute('allocated_quantity', bcadd((string) $reservation->getAttribute('allocated_quantity'), $quantity, 4));
            $reservation->save();
            $balance->setAttribute('quantity_reserved', bcadd((string) $balance->getAttribute('quantity_reserved'), $quantity, 4));
            $balance->save();
            $this->event($actor, $reservation, 'allocate', $quantity, (string) $data['idempotency_key'], $hash);
            $this->bump($order, $actor);

            return $order;
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function release(User $actor, int $orderId, array $input): SalesOrder
    {
        $data = $this->validate($input, true);

        return DB::transaction(function () use ($actor, $orderId, $data): SalesOrder {
            $order = $this->lockOrder($actor, $orderId, 'erp.sales-reservations.release');
            $reservation = SalesStockReservation::query()->where('company_id', $order->getAttribute('company_id'))
                ->whereIn('sales_order_item_id', SalesOrderItem::query()->where('sales_order_id', $order->getKey())->select('id'))->findOrFail((int) $data['reservation_id']);
            $line = $this->line($order, (int) $reservation->getAttribute('sales_order_item_id'));
            $quantity = $this->quantity((string) $data['quantity']);
            $reason = trim((string) $data['reason']);
            $hash = $this->hash(['release', $orderId, $reservation->getKey(), $quantity, $reason]);
            if ($this->replay($order, (string) $data['idempotency_key'], $hash)) {
                return $order;
            }
            $this->guard->assertRevision($order, (int) $data['revision']);
            $this->assertOpen($order);
            $this->releaseOwned($actor, $order, $line, $reservation, $quantity, $reason, (string) $data['idempotency_key'], $hash);
            $this->bump($order, $actor);

            return $order;
        }, 3);
    }

    /** Trusted cancellation integration; caller owns company/order locks and the transaction. */
    public function releaseForCancellation(User $actor, SalesOrder $order, string $reason): void
    {
        $this->access->authorize($actor, (int) $order->getAttribute('company_id'), 'erp.sales-orders.cancel', true);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000) {
            $this->fail('reason', 'Cancellation requires a reason of at most 2000 characters.');
        }
        $lines = SalesOrderItem::query()->where('sales_order_id', $order->getKey())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        // The company lock serializes Sales writers. Match Inventory's warehouse -> product -> balance order.
        $rows = SalesStockReservation::query()->whereIn('sales_order_item_id', $lines->modelKeys())->orderBy('warehouse_id')->orderBy('product_id')->orderBy('variant_key')->get();
        Warehouse::query()->withoutGlobalScope(SoftDeletingScope::class)->whereIn('id', $rows->pluck('warehouse_id'))->orderBy('id')->lockForUpdate()->get(['id']);
        Product::query()->withoutGlobalScope(SoftDeletingScope::class)->whereIn('id', $rows->pluck('product_id'))->orderBy('id')->lockForUpdate()->get(['id']);
        ProductVariant::query()->whereIn('id', $rows->pluck('product_variant_id')->filter())->orderBy('id')->lockForUpdate()->get(['id']);
        foreach ($rows as $reservation) {
            $line = $lines->get((int) $reservation->getAttribute('sales_order_item_id'));
            if (! $line instanceof SalesOrderItem) {
                throw new \LogicException('Missing cancellation source item.');
            }
            $this->assertIdentity($order, $line, $reservation);
            $quantity = $this->active($reservation);
            if (bccomp($quantity, '0', 4) > 0) {
                $key = 'cancel:'.$order->getKey().':'.$order->getAttribute('revision').':'.$reservation->getKey();
                $this->releaseOwned($actor, $order, $line, $reservation, $quantity, $reason, $key, $this->hash(['cancel', $order->getKey(), $reservation->getKey(), $quantity, $reason]));
            }
        }
    }

    /**
     * INTERNAL ONLY: Phase 5.6 must consume, stockOut, link movement, update delivered counters
     * and post its delivery in this SAME outer transaction. No standalone consume route exists.
     */
    public function consumeForDelivery(User $actor, int $deliveryItemId): SalesReservationEvent
    {
        $this->access->requireTransaction();
        $item = SalesDeliveryItem::query()->findOrFail($deliveryItemId);
        $delivery = SalesDelivery::query()->findOrFail($item->getAttribute('sales_delivery_id'));
        $order = $this->lockOrder($actor, (int) $delivery->getAttribute('sales_order_id'), 'erp.sales-deliveries.post');
        $delivery = SalesDelivery::query()->where('company_id', $order->getAttribute('company_id'))->lockForUpdate()->findOrFail($delivery->getKey());
        $item = SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->lockForUpdate()->findOrFail($deliveryItemId);
        $line = $this->line($order, (int) $item->getAttribute('sales_order_item_id'));
        $reservation = SalesStockReservation::query()->where('company_id', $order->getAttribute('company_id'))->where('sales_order_item_id', $line->getKey())->findOrFail($item->getAttribute('sales_stock_reservation_id'));
        $quantity = $this->quantity((string) $item->getAttribute('quantity'));
        $key = 'delivery-item:'.$deliveryItemId.':consume';
        $hash = $this->hash(['consume', $delivery->getKey(), $item->getKey(), $reservation->getKey(), $quantity, $item->getAttribute('warehouse_id'), $item->getAttribute('product_id'), $item->getAttribute('product_variant_id'), $item->getAttribute('unit_id')]);
        $existing = $this->replay($order, $key, $hash);
        if ($existing !== null) {
            return $existing;
        }
        $this->assertOpen($order);
        if ($delivery->getAttribute('status') !== 'draft' || $delivery->getAttribute('posted_at') !== null || $item->getAttribute('stock_movement_id') !== null
            || (int) $delivery->getAttribute('customer_id') !== (int) $order->getAttribute('customer_id') || $delivery->getAttribute('currency_code') !== $order->getAttribute('currency_code')
            || $item->getAttribute('fulfillment_type') !== 'physical' || (int) $item->getAttribute('warehouse_id') !== (int) $reservation->getAttribute('warehouse_id')
            || (int) $item->getAttribute('product_id') !== (int) $line->getAttribute('product_id') || (int) ($item->getAttribute('product_variant_id') ?? 0) !== (int) ($line->getAttribute('product_variant_id') ?? 0)
            || (int) $item->getAttribute('unit_id') !== (int) $line->getAttribute('unit_id')) {
            $this->fail('delivery', 'Delivery reservation ownership or posting state is invalid.');
        }
        $this->sources->customer((int) $order->getAttribute('company_id'), (int) $order->getAttribute('customer_id'));
        [, $product, $balance] = $this->resources($order, $line, (int) $reservation->getAttribute('warehouse_id'), true);
        $this->assertConfiguration($order, $line, $product);
        $reservation = SalesStockReservation::query()->lockForUpdate()->findOrFail($reservation->getKey());
        $this->assertIdentity($order, $line, $reservation);
        $remaining = bcsub(bcsub((string) $line->getAttribute('quantity'), (string) $line->getAttribute('delivered_quantity'), 4), (string) $line->getAttribute('cancelled_quantity'), 4);
        if (bccomp($quantity, $remaining, 4) > 0 || bccomp($quantity, $this->active($reservation), 4) > 0 || bccomp($quantity, (string) $balance->getAttribute('quantity_on_hand'), 4) > 0) {
            $this->fail('quantity', 'Delivery exceeds its owned reservation or order/stock remainder.');
        }
        $reservation->setAttribute('consumed_quantity', bcadd((string) $reservation->getAttribute('consumed_quantity'), $quantity, 4));
        $reservation->save();
        $balance->setAttribute('quantity_reserved', bcsub((string) $balance->getAttribute('quantity_reserved'), $quantity, 4));
        $balance->save();

        return $this->event($actor, $reservation, 'consume', $quantity, $key, $hash);
    }

    /** Internal read-only preflight; caller has authorized and locked its company/order. */
    public function assertForDelivery(SalesOrder $order, SalesOrderItem $line, SalesStockReservation $reservation, string $quantity): InventoryBalance
    {
        $this->access->requireTransaction();
        if ((int) $line->getAttribute('sales_order_id') !== (int) $order->getKey()) {
            $this->fail('source', 'Delivery line does not belong to its order.');
        }
        [, $product, $balance] = $this->resources($order, $line, (int) $reservation->getAttribute('warehouse_id'), true);
        $this->assertConfiguration($order, $line, $product);
        $this->assertIdentity($order, $line, $reservation);
        $remaining = bcsub(bcsub((string) $line->getAttribute('quantity'), (string) $line->getAttribute('delivered_quantity'), 4), (string) $line->getAttribute('cancelled_quantity'), 4);
        if (bccomp($quantity, '0', 4) <= 0 || bccomp($quantity, $remaining, 4) > 0 || bccomp($quantity, $this->active($reservation), 4) > 0
            || bccomp((string) $balance->getAttribute('quantity_on_hand'), (string) $balance->getAttribute('quantity_reserved'), 4) < 0) {
            $this->fail('quantity', 'Delivery exceeds its owned, physically backed reservation or order remainder.');
        }

        return $balance;
    }

    private function lockOrder(User $actor, int $id, string $permission): SalesOrder
    {
        $found = $this->orders->find($actor, $id, $permission);
        $this->access->authorize($actor, (int) $found->getAttribute('company_id'), $permission, true);

        return SalesOrder::query()->where('company_id', $found->getAttribute('company_id'))->lockForUpdate()->findOrFail($id);
    }

    private function line(SalesOrder $order, int $id): SalesOrderItem
    {
        return SalesOrderItem::query()->where('sales_order_id', $order->getKey())->lockForUpdate()->findOrFail($id);
    }

    private function assertOpen(SalesOrder $order): void
    {
        if ($order->getAttribute('confirmed_at') === null || ! in_array($order->getAttribute('status'), ['confirmed', 'partially_delivered'], true)) {
            $this->fail('status', 'Reservations require a confirmed order with an unfulfilled remainder.');
        }
    }

    /** @return array{Warehouse, Product, InventoryBalance} */
    private function resources(SalesOrder $order, SalesOrderItem $line, int $warehouseId, bool $active): array
    {
        $companyId = (int) $order->getAttribute('company_id');
        $warehouse = Warehouse::query()->withoutGlobalScope(SoftDeletingScope::class)->where('company_id', $companyId)->lockForUpdate()->findOrFail($warehouseId);
        $product = Product::query()->withoutGlobalScope(SoftDeletingScope::class)->where('company_id', $companyId)->lockForUpdate()->findOrFail($line->getAttribute('product_id'));
        if ($active && (! $warehouse->getAttribute('is_active') || $warehouse->getAttribute('deleted_at') !== null || ! $product->getAttribute('is_active') || $product->getAttribute('deleted_at') !== null || ! $product->isStockItem())) {
            $this->fail('warehouse_id', 'Allocation requires an active warehouse and tracked physical product.');
        }
        $variantId = $line->getAttribute('product_variant_id');
        if ($variantId !== null) {
            $variant = ProductVariant::query()->where('product_id', $product->getKey())->lockForUpdate()->findOrFail($variantId);
            if ($active && ! $variant->getAttribute('is_active')) {
                $this->fail('product_variant_id', 'Select an active product variant.');
            }
        }
        $balance = InventoryBalance::query()->where('warehouse_id', $warehouseId)->where('product_id', $product->getKey())->where('variant_key', (int) ($variantId ?? 0))->lockForUpdate()->first();
        if ($balance === null) {
            $this->fail('warehouse_id', 'No stock balance exists for this warehouse and exact product variant.');
        }
        if ((int) $balance->getAttribute('company_id') !== $companyId || (int) ($balance->getAttribute('product_variant_id') ?? 0) !== (int) ($variantId ?? 0)) {
            $this->fail('stock', 'Inventory balance ownership requires review.');
        }
        $this->reconcile($balance);

        return [$warehouse, $product, $balance];
    }

    private function assertConfiguration(SalesOrder $order, SalesOrderItem $line, Product $product): void
    {
        $configuration = ($order->getAttribute('terms_snapshot') ?? [])['sales_order_source']['configuration'] ?? [];
        $expected = null;
        foreach ($configuration as $entry) {
            if ((int) ($entry['source_item_id'] ?? 0) === (int) $line->getAttribute('sales_quotation_item_id')) {
                $expected = $entry;
            }
        }
        if (! is_array($expected) || (int) ($expected['product_id'] ?? 0) !== (int) $product->getKey()
            || ($expected['product_type'] ?? null) !== $product->getAttribute('product_type') || (bool) ($expected['track_inventory'] ?? false) !== (bool) $product->getAttribute('track_inventory')
            || (bool) ($expected['has_variants'] ?? false) !== (bool) $product->getAttribute('has_variants') || (int) ($expected['unit_id'] ?? 0) !== (int) $line->getAttribute('unit_id')
            || (int) $product->getAttribute('unit_id') !== (int) $line->getAttribute('unit_id') || (bool) $product->getAttribute('has_variants') !== ($line->getAttribute('product_variant_id') !== null)) {
            $this->fail('product_id', 'Product configuration changed after confirmation; review this order before allocation.');
        }
        if (! Unit::query()->where('company_id', $order->getAttribute('company_id'))->where('is_active', true)->whereKey($line->getAttribute('unit_id'))->exists()) {
            $this->fail('unit_id', 'Allocation requires an active same-company unit.');
        }
    }

    private function reconcile(InventoryBalance $balance): void
    {
        $reserved = (string) $balance->getAttribute('quantity_reserved');
        if (bccomp($reserved, '0', 4) < 0) {
            $this->fail('stock', 'Reserved balance cannot be negative.');
        }
        $sum = '0.0000';
        $rows = SalesStockReservation::query()->where('warehouse_id', $balance->getAttribute('warehouse_id'))->where('product_id', $balance->getAttribute('product_id'))->where('variant_key', $balance->getAttribute('variant_key'))->orderBy('id')->lockForUpdate()->get();
        foreach ($rows as $row) {
            $line = SalesOrderItem::query()->findOrFail($row->getAttribute('sales_order_item_id'));
            $order = SalesOrder::query()->findOrFail($line->getAttribute('sales_order_id'));
            $this->assertIdentity($order, $line, $row);
            if ((int) $row->getAttribute('company_id') !== (int) $balance->getAttribute('company_id')) {
                $this->fail('stock', 'Reservation ownership does not match its inventory balance.');
            }
            $sum = bcadd($sum, $this->active($row), 4);
        }
        if (bccomp($sum, $reserved, 4) !== 0) {
            $this->fail('stock', 'Reserved balance does not reconcile with Sales-owned reservations. Review existing reserved data before proceeding.');
        }
    }

    private function assertIdentity(SalesOrder $order, SalesOrderItem $line, SalesStockReservation $row): void
    {
        if ((int) $row->getAttribute('company_id') !== (int) $order->getAttribute('company_id') || (int) $row->getAttribute('sales_order_item_id') !== (int) $line->getKey()
            || (int) $row->getAttribute('product_id') !== (int) $line->getAttribute('product_id') || (int) ($row->getAttribute('product_variant_id') ?? 0) !== (int) ($line->getAttribute('product_variant_id') ?? 0)
            || (int) $row->getAttribute('variant_key') !== (int) ($line->getAttribute('product_variant_id') ?? 0)) {
            $this->fail('reservation_id', 'Reservation source identity is inconsistent.');
        }
    }

    private function active(SalesStockReservation $row): string
    {
        $active = bcsub(bcsub((string) $row->getAttribute('allocated_quantity'), (string) $row->getAttribute('released_quantity'), 4), (string) $row->getAttribute('consumed_quantity'), 4);
        if (bccomp($active, '0', 4) < 0) {
            $this->fail('stock', 'Reservation counters require review.');
        }

        return $active;
    }

    private function releaseOwned(User $actor, SalesOrder $order, SalesOrderItem $line, SalesStockReservation $row, string $quantity, string $reason, string $key, string $hash): void
    {
        [, , $balance] = $this->resources($order, $line, (int) $row->getAttribute('warehouse_id'), false);
        $row = SalesStockReservation::query()->lockForUpdate()->findOrFail($row->getKey());
        $this->assertIdentity($order, $line, $row);
        if (bccomp($quantity, $this->active($row), 4) > 0) {
            $this->fail('quantity', 'Release exceeds the unused owned reservation.');
        }
        $row->setAttribute('released_quantity', bcadd((string) $row->getAttribute('released_quantity'), $quantity, 4));
        $row->save();
        $balance->setAttribute('quantity_reserved', bcsub((string) $balance->getAttribute('quantity_reserved'), $quantity, 4));
        $balance->save();
        $this->event($actor, $row, 'release', $quantity, $key, $hash, $reason);
    }

    /** @param list<mixed> $parts */
    private function hash(array $parts): string
    {
        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    private function replay(SalesOrder $order, string $key, string $hash): ?SalesReservationEvent
    {
        $event = SalesReservationEvent::query()->where('company_id', $order->getAttribute('company_id'))->where('idempotency_key', $key)->lockForUpdate()->first();
        if ($event !== null && ! hash_equals((string) $event->getAttribute('payload_hash'), $hash)) {
            $this->fail('idempotency_key', 'This operation key was already used for a different request.');
        }

        return $event;
    }

    private function event(User $actor, SalesStockReservation $row, string $action, string $quantity, string $key, string $hash, ?string $reason = null): SalesReservationEvent
    {
        return SalesReservationEvent::query()->create([
            'company_id' => $row->getAttribute('company_id'), 'sales_stock_reservation_id' => $row->getKey(),
            'action' => $action, 'quantity' => $quantity, 'idempotency_key' => $key, 'payload_hash' => $hash,
            'actor_id' => $actor->getKey(), 'occurred_at' => now()->toDateTimeString(), 'reason' => $reason,
        ]);
    }

    private function bump(SalesOrder $order, User $actor): void
    {
        $order->forceFill(['revision' => (int) $order->getAttribute('revision') + 1, 'updated_by' => $actor->getKey()])->save();
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validate(array $input, bool $release): array
    {
        return Validator::make($input, [
            'revision' => ['required', 'integer', 'min:1'], 'quantity' => ['required', 'regex:/\A[0-9]{1,5}(?:\.[0-9]{1,4})?\z/'],
            'idempotency_key' => ['required', 'string', 'max:100', 'regex:/\A[a-zA-Z0-9_-]{16,100}\z/'],
            ...($release ? ['reservation_id' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u']] : ['sales_order_item_id' => ['required', 'integer', 'min:1'], 'warehouse_id' => ['required', 'integer', 'min:1']]),
        ])->validate();
    }

    private function quantity(string $quantity): string
    {
        $scaled = $this->math->scaled($quantity, 10000);
        if ($scaled <= 0) {
            $this->fail('quantity', 'Quantity must be greater than zero.');
        }

        return $this->math->format($scaled, 4);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
