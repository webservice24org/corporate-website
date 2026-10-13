<?php

declare(strict_types=1);

namespace App\Services\ERP\Purchase;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Models\Rfq;
use App\Models\Supplier;
use App\Models\SupplierQuotation;
use App\Models\SupplierQuotationItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PurchaseRfqService
{
    public function __construct(
        private readonly PurchaseCompanyAccess $access,
        private readonly PurchaseRequisitionService $requisitions,
        private readonly PurchaseDocumentNumberService $numbers,
        private readonly PurchaseQuoteMath $math,
    ) {}

    /** @return Builder<Company> */
    public function companies(User $actor, string $permission): Builder
    {
        return $this->requisitions->companies($actor, $permission);
    }

    /** @return Builder<Rfq> */
    public function visible(User $actor, string $permission = 'erp.purchase-rfqs.view'): Builder
    {
        return Rfq::query()->whereIn('company_id', $this->companies($actor, $permission)->select('id'));
    }

    public function find(User $actor, int $id, string $permission): Rfq
    {
        $rfq = $this->visible($actor, $permission)->findOrFail($id);
        $this->access->authorize($actor, (int) $rfq->company_id, $permission);

        return $rfq;
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): Rfq
    {
        $companyId = (int) $data['company_id'];
        $this->access->authorize($actor, $companyId, 'erp.purchase-rfqs.create');

        return DB::transaction(function () use ($actor, $companyId, $data): Rfq {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $source = PurchaseRequisition::query()->where('company_id', $companyId)->where('status', 'approved')->lockForUpdate()->findOrFail($data['purchase_requisition_id']);
            $currency = (string) $source->getAttribute('currency_code');
            if (! preg_match('/^[A-Z]{3}$/D', $currency)) {
                throw ValidationException::withMessages(['purchase_requisition_id' => 'This requisition has no valid estimate currency.']);
            }
            $this->validateSuppliers($companyId, $currency, $data['supplier_ids']);
            $items = PurchaseRequisitionItem::query()->where('purchase_requisition_id', $source->getKey())->lockForUpdate()->get();
            if ($items->isEmpty() || $items->count() > 100) {
                throw ValidationException::withMessages(['purchase_requisition_id' => 'Select a requisition with one to 100 valid lines.']);
            }
            $rfq = Rfq::query()->create([
                'company_id' => $companyId, 'purchase_requisition_id' => $source->getKey(), 'created_by' => $actor->getKey(),
                'number' => $this->numbers->next($companyId, 'rfq'), 'status' => 'draft', 'currency_code' => $currency, 'revision' => 1,
                'deadline_at' => $this->deadline($data['deadline_at'] ?? null), 'notes' => $data['notes'] ?? null,
            ]);
            foreach ($items as $item) {
                $product = Product::query()->where('company_id', $companyId)->where('is_active', true)->findOrFail($item->product_id);
                $variant = $item->product_variant_id === null ? null : ProductVariant::query()->where('product_id', $product->getKey())->where('is_active', true)->findOrFail($item->product_variant_id);
                if ((bool) $product->has_variants !== ($variant !== null)) {
                    throw ValidationException::withMessages(['purchase_requisition_id' => 'A requisition product variant configuration changed. Review the approved source.']);
                }
                $this->math->line((string) $item->quantity, (string) $item->estimated_unit_price, '0', '0');
                DB::table('rfq_items')->insert([
                    'rfq_id' => $rfq->getKey(), 'purchase_requisition_item_id' => $item->getKey(), 'product_id' => $product->getKey(),
                    'product_variant_id' => $variant?->getKey(), 'product_name' => $product->name, 'variant_name' => $variant?->name,
                    'quantity' => $item->quantity, 'estimated_unit_price' => $item->estimated_unit_price, 'notes' => $item->notes,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->replaceSuppliers($rfq, $data['supplier_ids']);
            $this->audit($actor, $rfq, 'created', null);

            return $rfq->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, int $id, array $data): Rfq
    {
        $this->find($actor, $id, 'erp.purchase-rfqs.edit');

        return DB::transaction(function () use ($actor, $id, $data): Rfq {
            $rfq = $this->visible($actor, 'erp.purchase-rfqs.edit')->lockForUpdate()->findOrFail($id);
            $this->revision($rfq, (int) $data['revision']);
            $this->status($rfq, 'draft');
            $this->validateSuppliers((int) $rfq->company_id, (string) $rfq->getAttribute('currency_code'), $data['supplier_ids']);
            $old = $this->snapshot($rfq);
            $rfq->fill(['deadline_at' => $this->deadline($data['deadline_at'] ?? null), 'notes' => $data['notes'] ?? null]);
            $this->bump($rfq);
            $this->replaceSuppliers($rfq, $data['supplier_ids']);
            $this->audit($actor, $rfq, 'updated', $old);

            return $rfq->refresh();
        }, 3);
    }

    public function issue(User $actor, int $id, int $revision): Rfq
    {
        $this->find($actor, $id, 'erp.purchase-rfqs.issue');

        return DB::transaction(function () use ($actor, $id, $revision): Rfq {
            $rfq = $this->visible($actor, 'erp.purchase-rfqs.issue')->lockForUpdate()->findOrFail($id);
            $this->revision($rfq, $revision);
            $this->status($rfq, 'draft');
            $this->openDeadline($rfq);
            $this->validateSuppliers((int) $rfq->company_id, (string) $rfq->getAttribute('currency_code'), DB::table('rfq_suppliers')->where('rfq_id', $id)->pluck('supplier_id')->all());
            $this->validateItems($rfq);
            $old = $this->snapshot($rfq);
            $rfq->fill(['status' => 'issued', 'issued_at' => now()->toDateTimeString()]);
            $this->bump($rfq);
            DB::table('rfq_suppliers')->where('rfq_id', $id)->update(['invited_at' => now(), 'updated_at' => now()]);
            $this->audit($actor, $rfq, 'issued', $old);

            return $rfq->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function quotation(User $actor, int $id, array $data, ?int $quoteId = null): SupplierQuotation
    {
        $permission = $quoteId === null ? 'erp.supplier-quotations.create' : 'erp.supplier-quotations.edit';
        $this->find($actor, $id, $permission);

        return DB::transaction(function () use ($actor, $id, $data, $quoteId, $permission): SupplierQuotation {
            $rfq = $this->visible($actor, $permission)->lockForUpdate()->findOrFail($id);
            $this->revision($rfq, (int) $data['rfq_revision']);
            $this->status($rfq, 'issued');
            $this->openDeadline($rfq);
            $this->validateItems($rfq);
            $supplierId = (int) $data['supplier_id'];
            if (! DB::table('rfq_suppliers')->where('rfq_id', $id)->where('supplier_id', $supplierId)->exists()) {
                throw ValidationException::withMessages(['supplier_id' => 'This supplier was not selected for the RFQ.']);
            }
            $this->validateSuppliers((int) $rfq->company_id, (string) $rfq->getAttribute('currency_code'), [$supplierId]);
            $old = $this->snapshot($rfq);
            $quote = $quoteId === null ? new SupplierQuotation : SupplierQuotation::query()->where('rfq_id', $id)->where('company_id', $rfq->company_id)->lockForUpdate()->findOrFail($quoteId);
            if ($quoteId !== null && ((int) $quote->supplier_id !== $supplierId || (int) $quote->getAttribute('revision') !== (int) ($data['revision'] ?? 0))) {
                throw ValidationException::withMessages(['revision' => 'This quotation changed, or its supplier cannot be changed. Reload it.']);
            }
            if ($quoteId === null && SupplierQuotation::query()->where('rfq_id', $id)->where('supplier_id', $supplierId)->exists()) {
                throw ValidationException::withMessages(['supplier_id' => 'A quotation is already recorded for this supplier. Edit that quotation.']);
            }
            $lines = DB::table('rfq_items')->where('rfq_id', $id)->orderBy('id')->get()->keyBy('id');
            $provided = array_map(fn (array $line): int => (int) $line['rfq_item_id'], $data['items']);
            if (count($provided) !== $lines->count() || count(array_unique($provided)) !== count($provided) || array_diff($provided, $lines->keys()->all()) !== []) {
                throw ValidationException::withMessages(['items' => 'Quote every RFQ line exactly once.']);
            }
            $total = 0;
            $prepared = [];
            foreach ($data['items'] as $input) {
                $line = $lines->get((int) $input['rfq_item_id']);
                if ($line === null) {
                    throw ValidationException::withMessages(['items' => 'Invalid RFQ line.']);
                }
                $cents = $this->math->line((string) $line->quantity, (string) $input['unit_price'], (string) $input['discount_amount'], (string) $input['tax_amount']);
                $total += $cents;
                if ($total > 100000000000000) {
                    throw ValidationException::withMessages(['items' => 'Quotation total exceeds the supported amount.']);
                }
                $prepared[] = ['rfq_item_id' => $line->id, 'product_id' => $line->product_id, 'product_variant_id' => $line->product_variant_id,
                    'quantity' => $line->quantity, 'unit_price' => $input['unit_price'], 'discount_amount' => $input['discount_amount'],
                    'tax_amount' => $input['tax_amount'], 'line_total' => $this->math->format($cents)];
            }
            $quote->fill(['company_id' => $rfq->company_id, 'rfq_id' => $id, 'supplier_id' => $supplierId, 'quotation_number' => trim((string) $data['quotation_number']),
                'quoted_at' => $data['quoted_at'], 'valid_until' => $data['valid_until'] ?? null, 'notes' => $data['notes'] ?? null,
                'currency_code' => $rfq->getAttribute('currency_code'), 'status' => 'submitted', 'total_amount' => $this->math->format($total),
                'revision' => $quoteId === null ? 1 : (int) $quote->getAttribute('revision') + 1]);
            $quote->save();
            SupplierQuotationItem::query()->where('supplier_quotation_id', $quote->getKey())->delete();
            foreach ($prepared as $line) {
                SupplierQuotationItem::query()->create([...$line, 'supplier_quotation_id' => $quote->getKey()]);
            }
            $this->bump($rfq);
            $this->audit($actor, $rfq, $quoteId === null ? 'quotation_created' : 'quotation_updated', $old);

            return $quote->refresh();
        }, 3);
    }

    public function award(User $actor, int $id, int $quoteId, int $revision, string $reason): Rfq
    {
        $rfq = $this->find($actor, $id, 'erp.purchase-rfqs.award');
        $this->access->authorize($actor, (int) $rfq->company_id, 'erp.supplier-quotations.compare');

        return DB::transaction(function () use ($actor, $id, $quoteId, $revision, $reason): Rfq {
            $rfq = $this->visible($actor, 'erp.purchase-rfqs.award')->lockForUpdate()->findOrFail($id);
            $this->revision($rfq, $revision);
            $this->status($rfq, 'issued');
            $this->validateItems($rfq);
            $quote = SupplierQuotation::query()->where('company_id', $rfq->company_id)->where('rfq_id', $id)->where('status', 'submitted')->lockForUpdate()->findOrFail($quoteId);
            if ($quote->valid_until !== null && Carbon::parse((string) $quote->getRawOriginal('valid_until'))->endOfDay()->isPast()) {
                throw ValidationException::withMessages(['quotation_id' => 'This supplier quotation has expired.']);
            }
            $this->validateSuppliers((int) $rfq->company_id, (string) $rfq->getAttribute('currency_code'), [(int) $quote->supplier_id]);
            if ($quote->currency_code !== $rfq->getAttribute('currency_code') || trim($reason) === '') {
                throw ValidationException::withMessages(['reason' => 'A same-currency quotation and award reason are required.']);
            }
            // Recheck the stored line totals and completeness before locking in the selection.
            $expected = DB::table('rfq_items')->where('rfq_id', $id)->get()->keyBy('id');
            $items = SupplierQuotationItem::query()->where('supplier_quotation_id', $quoteId)->get();
            $sum = 0;
            if ($items->count() !== $expected->count()) {
                throw ValidationException::withMessages(['quotation_id' => 'This quotation is incomplete.']);
            }
            foreach ($items as $item) {
                $source = $expected->get($item->getAttribute('rfq_item_id'));
                if ($source === null || (int) $source->product_id !== (int) $item->product_id || (int) ($source->product_variant_id ?? 0) !== (int) ($item->product_variant_id ?? 0)
                    || $this->math->scaled((string) $source->quantity, 4) !== $this->math->scaled((string) $item->quantity, 4)) {
                    throw ValidationException::withMessages(['quotation_id' => 'Quotation lines do not match this RFQ.']);
                }
                $cents = $this->math->line((string) $item->quantity, (string) $item->unit_price, (string) $item->discount_amount, (string) $item->tax_amount);
                if ($cents !== $this->math->scaled((string) $item->getAttribute('line_total'), 2, 1000000000000)) {
                    throw ValidationException::withMessages(['quotation_id' => 'Quotation totals are inconsistent.']);
                }
                $sum += $cents;
                if ($sum > 100000000000000) {
                    throw ValidationException::withMessages(['quotation_id' => 'Quotation total exceeds the supported amount.']);
                }
            }
            if ($sum !== $this->math->scaled((string) $quote->total_amount, 2, 1000000000000)) {
                throw ValidationException::withMessages(['quotation_id' => 'Quotation total does not match its lines.']);
            }
            $old = $this->snapshot($rfq);
            SupplierQuotation::query()->where('rfq_id', $id)->update(['status' => 'not_selected']);
            $quote->fill(['status' => 'accepted', 'revision' => (int) $quote->getAttribute('revision') + 1]);
            $quote->save();
            $rfq->fill(['status' => 'awarded', 'awarded_quotation_id' => $quoteId, 'awarded_by' => $actor->getKey(), 'awarded_at' => now()->toDateTimeString()]);
            $this->bump($rfq);
            $this->audit($actor, $rfq, 'awarded', $old, trim($reason));

            return $rfq->refresh();
        }, 3);
    }

    private function status(Rfq $rfq, string $status): void
    {
        if ($rfq->status !== $status) {
            throw ValidationException::withMessages(['status' => 'This action is not allowed in the current RFQ state.']);
        }
    }

    private function revision(Rfq $rfq, int $revision): void
    {
        if ((int) $rfq->getAttribute('revision') !== $revision) {
            throw ValidationException::withMessages(['revision' => 'This RFQ changed in another session. Reload before continuing.']);
        }
    }

    private function bump(Rfq $rfq): void
    {
        $rfq->setAttribute('revision', (int) $rfq->getAttribute('revision') + 1);
        $rfq->save();
    }

    private function deadline(?string $date): ?string
    {
        return empty($date) ? null : Carbon::parse($date)->endOfDay()->toDateTimeString();
    }

    private function openDeadline(Rfq $rfq): void
    {
        if ($rfq->deadline_at !== null && Carbon::parse((string) $rfq->getRawOriginal('deadline_at'))->isPast()) {
            throw ValidationException::withMessages(['deadline_at' => 'The RFQ response deadline has passed.']);
        }
    }

    /** @param array<int, mixed> $ids */
    private function validateSuppliers(int $companyId, string $currency, array $ids): void
    {
        if ($ids === [] || count($ids) > 50 || count(array_unique(array_map('intval', $ids))) !== count($ids)) {
            throw ValidationException::withMessages(['supplier_ids' => 'Select one to 50 distinct suppliers.']);
        }
        foreach ($ids as $id) {
            if (! Supplier::query()->where('company_id', $companyId)->where('status', 'active')->where('currency_code', $currency)->whereKey($id)->exists()) {
                throw ValidationException::withMessages(['supplier_ids' => 'Suppliers must be active, in this company, and use the RFQ currency.']);
            }
        }
    }

    /** @param array<int, mixed> $ids */
    private function replaceSuppliers(Rfq $rfq, array $ids): void
    {
        DB::table('rfq_suppliers')->where('rfq_id', $rfq->getKey())->delete();
        foreach ($ids as $id) {
            DB::table('rfq_suppliers')->insert(['rfq_id' => $rfq->getKey(), 'supplier_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function validateItems(Rfq $rfq): void
    {
        PurchaseRequisition::query()->where('company_id', $rfq->company_id)->where('status', 'approved')->findOrFail($rfq->purchase_requisition_id);
        $items = DB::table('rfq_items')->where('rfq_id', $rfq->getKey())->get();
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'This RFQ has no lines.']);
        }
        foreach ($items as $item) {
            $product = Product::query()->where('company_id', $rfq->company_id)->where('is_active', true)->findOrFail($item->product_id);
            if ($item->product_variant_id !== null) {
                if (! $product->has_variants) {
                    throw ValidationException::withMessages(['items' => 'This RFQ product no longer uses variants. Review its source configuration.']);
                }
                ProductVariant::query()->where('product_id', $product->getKey())->where('is_active', true)->findOrFail($item->product_variant_id);
            } elseif ($product->has_variants) {
                throw ValidationException::withMessages(['items' => 'Select a valid product variant.']);
            }
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Rfq $rfq): array
    {
        return [...$rfq->attributesToArray(),
            'items' => DB::table('rfq_items')->where('rfq_id', $rfq->getKey())->get()->toArray(),
            'suppliers' => DB::table('rfq_suppliers')->where('rfq_id', $rfq->getKey())->get()->toArray(),
            'quotations' => SupplierQuotation::query()->where('rfq_id', $rfq->getKey())->get()->map(fn ($quote): array => [...$quote->toArray(), 'items' => SupplierQuotationItem::query()->where('supplier_quotation_id', $quote->getKey())->get()->toArray()])->all()];
    }

    /** @param array<string, mixed>|null $old */
    private function audit(User $actor, Rfq $rfq, string $action, ?array $old, ?string $comment = null): void
    {
        DB::table('purchase_rfq_audit_logs')->insert(['company_id' => $rfq->company_id, 'rfq_id' => $rfq->getKey(), 'actor_id' => $actor->getKey(), 'action' => $action,
            'old_values' => $old === null ? null : json_encode($old, JSON_THROW_ON_ERROR), 'new_values' => json_encode($this->snapshot($rfq), JSON_THROW_ON_ERROR),
            'comment' => $comment, 'created_at' => now()]);
    }
}
