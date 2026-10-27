<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Sales;

use App\Models\Accounting\AccountingAccountMapping;
use App\Models\Accounting\AccountingSalesCostSlice;
use App\Models\Accounting\AccountingSalesEvent;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\JournalEntry;
use App\Models\Company;
use App\Models\SalesDelivery;
use App\Models\SalesDeliveryItem;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingDecimal;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\Source\AccountingAutomaticEvidence;
use App\Services\ERP\Accounting\Source\AccountingPreparedSourceEvent;
use App\Services\ERP\Accounting\Source\AccountingSourceEffectPlan;
use App\Services\ERP\Sales\SalesCompanyAccess;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** sales.v1: original dispatch valuation and invoice commercial/cost recognition. */
final class AccountingSalesEvidence
{
    public function __construct(private readonly SalesCompanyAccess $access, private readonly AccountingDecimal $decimal, private readonly AccountingCanonicalPayload $canonical, private readonly AccountingMappingService $mappings, private readonly AccountingAutomaticEvidence $journals) {}

    public function capture(User $actor, int $company, string $kind, int $id): void
    {
        $this->access->requireTransaction();
        if (in_array($kind, ['sales_return', 'sales_credit_note', 'customer_credit_allocation'], true)) {
            app(AccountingSalesCorrectionEvidence::class)->capture($actor, $company, $kind, $id);

            return;
        }
        $permission = match ($kind) {
            'sales_delivery' => 'erp.sales-deliveries.post', 'sales_invoice' => 'erp.sales-invoices.issue', default => throw new \LogicException('Unsupported Sales admission.')
        };
        $ownedCompany = $this->access->authorize($actor, $company, $permission, true);
        $projection = $this->projection($company, $kind, $id);
        $references = [];
        $slices = [];
        if ($kind === 'sales_delivery') {
            $effects = ['valuation' => $this->deliveryPlan($projection)];
        } else {
            foreach ($projection['deliveries'] as $deliveryId => $fingerprint) {
                $parent = $this->recognized($company, 'sales_delivery', (int) $deliveryId);
                if ($this->canonical->hash($parent['projection']) !== $fingerprint) {
                    $this->fail('Delivery evidence changed during invoice capture.');
                }
                $references[] = ['source_kind' => 'sales_delivery', 'source_id' => (int) $deliveryId, 'origin_date' => $parent['projection']['date']];
            }
            [$cost, $slices] = $this->costPlan($company, $id, $projection);
            if ($cost['lines'] !== []) {
                $this->sameDispatchAccount($ownedCompany, $projection, $slices);
            }
            $effects = ['commercial' => $this->commercialPlan($projection), 'cost' => $cost];
        }
        $payload = ['adapter_contract' => 'sales.v1', 'projection' => $projection, 'references' => $references, 'effects' => $effects, 'slices' => $slices];
        $event = AccountingSalesEvent::query()->create(['company_id' => $company, 'source_kind' => $kind, 'source_id' => $id, 'payload' => $payload, 'payload_hash' => $this->canonical->hash($payload), 'created_by' => $actor->getKey()]);
        foreach ($slices as $slice) {
            AccountingSalesCostSlice::query()->create(['company_id' => $company, 'sales_event_id' => $event->getKey(), ...$slice]);
        }
    }

    public function prepare(int $company, string $kind, int $id): AccountingPreparedSourceEvent
    {
        $event = AccountingSalesEvent::query()->where('company_id', $company)->where('source_kind', $kind)->where('source_id', $id)->first();
        if ($event === null) {
            $this->fail('Original Sales financial admission is missing. Reconcile this source instead of backfilling it.');
        }
        $payload = $this->checked($event);
        $projection = $payload['projection'];
        if ($this->canonical->hash($projection) !== $this->canonical->hash($this->projection($company, $kind, $id))) {
            $this->fail('Persisted Sales source or linked movement facts changed after recognition.');
        }
        $effects = [];
        foreach ($payload['effects'] as $key => $effect) {
            $effects[] = new AccountingSourceEffectPlan($key, $effect['lines'], [...$effect['proof'], 'evidence_hash' => (string) $event->getAttribute('payload_hash')], $effect['reason']);
        }

        return new AccountingPreparedSourceEvent($company, $kind, $id, $this->eventKind($kind, $projection), 'original', $projection['currency'], $projection['date'], $projection['date'], $projection['date'], ['source_number' => $projection['number'], 'currency_code' => $projection['currency'], 'evidence_hash' => (string) $event->getAttribute('payload_hash')], $effects, $payload['references']);
    }

    /** Internal dependency read, under caller's authorized company transaction.
     * @return array<string, mixed>
     */
    public function recognized(int $company, string $kind, int $id): array
    {
        $this->access->requireTransaction();
        $event = AccountingSalesEvent::query()->where('company_id', $company)->where('source_kind', $kind)->where('source_id', $id)->first();
        if ($event === null) {
            $this->fail('An unrecognized or legacy Sales dependency requires verified coverage; no original cost can be assumed.');
        }
        $payload = $this->checked($event);
        if ($this->canonical->hash($payload['projection']) !== $this->canonical->hash($this->projection($company, $kind, $id))) {
            $this->fail('Recognized Sales dependency facts changed.');
        }
        $storedSlices = AccountingSalesCostSlice::query()->where('company_id', $company)->where('sales_event_id', $event->getKey())->get()->keyBy('source_line_id');
        if ($storedSlices->count() !== count($payload['slices'])) {
            $this->fail('Recognized source has an incomplete cost-slice bundle.');
        }
        foreach ($payload['slices'] as $slice) {
            $stored = $storedSlices->get($slice['source_line_id']);
            if ($stored === null) {
                $this->fail('Recognized source cost slice is missing.');
            }
            foreach ($slice as $field => $value) {
                if ((string) $stored->getAttribute($field) !== (string) $value) {
                    $this->fail('Recognized source cost slice changed.');
                }
            }
        }
        $expected = array_keys($payload['effects']);
        sort($expected, SORT_STRING);
        $rows = AccountingSourceEffect::query()->where('company_id', $company)->where('source_kind', $kind)->where('source_id', $id)->orderBy('effect_kind')->get();
        $actual = [];
        foreach ($rows as $row) {
            $actual[] = (string) $row->getAttribute('effect_kind');
        }
        if ($actual !== $expected) {
            $this->fail('Prerequisite Sales financial bundle is incomplete.');
        }
        foreach ($rows as $row) {
            $effectKind = (string) $row->getAttribute('effect_kind');
            $plan = $payload['effects'][$effectKind];
            $snapshot = $row->getAttribute('source_snapshot');
            $body = is_array($snapshot) ? ($snapshot['evidence'] ?? null) : null;
            $proof = [...$plan['proof'], 'evidence_hash' => (string) $event->getAttribute('payload_hash')];
            if ($row->getAttribute('event_token') !== 'original' || $row->getAttribute('event_kind') !== $this->eventKind($kind, $payload['projection']) || ! is_array($body) || ! is_array($body['source'] ?? null) || ! hash_equals((string) ($snapshot['evidence_hash'] ?? ''), $this->canonical->hash($body)) || ! $this->sameEvidence($body['proof'] ?? null, $proof) || ($body['bundle_effects'] ?? null) !== $expected || ! hash_equals((string) $row->getAttribute('source_payload_hash'), $this->canonical->hash($body['source']))) {
                $this->fail('Prerequisite Sales proof does not match its financial source.');
            }
            $source = $body['source'];
            if (! is_int($body['recorded_by'] ?? null) || $body['recorded_by'] < 1 || ! is_string($body['recorded_at'] ?? null) || $row->getAttribute('accounting_date')->format('Y-m-d') !== $payload['projection']['date'] || $row->getAttribute('source_document_date')?->format('Y-m-d') !== $payload['projection']['date'] || ($source['accounting_date'] ?? null) !== $payload['projection']['date'] || ($source['document_date'] ?? null) !== $payload['projection']['date'] || ($source['origin_date'] ?? null) !== $payload['projection']['date'] || ($source['currency'] ?? null) !== $payload['projection']['currency'] || ($body['effect_kind'] ?? null) !== $effectKind || ($source['company_id'] ?? null) !== $company || ($source['source_kind'] ?? null) !== $kind || ($source['source_id'] ?? null) !== $id || ! $this->sameEvidence($source['effect_proofs'][$effectKind] ?? null, $proof) || ($body['outcome'] ?? null) !== $row->getAttribute('outcome') || ($body['no_journal_reason'] ?? null) !== $plan['reason']) {
                $this->fail('Prerequisite Sales source and outcome identity disagree.');
            }
            $this->mappings->verifyRecordedProfile($company, (int) $row->getAttribute('mapping_version_id'), $payload['projection']['currency'], (string) $row->getAttribute('contract_version'), (string) $body['mapping_hash']);
            if ($row->getAttribute('outcome') === 'posted') {
                $journal = JournalEntry::query()->where('company_id', $company)->findOrFail((int) $row->getAttribute('journal_entry_id'));
                if ((int) $journal->getAttribute('mapping_version_id') !== (int) $row->getAttribute('mapping_version_id') || $journal->getAttribute('payload_hash') !== ($body['journal_hash'] ?? null) || $journal->getAttribute('currency_code') !== $payload['projection']['currency']) {
                    $this->fail('Prerequisite Sales journal/profile identity changed.');
                }
                $this->journals->verify($journal, (string) $row->getAttribute('source_payload_hash'), $effectKind, (string) $body['mapping_hash']);
            } elseif ($row->getAttribute('outcome') !== 'verified_no_journal' || $row->getAttribute('journal_entry_id') !== null || $row->getAttribute('no_journal_reason') !== $plan['reason'] || $plan['lines'] !== [] || $plan['proof']['gl_amount'] !== '0.00') {
                $this->fail('Invalid prerequisite Sales no-journal outcome.');
            }
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function checked(AccountingSalesEvent $event): array
    {
        $payload = $event->getAttribute('payload');
        if (! is_array($payload) || ($payload['adapter_contract'] ?? null) !== 'sales.v1' || ! hash_equals((string) $event->getAttribute('payload_hash'), $this->canonical->hash($payload))) {
            $this->fail('Sales admission evidence is missing or damaged.');
        }

        return $payload;
    }

    /** Explicit safe source fields; excludes mutable progress counters and private snapshots/notes.
     * @return array<string, mixed>
     */
    private function projection(int $company, string $kind, int $id): array
    {
        if (in_array($kind, ['sales_return', 'sales_credit_note', 'customer_credit_allocation'], true)) {
            return app(AccountingSalesCorrectionEvidence::class)->projection($company, $kind, $id);
        }
        if (! in_array($kind, ['sales_delivery', 'sales_invoice'], true)) {
            $this->fail('Uninstalled Sales source.');
        }
        $isDelivery = $kind === 'sales_delivery';
        $header = $isDelivery ? SalesDelivery::query()->where('company_id', $company)->findOrFail($id) : SalesInvoice::query()->where('company_id', $company)->findOrFail($id);
        $stamp = $isDelivery ? 'posted' : 'issued';
        if ($header->getAttribute('status') !== $stamp || $header->getAttribute($stamp.'_by') === null || $header->getAttribute($stamp.'_at') === null || ! is_string($header->getAttribute('idempotency_key')) || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $header->getAttribute('payload_hash'))) {
            $this->fail('Final native Sales state, actor, key and fingerprint are required.');
        }
        $order = SalesOrder::query()->where('company_id', $company)->findOrFail((int) $header->getAttribute('sales_order_id'));
        $customer = (int) $header->getAttribute('customer_id');
        if ((int) $order->getAttribute('customer_id') !== $customer || $order->getAttribute('currency_code') !== $header->getAttribute('currency_code') || ! DB::table('customers')->where('company_id', $company)->where('id', $customer)->exists()) {
            $this->fail('Sales customer/order/currency ownership disagrees.');
        }
        $lines = [];
        $dependencies = [];
        $items = $isDelivery ? SalesDeliveryItem::query()->where('sales_delivery_id', $id)->orderBy('id')->get() : SalesInvoiceItem::query()->where('sales_invoice_id', $id)->orderBy('id')->get();
        foreach ($items as $item) {
            if ($isDelivery) {
                if (! $item instanceof SalesDeliveryItem) {
                    $this->fail('Unexpected fulfillment model.');
                }
                $orderLine = SalesOrderItem::query()->where('sales_order_id', $order->getKey())->findOrFail((int) $item->getAttribute('sales_order_item_id'));
                $row = ['id' => (int) $item->getKey(), 'order_line_id' => (int) $orderLine->getKey(), 'quantity' => $this->decimal->source((string) $item->getAttribute('quantity')), 'fulfillment' => (string) $item->getAttribute('fulfillment_type'), 'product' => (int) $item->getAttribute('product_id'), 'variant' => (int) $item->getAttribute('product_variant_id'), 'unit' => (int) $item->getAttribute('unit_id'), 'warehouse' => $item->getAttribute('warehouse_id') === null ? null : (int) $item->getAttribute('warehouse_id'), 'reservation' => $item->getAttribute('sales_stock_reservation_id') === null ? null : (int) $item->getAttribute('sales_stock_reservation_id'), 'unit_cost' => $this->decimal->source((string) $item->getAttribute('unit_cost')), 'cost' => $this->decimal->source((string) $item->getAttribute('total_cost'))];
                foreach (['product_id' => 'product', 'product_variant_id' => 'variant', 'unit_id' => 'unit'] as $field => $key) {
                    if ((int) $orderLine->getAttribute($field) !== $row[$key]) {
                        $this->fail('Delivery line identity differs from its order.');
                    }
                }
                $row['movement'] = $this->movement($company, $id, (string) $header->getAttribute('number'), $item, $row);
            } else {
                if (! $item instanceof SalesInvoiceItem) {
                    $this->fail('Unexpected invoice line model.');
                }
                $source = SalesDeliveryItem::query()->findOrFail((int) $item->getAttribute('sales_delivery_item_id'));
                $parent = SalesDelivery::query()->where('company_id', $company)->where('sales_order_id', $order->getKey())->where('customer_id', $customer)->where('currency_code', $header->getAttribute('currency_code'))->where('status', 'posted')->findOrFail((int) $source->getAttribute('sales_delivery_id'));
                $deliveryId = (int) $parent->getKey();
                if (! isset($dependencies[$deliveryId])) {
                    $dependencies[$deliveryId] = $this->canonical->hash($this->projection($company, 'sales_delivery', $deliveryId));
                }
                $row = ['id' => (int) $item->getKey(), 'delivery_id' => $deliveryId, 'delivery_item_id' => (int) $source->getKey(), 'quantity' => $this->decimal->source((string) $item->getAttribute('quantity')), 'source_quantity' => $this->decimal->source((string) $source->getAttribute('quantity')), 'fulfillment' => (string) $source->getAttribute('fulfillment_type'), 'source_cost' => $this->decimal->source((string) $source->getAttribute('total_cost'))];
                foreach (['product_id', 'product_variant_id', 'unit_id'] as $field) {
                    if ((int) $item->getAttribute($field) !== (int) $source->getAttribute($field)) {
                        $this->fail('Invoice line identity differs from its delivered source.');
                    }
                }
                foreach (['subtotal', 'discount_amount', 'tax_amount', 'line_total'] as $field) {
                    $row[$field] = $this->decimal->money((string) $item->getAttribute($field));
                }
                if (bccomp($row['discount_amount'], $row['subtotal'], 2) > 0 || bcadd(bcsub($row['subtotal'], $row['discount_amount'], 2), $row['tax_amount'], 2) !== $row['line_total']) {
                    $this->fail('Invoice monetary components do not reconcile.');
                }
                $row['unit_price'] = $this->decimal->source((string) $item->getAttribute('unit_price'));
                $row['discount_percent'] = $this->decimal->source((string) $item->getAttribute('discount_percent'));
                $row['tax_percent'] = $this->decimal->source((string) $item->getAttribute('tax_percent'));
            }
            if (bccomp($row['quantity'], '0', 4) <= 0) {
                $this->fail('Positive fulfillment/invoice quantity is required.');
            }
            $lines[] = $row;
        }
        if ($lines === [] || count($lines) > 40) {
            $this->fail('Enabled Sales posting requires one to forty source lines.');
        }
        $p = ['source_id' => $id, 'number' => (string) $header->getAttribute('number'), 'date' => $this->date($header->getAttribute('document_date')), 'currency' => (string) $header->getAttribute('currency_code'), 'order_id' => (int) $order->getKey(), 'customer_id' => $customer, 'actor' => (int) $header->getAttribute($stamp.'_by'), 'instant' => $this->instant($header->getAttribute($stamp.'_at')), 'native_key_hash' => hash('sha256', (string) $header->getAttribute('idempotency_key')), 'native_payload_hash' => (string) $header->getAttribute('payload_hash'), 'lines' => $lines];
        if ($isDelivery) {
            $linked = [];
            foreach ($lines as $line) {
                if ($line['movement'] !== null) {
                    $linked[] = $line['movement']['id'];
                }
            }
            $actual = StockMovement::query()->where('reference_type', SalesDelivery::class)->where('reference_id', $id)->pluck('id')->map(static fn ($value): int => (int) $value)->all();
            sort($actual, SORT_NUMERIC);
            sort($linked, SORT_NUMERIC);
            if (count(array_unique($linked)) !== count($linked) || $actual !== $linked) {
                $this->fail('Delivery movement links are duplicated, missing or have extra entries.');
            }
        } else {
            ksort($dependencies, SORT_NUMERIC);
            $p['deliveries'] = $dependencies;
            foreach (['subtotal', 'discount_total', 'tax_total', 'grand_total'] as $field) {
                $p[$field] = $this->decimal->money((string) $header->getAttribute($field));
            }
            $p['due_date'] = $this->date($header->getAttribute('due_date'));
        }

        return $p;
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function movement(int $company, int $id, string $number, SalesDeliveryItem $item, array $row): ?array
    {
        if ($row['fulfillment'] === 'completion') {
            if ($item->getAttribute('stock_movement_id') !== null || $row['warehouse'] !== null || $row['reservation'] !== null || $row['cost'] !== '0.0000' || $row['unit_cost'] !== '0.0000') {
                $this->fail('Nonstock completion cannot own inventory value or a movement.');
            }

return null;
        }
        if ($row['fulfillment'] !== 'physical' || $row['warehouse'] === null || $row['reservation'] === null) {
            $this->fail('Physical delivery requires its warehouse and reservation trace.');
        }
        $m = StockMovement::query()->where('company_id', $company)->findOrFail((int) $item->getAttribute('stock_movement_id'));
        if ($m->getAttribute('movement_type') !== StockMovement::TYPE_SALE || $m->getAttribute('direction') !== 'out' || $m->getAttribute('reference_type') !== SalesDelivery::class || (int) $m->getAttribute('reference_id') !== $id || $m->getAttribute('reference_number') !== $number || (int) $m->getAttribute('warehouse_id') !== $row['warehouse'] || (int) $m->getAttribute('product_id') !== $row['product'] || (int) $m->getAttribute('product_variant_id') !== $row['variant'] || $this->decimal->source((string) $m->getAttribute('quantity')) !== $row['quantity'] || $this->decimal->source((string) $m->getAttribute('unit_cost')) !== $row['unit_cost'] || $this->decimal->source((string) $m->getAttribute('total_cost')) !== $row['cost'] || bcmul($row['quantity'], $row['unit_cost'], 4) !== $row['cost']) {
            $this->fail('Actual dispatch movement identity or exact cost does not match its delivery item.');
        }
        if (! DB::table('warehouses')->where('company_id', $company)->where('id', $row['warehouse'])->exists() || ! DB::table('products')->where('company_id', $company)->where('id', $row['product'])->exists() || ! DB::table('units')->where('company_id', $company)->where('id', $row['unit'])->exists()) {
            $this->fail('Dispatch masters belong to another company.');
        }
        if ($row['variant'] !== 0 && ! DB::table('product_variants')->where('id', $row['variant'])->where('product_id', $row['product'])->exists()) {
            $this->fail('Dispatch variant belongs to another product.');
        }

        return ['id' => (int) $m->getKey(), 'instant' => $this->instant($m->getAttribute('occurred_at')), 'quantity' => $row['quantity'], 'unit_cost' => $row['unit_cost'], 'cost' => $row['cost']];
    }

    /** @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private function deliveryPlan(array $p): array
    {
        $lines = [];
        $costs = [];
        $sourceCost = '0.0000';
        $amount = '0.00';
        $physical = false;
        foreach ($p['lines'] as $l) {
            $physical = $physical || $l['fulfillment'] === 'physical';
            $sourceCost = $this->decimal->source(bcadd($sourceCost, $l['cost'], 4));
            $costs[] = ['source_cost' => $l['cost']];
            $value = $this->decimal->roundedCost($l['cost']);
            $amount = $this->decimal->total([$amount, $value]);
            $this->line($lines, 'dispatched_inventory', $value, '0.00', 'sales_delivery_item', $l['id']);
            $this->line($lines, 'inventory', '0.00', $value, 'sales_delivery_item', $l['id']);
        }
        $reason = $lines !== [] ? null : (! $physical ? 'nonstock_completion' : ($sourceCost === '0.0000' ? 'zero_source_cost' : 'subcent_valuation'));

        return ['lines' => $lines, 'proof' => ['gl_amount' => $amount, 'source_cost' => $sourceCost, 'line_facts' => $costs, 'fulfillment_type' => $physical ? 'physical' : 'nonstock'], 'reason' => $reason];
    }

    /** @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private function commercialPlan(array $p): array
    {
        $lines = [];
        $subtotal = $discount = $tax = $grand = '0.00';
        foreach ($p['lines'] as $l) {
            $net = bcsub($l['subtotal'], $l['discount_amount'], 2);
            $subtotal = $this->decimal->total([$subtotal, $l['subtotal']]);
            $discount = $this->decimal->total([$discount, $l['discount_amount']]);
            $tax = $this->decimal->total([$tax, $l['tax_amount']]);
            $grand = $this->decimal->total([$grand, $l['line_total']]);
            $this->line($lines, 'sales_revenue', '0.00', $net, 'sales_invoice_item', $l['id']);
            $this->line($lines, 'output_tax', '0.00', $l['tax_amount'], 'sales_invoice_item', $l['id']);
        }
        if ($subtotal !== $p['subtotal'] || $discount !== $p['discount_total'] || $tax !== $p['tax_total'] || $grand !== $p['grand_total']) {
            $this->fail('Invoice header and line financial totals differ.');
        }
        $this->line($lines, 'trade_ar', $grand, '0.00', 'sales_invoice', $p['source_id'], $p['customer_id']);

        return ['lines' => $lines, 'proof' => ['gl_amount' => $grand, 'amount' => $grand, 'net' => bcsub($subtotal, $discount, 2), 'tax' => $tax], 'reason' => $lines === [] ? 'zero_commercial_value' : null];
    }

    /** @param array<string, mixed> $p
     * @return array{array<string, mixed>, list<array<string, mixed>>}
     */
    private function costPlan(int $company, int $invoiceId, array $p): array
    {
        $lines = [];
        $slices = [];
        $facts = [];
        $amount = '0.00';
        $sourceCost = '0.0000';
        $physical = false;
        foreach ($p['lines'] as $l) {
            $before = $this->consumed($company, $invoiceId, $l);
            $after = bcadd($before, $l['quantity'], 4);
            $share = $this->decimal->costShare($l['source_cost'], $l['source_quantity'], $before, $after);
            $slices[] = ['source_line_id' => $l['id'], 'sales_delivery_item_id' => $l['delivery_item_id'], 'quantity' => $l['quantity'], 'quantity_before' => $before, 'quantity_after' => $after, 'amount' => $share];
            $amount = $this->decimal->total([$amount, $share]);
            $sourceCost = $this->decimal->source(bcadd($sourceCost, $l['source_cost'], 4));
            $physical = $physical || $l['fulfillment'] === 'physical';
            $facts[] = ['source_line_id' => $l['id'], 'original_id' => $l['delivery_item_id'], 'quantity' => $l['quantity'], 'source_quantity' => $l['source_quantity'], 'source_cost' => $l['source_cost'], 'amount' => $share];
            $this->line($lines, 'cogs', $share, '0.00', 'sales_invoice_item', $l['id']);
            $this->line($lines, 'dispatched_inventory', '0.00', $share, 'sales_invoice_item', $l['id']);
        }
        $reason = $lines !== [] ? null : (! $physical ? 'nonstock_completion' : ($sourceCost === '0.0000' ? 'zero_source_cost' : 'zero_cost_release'));

        return [['lines' => $lines, 'proof' => ['gl_amount' => $amount, 'source_cost' => $sourceCost, 'line_facts' => $facts, 'fulfillment_type' => $physical ? 'physical' : 'nonstock'], 'reason' => $reason], $slices];
    }

    /** A new effective profile must not credit a different control account from the original dispatch.
     * @param  array<string, mixed>  $projection
     * @param  list<array<string, mixed>>  $slices
     */
    private function sameDispatchAccount(Company $company, array $projection, array $slices): void
    {
        $companyId = (int) $company->getKey();
        $current = $this->mappings->resolveForSource($company, $projection['date'], ['dispatched_inventory']);
        $positive = [];
        foreach ($slices as $slice) {
            if ($slice['amount'] !== '0.00') {
                $positive[$slice['source_line_id']] = true;
            }
        }
        $checked = [];
        foreach ($projection['lines'] as $line) {
            if (! isset($positive[$line['id']]) || isset($checked[$line['delivery_id']])) {
                continue;
            }
            $checked[$line['delivery_id']] = true;
            $effect = AccountingSourceEffect::query()->where('company_id', $companyId)->where('source_kind', 'sales_delivery')->where('source_id', $line['delivery_id'])->where('event_kind', 'posted')->where('event_token', 'original')->where('effect_kind', 'valuation')->firstOrFail();
            $account = AccountingAccountMapping::query()->where('company_id', $companyId)->where('mapping_version_id', $effect->getAttribute('mapping_version_id'))->where('role', 'dispatched_inventory')->value('account_id');
            if ((int) $account !== $current['accounts']['dispatched_inventory']) {
                $this->fail('The original dispatch uses a different Dispatched Inventory account. A verified source-linked control-account transition is required before releasing this cost.');
            }
        }
    }

    /** @param array<string, mixed> $line */
    public function consumed(int $company, int $currentInvoice, array $line): string
    {
        $quantity = '0.0000';
        foreach (AccountingSalesCostSlice::query()->where('company_id', $company)->where('sales_delivery_item_id', $line['delivery_item_id'])->orderBy('id')->get() as $slice) {
            $event = AccountingSalesEvent::query()->where('company_id', $company)->findOrFail((int) $slice->getAttribute('sales_event_id'));
            if (! in_array($event->getAttribute('source_kind'), ['sales_invoice', 'sales_return'], true)) {
                $this->fail('This cost cursor requires a later installed source contract.');
            }
            $payload = $this->recognized($company, (string) $event->getAttribute('source_kind'), (int) $event->getAttribute('source_id'));
            $expected = null;
            foreach ($payload['slices'] as $candidate) {
                if ($candidate['source_line_id'] === (int) $slice->getAttribute('source_line_id')) {
                    $expected = $candidate;
                }
            }
            if ($expected === null) {
                $this->fail('Cost slice has no frozen source evidence.');
            }
            foreach ($expected as $key => $value) {
                if ((string) $slice->getAttribute($key) !== (string) $value) {
                    $this->fail('Cost allocation slice differs from its admitted source.');
                }
            }
            $after = bcadd($quantity, $expected['quantity'], 4);
            if ($expected['quantity_before'] !== $quantity || $expected['quantity_after'] !== $after || $expected['amount'] !== $this->decimal->costShare($line['source_cost'], $line['source_quantity'], $quantity, $after)) {
                $this->fail('Cumulative dispatch cost allocation is inconsistent.');
            }
            $quantity = $after;
        }
        $native = '0.0000';
        foreach (DB::table('sales_invoice_items as i')->join('sales_invoices as h', 'h.id', '=', 'i.sales_invoice_id')->where('h.company_id', $company)->where('h.status', 'issued')->where('h.id', '<>', $currentInvoice)->where('i.sales_delivery_item_id', $line['delivery_item_id'])->pluck('i.quantity') as $q) {
            $native = bcadd($native, (string) $q, 4);
        }
        foreach (DB::table('sales_return_items as i')->join('sales_returns as h', 'h.id', '=', 'i.sales_return_id')->where('h.company_id', $company)->where('h.status', 'posted')->where('i.sales_delivery_item_id', $line['delivery_item_id'])->when(isset($line['current_return_id']), fn ($q) => $q->where('h.id', '<>', $line['current_return_id']))->pluck('i.unbilled_quantity') as $q) {
            $native = bcadd($native, (string) $q, 4);
        }
        if ($native !== $quantity) {
            $this->fail('Invoice/return quantities include unrecognized financial sources; reconcile before consuming more dispatch cost.');
        }

        return $quantity;
    }

    /** @param array<string, mixed> $projection */
    private function eventKind(string $kind, array $projection): string
    {
        return match ($kind) {
            'sales_delivery', 'sales_return' => 'posted',
            'sales_invoice', 'sales_credit_note' => 'issued',
            'customer_credit_allocation' => $projection['original_id'] === null ? 'created' : 'reversed',
            default => throw new \LogicException('Unsupported financial source.'),
        };
    }

    /** @param list<array<string, mixed>> $lines */
    private function line(array &$lines, string $role, string $debit, string $credit, string $type, int $id, ?int $customer = null): void
    {
        if ($debit === '0.00' && $credit === '0.00') {
            return;
        }
        $lines[] = ['role' => $role, 'debit' => $debit, 'credit' => $credit, 'source_line_type' => $type, 'source_line_id' => $id, 'party_type' => $customer === null ? null : 'customer', 'party_id' => $customer];
    }

    private function date(mixed $value): string
    {
        if (! $value instanceof DateTimeInterface) {
            $this->fail('Persisted document date is required.');
        }

return $value->format('Y-m-d');
    }

    private function instant(mixed $value): string
    {
        if (! $value instanceof DateTimeInterface) {
            $this->fail('Persisted source event timestamp is required.');
        }

return CarbonImmutable::instance($value)->utc()->format('Y-m-d\TH:i:sP');
    }

    private function sameEvidence(mixed $left, mixed $right): bool
    {
        return hash_equals($this->canonical->hash(['value' => $left]), $this->canonical->hash(['value' => $right]));
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['accounting_sales' => $message]);
    }
}
