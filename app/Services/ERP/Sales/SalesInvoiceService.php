<?php

declare(strict_types=1);

namespace App\Services\ERP\Sales;

use App\Models\Customer;
use App\Models\SalesDelivery;
use App\Models\SalesDeliveryItem;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\User;
use App\Services\ERP\Accounting\Source\Sales\AccountingSalesPosting;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SalesInvoiceService
{
    private const COMPONENTS = ['subtotal', 'discount_amount', 'tax_amount', 'line_total'];

    private const TOTALS = ['subtotal' => 'subtotal', 'discount_total' => 'discount_amount', 'tax_total' => 'tax_amount', 'grand_total' => 'line_total'];

    public function __construct(
        private readonly SalesCompanyAccess $access,
        private readonly SalesOrderService $orders,
        private readonly SalesDocumentGuard $guard,
        private readonly SalesInvoiceMath $amounts,
        private readonly SalesDeliveryService $deliveries,
        private readonly SalesQuotationMath $math,
        private readonly SalesDocumentNumberService $numbers,
        private readonly SalesAuditService $audit,
        private readonly SalesReturnLedger $returns,
        private readonly AccountingSalesPosting $accounting,
    ) {}

    public function find(User $actor, int $id, string $permission = 'erp.sales-invoices.view'): SalesInvoice
    {
        $invoice = SalesInvoice::query()->whereIn('company_id', $this->orders->companies($actor, $permission)->select('id'))->findOrFail($id);
        $this->access->authorize($actor, (int) $invoice->getAttribute('company_id'), $permission);

        return $invoice;
    }

    /** @param array<string, mixed> $input */
    public function save(User $actor, array $input, ?int $id = null): SalesInvoice
    {
        $data = Validator::make($input, [
            'company_id' => ['required', 'integer', 'min:1'], 'sales_order_id' => ['required', 'integer', 'min:1'],
            'document_date' => ['required', 'date_format:Y-m-d'], 'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:document_date'], 'notes' => ['nullable', 'string', 'max:5000'],
            'revision' => [$id === null ? 'nullable' : 'required', 'integer', 'min:1'],
            'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*.sales_delivery_item_id' => ['required', 'integer', 'min:1', 'distinct'], 'items.*.quantity' => ['required', 'regex:/\A[0-9]{1,5}(?:\.[0-9]{1,4})?\z/'],
        ])->validate();

        return DB::transaction(function () use ($actor, $data, $id): SalesInvoice {
            $permission = 'erp.sales-invoices.'.($id === null ? 'create' : 'edit');
            $company = $this->access->authorize($actor, (int) $data['company_id'], $permission, true);
            abort_unless($actor->can('erp.sales-orders.view') && $actor->can('erp.sales-deliveries.view'), 403);
            $found = $id === null ? null : $this->find($actor, $id, $permission);
            if ($found !== null && ((int) $found->getAttribute('company_id') !== (int) $company->getKey() || (int) $found->getAttribute('sales_order_id') !== (int) $data['sales_order_id'])) {
                $this->fail('sales_order_id', 'An invoice cannot change its company or source order.');
            }
            $this->orders->find($actor, (int) $data['sales_order_id']);
            $order = SalesOrder::query()->where('company_id', $company->getKey())->lockForUpdate()->findOrFail((int) $data['sales_order_id']);
            $invoice = $found === null ? null : SalesInvoice::query()->where('company_id', $company->getKey())->lockForUpdate()->findOrFail($id);
            if ($invoice !== null) {
                $this->draft($invoice);
                $this->guard->assertRevision($invoice, (int) $data['revision']);
                if ($data['document_date'] !== $invoice->getAttribute('document_date')->format('Y-m-d')) {
                    $this->fail('document_date', 'Invoice date cannot change after number allocation.');
                }
            }
            $this->date($order, (string) $data['document_date'], (string) $data['due_date'], (string) $company->getAttribute('timezone'));
            $prepared = $this->prepare($order, $data['items'], (string) $data['document_date']);
            $totals = $this->totals($prepared);
            $before = $invoice === null ? [] : ['revision' => $invoice->getAttribute('revision'), 'grand_total' => $invoice->getAttribute('grand_total')];
            $terms = $order->getAttribute('terms_snapshot') ?? [];
            $terms['sales_invoice_source'] = ['id' => $order->getKey(), 'number' => $order->getAttribute('number')];
            if ($invoice === null) {
                $invoice = SalesInvoice::query()->create([
                    ...$order->only(['customer_id', 'currency_code', 'customer_snapshot', 'billing_address_snapshot', 'shipping_address_snapshot']),
                    ...$totals, 'terms_snapshot' => $terms, 'company_id' => $company->getKey(), 'sales_order_id' => $order->getKey(), 'number' => $this->numbers->next((int) $company->getKey(), 'invoice', (string) $data['document_date']),
                    'document_date' => $data['document_date'], 'due_date' => $data['due_date'], 'notes' => $data['notes'] ?? null, 'status' => 'draft', 'revision' => 1, 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
                ]);
            } else {
                $invoice->forceFill([...$totals, 'due_date' => $data['due_date'], 'notes' => $data['notes'] ?? null, 'revision' => (int) $data['revision'] + 1, 'updated_by' => $actor->getKey()])->save();
                SalesInvoiceItem::query()->where('sales_invoice_id', $invoice->getKey())->delete();
            }
            foreach ($prepared as $index => $row) {
                SalesInvoiceItem::query()->create([...$row, 'sales_invoice_id' => $invoice->getKey(), 'line_number' => $index + 1]);
            }
            $this->audit->record($actor, (int) $company->getKey(), 'invoice', (int) $invoice->getKey(), $id === null ? 'create' : 'edit', $before, ['revision' => $invoice->getAttribute('revision'), 'grand_total' => $invoice->getAttribute('grand_total')]);

            return $invoice;
        }, 3);
    }

    public function issue(User $actor, int $id, int $revision, string $key): SalesInvoice
    {
        Validator::make(['revision' => $revision, 'key' => $key], ['revision' => ['required', 'integer', 'min:1'], 'key' => ['required', 'regex:/\A[a-zA-Z0-9_-]{16,100}\z/']])->validate();

        return DB::transaction(function () use ($actor, $id, $revision, $key): SalesInvoice {
            $documents = $this->locked($actor, $id, 'erp.sales-invoices.issue');
            /** @var SalesOrder $order */
            $order = $documents[0];
            /** @var SalesInvoice $invoice */
            $invoice = $documents[1];
            $this->accounting->before((int) $invoice->getAttribute('company_id'));
            /** @var EloquentCollection<int, SalesInvoiceItem> $items */
            $items = SalesInvoiceItem::query()->where('sales_invoice_id', $id)->orderBy('line_number')->lockForUpdate()->get();
            $hash = $this->fingerprint($invoice, $items->all());
            if ($invoice->getAttribute('status') === 'issued') {
                if ($invoice->getAttribute('issued_at') === null || $invoice->getAttribute('idempotency_key') !== $key || ! hash_equals((string) $invoice->getAttribute('payload_hash'), $hash)) {
                    $this->fail('idempotency_key', 'The issued invoice does not match this issue retry.');
                }

                $this->accounting->complete($actor, (int) $invoice->getAttribute('company_id'), 'sales_invoice', $id, false);

                return $invoice;
            }
            $this->draft($invoice);
            $this->guard->assertRevision($invoice, $revision);
            $company = $this->access->authorize($actor, (int) $order->getAttribute('company_id'), 'erp.sales-invoices.issue', true);
            $this->date($order, $invoice->getAttribute('document_date')->format('Y-m-d'), $invoice->getAttribute('due_date')->format('Y-m-d'), (string) $company->getAttribute('timezone'));
            if (SalesInvoice::query()->where('company_id', $company->getKey())->where('idempotency_key', $key)->where('id', '<>', $id)->exists()) {
                $this->fail('idempotency_key', 'This issue key already belongs to another invoice.');
            }
            foreach (['customer_id', 'currency_code', 'customer_snapshot', 'billing_address_snapshot', 'shipping_address_snapshot'] as $field) {
                if ($this->canonical($invoice->getAttribute($field)) !== $this->canonical($order->getAttribute($field))) {
                    $this->fail('source', 'Invoice party and currency snapshots must match the order.');
                }
            }
            $terms = $invoice->getAttribute('terms_snapshot') ?? [];
            $trace = $terms['sales_invoice_source'] ?? [];
            unset($terms['sales_invoice_source']);
            if ((int) ($trace['id'] ?? 0) !== (int) $order->getKey() || ($trace['number'] ?? null) !== $order->getAttribute('number') || $this->canonical($terms) !== $this->canonical($order->getAttribute('terms_snapshot') ?? [])) {
                $this->fail('source', 'Invoice terms or source trace changed.');
            }
            $input = $items->map(fn (SalesInvoiceItem $item): array => $item->only(['sales_delivery_item_id', 'quantity']))->all();
            $prepared = $this->prepare($order, $input, $invoice->getAttribute('document_date')->format('Y-m-d'));
            $totals = $this->totals($prepared);
            foreach ($totals as $field => $value) {
                if ($invoice->getAttribute($field) !== $value) {
                    $this->fail('totals', 'Billing progress changed. Re-save the draft to review its current rounded amounts.');
                }
            }
            foreach ($items->values() as $index => $item) {
                foreach ($prepared[$index] as $field => $value) {
                    if ($this->canonical($item->getAttribute($field)) !== $this->canonical($value)) {
                        $this->fail('items', 'Source billing progress or saved line values changed. Re-save the draft before issue.');
                    }
                }
                if (bccomp((string) $item->getAttribute('credited_quantity'), '0', 4) !== 0 || bccomp((string) $item->getAttribute('credited_amount'), '0', 2) !== 0) {
                    $this->fail('items', 'An unissued invoice cannot already have credit counters.');
                }
                $deliveryItem = SalesDeliveryItem::query()->lockForUpdate()->findOrFail($item->getAttribute('sales_delivery_item_id'));
                $orderItem = SalesOrderItem::query()->where('sales_order_id', $order->getKey())->lockForUpdate()->findOrFail($deliveryItem->getAttribute('sales_order_item_id'));
                $quantity = (string) $item->getAttribute('quantity');
                $deliveryItem->setAttribute('invoiced_quantity', bcadd((string) $deliveryItem->getAttribute('invoiced_quantity'), $quantity, 4));
                $deliveryItem->save();
                $orderItem->setAttribute('invoiced_quantity', bcadd((string) $orderItem->getAttribute('invoiced_quantity'), $quantity, 4));
                $orderItem->save();
            }
            $order->forceFill(['revision' => (int) $order->getAttribute('revision') + 1, 'updated_by' => $actor->getKey()])->save();
            $invoice->forceFill(['status' => 'issued', 'revision' => $revision + 1, 'issued_at' => now()->toDateTimeString(), 'issued_by' => $actor->getKey(), 'updated_by' => $actor->getKey(), 'idempotency_key' => $key, 'payload_hash' => $hash])->save();
            $this->audit->record($actor, (int) $company->getKey(), 'invoice', $id, 'issue', ['status' => 'draft', 'revision' => $revision], ['status' => 'issued', 'revision' => $revision + 1, 'grand_total' => $invoice->getAttribute('grand_total')]);

            $this->accounting->complete($actor, (int) $invoice->getAttribute('company_id'), 'sales_invoice', $id, true);

            return $invoice;
        }, 3);
    }

    public function cancel(User $actor, int $id, int $revision, string $reason): SalesInvoice
    {
        return DB::transaction(function () use ($actor, $id, $revision, $reason): SalesInvoice {
            $documents = $this->locked($actor, $id, 'erp.sales-invoices.cancel');
            /** @var SalesInvoice $invoice */
            $invoice = $documents[1];
            $this->draft($invoice);
            $this->guard->assertRevision($invoice, $revision);
            $invoice->forceFill(['status' => 'cancelled', 'revision' => $revision + 1, 'updated_by' => $actor->getKey()])->save();
            $this->audit->record($actor, (int) $invoice->getAttribute('company_id'), 'invoice', $id, 'cancel', ['status' => 'draft', 'revision' => $revision], ['status' => 'cancelled', 'revision' => $revision + 1], $reason);

            return $invoice;
        }, 3);
    }

    /** @return array{0: SalesOrder, 1: SalesInvoice} */
    private function locked(User $actor, int $id, string $permission): array
    {
        $found = $this->find($actor, $id, $permission);
        $this->access->authorize($actor, (int) $found->getAttribute('company_id'), $permission, true);
        $order = SalesOrder::query()->where('company_id', $found->getAttribute('company_id'))->lockForUpdate()->findOrFail($found->getAttribute('sales_order_id'));
        $invoice = SalesInvoice::query()->where('company_id', $order->getAttribute('company_id'))->where('sales_order_id', $order->getKey())->lockForUpdate()->findOrFail($id);

        return [$order, $invoice];
    }

    /** @param array<array-key, array<string, mixed>> $input
     * @return list<array<string, mixed>>
     */
    private function prepare(SalesOrder $order, array $input, string $date): array
    {
        if ($order->getAttribute('confirmed_at') === null || ! in_array($order->getAttribute('status'), ['confirmed', 'partially_delivered', 'delivered', 'closed', 'cancelled'], true) || count($input) < 1 || count($input) > 100) {
            $this->fail('source', 'Invoices require posted fulfillment of a confirmed order.');
        }
        Customer::query()->withoutGlobalScope(SoftDeletingScope::class)->where('company_id', $order->getAttribute('company_id'))->findOrFail($order->getAttribute('customer_id'));
        $lines = SalesOrderItem::query()->where('sales_order_id', $order->getKey())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $deliveries = SalesDelivery::query()->where('company_id', $order->getAttribute('company_id'))->where('sales_order_id', $order->getKey())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $deliveryItems = SalesDeliveryItem::query()->whereIn('sales_delivery_id', $deliveries->modelKeys())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $returnProgress = [];
        foreach ($deliveries as $delivery) {
            if ($delivery->getAttribute('status') === 'posted') {
                $this->deliveries->assertInvoiceSource($delivery);
                $returnProgress += $this->returns->progress($delivery);
            }
        }
        $progress = [];
        $deliveryProgress = [];
        $delivered = [];
        foreach ($lines as $line) {
            $progress[(int) $line->getKey()] = ['quantity' => '0.0000', 'subtotal' => '0.00', 'discount_amount' => '0.00', 'tax_amount' => '0.00', 'line_total' => '0.00'];
        }
        foreach ($deliveryItems as $di) {
            $header = $deliveries->get((int) $di->getAttribute('sales_delivery_id'));
            $line = $lines->get((int) $di->getAttribute('sales_order_item_id'));
            if (! $header instanceof SalesDelivery || ! $line instanceof SalesOrderItem) {
                $this->fail('source', 'Fulfillment references require review.');
            }
            if (($header->getAttribute('status') === 'posted') !== ($header->getAttribute('posted_at') !== null)) {
                $this->fail('source', 'Delivery posting state requires review.');
            }
            if ($header->getAttribute('status') === 'posted') {
                if ((int) $header->getAttribute('customer_id') !== (int) $order->getAttribute('customer_id') || $header->getAttribute('currency_code') !== $order->getAttribute('currency_code')) {
                    $this->fail('source', 'Delivery ownership or currency does not match its order.');
                }
                foreach (['product_id', 'product_variant_id', 'unit_id', 'product_snapshot', 'description'] as $field) {
                    if ($this->canonical($di->getAttribute($field)) !== $this->canonical($line->getAttribute($field))) {
                        $this->fail('source', 'Posted delivery source values differ from the order.');
                    }
                }
                $lineId = (int) $line->getKey();
                $delivered[$lineId] = bcadd($delivered[$lineId] ?? '0.0000', (string) $di->getAttribute('quantity'), 4);
            }
        }
        $invoices = SalesInvoice::query()->where('company_id', $order->getAttribute('company_id'))->where('sales_order_id', $order->getKey())->orderBy('id')->lockForUpdate()->get();
        foreach ($invoices as $invoice) {
            if (! in_array($invoice->getAttribute('status'), ['draft', 'issued', 'cancelled'], true) || (($invoice->getAttribute('status') === 'issued') !== ($invoice->getAttribute('issued_at') !== null))) {
                $this->fail('source', 'Invoice issuance state requires review.');
            }
            if ($invoice->getAttribute('status') !== 'issued') {
                continue;
            }
            if ((int) $invoice->getAttribute('customer_id') !== (int) $order->getAttribute('customer_id') || $invoice->getAttribute('currency_code') !== $order->getAttribute('currency_code')) {
                $this->fail('source', 'Issued invoice ownership or currency requires review.');
            }
            $issuedItems = SalesInvoiceItem::query()->where('sales_invoice_id', $invoice->getKey())->orderBy('line_number')->lockForUpdate()->get();
            if ($issuedItems->isEmpty()) {
                $this->fail('source', 'An issued invoice has no source lines.');
            }
            $issuedRows = [];
            foreach ($issuedItems as $item) {
                $di = $deliveryItems->get((int) $item->getAttribute('sales_delivery_item_id'));
                if (! $di instanceof SalesDeliveryItem) {
                    $this->fail('source', 'An issued line belongs to a foreign fulfillment source.');
                }
                $dh = $deliveries->get((int) $di->getAttribute('sales_delivery_id'));
                if (! $dh instanceof SalesDelivery || $dh->getAttribute('status') !== 'posted') {
                    $this->fail('source', 'Issued invoice source is not posted fulfillment.');
                }
                $lineId = (int) $di->getAttribute('sales_order_item_id');
                $line = $lines->get($lineId);
                if (! $line instanceof SalesOrderItem) {
                    $this->fail('source', 'Missing issued invoice order source.');
                }
                foreach (['product_id', 'product_variant_id', 'unit_id', 'product_snapshot', 'description', 'unit_price', 'discount_percent', 'tax_percent'] as $field) {
                    if ($this->canonical($item->getAttribute($field)) !== $this->canonical($line->getAttribute($field))) {
                        $this->fail('source', 'Issued invoice source prices or snapshots require review.');
                    }
                }
                if (bccomp((string) $item->getAttribute('discount_amount'), (string) $item->getAttribute('subtotal'), 2) > 0
                    || bccomp(bcadd(bcsub((string) $item->getAttribute('subtotal'), (string) $item->getAttribute('discount_amount'), 2), (string) $item->getAttribute('tax_amount'), 2), (string) $item->getAttribute('line_total'), 2) !== 0) {
                    $this->fail('source', 'Issued line monetary components require review.');
                }
                $quantity = $this->quantity((string) $item->getAttribute('quantity'));
                $progress[$lineId]['quantity'] = bcadd($progress[$lineId]['quantity'], $quantity, 4);
                $deliveryProgress[(int) $di->getKey()] = bcadd($deliveryProgress[(int) $di->getKey()] ?? '0.0000', $quantity, 4);
                foreach (self::COMPONENTS as $component) {
                    $progress[$lineId][$component] = bcadd($progress[$lineId][$component], (string) $item->getAttribute($component), 2);
                }
                $issuedRows[] = $item->only(self::COMPONENTS);
            }
            foreach ($this->totals($issuedRows) as $field => $amount) {
                if ($invoice->getAttribute($field) !== $amount) {
                    $this->fail('source', 'Issued header and line amounts do not reconcile.');
                }
            }
        }
        foreach ($deliveryItems as $di) {
            if (bccomp((string) $di->getAttribute('invoiced_quantity'), $deliveryProgress[(int) $di->getKey()] ?? '0.0000', 4) !== 0 || bccomp((string) $di->getAttribute('invoiced_quantity'), (string) $di->getAttribute('quantity'), 4) > 0) {
                $this->fail('source', 'Delivery invoiced counters do not match issued source quantities.');
            }
        }
        foreach ($lines as $line) {
            $id = (int) $line->getKey();
            if (bccomp((string) $line->getAttribute('delivered_quantity'), $delivered[$id] ?? '0.0000', 4) !== 0 || bccomp((string) $line->getAttribute('invoiced_quantity'), $progress[$id]['quantity'], 4) !== 0) {
                $this->fail('source', 'Order delivered/invoiced counters require reconciliation.');
            }
            if (bccomp($progress[$id]['quantity'], '0', 4) > 0) {
                foreach ($this->amounts->slice($line, '0', $progress[$id]['quantity']) as $field => $amount) {
                    if ($progress[$id][$field] !== $amount) {
                        $this->fail('source', 'Existing invoice amounts do not match the original rounded order budget.');
                    }
                }
            }
        }
        usort($input, static fn (array $a, array $b): int => (int) $a['sales_delivery_item_id'] <=> (int) $b['sales_delivery_item_id']);
        $seen = [];
        $prepared = [];
        foreach ($input as $row) {
            $id = (int) $row['sales_delivery_item_id'];
            if (isset($seen[$id])) {
                $this->fail('items', 'Select each delivery item once.');
            }
            $seen[$id] = true;
            $di = $deliveryItems->get($id);
            abort_unless($di instanceof SalesDeliveryItem, 404);
            $dh = $deliveries->get((int) $di->getAttribute('sales_delivery_id'));
            abort_unless($dh instanceof SalesDelivery, 404);
            if ($dh->getAttribute('status') !== 'posted' || $dh->getAttribute('posted_at') === null || $date < $dh->getAttribute('document_date')->format('Y-m-d')) {
                $this->fail('source', 'Invoice only posted fulfillment, dated no later than the invoice.');
            }
            $lineId = (int) $di->getAttribute('sales_order_item_id');
            $line = $lines->get($lineId);
            abort_unless($line instanceof SalesOrderItem, 404);
            $quantity = $this->quantity((string) $row['quantity']);
            $available = $returnProgress[$id]['available'];
            if (bccomp($quantity, $available, 4) > 0 || bccomp(bcadd($progress[$lineId]['quantity'], $quantity, 4), bcsub((string) $line->getAttribute('quantity'), (string) $line->getAttribute('cancelled_quantity'), 4), 4) > 0) {
                $this->fail('quantity', 'Invoice exceeds unbilled, unreturned fulfillment or the uncancelled order budget.');
            }
            $amounts = $this->amounts->slice($line, $progress[$lineId]['quantity'], $quantity);
            $progress[$lineId]['quantity'] = bcadd($progress[$lineId]['quantity'], $quantity, 4);
            $prepared[] = [...$line->only(['product_id', 'product_variant_id', 'unit_id', 'product_snapshot', 'description', 'unit_price', 'discount_percent', 'tax_percent']), ...$amounts, 'sales_delivery_item_id' => $id, 'quantity' => $quantity];
        }

        return $prepared;
    }

    /** @param array<array-key, array<string, mixed>> $rows
     * @return array<string, string>
     */
    private function totals(array $rows): array
    {
        $totals = ['subtotal' => '0.00', 'discount_total' => '0.00', 'tax_total' => '0.00', 'grand_total' => '0.00'];
        foreach ($rows as $row) {
            foreach (self::TOTALS as $header => $field) {
                $totals[$header] = bcadd($totals[$header], (string) $row[$field], 2);
            }
        }

        return $totals;
    }

    private function draft(SalesInvoice $invoice): void
    {
        if ($invoice->getAttribute('status') !== 'draft' || $invoice->getAttribute('issued_at') !== null || $invoice->getAttribute('idempotency_key') !== null) {
            $this->fail('status', 'Only an unissued invoice draft can be edited, cancelled or issued.');
        }
    }

    private function date(SalesOrder $order, string $date, string $due, string $timezone): void
    {
        if ($date < $order->getAttribute('document_date')->format('Y-m-d') || $date > now($timezone)->toDateString() || $due < $date) {
            $this->fail('document_date', 'Review invoice and due dates against the order and company-local date.');
        }
    }

    private function quantity(string $value): string
    {
        $quantity = $this->math->scaled($value, 10000);
        if ($quantity <= 0) {
            $this->fail('quantity', 'Invoice quantity must be positive.');
        }

        return $this->math->format($quantity, 4);
    }

    /** @param array<array-key, SalesInvoiceItem> $items */
    private function fingerprint(SalesInvoice $invoice, array $items): string
    {
        $rows = [];
        foreach ($items as $item) {
            $rows[] = $item->only(['id', 'line_number', 'sales_delivery_item_id', 'product_id', 'product_variant_id', 'unit_id', 'product_snapshot', 'description', 'quantity', 'unit_price', 'discount_percent', 'tax_percent', ...self::COMPONENTS]);
        }

        return hash('sha256', json_encode($this->canonical(['header' => [...$invoice->only(['id', 'number', 'company_id', 'sales_order_id', 'customer_id', 'currency_code', 'customer_snapshot', 'billing_address_snapshot', 'shipping_address_snapshot', 'terms_snapshot', 'notes', ...array_keys(self::TOTALS)]), 'document_date' => $invoice->getAttribute('document_date')->format('Y-m-d'), 'due_date' => $invoice->getAttribute('due_date')->format('Y-m-d')], 'items' => $rows]), JSON_THROW_ON_ERROR));
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
