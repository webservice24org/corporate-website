<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Sales;

use App\Models\Accounting\AccountingAccountMapping;
use App\Models\Accounting\AccountingSalesCostSlice;
use App\Models\Accounting\AccountingSalesEvent;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Company;
use App\Models\CustomerCreditAllocation;
use App\Models\CustomerReceiptAllocation;
use App\Models\SalesCreditNote;
use App\Models\SalesCreditNoteItem;
use App\Models\SalesDelivery;
use App\Models\SalesDeliveryItem;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingDecimal;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Sales\SalesCompanyAccess;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Return intake and commercial corrections are independent, source-owned financial transitions. */
final class AccountingSalesCorrectionEvidence
{
    public function __construct(private readonly SalesCompanyAccess $access, private readonly AccountingSalesEvidence $sales, private readonly AccountingDecimal $decimal, private readonly AccountingCanonicalPayload $canonical, private readonly AccountingMappingService $mappings, private readonly AccountingBusinessDate $dates) {}

    public function capture(User $actor, int $company, string $kind, int $id): void
    {
        $this->access->requireTransaction();
        $p = $this->projection($company, $kind, $id);
        $permission = match ($kind) {
            'sales_return' => 'erp.sales-returns.post',
            'sales_credit_note' => 'erp.sales-credit-notes.issue',
            'customer_credit_allocation' => $p['original_id'] === null ? 'erp.customer-credits.allocate' : 'erp.customer-credits.reverse',
            default => throw new \LogicException('Unsupported correction source.'),
        };
        $owned = $this->access->authorize($actor, $company, $permission, true);
        $references = [];
        $slices = [];
        if ($kind === 'sales_return') {
            $dependency = $this->sales->recognized($company, 'sales_delivery', $p['delivery_id']);
            $references[] = $this->ref('sales_delivery', $p['delivery_id'], $dependency['projection']['date']);
            [$plan, $slices, $invoiceReferences] = $this->returnPlan($owned, $p);
            $references = [...$references, ...$invoiceReferences];
            $effects = ['valuation' => $plan];
        } elseif ($kind === 'sales_credit_note') {
            $invoice = $this->sales->recognized($company, 'sales_invoice', $p['invoice_id']);
            $references[] = $this->ref('sales_invoice', $p['invoice_id'], $invoice['projection']['date']);
            $this->sameControls($owned, $p['date'], 'sales_invoice', $p['invoice_id'], 'commercial', ['trade_ar', 'output_tax']);
            $this->coverage($company, $p['invoice_id'], $id, null);
            if ($p['return_id'] !== null) {
                $return = $this->sales->recognized($company, 'sales_return', $p['return_id']);
                $references[] = $this->ref('sales_return', $p['return_id'], $return['projection']['date']);
            }
            $lines = [];
            $net = $tax = $gross = '0.00';
            foreach ($p['lines'] as $l) {
                $value = bcsub($l['subtotal'], $l['discount_amount'], 2);
                if (bccomp($value, '0', 2) < 0 || bcadd($value, $l['tax_amount'], 2) !== $l['line_total']) {
                    $this->fail('Credit monetary components disagree.');
                }
                $net = $this->decimal->total([$net, $value]);
                $tax = $this->decimal->total([$tax, $l['tax_amount']]);
                $gross = $this->decimal->total([$gross, $l['line_total']]);
                $this->line($lines, 'sales_allowances', $value, '0.00', 'sales_credit_note_item', $l['id']);
                $this->line($lines, 'output_tax', $l['tax_amount'], '0.00', 'sales_credit_note_item', $l['id']);
            }
            if ($gross === '0.00' || $gross !== $p['grand_total'] || $tax !== $p['tax_total'] || bcsub($p['subtotal'], $p['discount_total'], 2) !== $net) {
                $this->fail('Credit header does not reconcile to positive exact line totals.');
            }
            $this->line($lines, 'trade_ar', '0.00', $gross, $kind, $id, $p['customer_id']);
            $effects = ['commercial' => ['lines' => $lines, 'proof' => ['gl_amount' => $gross, 'net' => $net, 'tax' => $tax], 'reason' => null]];
        } else {
            foreach ([$p['source_invoice_id'], $p['invoice_id']] as $invoiceId) {
                $invoice = $this->sales->recognized($company, 'sales_invoice', $invoiceId);
                if ($p['date'] < $invoice['projection']['date']) {
                    $this->fail('Credit transfer date cannot predate either invoice.');
                }
                $references[] = $this->ref('sales_invoice', $invoiceId, $invoice['projection']['date']);
                $this->sameControls($owned, $p['date'], 'sales_invoice', $invoiceId, 'commercial', ['trade_ar']);
                $this->coverage($company, $invoiceId, null, $id);
            }
            if ($p['original_id'] !== null) {
                $original = $this->sales->recognized($company, $kind, $p['original_id']);
                $references[] = $this->ref($kind, $p['original_id'], $original['projection']['date']);
            }
            $effects = ['transfer' => ['lines' => [], 'proof' => ['gl_amount' => '0.00', 'from_customer_id' => $p['customer_id'], 'to_customer_id' => $p['customer_id'], 'amount' => $p['amount']], 'reason' => 'same_customer_credit_transfer']];
        }
        $unique = [];
        foreach ($references as $reference) {
            $unique[$reference['source_kind'].':'.$reference['source_id']] = $reference;
        } $references = array_values($unique);
        $payload = ['adapter_contract' => 'sales.v1', 'projection' => $p, 'references' => $references, 'effects' => $effects, 'slices' => $slices];
        $event = AccountingSalesEvent::query()->create(['company_id' => $company, 'source_kind' => $kind, 'source_id' => $id, 'payload' => $payload, 'payload_hash' => $this->canonical->hash($payload), 'created_by' => $actor->getKey()]);
        foreach ($slices as $slice) {
            AccountingSalesCostSlice::query()->create(['company_id' => $company, 'sales_event_id' => $event->getKey(), ...$slice]);
        }
    }

    /** Safe source projection: private notes, personal snapshots, and raw operation keys are excluded.
     * @return array<string, mixed>
     */
    public function projection(int $company, string $kind, int $id): array
    {
        if ($kind === 'customer_credit_allocation') {
            return $this->transferProjection($company, $id);
        }
        $isReturn = $kind === 'sales_return';
        if (! $isReturn && $kind !== 'sales_credit_note') {
            $this->fail('Unknown correction source.');
        }
        $header = $isReturn ? SalesReturn::query()->where('company_id', $company)->findOrFail($id) : SalesCreditNote::query()->where('company_id', $company)->findOrFail($id);
        $stamp = $isReturn ? 'posted' : 'issued';
        $this->finalState($header, $stamp);
        $parentKind = $isReturn ? 'sales_delivery' : 'sales_invoice';
        $parentId = (int) $header->getAttribute($parentKind.'_id');
        $parent = $this->sales->prepare($company, $parentKind, $parentId);
        $p = ['source_id' => $id, 'number' => (string) $header->getAttribute('number'), 'date' => $this->date($header->getAttribute('document_date')), 'currency' => (string) $header->getAttribute('currency_code'), 'customer_id' => (int) $header->getAttribute('customer_id'), 'actor' => (int) $header->getAttribute($stamp.'_by'), 'instant' => $this->instant($header->getAttribute($stamp.'_at')), 'native_key_hash' => hash('sha256', (string) $header->getAttribute('idempotency_key')), 'native_payload_hash' => (string) $header->getAttribute('payload_hash'), 'parent_admission_hash' => $parent->facts['evidence_hash'], 'lines' => []];
        $source = $isReturn ? SalesDelivery::query()->where('company_id', $company)->findOrFail($parentId) : SalesInvoice::query()->where('company_id', $company)->findOrFail($parentId);
        if ($p['customer_id'] !== (int) $source->getAttribute('customer_id') || $p['currency'] !== $source->getAttribute('currency_code') || $p['date'] < $this->date($source->getAttribute('document_date'))) {
            $this->fail('Correction company, customer, currency or origin date differs.');
        }
        if ($isReturn) {
            $p['delivery_id'] = $parentId;
            $linked = [];
            foreach (SalesReturnItem::query()->where('sales_return_id', $id)->orderBy('id')->get() as $item) {
                $di = SalesDeliveryItem::query()->where('sales_delivery_id', $parentId)->findOrFail((int) $item->getAttribute('sales_delivery_item_id'));
                if ($di->getAttribute('fulfillment_type') !== 'physical') {
                    $this->fail('Returns require physical delivery lines.');
                }
                $line = ['id' => (int) $item->getKey(), 'delivery_item_id' => (int) $di->getKey(), 'source_quantity' => $this->decimal->source((string) $di->getAttribute('quantity')), 'source_cost' => $this->decimal->source((string) $di->getAttribute('total_cost')), 'unit_cost' => $this->decimal->source((string) $di->getAttribute('unit_cost'))];
                foreach (['quantity', 'accepted_quantity', 'restocked_quantity', 'unbilled_quantity', 'credit_review_quantity'] as $field) {
                    $line[$field] = $this->decimal->source((string) $item->getAttribute($field));
                }
                if (bccomp($line['accepted_quantity'], $line['quantity'], 4) > 0 || bccomp($line['restocked_quantity'], $line['accepted_quantity'], 4) > 0 || bcadd($line['unbilled_quantity'], $line['credit_review_quantity'], 4) !== $line['accepted_quantity']) {
                    $this->fail('Return inspection or billed/unbilled split disagrees.');
                }
                foreach (['product_id', 'product_variant_id', 'unit_id', 'warehouse_id', 'unit_cost'] as $field) {
                    if ((string) $item->getAttribute($field) !== (string) $di->getAttribute($field)) {
                        $this->fail('Return identity differs from its original delivered item.');
                    }
                }
                $line['movement'] = null;
                if ($line['restocked_quantity'] !== '0.0000') {
                    $m = StockMovement::query()->where('company_id', $company)->findOrFail((int) $item->getAttribute('stock_movement_id'));
                    if ($m->getAttribute('movement_type') !== StockMovement::TYPE_SALE_RETURN || $m->getAttribute('direction') !== 'in' || $m->getAttribute('reference_type') !== SalesReturn::class || (int) $m->getAttribute('reference_id') !== $id || $m->getAttribute('reference_number') !== $p['number'] || (int) $m->getAttribute('created_by') !== $p['actor']) {
                        $this->fail('Return movement source trace changed.');
                    }
                    foreach (['product_id', 'product_variant_id', 'warehouse_id'] as $field) {
                        if ((int) $m->getAttribute($field) !== (int) $di->getAttribute($field)) {
                            $this->fail('Return movement master identity changed.');
                        }
                    }
                    $cost = bcmul($line['restocked_quantity'], $line['unit_cost'], 4);
                    if ($this->decimal->source((string) $m->getAttribute('quantity')) !== $line['restocked_quantity'] || $this->decimal->source((string) $m->getAttribute('unit_cost')) !== $line['unit_cost'] || $this->decimal->source((string) $m->getAttribute('total_cost')) !== $cost) {
                        $this->fail('Return stock valuation differs from its exact original-cost intake.');
                    }
                    $line['movement'] = ['id' => (int) $m->getKey(), 'quantity' => $line['restocked_quantity'], 'cost' => $cost, 'instant' => $this->instant($m->getAttribute('occurred_at'))];
                    $linked[] = (int) $m->getKey();
                } elseif ($item->getAttribute('stock_movement_id') !== null) {
                    $this->fail('A nonrestocked return cannot own a stock movement.');
                }
                $p['lines'][] = $line;
            }
            $actual = StockMovement::query()->where('reference_type', SalesReturn::class)->where('reference_id', $id)->pluck('id')->map(static fn ($v): int => (int) $v)->all();
            sort($actual);
            sort($linked);
            if ($actual !== $linked || count(array_unique($linked)) !== count($linked)) {
                $this->fail('Return movement bundle has missing, extra or duplicate members.');
            }
        } else {
            $p['invoice_id'] = $parentId;
            $p['return_id'] = $header->getAttribute('sales_return_id') === null ? null : (int) $header->getAttribute('sales_return_id');
            foreach (['subtotal', 'discount_total', 'tax_total', 'grand_total'] as $field) {
                $p[$field] = $this->decimal->money((string) $header->getAttribute($field));
            }
            if ($p['return_id'] !== null) {
                $p['return_admission_hash'] = $this->sales->prepare($company, 'sales_return', $p['return_id'])->facts['evidence_hash'];
            }
            foreach (SalesCreditNoteItem::query()->where('sales_credit_note_id', $id)->orderBy('id')->get() as $item) {
                $il = SalesInvoiceItem::query()->where('sales_invoice_id', $parentId)->findOrFail((int) $item->getAttribute('sales_invoice_item_id'));
                $line = ['id' => (int) $item->getKey(), 'invoice_item_id' => (int) $il->getKey(), 'return_item_id' => $item->getAttribute('sales_return_item_id') === null ? null : (int) $item->getAttribute('sales_return_item_id'), 'credit_type' => (string) $item->getAttribute('credit_type'), 'quantity' => $this->decimal->source((string) $item->getAttribute('quantity'))];
                foreach (['subtotal', 'discount_amount', 'tax_amount', 'line_total'] as $field) {
                    $line[$field] = $this->decimal->money((string) $item->getAttribute($field));
                }
                if ($line['return_item_id'] !== null) {
                    SalesReturnItem::query()->where('sales_return_id', $p['return_id'])->where('sales_delivery_item_id', $il->getAttribute('sales_delivery_item_id'))->findOrFail($line['return_item_id']);
                }
                $p['lines'][] = $line;
            }
        }
        if ($p['lines'] === [] || count($p['lines']) > 40) {
            $this->fail('Enabled correction posting supports one to forty lines.');
        }

        return $p;
    }

    /** Financial coverage is checked before creating a new correction, never inferred from native balances.
     */
    public function coverage(int $company, int $invoiceId, ?int $skipNote = null, ?int $skipTransfer = null): void
    {
        foreach (SalesCreditNote::query()->where('company_id', $company)->where('sales_invoice_id', $invoiceId)->where('status', 'issued')->when($skipNote !== null, fn ($q) => $q->where('id', '<>', $skipNote))->get() as $note) {
            $this->sales->recognized($company, 'sales_credit_note', (int) $note->getKey());
        }
        foreach (CustomerReceiptAllocation::query()->where('company_id', $company)->where('sales_invoice_id', $invoiceId)->get() as $allocation) {
            app(AccountingReceiptEvidence::class)->recognized($company, 'customer_receipt_allocation', (int) $allocation->getKey());
        }
        foreach (CustomerCreditAllocation::query()->where('company_id', $company)->where(fn ($q) => $q->where('source_sales_invoice_id', $invoiceId)->orWhere('sales_invoice_id', $invoiceId))->when($skipTransfer !== null, fn ($q) => $q->where('id', '<>', $skipTransfer))->get() as $allocation) {
            $this->sales->recognized($company, 'customer_credit_allocation', (int) $allocation->getKey());
        }
    }

    public function creditCoverage(int $company, int $invoiceId): void
    {
        foreach (SalesCreditNote::query()->where('company_id', $company)->where('sales_invoice_id', $invoiceId)->where('status', 'issued')->get() as $note) {
            $this->sales->recognized($company, 'sales_credit_note', (int) $note->getKey());
        }
        foreach (CustomerCreditAllocation::query()->where('company_id', $company)->where(fn ($q) => $q->where('source_sales_invoice_id', $invoiceId)->orWhere('sales_invoice_id', $invoiceId))->get() as $allocation) {
            $this->sales->recognized($company, 'customer_credit_allocation', (int) $allocation->getKey());
        }
    }

    /** @return array<string, mixed> */
    private function transferProjection(int $company, int $id): array
    {
        $a = CustomerCreditAllocation::query()->where('company_id', $company)->findOrFail($id);
        $this->finalState($a, 'posted', false);
        $source = SalesInvoice::query()->where('company_id', $company)->where('status', 'issued')->findOrFail((int) $a->getAttribute('source_sales_invoice_id'));
        $target = SalesInvoice::query()->where('company_id', $company)->where('status', 'issued')->where('customer_id', $source->getAttribute('customer_id'))->where('currency_code', $source->getAttribute('currency_code'))->findOrFail((int) $a->getAttribute('sales_invoice_id'));
        if ($source->getKey() === $target->getKey()) {
            $this->fail('Transfer requires different invoices for one customer.');
        }
        $owned = Company::query()->findOrFail($company);
        $amount = $this->decimal->money((string) $a->getAttribute('amount'));
        if ($amount === '0.00') {
            $this->fail('Credit transfer amount must be positive.');
        }
        $p = ['source_id' => $id, 'number' => 'CA-'.$id, 'date' => $this->dates->fromInstant($owned, CarbonImmutable::instance($a->getAttribute('posted_at'))), 'currency' => (string) $source->getAttribute('currency_code'), 'customer_id' => (int) $source->getAttribute('customer_id'), 'source_invoice_id' => (int) $source->getKey(), 'invoice_id' => (int) $target->getKey(), 'amount' => $amount, 'actor' => (int) $a->getAttribute('posted_by'), 'instant' => $this->instant($a->getAttribute('posted_at')), 'native_key_hash' => hash('sha256', (string) $a->getAttribute('idempotency_key')), 'native_payload_hash' => (string) $a->getAttribute('payload_hash'), 'original_id' => $a->getAttribute('reverses_allocation_id') === null ? null : (int) $a->getAttribute('reverses_allocation_id'), 'source_invoice_admission_hash' => $this->sales->prepare($company, 'sales_invoice', (int) $source->getKey())->facts['evidence_hash'], 'invoice_admission_hash' => $this->sales->prepare($company, 'sales_invoice', (int) $target->getKey())->facts['evidence_hash']];
        if ($p['original_id'] !== null) {
            $original = CustomerCreditAllocation::query()->where('company_id', $company)->whereNull('reverses_allocation_id')->findOrFail($p['original_id']);
            foreach (['source_sales_invoice_id', 'sales_invoice_id', 'amount'] as $field) {
                if ((string) $a->getAttribute($field) !== (string) $original->getAttribute($field)) {
                    $this->fail('Credit compensation must retain original endpoints and amount.');
                }
            }
            $p['original_projection_hash'] = $this->canonical->hash($this->transferProjection($company, $p['original_id']));
        }

        return $p;
    }

    /** Original dispatch is consumed by invoices and unbilled returns exactly once. Billed returns release COGS only for restocked units.
     * @param  array<string, mixed>  $p
     * @return array{array<string, mixed>, list<array<string, mixed>>, list<array<string, mixed>>}
     */
    private function returnPlan(Company $company, array $p): array
    {
        $companyId = (int) $company->getKey();
        $lines = [];
        $slices = [];
        $facts = [];
        $references = [];
        $sourceCost = '0.0000';
        foreach ($p['lines'] as $l) {
            $sourceCost = $this->decimal->source(bcadd($sourceCost, $l['source_cost'], 4));
            $cursor = $this->sales->consumed($companyId, 0, [...$l, 'current_return_id' => $p['source_id']]);
            $after = bcadd($cursor, $l['unbilled_quantity'], 4);
            $dispatch = $this->decimal->costShare($l['source_cost'], $l['source_quantity'], $cursor, $after);
            if ($l['unbilled_quantity'] !== '0.0000') {
                $slices[] = ['source_line_id' => $l['id'], 'sales_delivery_item_id' => $l['delivery_item_id'], 'quantity' => $l['unbilled_quantity'], 'quantity_before' => $cursor, 'quantity_after' => $after, 'amount' => $dispatch];
            }
            $unbilledRestock = bccomp($l['restocked_quantity'], $l['unbilled_quantity'], 4) < 0 ? $l['restocked_quantity'] : $l['unbilled_quantity'];
            $billedRestock = bcsub($l['restocked_quantity'], $unbilledRestock, 4);
            [$cogs, $refs] = $this->billedCost($company, $p, $l, $billedRestock);
            $references = [...$references, ...$refs];
            $stock = $l['movement'] === null ? '0.00' : $this->decimal->roundedCost($l['movement']['cost']);
            if ($dispatch !== '0.00') {
                $this->sameControls($company, $p['date'], 'sales_delivery', $p['delivery_id'], 'valuation', ['dispatched_inventory']);
            }
            $this->line($lines, 'inventory', $stock, '0.00', 'sales_return_item', $l['id']);
            $this->line($lines, 'dispatched_inventory', '0.00', $dispatch, 'sales_return_item', $l['id']);
            $this->line($lines, 'cogs', '0.00', $cogs, 'sales_return_item', $l['id']);
            // Includes unbilled damaged value and the exact subledger-to-original-GL rounding residual.
            $difference = bcsub(bcadd($dispatch, $cogs, 2), $stock, 2);
            if (bccomp($difference, '0', 2) > 0) {
                $this->line($lines, 'inventory_loss', $difference, '0.00', 'sales_return_item', $l['id']);
            } elseif (bccomp($difference, '0', 2) < 0) {
                $this->line($lines, 'inventory_loss', '0.00', bcsub('0', $difference, 2), 'sales_return_item', $l['id']);
            }
            $facts[] = ['source_line_id' => $l['id'], 'unbilled_cost' => $dispatch, 'restored_cogs' => $cogs, 'inventory_value' => $stock, 'variance' => $difference];
        }
        $amount = '0.00';
        foreach ($lines as $line) {
            $amount = $this->decimal->total([$amount, $line['debit']]);
        }

        return [['lines' => $lines, 'proof' => ['gl_amount' => $amount, 'source_cost' => $sourceCost, 'source_line_id' => $p['source_id'], 'line_facts' => array_map(static fn (array $fact): array => ['source_line_id' => $fact['source_line_id'], 'amount' => $fact['inventory_value']], $facts)], 'reason' => $lines === [] ? ($sourceCost === '0.0000' ? 'zero_source_cost' : 'zero_return_valuation') : null, 'valuation_facts' => $facts], $slices, $references];
    }

    /** Billed-return cursor follows original invoice cost slices in their recognition order. Damaged accepted units consume quantity but restore no COGS.
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $l
     * @return array{string, list<array<string, mixed>>}
     */
    private function billedCost(Company $company, array $p, array $l, string $restocked): array
    {
        $companyId = (int) $company->getKey();
        $before = '0.0000';
        $references = [];
        $amount = '0.00';
        foreach (SalesReturnItem::query()->where('sales_delivery_item_id', $l['delivery_item_id'])->where('sales_return_id', '<>', $p['source_id'])->orderBy('id')->get() as $old) {
            $header = SalesReturn::query()->where('company_id', $companyId)->where('status', 'posted')->find((int) $old->getAttribute('sales_return_id'));
            if ($header === null) {
                continue;
            }
            $this->sales->recognized($companyId, 'sales_return', (int) $header->getKey());
            $before = bcadd($before, (string) $old->getAttribute('credit_review_quantity'), 4);
        }
        $endRestock = bcadd($before, $restocked, 4);
        $endAccepted = bcadd($before, $l['credit_review_quantity'], 4);
        $position = '0.0000';
        foreach (AccountingSalesCostSlice::query()->where('company_id', $companyId)->where('sales_delivery_item_id', $l['delivery_item_id'])->orderBy('id')->get() as $slice) {
            $event = AccountingSalesEvent::query()->where('company_id', $companyId)->findOrFail((int) $slice->getAttribute('sales_event_id'));
            if ($event->getAttribute('source_kind') !== 'sales_invoice') {
                continue;
            }
            $invoiceId = (int) $event->getAttribute('source_id');
            $invoice = $this->sales->recognized($companyId, 'sales_invoice', $invoiceId);
            $q = (string) $slice->getAttribute('quantity');
            $next = bcadd($position, $q, 4);
            $low = bccomp($before, $position, 4) > 0 ? $before : $position;
            $high = bccomp($endRestock, $next, 4) < 0 ? $endRestock : $next;
            if (bccomp($high, $low, 4) > 0) {
                $share = $this->decimal->costShare(bcadd((string) $slice->getAttribute('amount'), '0', 4), $q, bcsub($low, $position, 4), bcsub($high, $position, 4));
                $amount = $this->decimal->total([$amount, $share]);
                if ($share !== '0.00') {
                    $this->sameControls($company, $p['date'], 'sales_invoice', $invoiceId, 'cost', ['cogs']);
                }
            }
            if ($l['credit_review_quantity'] !== '0.0000' && $p['date'] < $invoice['projection']['date']) {
                $this->fail('A billed return cannot predate its recognized invoice cost.');
            }
            $references[] = $this->ref('sales_invoice', $invoiceId, $invoice['projection']['date']);
            $position = $next;
        }
        if (bccomp($endAccepted, $position, 4) > 0) {
            $this->fail('Billed return quantity exceeds recognized original invoice costs.');
        }

        return [$amount, $references];
    }

    /** @param list<string> $roles */
    private function sameControls(Company $company, string $date, string $kind, int $id, string $effect, array $roles): void
    {
        $companyId = (int) $company->getKey();
        $current = $this->mappings->resolveForSource($company, $date, $roles);
        $original = AccountingSourceEffect::query()->where('company_id', $companyId)->where('source_kind', $kind)->where('source_id', $id)->where('event_token', 'original')->where('effect_kind', $effect)->firstOrFail();
        foreach ($roles as $role) {
            $account = AccountingAccountMapping::query()->where('company_id', $companyId)->where('mapping_version_id', $original->getAttribute('mapping_version_id'))->where('role', $role)->value('account_id');
            if ((int) $account !== $current['accounts'][$role]) {
                $this->fail('Correction control account differs from its original profile; a verified transition is required.');
            }
        }
    }

    private function finalState(Model $row, string $stamp, bool $status = true): void
    {
        if (($status && $row->getAttribute('status') !== $stamp) || $row->getAttribute($stamp.'_by') === null || ! $row->getAttribute($stamp.'_at') instanceof DateTimeInterface || ! is_string($row->getAttribute('idempotency_key')) || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $row->getAttribute('payload_hash'))) {
            $this->fail('Final native correction state, actor, timestamp, key and hash are required.');
        }
    }

    /** @return array<string, mixed> */
    private function ref(string $kind, int $id, string $date): array
    {
        return ['source_kind' => $kind, 'source_id' => $id, 'origin_date' => $date];
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
            $this->fail('Source date is required.');
        }

return $value->format('Y-m-d');
    }

    private function instant(mixed $value): string
    {
        if (! $value instanceof DateTimeInterface) {
            $this->fail('Source timestamp is required.');
        }

return CarbonImmutable::instance($value)->utc()->format('Y-m-d\TH:i:sP');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['accounting_sales' => $message]);
    }
}
