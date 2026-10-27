<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Sales;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingPurchaseEvent;
use App\Models\Accounting\AccountingSalesEvent;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Company;
use App\Models\CustomerCreditAllocation;
use App\Models\CustomerReceiptAllocation;
use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingCoverageResolver;
use App\Services\ERP\Accounting\AccountingDecimal;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Internal legacy AR coverage: unpaid v1 or verified initial-receipt history. No original revenue/cost/cash recognition or activation. */
final class AccountingLegacyReceivableCoverage
{
    public function __construct(private readonly AccountingCoverageResolver $coverage, private readonly AccountingCanonicalPayload $canonical, private readonly AccountingDecimal $decimal) {}

    public function isLegacy(int $company, int $invoice): bool
    {
        return ! AccountingPurchaseEvent::query()->where('company_id', $company)->where('source_kind', 'sales_invoice')->where('source_id', $invoice)->exists()
            && ! AccountingSalesEvent::query()->where('company_id', $company)->where('source_kind', 'sales_invoice')->where('source_id', $invoice)->exists()
            && ! AccountingSourceEffect::query()->where('company_id', $company)->where('source_kind', 'sales_invoice')->where('source_id', $invoice)->exists()
            && ! DB::table('journal_entry_lines as l')->join('journal_entries as h', 'h.id', '=', 'l.journal_entry_id')->where('l.company_id', $company)->where('h.company_id', $company)->where('h.entry_kind', 'automatic')->where('l.source_line_type', 'sales_invoice')->where('l.source_line_id', $invoice)->exists();
    }

    /** Authoritative preparation projection; never HTTP authority or a recognized-coverage writer.
     * @return array<string, mixed>
     */
    public function snapshot(Company $company, int $id, string $cutover): array
    {
        $this->transaction();
        $companyId = (int) $company->getKey();
        if (! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $cutover) || ! checkdate((int) substr($cutover, 5, 2), (int) substr($cutover, 8, 2), (int) substr($cutover, 0, 4))) {
            $this->fail('A valid cutover boundary is required.');
        }
        $invoice = SalesInvoice::query()->where('company_id', $companyId)->where('status', 'issued')->findOrFail($id);
        $date = $this->date($invoice->getAttribute('document_date'));
        $issued = $invoice->getAttribute('issued_at');
        $boundary = CarbonImmutable::parse($cutover, (string) $company->getAttribute('timezone'))->startOfDay()->utc();
        if ($date >= $cutover || ! $issued instanceof DateTimeInterface || CarbonImmutable::instance($issued)->utc()->greaterThanOrEqualTo($boundary) || $invoice->getAttribute('issued_by') === null || $invoice->getAttribute('currency_code') !== $company->getAttribute('currency_code') || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $invoice->getAttribute('payload_hash')) || ! is_string($invoice->getAttribute('idempotency_key'))) {
            $this->fail('Only a finalized functional-currency invoice genuinely issued before cutover can be covered.');
        }
        if (! $this->isLegacy($companyId, $id)) {
            $this->fail('Live or partial invoice recognition cannot also use opening AR coverage.');
        }
        if (! hash_equals((string) $invoice->getAttribute('payload_hash'), $this->nativeHash($invoice))) {
            $this->fail('The original issued invoice no longer matches its native financial fingerprint.');
        }
        if (CustomerReceiptAllocation::query()->where('sales_invoice_id', $id)->where('company_id', '<>', $companyId)->exists()
            || SalesCreditNote::query()->where('sales_invoice_id', $id)->where('company_id', '<>', $companyId)->exists()
            || CustomerCreditAllocation::query()->where(fn ($q) => $q->where('source_sales_invoice_id', $id)->orWhere('sales_invoice_id', $id))->where('company_id', '<>', $companyId)->exists()) {
            $this->fail('Legacy settlement history crosses company ownership.');
        }
        // Credit/transfer history remains outside both supported AR coverage contracts.
        $cutoff = $boundary->toDateTimeString();
        if (SalesCreditNote::query()->where('company_id', $companyId)->where('sales_invoice_id', $id)->where(fn ($q) => $q->where('status', 'issued')->orWhereNotNull('issued_at'))->where(fn ($q) => $q->whereNull('issued_at')->orWhere('issued_at', '<', $cutoff))->exists()
            || CustomerCreditAllocation::query()->where('company_id', $companyId)->where(fn ($q) => $q->where('source_sales_invoice_id', $id)->orWhere('sales_invoice_id', $id))->where(fn ($q) => $q->whereNull('posted_at')->orWhere('posted_at', '<', $cutoff))->exists()) {
            $this->fail('Legacy credit or transfer history requires a separate coverage contract.');
        }
        $source = ['company_id' => $companyId, 'id' => $id, 'customer_id' => (int) $invoice->getAttribute('customer_id'), 'number' => (string) $invoice->getAttribute('number'), 'document_date' => $date, 'currency_code' => (string) $invoice->getAttribute('currency_code'), 'issued_by' => (int) $invoice->getAttribute('issued_by'), 'issued_at' => CarbonImmutable::instance($issued)->utc()->toIso8601String(), 'native_payload_hash' => (string) $invoice->getAttribute('payload_hash'), 'native_key_hash' => hash('sha256', (string) $invoice->getAttribute('idempotency_key')), 'items' => []];
        $sums = ['subtotal' => '0.00', 'discount_total' => '0.00', 'tax_total' => '0.00', 'grand_total' => '0.00'];
        foreach (SalesInvoiceItem::query()->where('sales_invoice_id', $id)->orderBy('id')->get() as $item) {
            $line = ['id' => (int) $item->getKey(), 'delivery_item_id' => (int) $item->getAttribute('sales_delivery_item_id'), 'product_id' => (int) $item->getAttribute('product_id'), 'variant_id' => $item->getAttribute('product_variant_id') === null ? null : (int) $item->getAttribute('product_variant_id'), 'unit_id' => (int) $item->getAttribute('unit_id')];
            foreach (['quantity', 'unit_price', 'discount_percent', 'tax_percent'] as $field) {
                $line[$field] = $this->decimal->source((string) $item->getAttribute($field));
            }
            foreach (['subtotal' => 'subtotal', 'discount_total' => 'discount_amount', 'tax_total' => 'tax_amount', 'grand_total' => 'line_total'] as $header => $field) {
                $line[$field] = $this->decimal->money((string) $item->getAttribute($field));
                $sums[$header] = $this->decimal->total([$sums[$header], $line[$field]]);
            }
            if ($line['quantity'] === '0.0000' || bccomp($line['discount_amount'], $line['subtotal'], 2) > 0 || bcadd(bcsub($line['subtotal'], $line['discount_amount'], 2), $line['tax_amount'], 2) !== $line['line_total']) {
                $this->fail('Legacy invoice financial components do not reconcile.');
            }
            $source['items'][] = $line;
        }
        if ($source['items'] === [] || count($source['items']) > 100) {
            $this->fail('Legacy AR identity requires one to one hundred persisted invoice lines.');
        }
        foreach ($sums as $field => $value) {
            $source[$field] = $this->decimal->money((string) $invoice->getAttribute($field));
            if ($source[$field] !== $value) {
                $this->fail('Legacy invoice totals differ from their original line evidence.');
            }
        }
        if ($source['grand_total'] === '0.00') {
            $this->fail('Opening receivable coverage requires positive unpaid AR.');
        }
        $history = app(AccountingLegacyReceiptHistory::class)->project($company, $invoice, $cutover);
        if ($history !== null) {
            $amount = $history['dated_projection']['opening_ar'];
            if ($amount === '0.00') {
                $this->fail('A fully settled legacy invoice has no positive opening AR to settle.');
            }

            return ['coverage_contract' => 'legacy-ar.dated-initial.v1', 'cutover_date' => $cutover, 'cutover_timezone' => (string) $company->getAttribute('timezone'), 'covered_through_event' => 'issued.ar_dated_opening.v1', 'history_policy' => 'initial_cash_only.v1', 'opening_ar' => $amount, 'source' => $source, 'dated_history' => $history];
        }

        return ['coverage_contract' => 'legacy-ar.v1', 'cutover_date' => $cutover, 'cutover_timezone' => (string) $company->getAttribute('timezone'), 'covered_through_event' => 'issued.ar_opening.v1', 'history_policy' => 'unpaid_clean.v1', 'opening_ar' => $source['grand_total'], 'source' => $source];
    }

    /** Historical read, after caller authorization/company lock. No current active-account, period or enabled-state gate.
     * @return array<string, mixed>
     */
    public function verify(Company $company, int $id): array
    {
        $this->transaction();
        $companyId = (int) $company->getKey();
        $plans = AccountingCutover::query()->where('company_id', $companyId)->where('status', 'activated')->get();
        if ($plans->count() !== 1) {
            $this->fail('One activated recognized opening is required.');
        }
        $date = $this->date($plans->firstOrFail()->getAttribute('cutover_date'));
        $expected = $this->snapshot($company, $id, $date);
        $proof = $this->coverage->recorded($company, 'sales_invoice', $id, $this->canonical->hash($expected), $expected['covered_through_event']);
        if (! hash_equals($this->canonical->hash($expected), $this->canonical->hash($proof['snapshot']))) {
            $this->fail('Legacy AR source identity differs from its recognized snapshot.');
        }
        $account = null;
        $amount = '0.00';
        $lines = 0;
        foreach ($proof['opening_lines'] as $line) {
            if ($line['source_line_type'] !== 'sales_invoice' || (int) $line['source_line_id'] !== $id) {
                continue;
            }
            $owned = Account::query()->where('company_id', $companyId)->findOrFail((int) $line['account_id']);
            if ($owned->getAttribute('control_role') !== 'trade_ar' || $line['party_type'] !== 'customer' || (int) $line['party_id'] !== $expected['source']['customer_id'] || $line['credit'] !== '0.00' || $line['branch_id'] !== null || $line['department_id'] !== null || $line['cash_account_id'] !== null || ($account !== null && $account !== (int) $owned->getKey())) {
                $this->fail('Opening AR must use an undimensioned matching customer/invoice debit on one control account.');
            }
            $account = (int) $owned->getKey();
            $amount = $this->decimal->total([$amount, $line['debit']]);
            $lines++;
        }
        if ($lines === 0 || $amount !== $expected['opening_ar']) {
            $this->fail('Source-linked posted opening AR does not equal the verified cutover receivable.');
        }

        return ['source_id' => $id, 'original_date' => $expected['source']['document_date'], 'customer_id' => $expected['source']['customer_id'], 'currency' => $expected['source']['currency_code'], 'amount' => $amount, 'account_id' => $account, 'evidence_hash' => $this->canonical->hash(['manifest_hash' => $proof['manifest_hash'], 'source_payload_hash' => $proof['source_payload_hash'], 'opening_journal_entry_id' => $proof['opening_journal_entry_id']]), 'manifest_hash' => $proof['manifest_hash'], 'opening_journal_id' => $proof['opening_journal_entry_id'], 'cutover_date' => $proof['cutover_date']];
    }

    /** Validate prior new-era financial settlements before a new operation; pending IDs come only from the native transaction coordinator.
     * @param  list<int>  $pendingIds
     */
    public function assertHistory(Company $company, int $invoice, array $pendingIds): void
    {
        $companyId = (int) $company->getKey();
        if (SalesCreditNote::query()->where('company_id', $companyId)->where('sales_invoice_id', $invoice)->where(fn ($q) => $q->where('status', 'issued')->orWhereNotNull('issued_at'))->exists()
            || CustomerCreditAllocation::query()->where('company_id', $companyId)->where(fn ($q) => $q->where('source_sales_invoice_id', $invoice)->orWhere('sales_invoice_id', $invoice))->exists()) {
            $this->fail('Credit-note/credit-transfer history is outside the unpaid legacy AR settlement contract.');
        }
        $proof = $this->verify($company, $invoice);
        $boundary = CarbonImmutable::parse($proof['cutover_date'], (string) $company->getAttribute('timezone'))->startOfDay()->utc();
        foreach (CustomerReceiptAllocation::query()->where('company_id', $companyId)->where('sales_invoice_id', $invoice)->whereNotIn('id', $pendingIds)->get() as $allocation) {
            $posted = $allocation->getAttribute('posted_at');
            // verify() recomputed every archived initial batch before this boundary can be used.
            if ($posted instanceof DateTimeInterface && CarbonImmutable::instance($posted)->utc()->lessThan($boundary)) {
                continue;
            }
            app(AccountingReceiptEvidence::class)->recognized($companyId, 'customer_receipt_allocation', (int) $allocation->getKey());
        }
    }

    /** Recompute the native invoice fingerprint privately; never return/persist its personal snapshots or notes. */
    private function nativeHash(SalesInvoice $invoice): string
    {
        $items = [];
        foreach (SalesInvoiceItem::query()->where('sales_invoice_id', $invoice->getKey())->orderBy('line_number')->get() as $item) {
            $items[] = $item->only(['id', 'line_number', 'sales_delivery_item_id', 'product_id', 'product_variant_id', 'unit_id', 'product_snapshot', 'description', 'quantity', 'unit_price', 'discount_percent', 'tax_percent', 'subtotal', 'discount_amount', 'tax_amount', 'line_total']);
        }
        $payload = ['header' => [...$invoice->only(['id', 'number', 'company_id', 'sales_order_id', 'customer_id', 'currency_code', 'customer_snapshot', 'billing_address_snapshot', 'shipping_address_snapshot', 'terms_snapshot', 'notes', 'subtotal', 'discount_total', 'tax_total', 'grand_total']), 'document_date' => $this->date($invoice->getAttribute('document_date')), 'due_date' => $this->date($invoice->getAttribute('due_date'))], 'items' => $items];

        return hash('sha256', json_encode($this->nativeCanonical($payload), JSON_THROW_ON_ERROR));
    }

    private function nativeCanonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->nativeCanonical($item), $value);
    }

    private function transaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Legacy coverage requires an authorized caller-owned transaction.');
        }
    }

    private function date(mixed $date): string
    {
        if (! $date instanceof DateTimeInterface) {
            $this->fail('Persisted coverage dates are required.');
        }

return $date->format('Y-m-d');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['coverage' => $message]);
    }
}
