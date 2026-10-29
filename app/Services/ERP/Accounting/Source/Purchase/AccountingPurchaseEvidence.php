<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Purchase;

use App\Models\Accounting\AccountingPurchaseCostSlice;
use App\Models\Accounting\AccountingPurchaseEvent;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\AccountingSupplierCredit;
use App\Models\Accounting\JournalEntry;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\SupplierBill;
use App\Models\SupplierBillItem;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingDecimal;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\Source\AccountingAutomaticEvidence;
use App\Services\ERP\Accounting\Source\AccountingPreparedSourceEvent;
use App\Services\ERP\Accounting\Source\AccountingSourceEffectPlan;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Frozen posting intent and cumulative cost slices are admitted only during a new source transition. */
final class AccountingPurchaseEvidence
{
    public function __construct(private readonly AccountingDecimal $decimal, private readonly AccountingCanonicalPayload $canonical, private readonly AccountingBusinessDate $dates, private readonly AccountingAutomaticEvidence $journals, private readonly AccountingMappingService $mappings) {}

    public function capture(User $actor, int $companyId, string $kind, int $id): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Capture belongs to the source transaction.');
        }
        $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
        $permission = match ($kind) {
            'goods_receipt' => 'erp.goods-receipts.post', 'supplier_bill' => 'erp.supplier-bills.approve', 'purchase_return' => 'erp.purchase-returns.post', 'stock_transfer' => 'erp.stock-transfers.post', 'supplier_credit' => 'erp.supplier-credits.issue', default => throw new \LogicException('Uninstalled purchase source.')
        };
        app(PurchaseCompanyAccess::class)->authorize($actor, $companyId, $permission);
        $projection = $this->projection($companyId, $kind, $id);
        $date = in_array($kind, ['supplier_bill', 'supplier_credit'], true) ? (string) $projection['document_date'] : $this->date($company, $projection['instant']);
        $references = [];
        if (in_array($kind, ['supplier_bill', 'purchase_return'], true)) {
            $receipt = $this->recognizedReceipt($companyId, (int) $projection['receipt_id']);
            $references[] = ['source_kind' => 'goods_receipt', 'source_id' => (int) $projection['receipt_id'], 'origin_date' => (string) $receipt['date']];
        }
        if ($kind === 'supplier_credit') {
            foreach (['supplier_bill' => 'supplier_bill_id', 'purchase_return' => 'purchase_return_id'] as $parentKind => $field) {
                $parent = $this->recognized($companyId, $parentKind, (int) $projection[$field]);
                $references[] = ['source_kind' => $parentKind, 'source_id' => (int) $projection[$field], 'origin_date' => $parent['date']];
            }
        }
        [$lines, $proof, $reason, $slices] = match ($kind) {
            'goods_receipt' => $this->receipt($projection),
            'supplier_bill' => $this->bill($companyId, $projection),
            'purchase_return' => $this->returned($companyId, $projection),
            'supplier_credit' => $this->credit($projection),
            default => [[], ['gl_amount' => '0.00', 'source_line_id' => $id], 'same_company_transfer', []],
        };
        $payload = ['adapter_contract' => 'purchase.v1', 'projection' => $projection, 'date' => $date, 'currency' => (string) $projection['currency'], 'references' => $references, 'lines' => $lines, 'proof' => $proof, 'reason' => $reason, 'effect_kind' => in_array($kind, ['supplier_bill', 'supplier_credit'], true) ? 'commercial' : 'valuation', 'slices' => $slices];
        $event = AccountingPurchaseEvent::query()->create(['company_id' => $companyId, 'source_kind' => $kind, 'source_id' => $id, 'payload' => $payload, 'payload_hash' => $this->canonical->hash($payload), 'created_by' => $actor->getKey()]);
        foreach ($slices as $slice) {
            AccountingPurchaseCostSlice::query()->create(['company_id' => $companyId, 'purchase_event_id' => $event->getKey(), ...$slice]);
        }
    }

    public function prepare(int $companyId, string $kind, int $id): AccountingPreparedSourceEvent
    {
        $record = AccountingPurchaseEvent::query()->where('company_id', $companyId)->where('source_kind', $kind)->where('source_id', $id)->first();
        if ($record === null) {
            $this->fail('The original financial admission is missing; reconcile the source instead of backfilling it.');
        }
        $payload = $this->checked($record);
        if ($this->canonical->hash($payload['projection']) !== $this->canonical->hash($this->projection($companyId, $kind, $id))) {
            $this->fail('Source or linked movement facts changed after original recognition.');
        }
        $proof = [...$payload['proof'], 'evidence_hash' => (string) $record->getAttribute('payload_hash')];

        return new AccountingPreparedSourceEvent($companyId, $kind, $id, $kind === 'supplier_bill' ? 'approved' : ($kind === 'supplier_credit' ? 'issued' : 'posted'), 'original', $payload['currency'], $payload['date'], $payload['date'], $payload['date'], ['source_number' => $payload['projection']['number'], 'currency_code' => $payload['currency'], 'evidence_hash' => (string) $record->getAttribute('payload_hash')], [new AccountingSourceEffectPlan($payload['effect_kind'], $payload['lines'], $proof, $payload['reason'])], $payload['references']);
    }

    /** @return array<string, mixed> */
    private function checked(AccountingPurchaseEvent $record): array
    {
        $payload = $record->getAttribute('payload');
        if (! is_array($payload) || ($payload['adapter_contract'] ?? null) !== 'purchase.v1' || ! hash_equals((string) $record->getAttribute('payload_hash'), $this->canonical->hash($payload))) {
            $this->fail('Purchase admission evidence is missing or damaged.');
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function recognizedReceipt(int $companyId, int $id): array
    {
        return $this->recognized($companyId, 'goods_receipt', $id);
    }

    /** Internal dependency reader, after caller authorization/company lock. @return array<string, mixed> */
    public function recognized(int $companyId, string $kind, int $id): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Dependency verification belongs to an authorized source transaction.');
        }
        $event = AccountingPurchaseEvent::query()->where('company_id', $companyId)->where('source_kind', $kind)->where('source_id', $id)->first();
        if ($event === null) {
            $this->fail('This prerequisite has no recognized live financial effect. Legacy sources require verified cutover coverage in 6.6E.');
        }
        $payload = $this->checked($event);
        if ($this->canonical->hash($payload['projection']) !== $this->canonical->hash($this->projection($companyId, $kind, $id))) {
            $this->fail('Prerequisite source evidence changed.');
        }
        $storedSlices = AccountingPurchaseCostSlice::query()->where('company_id', $companyId)->where('purchase_event_id', $event->getKey())->get()->keyBy('source_line_id');
        if ($storedSlices->count() !== count($payload['slices'])) {
            $this->fail('Recognized Purchase cost-slice bundle is incomplete.');
        }
        foreach ($payload['slices'] as $slice) {
            $stored = $storedSlices->get($slice['source_line_id']);
            if ($stored === null) {
                $this->fail('Recognized Purchase cost slice is missing.');
            }
            foreach ($slice as $field => $value) {
                if ((string) $stored->getAttribute($field) !== (string) $value) {
                    $this->fail('Recognized Purchase cost slice changed.');
                }
            }
        }
        $this->verifyRecognition($companyId, $event, $payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function verifyRecognition(int $companyId, AccountingPurchaseEvent $event, array $payload): void
    {
        $kind = (string) $event->getAttribute('source_kind');
        $id = (int) $event->getAttribute('source_id');
        $effectKind = $payload['effect_kind'];
        $effects = AccountingSourceEffect::query()->where('company_id', $companyId)->where('source_kind', $kind)->where('source_id', $id)->get();
        $effect = $effects->count() === 1 ? $effects->first() : null;
        if ($effect === null || $effect->getAttribute('event_kind') !== ($kind === 'supplier_bill' ? 'approved' : ($kind === 'supplier_credit' ? 'issued' : 'posted')) || $effect->getAttribute('event_token') !== 'original' || $effect->getAttribute('effect_kind') !== $effectKind) {
            $this->fail('A prerequisite financial bundle is incomplete.');
        }
        $snapshot = $effect->getAttribute('source_snapshot');
        $body = is_array($snapshot) ? ($snapshot['evidence'] ?? null) : null;
        if (! is_array($body) || ! is_array($body['source'] ?? null) || ! hash_equals((string) ($snapshot['evidence_hash'] ?? ''), $this->canonical->hash($body)) || ($body['proof']['evidence_hash'] ?? null) !== $event->getAttribute('payload_hash') || ! hash_equals((string) $effect->getAttribute('source_payload_hash'), $this->canonical->hash($body['source']))) {
            $this->fail('Prerequisite financial evidence does not match its admitted source.');
        }
        $source = $body['source'];
        if (($source['company_id'] ?? null) !== $companyId || ($source['source_kind'] ?? null) !== $kind || ($source['source_id'] ?? null) !== $id || ! hash_equals($this->canonical->hash(['proof' => $source['effect_proofs'][$effectKind] ?? null]), $this->canonical->hash(['proof' => $body['proof']])) || ($body['bundle_effects'] ?? null) !== [$effectKind] || ($body['outcome'] ?? null) !== $effect->getAttribute('outcome') || ($body['no_journal_reason'] ?? null) !== $payload['reason']) {
            $this->fail('Prerequisite source identity and financial evidence disagree.');
        }
        $this->mappings->verifyRecordedProfile($companyId, (int) $effect->getAttribute('mapping_version_id'), $payload['currency'], (string) $effect->getAttribute('contract_version'), (string) $body['mapping_hash']);
        if ($effect->getAttribute('outcome') === 'posted') {
            $journal = JournalEntry::query()->where('company_id', $companyId)->findOrFail((int) $effect->getAttribute('journal_entry_id'));
            if ((int) $journal->getAttribute('mapping_version_id') !== (int) $effect->getAttribute('mapping_version_id') || $journal->getAttribute('payload_hash') !== ($body['journal_hash'] ?? null) || $journal->getAttribute('currency_code') !== $payload['currency']) {
                $this->fail('Prerequisite journal/profile identity changed.');
            }
            $this->journals->verify($journal, (string) $effect->getAttribute('source_payload_hash'), $effectKind, (string) $body['mapping_hash']);
        } elseif ($effect->getAttribute('outcome') !== 'verified_no_journal' || $effect->getAttribute('journal_entry_id') !== null || $effect->getAttribute('no_journal_reason') !== $payload['reason'] || $payload['lines'] !== [] || ($body['proof']['gl_amount'] ?? null) !== '0.00') {
            $this->fail('Invalid prerequisite zero-journal outcome.');
        }
    }

    /** A safe, explicit projection: no notes, addresses, supplier private fields or live balance valuation.
     * @return array<string, mixed>
     */
    private function projection(int $companyId, string $kind, int $id): array
    {
        if ($kind === 'supplier_credit') {
            $credit = AccountingSupplierCredit::query()->where('company_id', $companyId)->where('status', 'issued')->findOrFail((int) $id);
            if ($credit->getAttribute('approved_by') === null || $credit->getAttribute('approved_at') === null || $credit->getAttribute('issued_by') === null || $credit->getAttribute('issued_at') === null || (int) $credit->getAttribute('approved_by') === (int) $credit->getAttribute('created_by')) {
                $this->fail('Independent supplier-credit approval and issue evidence are required.');
            }
            $projection = app(AccountingSupplierCreditService::class)->financialProjection($companyId, $id);
            if (! hash_equals((string) $credit->getAttribute('payload_hash'), $this->canonical->hash($projection))) {
                $this->fail('Issued supplier credit financial evidence changed.');
            }
            $bill = SupplierBill::query()->where('company_id', $companyId)->findOrFail((int) $credit->getAttribute('supplier_bill_id'));

            return [...$projection, 'supplier_id' => (int) $bill->getAttribute('supplier_id'), 'approved_by' => (int) $credit->getAttribute('approved_by'), 'approved_at' => $this->instant($credit->getAttribute('approved_at')), 'issued_by' => (int) $credit->getAttribute('issued_by'), 'issued_at' => $this->instant($credit->getAttribute('issued_at'))];
        }
        if ($kind === 'stock_transfer') {
            return $this->transferProjection($companyId, $id);
        }
        if ($kind === 'goods_receipt') {
            $header = GoodsReceipt::query()->where('company_id', $companyId)->findOrFail((int) $id);
            if ($header->getAttribute('status') !== 'posted' || $header->getAttribute('posted_by') === null || $header->getAttribute('posted_at') === null) {
                $this->fail('Only a posted receipt can be admitted.');
            }
            $order = PurchaseOrder::query()->where('company_id', $companyId)->findOrFail((int) $header->getAttribute('purchase_order_id'));
            $supplier = (int) $order->getAttribute('supplier_id');
            $lines = [];
            foreach (GoodsReceiptItem::query()->where('goods_receipt_id', $id)->orderBy('id')->get() as $line) {
                $po = PurchaseOrderItem::query()->where('purchase_order_id', $order->getKey())->findOrFail((int) $line->getAttribute('purchase_order_item_id'));
                $stock = (bool) $line->getAttribute('is_stock_item');
                $quantity = $this->decimal->source((string) $line->getAttribute('quantity_received'));
                $movement = $this->movement($companyId, $line->getAttribute('stock_movement_id'), GoodsReceipt::class, $id, (int) $header->getAttribute('warehouse_id'), (int) $po->getAttribute('product_id'), $po->getAttribute('product_variant_id'), $quantity, $stock, StockMovement::TYPE_PURCHASE_RECEIPT, 'in');
                if ($movement !== null && $movement['unit_cost'] !== $this->decimal->source((string) $line->getAttribute('unit_cost'))) {
                    $this->fail('Receipt unit cost disagrees with stock movement.');
                }
                $lines[] = ['id' => (int) $line->getKey(), 'stock' => $stock, 'quantity' => $quantity, 'unit_cost' => $this->decimal->source((string) $line->getAttribute('unit_cost')), 'movement' => $movement];
            }
            $this->completeMovements(GoodsReceipt::class, $id, array_column(array_filter(array_column($lines, 'movement')), 'id'));
            $result = ['number' => (string) $header->getAttribute('number'), 'currency' => (string) $order->getAttribute('currency_code'), 'supplier_id' => $supplier, 'order_id' => (int) $order->getKey(), 'warehouse_id' => (int) $header->getAttribute('warehouse_id'), 'instant' => $this->instant($header->getAttribute('received_at')), 'posted_by' => (int) $header->getAttribute('posted_by'), 'posted_at' => $this->instant($header->getAttribute('posted_at')), 'lines' => $lines];
        } else {
            $header = $kind === 'supplier_bill' ? SupplierBill::query()->where('company_id', $companyId)->findOrFail($id) : PurchaseReturn::query()->where('company_id', $companyId)->findOrFail((int) $id);
            $expected = $kind === 'supplier_bill' ? 'approved' : 'posted';
            if ($header->getAttribute('status') !== $expected || $header->getAttribute($kind === 'supplier_bill' ? 'approved_at' : 'posted_at') === null || $header->getAttribute('approved_by') === null || (int) $header->getAttribute('approved_by') === (int) $header->getAttribute('created_by')) {
                $this->fail('Independent approval and final source state are required.');
            }
            $receipt = GoodsReceipt::query()->where('company_id', $companyId)->where('status', 'posted')->findOrFail((int) $header->getAttribute('goods_receipt_id'));
            $order = PurchaseOrder::query()->where('company_id', $companyId)->findOrFail((int) $receipt->getAttribute('purchase_order_id'));
            if ((int) $header->getAttribute('supplier_id') !== (int) $order->getAttribute('supplier_id') || (int) $header->getAttribute('purchase_order_id') !== (int) $order->getKey()) {
                $this->fail('Source purchasing ownership is inconsistent.');
            }
            $supplier = (int) $header->getAttribute('supplier_id');
            $lines = [];
            $items = $kind === 'supplier_bill' ? SupplierBillItem::query()->where('supplier_bill_id', $id)->orderBy('id')->get() : PurchaseReturnItem::query()->where('purchase_return_id', $id)->orderBy('id')->get();
            foreach ($items as $line) {
                $source = GoodsReceiptItem::query()->where('goods_receipt_id', $receipt->getKey())->findOrFail((int) $line->getAttribute('goods_receipt_item_id'));
                $quantity = $this->decimal->source((string) $line->getAttribute('quantity'));
                $row = ['id' => (int) $line->getKey(), 'receipt_line_id' => (int) $source->getKey(), 'stock' => (bool) $source->getAttribute('is_stock_item'), 'quantity' => $quantity, 'received_quantity' => $this->decimal->source((string) $source->getAttribute('quantity_received')), 'receipt_cost' => $this->receiptCost($source)];
                if ($kind === 'supplier_bill') {
                    if ((int) $line->getAttribute('purchase_order_item_id') !== (int) $source->getAttribute('purchase_order_item_id')) {
                        $this->fail('Bill line is outside its receipt order.');
                    }
                    $row += ['total' => $this->decimal->money((string) $line->getAttribute('line_total')), 'tax' => $this->decimal->money((string) $line->getAttribute('tax_amount')), 'discount' => $this->decimal->money((string) $line->getAttribute('discount_amount')), 'unit_price' => $this->decimal->money((string) $line->getAttribute('unit_price'))];
                } else {
                    $po = PurchaseOrderItem::query()->where('purchase_order_id', $order->getKey())->findOrFail((int) $source->getAttribute('purchase_order_item_id'));
                    if ((bool) $line->getAttribute('is_stock_item') !== $row['stock']) {
                        $this->fail('Return inventory classification changed.');
                    }
                    $row['movement'] = $this->movement($companyId, $line->getAttribute('stock_movement_id'), PurchaseReturn::class, $id, (int) $receipt->getAttribute('warehouse_id'), (int) $po->getAttribute('product_id'), $po->getAttribute('product_variant_id'), $quantity, $row['stock'], StockMovement::TYPE_PURCHASE_RETURN, 'out');
                    if ($row['movement'] !== null && $row['movement']['unit_cost'] !== $this->decimal->source((string) $line->getAttribute('unit_cost'))) {
                        $this->fail('Return cost disagrees with its actual stock movement.');
                    }
                }
                $lines[] = $row;
            }
            if ($kind === 'purchase_return') {
                $this->completeMovements(PurchaseReturn::class, $id, array_column(array_filter(array_column($lines, 'movement')), 'id'));
            }
            $result = ['number' => (string) $header->getAttribute('number'), 'currency' => (string) $order->getAttribute('currency_code'), 'supplier_id' => $supplier, 'receipt_id' => (int) $receipt->getKey(), 'receipt_projection_hash' => $this->canonical->hash($this->projection($companyId, 'goods_receipt', (int) $receipt->getKey())), 'approved_by' => (int) $header->getAttribute('approved_by'), 'approved_at' => $this->instant($header->getAttribute('approved_at')), 'lines' => $lines];
            if ($kind === 'supplier_bill') {
                if ($header->getAttribute('currency_code') !== $result['currency']) {
                    $this->fail('Bill and order currencies differ.');
                }
                $date = $header->getAttribute('invoice_date');
                if (! $date instanceof DateTimeInterface) {
                    $this->fail('Missing invoice date.');
                }
                $result += ['document_date' => $date->format('Y-m-d'), 'grand_total' => $this->decimal->money((string) $header->getAttribute('grand_total')), 'tax_total' => $this->decimal->money((string) $header->getAttribute('tax_total')), 'discount_total' => $this->decimal->money((string) $header->getAttribute('discount_total')), 'subtotal' => $this->decimal->money((string) $header->getAttribute('subtotal'))];
                $payable = DB::table('supplier_payables')->where('supplier_bill_id', $id)->first();
                if ($payable === null || (int) $payable->company_id !== $companyId || (int) $payable->supplier_id !== $supplier || (string) $payable->currency_code !== $result['currency'] || $this->decimal->money((string) $payable->amount) !== $result['grand_total']) {
                    $this->fail('Bill payable is missing or inconsistent.');
                }
            } else {
                $result['instant'] = $this->instant($header->getAttribute('posted_at'));
                $result['posted_by'] = (int) $header->getAttribute('posted_by');
            }
        }
        if (! DB::table('suppliers')->where('company_id', $companyId)->where('id', $supplier)->exists() || $result['lines'] === [] || count($result['lines']) > 40) {
            $this->fail('Purchase evidence requires a company-owned supplier and one to forty lines.');
        }

        return ['source_id' => $id, 'source_kind' => $kind, ...$result];
    }

    private function instant(mixed $value): string
    {
        if (! $value instanceof DateTimeInterface) {
            $this->fail('A persisted source timestamp is required.');
        }

        return CarbonImmutable::instance($value)->utc()->format('Y-m-d\TH:i:sP');
    }

    private function date(Company $company, string $instant): string
    {
        return $this->dates->fromInstant($company, CarbonImmutable::parse($instant));
    }

    /** @return array<string, mixed>|null */
    private function movement(int $company, mixed $id, string $reference, int $source, int $warehouse, int $product, mixed $variant, string $quantity, bool $stock, string $type, string $direction): ?array
    {
        if (! $stock || bccomp($quantity, '0', 4) === 0) {
            if ($id !== null) {
                $this->fail('A nonstock or unaccepted line cannot own a movement.');
            }

            return null;
        }
        $m = StockMovement::query()->where('company_id', $company)->findOrFail((int) $id);
        if ($m->getAttribute('reference_type') !== $reference || (int) $m->getAttribute('reference_id') !== $source || (int) $m->getAttribute('warehouse_id') !== $warehouse || (int) $m->getAttribute('product_id') !== $product || (int) $m->getAttribute('product_variant_id') !== (int) $variant || $m->getAttribute('movement_type') !== $type || $m->getAttribute('direction') !== $direction || $this->decimal->source((string) $m->getAttribute('quantity')) !== $quantity || ! DB::table('products')->where('company_id', $company)->where('id', $product)->exists() || ! DB::table('warehouses')->where('company_id', $company)->where('id', $warehouse)->exists()) {
            $this->fail('The linked movement does not belong to this source, company, warehouse, product and quantity.');
        }
        if ($variant !== null && ! DB::table('product_variants')->where('id', $variant)->where('product_id', $product)->exists()) {
            $this->fail('Movement variant belongs to another product.');
        }
        $cost = $this->decimal->source((string) $m->getAttribute('unit_cost'));
        $total = $this->decimal->source((string) $m->getAttribute('total_cost'));
        if (bcmul($quantity, $cost, 4) !== $total) {
            $this->fail('Movement quantity times cost disagrees with its persisted value.');
        }

        return ['id' => (int) $m->getKey(), 'warehouse' => $warehouse, 'product' => $product, 'variant' => (int) $variant, 'quantity' => $quantity, 'unit_cost' => $cost, 'total_cost' => $total, 'instant' => $this->instant($m->getAttribute('occurred_at'))];
    }

    /** @param list<int> $linked */
    private function completeMovements(string $type, int $id, array $linked): void
    {
        $actual = StockMovement::query()->where('reference_type', $type)->where('reference_id', $id)->pluck('id')->map(static fn ($value): int => (int) $value)->all();
        sort($actual, SORT_NUMERIC);
        sort($linked, SORT_NUMERIC);
        if (count(array_unique($linked)) !== count($linked) || $actual !== $linked) {
            $this->fail('Source movement links are duplicated, incomplete or have unexpected extra entries.');
        }
    }

    private function receiptCost(GoodsReceiptItem $line): string
    {
        if (! (bool) $line->getAttribute('is_stock_item') || $line->getAttribute('stock_movement_id') === null) {
            return '0.0000';
        }

        return $this->decimal->source((string) StockMovement::query()->findOrFail((int) $line->getAttribute('stock_movement_id'))->getAttribute('total_cost'));
    }

    /** @param array<string, mixed> $p @return array{list<array<string, mixed>>, array<string, mixed>, ?string, list<array<string, mixed>>} */
    private function receipt(array $p): array
    {
        $lines = [];
        $costs = [];
        $sum = '0.00';
        foreach ($p['lines'] as $line) {
            $cost = $line['movement'] === null ? '0.0000' : $line['movement']['total_cost'];
            $costs[] = ['source_cost' => $cost];
            $amount = $this->decimal->roundedCost($cost);
            $sum = $this->decimal->total([$sum, $amount]);
            $this->line($lines, 'inventory', $amount, '0.00', 'goods_receipt_item', $line['id']);
            $this->line($lines, 'grni', '0.00', $amount, 'goods_receipt_item', $line['id']);
        }
        $sourceCost = '0.0000';
        foreach ($costs as $cost) {
            $sourceCost = $this->decimal->source(bcadd($sourceCost, $cost['source_cost'], 4));
        }
        $proof = ['gl_amount' => $sum, 'source_cost' => $sourceCost, 'line_facts' => $costs];

        return [$lines, $proof, $lines === [] ? ($sourceCost === '0.0000' ? 'zero_source_cost' : 'subcent_valuation') : null, []];
    }

    /** @param array<string, mixed> $p @return array{list<array<string, mixed>>, array<string, mixed>, ?string, list<array<string, mixed>>} */
    private function bill(int $company, array $p): array
    {
        $lines = [];
        $slices = [];
        $grand = $tax = $discount = $subtotal = '0.00';
        foreach ($p['lines'] as $l) {
            if (bccomp($l['total'], $l['tax'], 2) < 0) {
                $this->fail('Bill tax exceeds its line total.');
            }
            $net = bcsub($l['total'], $l['tax'], 2);
            $grand = $this->decimal->total([$grand, $l['total']]);
            $tax = $this->decimal->total([$tax, $l['tax']]);
            $discount = $this->decimal->total([$discount, $l['discount']]);
            $subtotal = $this->decimal->total([$subtotal, $net, $l['discount']]);
            [$consumed, $billed, $returned] = $this->ledger($company, $l, $p['source_kind'], (int) $p['source_id']);
            if (! $l['stock']) {
                $slices[] = $this->slice($l, $l['quantity'], '0.0000', '0.00', '0.00');
            }
            if ($l['stock']) {
                $after = bcadd($consumed, $l['quantity'], 4);
                $release = $this->decimal->costShare($l['receipt_cost'], $l['received_quantity'], $consumed, $after);
                $slices[] = $this->slice($l, $l['quantity'], '0.0000', $release, '0.00');
                $this->line($lines, 'grni', $release, '0.00', 'supplier_bill_item', $l['id']);
                $this->difference($lines, $net, $release, 'supplier_bill_item', $l['id']);
            } else {
                $this->line($lines, 'general_expense', $net, '0.00', 'supplier_bill_item', $l['id']);
            }
            $this->line($lines, 'input_tax', $l['tax'], '0.00', 'supplier_bill_item', $l['id']);
        }
        if ($grand !== $p['grand_total'] || $tax !== $p['tax_total'] || $discount !== $p['discount_total'] || $subtotal !== $p['subtotal'] || bccomp($grand, '0', 2) <= 0) {
            $this->fail('Bill financial totals do not reconcile to its lines.');
        }
        $this->line($lines, 'trade_ap', '0.00', $grand, 'supplier_bill', (int) $p['source_id'], (int) $p['supplier_id']);

        return [$lines, ['gl_amount' => $this->debits($lines)], null, $slices];
    }

    /** @param array<string, mixed> $p @return array{list<array<string, mixed>>, array<string, mixed>, ?string, list<array<string, mixed>>} */
    private function returned(int $company, array $p): array
    {
        $lines = [];
        $slices = [];
        $costs = [];
        foreach ($p['lines'] as $l) {
            $actual = $l['movement'] === null ? '0.0000' : $l['movement']['total_cost'];
            $costs[] = ['source_cost' => $actual];
            [$consumed, $billed, $returned] = $this->ledger($company, $l, $p['source_kind'], (int) $p['source_id']);
            $available = bcsub($l['received_quantity'], $consumed, 4);
            $unbilled = bccomp($available, $l['quantity'], 4) < 0 ? $available : $l['quantity'];
            $billedPart = bcsub($l['quantity'], $unbilled, 4);
            if (bccomp(bcadd($returned, $billedPart, 4), $billed, 4) > 0) {
                $this->fail('Return allocation exceeds previously recognized billed stock.');
            }
            $grni = $this->decimal->costShare($l['receipt_cost'], $l['received_quantity'], $consumed, bcadd($consumed, $unbilled, 4));
            $clearing = $this->decimal->costShare($l['receipt_cost'], $l['received_quantity'], $returned, bcadd($returned, $billedPart, 4));
            $slices[] = $this->slice($l, $unbilled, $billedPart, $grni, $clearing);
            if (! $l['stock']) {
                continue;
            }
            $this->line($lines, 'grni', $grni, '0.00', 'purchase_return_item', $l['id']);
            $this->line($lines, 'supplier_return_clearing', $clearing, '0.00', 'purchase_return_item', $l['id'], (int) $p['supplier_id']);
            $out = $this->decimal->roundedCost($actual);
            $offset = $this->decimal->total([$grni, $clearing]);
            $this->difference($lines, $out, $offset, 'purchase_return_item', $l['id']);
            $this->line($lines, 'inventory', '0.00', $out, 'purchase_return_item', $l['id']);
        }
        $sourceCost = '0.0000';
        foreach ($costs as $cost) {
            $sourceCost = $this->decimal->source(bcadd($sourceCost, $cost['source_cost'], 4));
        }

        return [$lines, ['gl_amount' => $this->debits($lines), 'source_cost' => $sourceCost, 'line_facts' => $costs], $lines === [] ? ($sourceCost === '0.0000' ? 'zero_source_cost' : 'subcent_valuation') : null, $slices];
    }

    /** @param array<string, mixed> $p @return array{list<array<string, mixed>>, array<string, mixed>, ?string, list<array<string, mixed>>} */
    private function credit(array $p): array
    {
        $lines = [];
        foreach ($p['lines'] as $l) {
            if ($l['stock']) {
                $this->line($lines, 'supplier_return_clearing', '0.00', $l['clearing_amount'], 'supplier_credit_line', $l['id'], (int) $p['supplier_id']);
                // AP debit is net; the physical-return clearing credit is the original cost.
                $this->difference($lines, $l['clearing_amount'], $l['net'], 'supplier_credit_line', $l['id']);
            } else {
                $this->line($lines, 'general_expense', '0.00', $l['net'], 'supplier_credit_line', $l['id']);
            }
            $this->line($lines, 'input_tax', '0.00', $l['tax'], 'supplier_credit_line', $l['id']);
        }
        $this->line($lines, 'trade_ap', $p['amount'], '0.00', 'supplier_credit', (int) $p['source_id'], (int) $p['supplier_id']);

        return [$lines, ['gl_amount' => $this->debits($lines)], null, []];
    }

    /** Validate previously admitted slices before consuming another part of a receipt. @param array<string, mixed> $l @return array{string, string, string} */
    private function ledger(int $company, array $l, string $currentKind, int $currentId): array
    {
        $consumed = $billed = $returned = $allReturns = '0.0000';
        $slices = AccountingPurchaseCostSlice::query()->where('company_id', $company)->where('goods_receipt_item_id', $l['receipt_line_id'])->orderBy('id')->get();
        foreach ($slices as $s) {
            $event = AccountingPurchaseEvent::query()->where('company_id', $company)->findOrFail((int) $s->getAttribute('purchase_event_id'));
            $p = $this->checked($event);
            if ($this->canonical->hash($p['projection']) !== $this->canonical->hash($this->projection($company, (string) $event->getAttribute('source_kind'), (int) $event->getAttribute('source_id')))) {
                $this->fail('A previously recognized allocation source changed.');
            }
            $expected = null;
            foreach ($p['slices'] as $slice) {
                if ($slice['source_line_id'] === (int) $s->getAttribute('source_line_id')) {
                    $expected = $slice;
                }
            }
            if ($expected === null) {
                $this->fail('An allocation slice lacks immutable source evidence.');
            }
            foreach ($expected as $key => $value) {
                if ((string) $s->getAttribute($key) !== (string) $value) {
                    $this->fail('An allocation slice disagrees with its immutable source.');
                }
            }
            $this->verifyRecognition($company, $event, $p);
            $consumed = bcadd($consumed, (string) $s->getAttribute('grni_quantity'), 4);
            if ($event->getAttribute('source_kind') === 'supplier_bill') {
                $billed = bcadd($billed, (string) $s->getAttribute('quantity'), 4);
            }
            $returned = bcadd($returned, (string) $s->getAttribute('billed_return_quantity'), 4);
            if ($event->getAttribute('source_kind') === 'purchase_return') {
                $allReturns = bcadd($allReturns, (string) $s->getAttribute('quantity'), 4);
            }
        }
        $billQuery = DB::table('supplier_bill_items')->join('supplier_bills', 'supplier_bills.id', '=', 'supplier_bill_items.supplier_bill_id')->where('supplier_bills.company_id', $company)->where('supplier_bills.status', 'approved')->where('goods_receipt_item_id', $l['receipt_line_id']);
        if ($currentKind === 'supplier_bill') {
            $billQuery->where('supplier_bills.id', '<>', $currentId);
        }
        $returnQuery = DB::table('purchase_return_items')->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_items.purchase_return_id')->where('purchase_returns.company_id', $company)->where('purchase_returns.status', 'posted')->where('goods_receipt_item_id', $l['receipt_line_id']);
        if ($currentKind === 'purchase_return') {
            $returnQuery->where('purchase_returns.id', '<>', $currentId);
        }
        $actualBills = $actualReturns = '0.0000';
        foreach ($billQuery->pluck('supplier_bill_items.quantity') as $q) {
            $actualBills = bcadd($actualBills, (string) $q, 4);
        }
        foreach ($returnQuery->pluck('purchase_return_items.quantity') as $q) {
            $actualReturns = bcadd($actualReturns, (string) $q, 4);
        }
        if ($actualBills !== $billed || $actualReturns !== $allReturns) {
            $this->fail('Unrecognized approved bills or posted returns require reconciliation before consuming receipt clearing.');
        }
        if (bccomp($consumed, $l['received_quantity'], 4) > 0) {
            $this->fail('Receipt clearing quantities exceed accepted stock.');
        }

        return [$consumed, $billed, $returned];
    }

    /** @param array<string, mixed> $l @return array<string, mixed> */
    private function slice(array $l, string $grniQuantity, string $billedReturn, string $grni, string $clearing): array
    {
        return ['source_line_id' => $l['id'], 'goods_receipt_item_id' => $l['receipt_line_id'], 'quantity' => $l['quantity'], 'grni_quantity' => $grniQuantity, 'billed_return_quantity' => $billedReturn, 'grni_amount' => $grni, 'return_clearing_amount' => $clearing];
    }

    /** @param list<array<string, mixed>> $lines */
    private function line(array &$lines, string $role, string $debit, string $credit, string $type, int $id, ?int $supplier = null): void
    {
        if ($debit === '0.00' && $credit === '0.00') {
            return;
        }
        $lines[] = ['role' => $role, 'debit' => $debit, 'credit' => $credit, 'source_line_type' => $type, 'source_line_id' => $id, 'party_type' => $supplier === null ? null : 'supplier', 'party_id' => $supplier];
    }

    /** @param list<array<string, mixed>> $lines */
    private function difference(array &$lines, string $debitTarget, string $creditTarget, string $type, int $id): void
    {
        $cmp = bccomp($debitTarget, $creditTarget, 2);
        if ($cmp > 0) {
            $this->line($lines, 'purchase_variance', bcsub($debitTarget, $creditTarget, 2), '0.00', $type, $id);
        } elseif ($cmp < 0) {
            $this->line($lines, 'purchase_variance', '0.00', bcsub($creditTarget, $debitTarget, 2), $type, $id);
        }
    }

    /** @param list<array<string, mixed>> $lines */
    private function debits(array $lines): string
    {
        return $this->decimal->total(array_map(static fn (array $l): string => $l['debit'], $lines));
    }

    /** @return array<string, mixed> */
    private function transferProjection(int $company, int $id): array
    {
        $t = StockTransfer::query()->where('company_id', $company)->findOrFail((int) $id);
        if ($t->getAttribute('status') !== 'posted' || $t->getAttribute('posted_by') === null || $t->getAttribute('posted_at') === null || (int) $t->getAttribute('source_warehouse_id') === (int) $t->getAttribute('destination_warehouse_id')) {
            $this->fail('A completed two-warehouse transfer is required.');
        }
        $lines = [];
        $used = [];
        $movements = StockMovement::query()->where('reference_type', StockTransfer::class)->where('reference_id', $id)->orderBy('id')->get();
        foreach (StockTransferItem::query()->where('stock_transfer_id', $id)->orderBy('id')->get() as $item) {
            $pair = [];
            foreach ([[StockMovement::TYPE_TRANSFER_OUT, 'out', (int) $t->getAttribute('source_warehouse_id')], [StockMovement::TYPE_TRANSFER_IN, 'in', (int) $t->getAttribute('destination_warehouse_id')]] as [$type, $direction, $warehouse]) {
                $matches = $movements->filter(static fn (StockMovement $m): bool => $m->getAttribute('movement_type') === $type && (int) $m->getAttribute('warehouse_id') === $warehouse && (int) $m->getAttribute('product_id') === (int) $item->getAttribute('product_id') && (int) $m->getAttribute('product_variant_id') === (int) $item->getAttribute('product_variant_id'));
                if ($matches->count() !== 1) {
                    $this->fail('Each transfer item requires exactly one outgoing and one incoming movement.');
                }
                $m = $matches->first();
                if ($m === null || isset($used[(int) $m->getKey()])) {
                    $this->fail('Transfer movement reused.');
                } $used[(int) $m->getKey()] = true;
                $pair[] = $this->movement($company, $m->getKey(), StockTransfer::class, $id, $warehouse, (int) $item->getAttribute('product_id'), $item->getAttribute('product_variant_id'), $this->decimal->source((string) $item->getAttribute('quantity')), true, $type, $direction);
            }
            if ($pair[0] === null || $pair[1] === null || $pair[0]['total_cost'] !== $pair[1]['total_cost'] || $pair[0]['unit_cost'] !== $pair[1]['unit_cost'] || $pair[0]['instant'] !== $pair[1]['instant'] || $pair[0]['unit_cost'] !== $this->decimal->source((string) $item->getAttribute('unit_cost')) || $pair[0]['total_cost'] !== $this->decimal->source((string) $item->getAttribute('total_cost'))) {
                $this->fail('Transfer values differ; the difference requires explicit reconciliation.');
            }
            $lines[] = ['id' => (int) $item->getKey(), 'out' => $pair[0], 'in' => $pair[1]];
        }
        if ($lines === [] || count($lines) > 40 || count($used) !== $movements->count()) {
            $this->fail('Transfer movement bundle is empty, oversized or has extra movements.');
        }

        return ['source_id' => $id, 'source_kind' => 'stock_transfer', 'number' => (string) $t->getAttribute('document_number'), 'currency' => (string) Company::query()->findOrFail($company)->getAttribute('currency_code'), 'instant' => $this->instant($t->getAttribute('posted_at')), 'posted_by' => (int) $t->getAttribute('posted_by'), 'lines' => $lines];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['accounting_source' => $message]);
    }
}
