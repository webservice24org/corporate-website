<?php

declare(strict_types=1);

namespace App\Services\ERP\Purchase;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequisition;
use App\Models\Rfq;
use App\Models\Supplier;
use App\Models\SupplierQuotation;
use App\Models\SupplierQuotationItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PurchaseOrderService
{
    public function __construct(
        private readonly PurchaseCompanyAccess $access,
        private readonly PurchaseRequisitionService $requisitions,
        private readonly PurchaseDocumentNumberService $numbers,
        private readonly PurchaseWorkflowService $workflow,
        private readonly PurchaseQuoteMath $math,
    ) {}

    /** @return Builder<Company> */
    public function companies(User $actor, string $permission): Builder
    {
        return $this->requisitions->companies($actor, $permission);
    }

    /** @return Builder<PurchaseOrder> */
    public function visible(User $actor, string $permission = 'erp.purchase-orders.view'): Builder
    {
        return PurchaseOrder::query()->whereIn('company_id', $this->companies($actor, $permission)->select('id'));
    }

    public function find(User $actor, int $id, string $permission): PurchaseOrder
    {
        $order = $this->visible($actor, $permission)->findOrFail($id);
        $this->access->authorize($actor, (int) $order->company_id, $permission);

        return $order;
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): PurchaseOrder
    {
        $companyId = (int) $data['company_id'];
        $this->access->authorize($actor, $companyId, 'erp.purchase-orders.create');

        return DB::transaction(function () use ($actor, $companyId, $data): PurchaseOrder {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $peek = SupplierQuotation::query()->where('company_id', $companyId)->findOrFail($data['supplier_quotation_id']);
            Rfq::query()->where('company_id', $companyId)->whereKey($peek->rfq_id)->lockForUpdate()->firstOrFail();
            $quote = SupplierQuotation::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($peek->getKey());
            // Retried creates return the original order without consuming another number or writing another audit event.
            $existing = PurchaseOrder::query()->where('company_id', $companyId)->where('supplier_quotation_id', $quote->getKey())->first();
            if ($existing !== null) {
                return $existing;
            }
            $source = $this->source($quote, $companyId);
            $order = PurchaseOrder::query()->create([
                'company_id' => $companyId, 'supplier_id' => $quote->supplier_id, 'purchase_requisition_id' => $source['rfq']->purchase_requisition_id,
                'supplier_quotation_id' => $quote->getKey(), 'created_by' => $actor->getKey(), 'number' => $this->numbers->next($companyId, 'order'),
                'status' => 'draft', 'currency_code' => $quote->currency_code, 'revision' => 1,
                'expected_delivery_at' => $data['expected_delivery_at'] ?? null, 'notes' => $data['notes'] ?? null,
                ...$source['totals'],
            ]);
            foreach ($source['lines'] as $line) {
                PurchaseOrderItem::query()->create([...$line, 'purchase_order_id' => $order->getKey(), 'expected_delivery_at' => $data['expected_delivery_at'] ?? null, 'received_quantity' => '0.0000']);
            }
            $this->event($actor, $order, null, 'draft', null);
            $this->audit($actor, $order, 'created', null);

            return $order->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, int $id, array $data): PurchaseOrder
    {
        $this->find($actor, $id, 'erp.purchase-orders.edit');

        return DB::transaction(function () use ($actor, $id, $data): PurchaseOrder {
            $order = $this->visible($actor, 'erp.purchase-orders.edit')->lockForUpdate()->findOrFail($id);
            $this->revision($order, (int) $data['revision']);
            $this->status($order, 'draft');
            $old = $this->snapshot($order);
            $order->fill(['expected_delivery_at' => $data['expected_delivery_at'] ?? null, 'notes' => $data['notes'] ?? null]);
            $this->bump($order);
            PurchaseOrderItem::query()->where('purchase_order_id', $id)->update(['expected_delivery_at' => $data['expected_delivery_at'] ?? null]);
            $this->audit($actor, $order, 'updated', $old);

            return $order->refresh();
        }, 3);
    }

    public function transition(User $actor, int $id, string $action, int $revision, ?string $comment): PurchaseOrder
    {
        if (! in_array($action, ['submit', 'approve', 'reject', 'order', 'revise'], true)) {
            throw ValidationException::withMessages(['action' => 'Unsupported purchase-order action.']);
        }
        $permission = 'erp.purchase-orders.'.($action === 'revise' ? 'edit' : $action);
        $this->find($actor, $id, $permission);

        return DB::transaction(function () use ($actor, $id, $action, $revision, $comment, $permission): PurchaseOrder {
            $order = $this->visible($actor, $permission)->lockForUpdate()->findOrFail($id);
            $this->revision($order, $revision);
            $old = $this->snapshot($order);
            if ($action === 'revise') {
                $this->status($order, 'rejected');
                if (trim((string) $comment) === '') {
                    throw ValidationException::withMessages(['comment' => 'Record a reason for returning this order to draft.']);
                }
                $order->fill(['status' => 'draft', 'submitted_at' => null, 'approved_at' => null, 'approved_by' => null, 'ordered_at' => null]);
                $this->bump($order);
                $this->event($actor, $order, 'rejected', 'draft', $comment);
                $this->audit($actor, $order, 'revise', $old, $comment);

                return $order->refresh();
            }
            if (in_array($action, ['submit', 'approve', 'order'], true)) {
                $this->validateOrder($order);
            }
            $changed = $this->workflow->transitionOrder($actor, (int) $order->company_id, $id, $action, $comment);
            $changed->setAttribute('revision', $revision + 1);
            $changed->save();
            $this->audit($actor, $changed, $action, $old, $comment);

            return $changed->refresh();
        }, 3);
    }

    private function validateOrder(PurchaseOrder $order): void
    {
        if ($order->supplier_quotation_id === null) {
            throw ValidationException::withMessages(['source' => 'This legacy order has no awarded quotation. Create an order from the RFQ workflow.']);
        }
        $quote = SupplierQuotation::query()->where('company_id', $order->company_id)->findOrFail($order->supplier_quotation_id);
        $source = $this->source($quote, (int) $order->company_id);
        if ((int) $order->supplier_id !== (int) $quote->supplier_id || $order->currency_code !== $quote->currency_code
            || (int) $order->purchase_requisition_id !== (int) $source['rfq']->purchase_requisition_id) {
            throw ValidationException::withMessages(['source' => 'Order ownership and supplier must match the awarded quotation.']);
        }
        foreach ($source['totals'] as $field => $amount) {
            if ($this->math->scaled((string) $order->getAttribute($field), 2, 1000000000000) !== $this->math->scaled($amount, 2, 1000000000000)) {
                throw ValidationException::withMessages(['source' => 'Order totals must match the awarded quotation.']);
            }
        }
        $items = PurchaseOrderItem::query()->where('purchase_order_id', $order->getKey())->get()->keyBy('supplier_quotation_item_id');
        if ($items->count() !== count($source['lines'])) {
            throw ValidationException::withMessages(['items' => 'Order lines must match the complete awarded quotation.']);
        }
        foreach ($source['lines'] as $line) {
            $item = $items->get($line['supplier_quotation_item_id']);
            if ($item === null || (int) $item->product_id !== (int) $line['product_id'] || (int) ($item->product_variant_id ?? 0) !== (int) ($line['product_variant_id'] ?? 0)
                || $this->math->scaled((string) $item->received_quantity, 4) !== 0) {
                throw ValidationException::withMessages(['items' => 'Order product/variant/source lines are inconsistent or already received.']);
            }
            foreach (['quantity' => 4, 'unit_price' => 2, 'discount_amount' => 2, 'tax_amount' => 2, 'line_total' => 2] as $field => $scale) {
                $max = in_array($field, ['quantity', 'unit_price'], true) ? 1000000 : 1000000000000;
                if ($this->math->scaled((string) $item->getAttribute($field), $scale, $max) !== $this->math->scaled((string) $line[$field], $scale, $max)) {
                    throw ValidationException::withMessages(['items' => 'Order quantities and prices must match the awarded quotation.']);
                }
            }
        }
    }

    /** @return array{rfq: Rfq, lines: array<int, array<string, mixed>>, totals: array<string, string>} */
    private function source(SupplierQuotation $quote, int $companyId): array
    {
        $rfq = Rfq::query()->where('company_id', $companyId)->findOrFail($quote->rfq_id);
        if ($rfq->status !== 'awarded' || (int) $rfq->getAttribute('awarded_quotation_id') !== (int) $quote->getKey() || $quote->status !== 'accepted'
            || $quote->currency_code !== $rfq->getAttribute('currency_code')) {
            throw ValidationException::withMessages(['supplier_quotation_id' => 'Select the accepted quotation from an awarded RFQ.']);
        }
        if ($quote->valid_until !== null && Carbon::parse((string) $quote->getRawOriginal('valid_until'))->endOfDay()->isPast()) {
            throw ValidationException::withMessages(['supplier_quotation_id' => 'The supplier quotation has expired. Obtain a current awarded offer.']);
        }
        $requisition = PurchaseRequisition::query()->where('company_id', $companyId)->where('status', 'approved')->findOrFail($rfq->purchase_requisition_id);
        if ($requisition->getAttribute('currency_code') !== $quote->currency_code) {
            throw ValidationException::withMessages(['source' => 'Source requisition and awarded offer currencies must match.']);
        }
        $supplier = Supplier::query()->where('company_id', $companyId)->findOrFail($quote->supplier_id);
        if ($supplier->status !== 'active' || $supplier->currency_code !== $quote->currency_code
            || ! DB::table('rfq_suppliers')->where('rfq_id', $rfq->getKey())->where('supplier_id', $supplier->getKey())->exists()) {
            throw ValidationException::withMessages(['supplier_quotation_id' => 'The awarded supplier must remain active and use the quotation currency.']);
        }
        $rfqLines = DB::table('rfq_items')->where('rfq_id', $rfq->getKey())->get()->keyBy('id');
        $items = SupplierQuotationItem::query()->where('supplier_quotation_id', $quote->getKey())->orderBy('rfq_item_id')->get();
        if ($items->isEmpty() || $items->count() !== $rfqLines->count() || $items->count() > 100) {
            throw ValidationException::withMessages(['items' => 'The awarded quotation must cover every RFQ line.']);
        }
        $subtotal = 0;
        $discount = 0;
        $tax = 0;
        $total = 0;
        $lines = [];
        foreach ($items as $item) {
            $rfqLine = $rfqLines->get($item->getAttribute('rfq_item_id'));
            if ($rfqLine === null || (int) $rfqLine->product_id !== (int) $item->product_id || (int) ($rfqLine->product_variant_id ?? 0) !== (int) ($item->product_variant_id ?? 0)
                || $this->math->scaled((string) $rfqLine->quantity, 4) !== $this->math->scaled((string) $item->quantity, 4)) {
                throw ValidationException::withMessages(['items' => 'Quotation lines must match the RFQ source.']);
            }
            $product = Product::query()->where('company_id', $companyId)->where('is_active', true)->findOrFail($item->product_id);
            if ($item->product_variant_id !== null) {
                if (! $product->has_variants) {
                    throw ValidationException::withMessages(['items' => 'This product no longer uses variants. Review its source configuration.']);
                }
                ProductVariant::query()->where('product_id', $product->getKey())->where('is_active', true)->findOrFail($item->product_variant_id);
            } elseif ($product->has_variants) {
                throw ValidationException::withMessages(['items' => 'An active variant is required.']);
            }
            $base = $this->math->line((string) $item->quantity, (string) $item->unit_price, '0', '0');
            $lineTotal = $this->math->line((string) $item->quantity, (string) $item->unit_price, (string) $item->discount_amount, (string) $item->tax_amount);
            if ($lineTotal !== $this->math->scaled((string) $item->getAttribute('line_total'), 2, 1000000000000)) {
                throw ValidationException::withMessages(['items' => 'Quotation line totals are inconsistent.']);
            }
            $subtotal += $base;
            $discount += $this->math->scaled((string) $item->discount_amount, 2, 1000000000000);
            $tax += $this->math->scaled((string) $item->tax_amount, 2, 1000000000000);
            $total += $lineTotal;
            if (max($subtotal, $discount, $tax, $total) > 100000000000000) {
                throw ValidationException::withMessages(['items' => 'Order amounts exceed the supported total.']);
            }
            $lines[] = ['supplier_quotation_item_id' => $item->getKey(), 'product_id' => $item->product_id, 'product_variant_id' => $item->product_variant_id,
                'product_name' => $rfqLine->product_name, 'variant_name' => $rfqLine->variant_name,
                'quantity' => (string) $item->quantity, 'unit_price' => (string) $item->unit_price, 'discount_amount' => (string) $item->discount_amount,
                'tax_amount' => (string) $item->tax_amount, 'line_total' => $this->math->format($lineTotal)];
        }
        if ($total !== $this->math->scaled((string) $quote->total_amount, 2, 1000000000000)) {
            throw ValidationException::withMessages(['items' => 'Quotation header total does not match its lines.']);
        }

        return ['rfq' => $rfq, 'lines' => $lines, 'totals' => ['subtotal' => $this->math->format($subtotal), 'discount_total' => $this->math->format($discount), 'tax_total' => $this->math->format($tax), 'grand_total' => $this->math->format($total)]];
    }

    private function status(PurchaseOrder $order, string $expected): void
    {
        if ($order->status !== $expected) {
            throw ValidationException::withMessages(['status' => 'This action is not allowed in the current order state.']);
        }
    }

    private function revision(PurchaseOrder $order, int $revision): void
    {
        if ((int) $order->getAttribute('revision') !== $revision) {
            throw ValidationException::withMessages(['revision' => 'This order changed in another session. Reload before continuing.']);
        }
    }

    private function bump(PurchaseOrder $order): void
    {
        $order->setAttribute('revision', (int) $order->getAttribute('revision') + 1);
        $order->save();
    }

    /** @return array<string, mixed> */
    private function snapshot(PurchaseOrder $order): array
    {
        return [...$order->attributesToArray(), 'items' => PurchaseOrderItem::query()->where('purchase_order_id', $order->getKey())->orderBy('id')->get()->toArray()];
    }

    /** @param array<string, mixed>|null $old */
    private function audit(User $actor, PurchaseOrder $order, string $action, ?array $old, ?string $comment = null): void
    {
        DB::table('purchase_order_audit_logs')->insert(['company_id' => $order->company_id, 'purchase_order_id' => $order->getKey(), 'actor_id' => $actor->getKey(), 'action' => $action,
            'old_values' => $old === null ? null : json_encode($old, JSON_THROW_ON_ERROR), 'new_values' => json_encode($this->snapshot($order), JSON_THROW_ON_ERROR), 'comment' => $comment, 'created_at' => now()]);
    }

    private function event(User $actor, PurchaseOrder $order, ?string $from, string $to, ?string $comment): void
    {
        DB::table('purchase_workflow_events')->insert(['company_id' => $order->company_id, 'actor_id' => $actor->getKey(), 'document_type' => 'order', 'document_id' => $order->getKey(),
            'from_status' => $from, 'to_status' => $to, 'comment' => $comment, 'created_at' => now()]);
    }
}
