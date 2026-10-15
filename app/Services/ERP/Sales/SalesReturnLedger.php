<?php

declare(strict_types=1);

namespace App\Services\ERP\Sales;

use App\Models\SalesDelivery;
use App\Models\SalesDeliveryItem;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Internal reader. Caller authorizes and locks the company in the same transaction. */
final class SalesReturnLedger
{
    public function quantity(string $value, bool $positive = false): string
    {
        if (preg_match('/\A[0-9]{1,5}(?:\.[0-9]{1,4})?\z/', $value) !== 1 || ($positive && bccomp($value, '0', 4) <= 0)) {
            $this->fail('Return quantities require nonnegative decimals with at most four decimal places; requested quantities must be positive.');
        }

        return bcadd($value, '0', 4);
    }

    /** @return array<int, array{accepted: string, unbilled: string, credit_review: string, available: string}> */
    public function progress(SalesDelivery $delivery): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Return ledger requires an authorized transaction and company lock.');
        }
        if ($delivery->getAttribute('status') !== 'posted' || $delivery->getAttribute('posted_at') === null) {
            $this->fail('Returns require posted delivery fulfillment.');
        }
        $source = SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $progress = [];
        foreach ($source as $item) {
            $progress[(int) $item->getKey()] = ['accepted' => '0.0000', 'unbilled' => '0.0000', 'credit_review' => '0.0000', 'available' => '0.0000'];
        }
        $headers = SalesReturn::query()->where('sales_delivery_id', $delivery->getKey())->orderBy('id')->lockForUpdate()->get();
        foreach ($headers as $header) {
            if ((int) $header->getAttribute('company_id') !== (int) $delivery->getAttribute('company_id') || (int) $header->getAttribute('customer_id') !== (int) $delivery->getAttribute('customer_id') || $header->getAttribute('currency_code') !== $delivery->getAttribute('currency_code')
                || ! in_array($header->getAttribute('status'), ['draft', 'inspected', 'posted', 'cancelled'], true) || (($header->getAttribute('status') === 'posted') !== ($header->getAttribute('posted_at') !== null))) {
                $this->fail('Return ownership or posting metadata requires review.');
            }
            if ($header->getAttribute('status') !== 'posted') {
                continue;
            }
            if ($header->getAttribute('inspected_at') === null || $header->getAttribute('idempotency_key') === null || $header->getAttribute('payload_hash') === null || $header->getAttribute('document_date')->format('Y-m-d') < $delivery->getAttribute('document_date')->format('Y-m-d')) {
                $this->fail('Posted return inspection, date or operation metadata requires review.');
            }
            $items = SalesReturnItem::query()->where('sales_return_id', $header->getKey())->orderBy('id')->lockForUpdate()->get();
            if ($items->isEmpty()) {
                $this->fail('Posted return has no lines.');
            }
            $seen = [];
            foreach ($items as $row) {
                $id = (int) $row->getAttribute('sales_delivery_item_id');
                $di = $source->get($id);
                if (! $di instanceof SalesDeliveryItem || isset($seen[$id]) || $di->getAttribute('fulfillment_type') !== 'physical') {
                    $this->fail('Return line requires a unique physical source in its delivery.');
                }
                $seen[$id] = true;
                foreach (['product_id', 'product_variant_id', 'unit_id', 'warehouse_id', 'product_snapshot', 'description', 'unit_cost'] as $field) {
                    if ($row->getAttribute($field) !== $di->getAttribute($field)) {
                        $this->fail('Return source snapshot, warehouse or original cost requires review.');
                    }
                }
                $requested = $this->quantity((string) $row->getAttribute('quantity'), true);
                $accepted = $this->quantity((string) $row->getAttribute('accepted_quantity'));
                $restocked = $this->quantity((string) $row->getAttribute('restocked_quantity'));
                $unbilled = $this->quantity((string) $row->getAttribute('unbilled_quantity'));
                $review = $this->quantity((string) $row->getAttribute('credit_review_quantity'));
                if (bccomp($accepted, $requested, 4) > 0 || bccomp($restocked, $accepted, 4) > 0 || bccomp(bcadd($unbilled, $review, 4), $accepted, 4) !== 0 || $row->getAttribute('disposition') !== $this->disposition($accepted, $restocked)) {
                    $this->fail('Posted return inspection or billing split requires review.');
                }
                $movementId = $row->getAttribute('stock_movement_id');
                if (bccomp($restocked, '0', 4) > 0) {
                    $m = StockMovement::query()->where('company_id', $delivery->getAttribute('company_id'))->find($movementId);
                    if (! $m instanceof StockMovement || $m->getAttribute('direction') !== StockMovement::DIRECTION_IN || $m->getAttribute('movement_type') !== StockMovement::TYPE_SALE_RETURN || $m->getAttribute('reference_type') !== SalesReturn::class || (int) $m->getAttribute('reference_id') !== (int) $header->getKey() || $m->getAttribute('reference_number') !== $header->getAttribute('number')
                        || (int) $m->getAttribute('product_id') !== (int) $row->getAttribute('product_id') || (int) ($m->getAttribute('product_variant_id') ?? 0) !== (int) ($row->getAttribute('product_variant_id') ?? 0) || (int) $m->getAttribute('warehouse_id') !== (int) $row->getAttribute('warehouse_id') || bccomp((string) $m->getAttribute('quantity'), $restocked, 4) !== 0 || bccomp((string) $m->getAttribute('unit_cost'), (string) $row->getAttribute('unit_cost'), 4) !== 0 || bccomp((string) $m->getAttribute('total_cost'), bcmul($restocked, (string) $row->getAttribute('unit_cost'), 4), 4) !== 0) {
                        $this->fail('Restock movement identity, quantity or original cost requires review.');
                    }
                } elseif ($movementId !== null) {
                    $this->fail('Non-restocked items cannot have stock movements.');
                }
                foreach (['accepted' => $accepted, 'unbilled' => $unbilled, 'credit_review' => $review] as $key => $value) {
                    $progress[$id][$key] = bcadd($progress[$id][$key], $value, 4);
                }
            }
        }
        foreach ($source as $di) {
            $id = (int) $di->getKey();
            $p = $progress[$id];
            if (bccomp($p['accepted'], (string) $di->getAttribute('returned_quantity'), 4) !== 0 || bccomp($p['accepted'], (string) $di->getAttribute('quantity'), 4) > 0 || bccomp($p['credit_review'], (string) $di->getAttribute('invoiced_quantity'), 4) > 0) {
                $this->fail('Delivery returned counters do not reconcile to posted accepted returns.');
            }
            $available = bcsub(bcsub((string) $di->getAttribute('quantity'), $p['unbilled'], 4), (string) $di->getAttribute('invoiced_quantity'), 4);
            if (bccomp($available, '0', 4) < 0) {
                $this->fail('Invoice quantities overlap unbilled returns.');
            }
            $progress[$id]['available'] = $available;
        }

        return $progress;
    }

    public function disposition(string $accepted, string $restocked): string
    {
        return bccomp($accepted, '0', 4) === 0 ? 'rejected' : (bccomp($restocked, $accepted, 4) === 0 ? 'restockable' : (bccomp($restocked, '0', 4) === 0 ? 'damaged' : 'mixed'));
    }

    public function fail(string $message): never
    {
        throw ValidationException::withMessages(['returns' => $message]);
    }
}
