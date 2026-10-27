<?php

declare(strict_types=1);

namespace App\Services\ERP\Sales;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesDelivery;
use App\Models\SalesDeliveryItem;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ERP\Accounting\Source\Purchase\AccountingInventoryContext;
use App\Services\ERP\Accounting\Source\Sales\AccountingSalesCorrectionPosting;
use App\Services\Inventory\InventoryService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class SalesReturnService
{
    public function __construct(private readonly SalesCompanyAccess $access, private readonly SalesOrderService $orders, private readonly SalesDeliveryService $deliveries, private readonly SalesReturnLedger $ledger, private readonly SalesDocumentGuard $guard, private readonly SalesDocumentNumberService $numbers, private readonly SalesAuditService $audit, private readonly InventoryService $inventory, private readonly AccountingSalesCorrectionPosting $finance) {}

    public function find(User $actor, int $id, string $permission = 'erp.sales-returns.view'): SalesReturn
    {
        $row = SalesReturn::query()->whereIn('company_id', $this->orders->companies($actor, $permission)->select('id'))->findOrFail($id);
        $this->access->authorize($actor, (int) $row->getAttribute('company_id'), $permission);

        return $row;
    }

    /** @param array<string, mixed> $input */
    public function save(User $actor, array $input, ?int $id = null): SalesReturn
    {
        $data = Validator::make($input, ['company_id' => ['required', 'integer', 'min:1'], 'sales_delivery_id' => ['required', 'integer', 'min:1'], 'document_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u'], 'notes' => ['nullable', 'string', 'max:5000'], 'revision' => [$id === null ? 'nullable' : 'required', 'integer', 'min:1'], 'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*.sales_delivery_item_id' => ['required', 'integer', 'min:1', 'distinct'], 'items.*.quantity' => ['required', 'regex:/\A[0-9]{1,5}(?:\.[0-9]{1,4})?\z/']])->validate();

        return DB::transaction(function () use ($actor, $data, $id): SalesReturn {
            $permission = 'erp.sales-returns.'.($id === null ? 'create' : 'edit');
            $company = $this->access->authorize($actor, (int) $data['company_id'], $permission, true);
            abort_unless($actor->can('erp.sales-deliveries.view'), 403);
            $delivery = SalesDelivery::query()->where('company_id', $company->getKey())->lockForUpdate()->findOrFail((int) $data['sales_delivery_id']);
            $this->deliveries->assertInvoiceSource($delivery);
            $progress = $this->ledger->progress($delivery);
            $row = $id === null ? null : $this->find($actor, $id, $permission);
            if ($row !== null) {
                $row = SalesReturn::query()->where('company_id', $company->getKey())->where('sales_delivery_id', $delivery->getKey())->lockForUpdate()->findOrFail($id);
                $this->draft($row);
                $this->guard->assertRevision($row, (int) $data['revision']);
                if ($row->getAttribute('document_date')->format('Y-m-d') !== $data['document_date']) {
                    $this->ledger->fail('Numbered return date cannot change.');
                }
            }
            $this->date($delivery, (string) $data['document_date'], (string) $company->getAttribute('timezone'));
            $items = SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $prepared = [];
            foreach ($data['items'] as $inputRow) {
                $di = $items->get((int) $inputRow['sales_delivery_item_id']);
                abort_unless($di instanceof SalesDeliveryItem, 404);
                $qty = $this->ledger->quantity((string) $inputRow['quantity'], true);
                if ($di->getAttribute('fulfillment_type') !== 'physical' || bccomp($qty, bcsub((string) $di->getAttribute('quantity'), $progress[(int) $di->getKey()]['accepted'], 4), 4) > 0) {
                    $this->ledger->fail('Request only remaining physical quantities from this posted delivery.');
                }
                $prepared[] = [...$di->only(['product_id', 'product_variant_id', 'unit_id', 'product_snapshot', 'description', 'warehouse_id', 'unit_cost']), 'sales_delivery_item_id' => $di->getKey(), 'quantity' => $qty];
            }
            usort($prepared, static fn (array $a, array $b): int => $a['sales_delivery_item_id'] <=> $b['sales_delivery_item_id']);
            $before = $row === null ? [] : ['revision' => $row->getAttribute('revision')];
            $terms = $delivery->getAttribute('terms_snapshot') ?? [];
            $terms['return_source'] = ['id' => $delivery->getKey(), 'number' => $delivery->getAttribute('number')];
            $terms['return_reason'] = trim((string) $data['reason']);
            if ($row === null) {
                $row = SalesReturn::query()->create([...$delivery->only(['company_id', 'customer_id', 'currency_code', 'customer_snapshot', 'billing_address_snapshot', 'shipping_address_snapshot']), 'terms_snapshot' => $terms, 'sales_delivery_id' => $delivery->getKey(), 'document_date' => $data['document_date'], 'number' => $this->numbers->next((int) $company->getKey(), 'return', (string) $data['document_date']), 'status' => 'draft', 'revision' => 1, 'notes' => $data['notes'] ?? null, 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey()]);
            } else {
                $row->forceFill(['terms_snapshot' => $terms, 'notes' => $data['notes'] ?? null, 'revision' => (int) $data['revision'] + 1, 'updated_by' => $actor->getKey()])->save();
                SalesReturnItem::query()->where('sales_return_id', $id)->delete();
            }
            foreach ($prepared as $index => $line) {
                SalesReturnItem::query()->create([...$line, 'sales_return_id' => $row->getKey(), 'line_number' => $index + 1]);
            }
            $this->audit->record($actor, (int) $company->getKey(), 'return', (int) $row->getKey(), $id === null ? 'create' : 'edit', $before, ['revision' => $row->getAttribute('revision'), 'sales_delivery_id' => $delivery->getKey()]);

            return $row;
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function inspect(User $actor, int $id, array $input): SalesReturn
    {
        $data = Validator::make($input, ['revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u'], 'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*.id' => ['required', 'integer', 'min:1', 'distinct'], 'items.*.accepted_quantity' => ['required', 'regex:/\A[0-9]{1,5}(?:\.[0-9]{1,4})?\z/'], 'items.*.restocked_quantity' => ['required', 'regex:/\A[0-9]{1,5}(?:\.[0-9]{1,4})?\z/']])->validate();

        return DB::transaction(function () use ($actor, $id, $data): SalesReturn {
            /** @var array{0: SalesDelivery, 1: SalesReturn} $locked */
            $locked = $this->locked($actor, $id, 'erp.sales-returns.inspect');
            $delivery = $locked[0];
            $row = $locked[1];
            if (! in_array($row->getAttribute('status'), ['draft', 'inspected'], true) || $row->getAttribute('posted_at') !== null) {
                $this->ledger->fail('Only unposted returns can be inspected.');
            }
            $this->guard->assertRevision($row, (int) $data['revision']);
            $this->deliveries->assertInvoiceSource($delivery);
            $this->ledger->progress($delivery);
            $items = SalesReturnItem::query()->where('sales_return_id', $id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($items->count() !== count($data['items'])) {
                $this->ledger->fail('Inspect every requested line exactly once.');
            }
            foreach ($data['items'] as $line) {
                $item = $items->get((int) $line['id']);
                abort_unless($item instanceof SalesReturnItem, 404);
                $accepted = $this->ledger->quantity((string) $line['accepted_quantity']);
                $restocked = $this->ledger->quantity((string) $line['restocked_quantity']);
                if (bccomp($accepted, (string) $item->getAttribute('quantity'), 4) > 0 || bccomp($restocked, $accepted, 4) > 0) {
                    $this->ledger->fail('Restockable quantity cannot exceed accepted quantity; accepted cannot exceed requested.');
                }
                $item->forceFill(['accepted_quantity' => $accepted, 'restocked_quantity' => $restocked, 'disposition' => $this->ledger->disposition($accepted, $restocked)])->save();
            }
            $row->forceFill(['status' => 'inspected', 'revision' => (int) $data['revision'] + 1, 'inspected_by' => $actor->getKey(), 'inspected_at' => now()->toDateTimeString(), 'updated_by' => $actor->getKey(), 'terms_snapshot' => [...($row->getAttribute('terms_snapshot') ?? []), 'inspection_reason' => trim((string) $data['reason'])]])->save();
            $this->audit->record($actor, (int) $row->getAttribute('company_id'), 'return', $id, 'inspect', ['revision' => $data['revision']], ['revision' => $row->getAttribute('revision'), 'items' => $data['items']], trim((string) $data['reason']));

            return $row;
        }, 3);
    }

    public function postReturn(User $actor, int $id, int $revision, string $key): SalesReturn
    {
        Validator::make(['revision' => $revision, 'key' => $key], ['revision' => ['required', 'integer', 'min:1'], 'key' => ['required', 'regex:/\A[a-zA-Z0-9_-]{16,100}\z/']])->validate();

        return DB::transaction(fn (): SalesReturn => $this->postLocked($actor, $id, $revision, $key), 3);
    }

    /** Runs inside postReturn's caller-owned transaction. */
    private function postLocked(User $actor, int $id, int $revision, string $key): SalesReturn
    {
        /** @var array{0: SalesDelivery, 1: SalesReturn} $locked */
        $locked = $this->locked($actor, $id, 'erp.sales-returns.post');
        $delivery = $locked[0];
        $row = $locked[1];
        /** @var Collection<int, SalesReturnItem> $items */
        $items = SalesReturnItem::query()->where('sales_return_id', $id)->orderBy('line_number')->lockForUpdate()->get();
        $hash = $this->fingerprint($row, $items->all());
        if ($row->getAttribute('status') === 'posted') {
            if ($row->getAttribute('idempotency_key') !== $key || ! hash_equals((string) $row->getAttribute('payload_hash'), $hash)) {
                $this->ledger->fail('Posted return retry differs from the original operation.');
            }
            $this->deliveries->assertInvoiceSource($delivery);
            $this->ledger->progress($delivery);

            $this->finance->complete($actor, (int) $row->getAttribute('company_id'), 'sales_return', $id, 'posted', false);

            return $row;
        }
        if ($row->getAttribute('status') !== 'inspected' || $row->getAttribute('inspected_at') === null || $row->getAttribute('posted_at') !== null || $items->isEmpty()) {
            $this->ledger->fail('Inspect the complete return before posting.');
        }
        $this->guard->assertRevision($row, $revision);
        $company = $this->access->authorize($actor, (int) $row->getAttribute('company_id'), 'erp.sales-returns.post', true);
        $this->date($delivery, $row->getAttribute('document_date')->format('Y-m-d'), (string) $company->getAttribute('timezone'));
        $this->deliveries->assertInvoiceSource($delivery);
        $progress = $this->ledger->progress($delivery);
        if (SalesReturn::query()->where('company_id', $company->getKey())->where('idempotency_key', $key)->exists()) {
            $this->ledger->fail('Operation key already belongs to another return.');
        }
        foreach (['company_id', 'customer_id', 'currency_code', 'customer_snapshot', 'billing_address_snapshot', 'shipping_address_snapshot'] as $field) {
            if ($this->canonical($row->getAttribute($field)) !== $this->canonical($delivery->getAttribute($field))) {
                $this->ledger->fail('Return party or currency snapshots differ from the posted delivery.');
            }
        }
        $terms = $row->getAttribute('terms_snapshot') ?? [];
        $source = $terms['return_source'] ?? [];
        if ((int) ($source['id'] ?? 0) !== (int) $delivery->getKey() || ($source['number'] ?? null) !== $delivery->getAttribute('number') || trim((string) ($terms['return_reason'] ?? '')) === '' || trim((string) ($terms['inspection_reason'] ?? '')) === '') {
            $this->ledger->fail('Return source and inspection trace require review.');
        }
        $seen = [];
        foreach ($items as $item) {
            $di = SalesDeliveryItem::query()->where('sales_delivery_id', $delivery->getKey())->lockForUpdate()->findOrFail($item->getAttribute('sales_delivery_item_id'));
            if (isset($seen[(int) $di->getKey()]) || $di->getAttribute('fulfillment_type') !== 'physical') {
                $this->ledger->fail('Only unique physical delivery lines can be returned.');
            } $seen[(int) $di->getKey()] = true;
            foreach (['product_id', 'product_variant_id', 'unit_id', 'warehouse_id', 'product_snapshot', 'description', 'unit_cost'] as $field) {
                if ($this->canonical($item->getAttribute($field)) !== $this->canonical($di->getAttribute($field))) {
                    $this->ledger->fail('Return source identity, original warehouse or cost changed.');
                }
            }
            $this->postItem($actor, $company, $row, $item, $di, $progress[(int) $di->getKey()]);
        }
        $row->forceFill(['status' => 'posted', 'revision' => $revision + 1, 'posted_by' => $actor->getKey(), 'posted_at' => now()->toDateTimeString(), 'updated_by' => $actor->getKey(), 'idempotency_key' => $key, 'payload_hash' => $hash])->save();
        $this->ledger->progress($delivery);
        // Preserve original fulfillment; invalidate stale open document screens by advancing order revision.
        $order = SalesOrder::query()->where('company_id', $company->getKey())->lockForUpdate()->findOrFail($delivery->getAttribute('sales_order_id'));
        $order->forceFill(['revision' => (int) $order->getAttribute('revision') + 1, 'updated_by' => $actor->getKey()])->save();
        $this->audit->record($actor, (int) $company->getKey(), 'return', $id, 'post', ['status' => 'inspected', 'revision' => $revision], ['status' => 'posted', 'revision' => $revision + 1, 'movement_ids' => SalesReturnItem::query()->where('sales_return_id', $id)->pluck('stock_movement_id')->filter()->values()->all()]);

        $this->finance->complete($actor, (int) $row->getAttribute('company_id'), 'sales_return', $id, 'posted', true);

        return $row;
    }

    /** @param array{accepted: string, unbilled: string, credit_review: string, available: string} $progress */
    private function postItem(User $actor, Company $company, SalesReturn $row, SalesReturnItem $item, SalesDeliveryItem $di, array $progress): void
    {
        $requested = $this->ledger->quantity((string) $item->getAttribute('quantity'), true);
        $accepted = $this->ledger->quantity((string) $item->getAttribute('accepted_quantity'));
        $restocked = $this->ledger->quantity((string) $item->getAttribute('restocked_quantity'));
        if (bccomp($accepted, $requested, 4) > 0 || bccomp($restocked, $accepted, 4) > 0 || bccomp(bcadd($progress['accepted'], $accepted, 4), (string) $di->getAttribute('quantity'), 4) > 0 || $item->getAttribute('stock_movement_id') !== null || $item->getAttribute('disposition') !== $this->ledger->disposition($accepted, $restocked)) {
            $this->ledger->fail('Inspection quantities, disposition or cumulative accepted returns require review.');
        }
        $unbilled = bccomp($accepted, $progress['available'], 4) > 0 ? $progress['available'] : $accepted;
        if (bccomp($restocked, '0', 4) > 0) {
            $warehouse = Warehouse::query()->where('company_id', $company->getKey())->where('is_active', true)->findOrFail($di->getAttribute('warehouse_id'));
            $product = Product::query()->where('company_id', $company->getKey())->where('is_active', true)->findOrFail($di->getAttribute('product_id'));
            $variantId = $di->getAttribute('product_variant_id');
            $variant = $variantId === null ? null : ProductVariant::query()->where('product_id', $product->getKey())->where('is_active', true)->findOrFail($variantId);
            $movement = AccountingInventoryContext::run((int) $company->getKey(), 'sales_return', (int) $row->getKey(), (int) $actor->getKey(), fn () => $this->inventory->stockIn($warehouse, $product, $restocked, StockMovement::TYPE_SALE_RETURN, unitCost: (string) $di->getAttribute('unit_cost'), variant: $variant, referenceType: SalesReturn::class, referenceId: (int) $row->getKey(), referenceNumber: (string) $row->getAttribute('number'), createdBy: (int) $actor->getKey(), notes: 'Accepted customer return'));
            $item->setAttribute('stock_movement_id', $movement->getKey());
        }
        $item->forceFill(['unbilled_quantity' => $unbilled, 'credit_review_quantity' => bcsub($accepted, $unbilled, 4)])->save();
        $di->setAttribute('returned_quantity', bcadd((string) $di->getAttribute('returned_quantity'), $accepted, 4));
        $di->save();
    }

    public function cancel(User $actor, int $id, int $revision, string $reason): SalesReturn
    {
        return DB::transaction(function () use ($actor, $id, $revision, $reason): SalesReturn {
            /** @var array{0: SalesDelivery, 1: SalesReturn} $locked */
            $locked = $this->locked($actor, $id, 'erp.sales-returns.cancel');
            $row = $locked[1];
            if (! in_array($row->getAttribute('status'), ['draft', 'inspected'], true) || $row->getAttribute('posted_at') !== null) {
                $this->ledger->fail('Only unposted returns can be cancelled.');
            }
            $this->guard->assertRevision($row, $revision);
            $row->forceFill(['status' => 'cancelled', 'revision' => $revision + 1, 'updated_by' => $actor->getKey()])->save();
            $this->audit->record($actor, (int) $row->getAttribute('company_id'), 'return', $id, 'cancel', [], ['status' => 'cancelled', 'revision' => $revision + 1], $reason);

            return $row;
        }, 3);
    }

    /** @return array<int, array{accepted: string, unbilled: string, credit_review: string, available: string}> */
    public function sourceProgress(User $actor, int $deliveryId, string $permission = 'erp.sales-returns.view'): array
    {
        return DB::transaction(function () use ($actor, $deliveryId, $permission): array {
            $delivery = SalesDelivery::query()->whereIn('company_id', $this->orders->companies($actor, $permission)->select('id'))->findOrFail($deliveryId);
            $company = $this->access->authorize($actor, (int) $delivery->getAttribute('company_id'), $permission);
            Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
            $delivery = SalesDelivery::query()->lockForUpdate()->findOrFail($deliveryId);
            $this->deliveries->assertInvoiceSource($delivery);

            return $this->ledger->progress($delivery);
        }, 3);
    }

    /** @return array{0: SalesDelivery, 1: SalesReturn} */
    private function locked(User $actor, int $id, string $permission): array
    {
        $found = $this->find($actor, $id, $permission);
        $this->access->authorize($actor, (int) $found->getAttribute('company_id'), $permission, true);
        $delivery = SalesDelivery::query()->where('company_id', $found->getAttribute('company_id'))->lockForUpdate()->findOrFail($found->getAttribute('sales_delivery_id'));

        return [$delivery, SalesReturn::query()->where('company_id', $delivery->getAttribute('company_id'))->lockForUpdate()->findOrFail($id)];
    }

    private function draft(SalesReturn $row): void
    {
        if ($row->getAttribute('status') !== 'draft' || $row->getAttribute('posted_at') !== null || $row->getAttribute('inspected_at') !== null || $row->getAttribute('idempotency_key') !== null) {
            $this->ledger->fail('Only an uninspected draft can be edited.');
        }
    }

    private function date(SalesDelivery $delivery, string $date, string $timezone): void
    {
        if ($date < $delivery->getAttribute('document_date')->format('Y-m-d') || $date > now($timezone)->toDateString()) {
            $this->ledger->fail('Return date must be between delivery date and company-local today.');
        }
    }

    /** @param array<array-key, SalesReturnItem> $items */
    private function fingerprint(SalesReturn $row, array $items): string
    {
        return hash('sha256', json_encode($this->canonical(['header' => [...$row->only(['id', 'company_id', 'customer_id', 'sales_delivery_id', 'number', 'currency_code', 'customer_snapshot', 'billing_address_snapshot', 'shipping_address_snapshot', 'terms_snapshot', 'notes']), 'document_date' => $row->getAttribute('document_date')->format('Y-m-d')], 'items' => array_map(static fn (SalesReturnItem $item): array => $item->only(['id', 'line_number', 'sales_delivery_item_id', 'product_id', 'product_variant_id', 'unit_id', 'product_snapshot', 'description', 'warehouse_id', 'unit_cost', 'quantity', 'accepted_quantity', 'restocked_quantity', 'disposition']), $items)]), JSON_THROW_ON_ERROR));
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
}
