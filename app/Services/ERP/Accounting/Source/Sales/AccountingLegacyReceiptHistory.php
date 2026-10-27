<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Sales;

use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingCutoverSource;
use App\Models\Accounting\AccountingPurchaseEvent;
use App\Models\Accounting\AccountingReceiptOperation;
use App\Models\Accounting\AccountingSalesEvent;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Company;
use App\Models\CustomerReceipt;
use App\Models\CustomerReceiptAllocation;
use App\Models\SalesInvoice;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingDecimal;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Read-only native initial-batch verification; no original cash recognition or authority to spend old funds. */
final class AccountingLegacyReceiptHistory
{
    public function __construct(private readonly AccountingDatedReceivableContract $dated, private readonly AccountingDecimal $decimal, private readonly AccountingCanonicalPayload $canonical) {}

    /** Called only by the original-invoice coverage verifier in its authorized transaction.
     * @return array<string, mixed>|null
     */
    public function project(Company $company, SalesInvoice $invoice, string $cutover): ?array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Native legacy history requires a caller-owned transaction.');
        }
        $companyId = (int) $company->getKey();
        $invoiceId = (int) $invoice->getKey();
        if ((int) $invoice->getAttribute('company_id') !== $companyId) {
            $this->fail('Invoice ownership differs from its history scope.');
        }
        $boundary = CarbonImmutable::parse($cutover, (string) $company->getAttribute('timezone'))->startOfDay()->utc();
        $all = CustomerReceiptAllocation::query()->where('sales_invoice_id', $invoiceId)->orderBy('id')->limit(1001)->get();
        if ($all->count() > 1000) {
            $this->fail('Legacy invoice history exceeds the bounded reconciliation contract.');
        }
        $receiptIds = [];
        foreach ($all as $allocation) {
            if ((int) $allocation->getAttribute('company_id') !== $companyId) {
                $this->fail('Invoice allocation crosses company ownership.');
            }
            $time = $allocation->getAttribute('posted_at');
            if (! $time instanceof DateTimeInterface) {
                $this->fail('Allocation has no persisted posting instant.');
            }
            if (CarbonImmutable::instance($time)->utc()->lessThan($boundary)) {
                $receiptIds[(int) $allocation->getAttribute('customer_receipt_id')] = true;
            }
        }
        if ($receiptIds === []) {
            return null;
        }
        ksort($receiptIds);
        $events = [];
        $batches = [];
        $memberCount = 0;
        foreach (array_keys($receiptIds) as $receiptId) {
            $receipt = CustomerReceipt::query()->where('company_id', $companyId)->where('customer_id', $invoice->getAttribute('customer_id'))->where('currency_code', $invoice->getAttribute('currency_code'))->where('status', 'posted')->whereNull('reverses_receipt_id')->findOrFail($receiptId);
            $posted = $receipt->getAttribute('posted_at');
            $key = $receipt->getAttribute('idempotency_key');
            $hash = (string) $receipt->getAttribute('payload_hash');
            if (! $posted instanceof DateTimeInterface || CarbonImmutable::instance($posted)->utc()->greaterThanOrEqualTo($boundary) || $receipt->getAttribute('posted_by') === null || ! is_string($key) || ! preg_match('/\A[a-zA-Z0-9_-]{16,64}\z/', $key) || ! preg_match('/\A[a-f0-9]{64}\z/', $hash)) {
                $this->fail('Legacy receipt original posting identity is incomplete.');
            }
            if (CustomerReceipt::query()->where('reverses_receipt_id', $receiptId)->exists() || AccountingReceiptOperation::query()->where('customer_receipt_id', $receiptId)->exists()) {
                $this->fail('Legacy receipt reversal or live finance operations require a separate funds contract.');
            }
            $this->noFinance('customer_receipt', $receiptId);
            $rows = CustomerReceiptAllocation::query()->where('customer_receipt_id', $receiptId)->orderBy('id')->limit(101)->get();
            if ($rows->isEmpty() || $rows->count() > 100) {
                $this->fail('Legacy receipt must retain its complete bounded initial posting batch.');
            }
            $targets = [];
            $members = [];
            $total = '0.00';
            $previousInvoice = 0;
            $memberCount += $rows->count();
            if ($memberCount > 1000) {
                $this->fail('Linked native receipt history exceeds one thousand batch members.');
            }
            foreach ($rows->values() as $ordinal => $row) {
                $targetId = (int) $row->getAttribute('sales_invoice_id');
                $time = $row->getAttribute('posted_at');
                if ((int) $row->getAttribute('company_id') !== $companyId || $row->getAttribute('reverses_allocation_id') !== null || $row->getAttribute('idempotency_key') !== $key.':post:'.$ordinal || $row->getAttribute('payload_hash') !== $hash || (int) $row->getAttribute('posted_by') !== (int) $receipt->getAttribute('posted_by') || ! $time instanceof DateTimeInterface || CarbonImmutable::instance($time)->utc()->lessThan(CarbonImmutable::instance($posted)->utc()) || CarbonImmutable::instance($time)->utc()->greaterThanOrEqualTo($boundary) || $targetId <= $previousInvoice) {
                    $this->fail('Legacy initial allocation membership, chronology, actor or fingerprint differs.');
                }
                $previousInvoice = $targetId;
                $target = SalesInvoice::query()->where('company_id', $companyId)->where('customer_id', $invoice->getAttribute('customer_id'))->where('currency_code', $invoice->getAttribute('currency_code'))->where('status', 'issued')->findOrFail($targetId);
                $issued = $target->getAttribute('issued_at');
                if (! $issued instanceof DateTimeInterface || CarbonImmutable::instance($issued)->utc()->greaterThan(CarbonImmutable::instance($time)->utc()) || $this->date($target->getAttribute('document_date')) >= $cutover) {
                    $this->fail('Original receipt target was not a matching issued legacy invoice.');
                }
                $amount = $this->decimal->money((string) $row->getAttribute('amount'));
                if ($amount === '0.00') {
                    $this->fail('Legacy initial allocation must be positive.');
                }
                $total = $this->decimal->total([$total, $amount]);
                $this->noFinance('customer_receipt_allocation', (int) $row->getKey());
                $targets[] = ['sales_invoice_id' => $targetId, 'amount' => $amount];
                $member = ['id' => (int) $row->getKey(), 'invoice_id' => $targetId, 'amount' => $amount, 'posted_at' => $this->instant($time), 'actor_id' => (int) $row->getAttribute('posted_by'), 'native_key_hash' => hash('sha256', (string) $row->getAttribute('idempotency_key'))];
                $members[] = $member;
                if ($targetId === $invoiceId) {
                    $events[] = ['id' => (int) $row->getKey(), 'invoice_id' => $invoiceId, 'company_id' => $companyId, 'customer_id' => (int) $invoice->getAttribute('customer_id'), 'currency' => (string) $invoice->getAttribute('currency_code'), 'amount' => $amount, 'posted_at' => $this->instant($time), 'reverses_id' => null, 'payload_hash' => $hash, 'receipt' => ['id' => $receiptId, 'company_id' => $companyId, 'customer_id' => (int) $receipt->getAttribute('customer_id'), 'currency' => (string) $receipt->getAttribute('currency_code'), 'document_date' => $this->date($receipt->getAttribute('document_date')), 'posted_at' => $this->instant($posted), 'amount' => $this->decimal->money((string) $receipt->getAttribute('amount')), 'payload_hash' => $hash]];
                }
            }
            if ($total !== $this->decimal->money((string) $receipt->getAttribute('amount'))) {
                $this->fail('Legacy receipt must have been fully allocated in its initial batch; unapplied old funds are unsupported.');
            }
            // Exact native CustomerReceiptService::header/hash algorithm, privately recomputed.
            $header = [...$receipt->only(['id', 'number', 'company_id', 'customer_id', 'currency_code', 'amount', 'payment_method', 'payment_reference', 'notes', 'customer_snapshot', 'billing_address_snapshot', 'shipping_address_snapshot', 'terms_snapshot']), 'document_date' => $this->date($receipt->getAttribute('document_date'))];
            $expected = hash('sha256', json_encode($this->nativeCanonical(['post', $header, $targets, []]), JSON_THROW_ON_ERROR));
            if (! hash_equals($hash, $expected)) {
                $this->fail('Legacy receipt no longer matches its original native posting fingerprint.');
            }
            $batches[] = ['receipt_id' => $receiptId, 'native_payload_hash' => $hash, 'native_key_hash' => hash('sha256', $key), 'posted_by' => (int) $receipt->getAttribute('posted_by'), 'members' => $members];
        }
        $projection = $this->dated->project(['company_id' => $companyId, 'invoice_id' => $invoiceId, 'customer_id' => (int) $invoice->getAttribute('customer_id'), 'currency' => (string) $invoice->getAttribute('currency_code'), 'document_date' => $this->date($invoice->getAttribute('document_date')), 'issued_at' => $this->instant($invoice->getAttribute('issued_at')), 'gross' => $this->decimal->money((string) $invoice->getAttribute('grand_total')), 'payload_hash' => (string) $invoice->getAttribute('payload_hash')], $events, $cutover, (string) $company->getAttribute('timezone'));

        return ['native_history_contract' => 'initial-receipt-batches.v1', 'dated_projection' => $projection['snapshot'], 'dated_payload_hash' => $projection['payload_hash'], 'native_batches' => $batches, 'native_batches_hash' => $this->canonical->hash($batches)];
    }

    /** Archived dated AR already uses this old initial batch. Disabling new posting cannot make it mutable.
     * Called after native authorization, inside the native transaction, before the finance-enabled gate.
     */
    public function assertReceiptMutationAllowed(int $company, int $receipt): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Covered receipt protection requires the native transaction.');
        }
        $invoiceIds = CustomerReceiptAllocation::query()->where('customer_receipt_id', $receipt)->pluck('sales_invoice_id');
        $rows = AccountingCutoverSource::query()->where('company_id', $company)->where('source_kind', 'sales_invoice')->whereIn('source_id', $invoiceIds)->whereIn('cutover_id', AccountingCutover::query()->where('company_id', $company)->where('status', 'activated')->select('id'))->get();
        foreach ($rows as $row) {
            $state = $row->getAttribute('opening_state');
            if (! is_array($state) || ($state['recognition_state'] ?? null) !== 'recognized') {
                continue;
            }
            $snapshot = $state['snapshot'] ?? null;
            if (! is_array($snapshot) || ($snapshot['coverage_contract'] ?? null) !== 'legacy-ar.dated-initial.v1') {
                continue;
            }
            if (! hash_equals((string) $row->getAttribute('source_payload_hash'), $this->canonical->hash($snapshot))) {
                $this->fail('Archived dated source identity is damaged; related receipt mutations require reconciliation.');
            }
            $history = $snapshot['dated_history'] ?? null;
            $batches = is_array($history) ? ($history['native_batches'] ?? null) : null;
            if (! is_array($batches) || $batches === []) {
                $this->fail('Archived dated receipt membership is damaged; reconcile it before mutating related receipts.');
            }
            foreach ($batches as $batch) {
                if (! is_array($batch) || ! is_int($batch['receipt_id'] ?? null)) {
                    $this->fail('Archived receipt membership is incomplete.');
                }
                if ($batch['receipt_id'] === $receipt) {
                    $this->fail('An activated dated opening covers this old receipt. Old cash/allocation mutations require a separate verified funds transition.');
                }
            }
        }
    }

    private function noFinance(string $kind, int $id): void
    {
        // IDs are globally unique within each native table: foreign financial references also conflict.
        foreach ([AccountingSalesEvent::class, AccountingPurchaseEvent::class, AccountingSourceEffect::class] as $model) {
            if ($model::query()->where('source_kind', $kind)->where('source_id', $id)->exists()) {
                $this->fail('Legacy cash/allocation already has live or partial financial evidence.');
            }
        }
        if (DB::table('journal_entry_lines as l')->join('journal_entries as h', 'h.id', '=', 'l.journal_entry_id')->where('h.entry_kind', 'automatic')->where('l.source_line_type', $kind)->where('l.source_line_id', $id)->exists()) {
            $this->fail('Orphan automatic finance cannot be replaced by legacy opening coverage.');
        }
    }

    private function nativeCanonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        } if (! array_is_list($value)) {
            ksort($value);
        }

return array_map(fn (mixed $item): mixed => $this->nativeCanonical($item), $value);
    }

    private function date(mixed $value): string
    {
        if (! $value instanceof DateTimeInterface) {
            $this->fail('Persisted legacy dates are required.');
        }

return $value->format('Y-m-d');
    }

    private function instant(mixed $value): string
    {
        if (! $value instanceof DateTimeInterface) {
            $this->fail('Persisted legacy instants are required.');
        }

return CarbonImmutable::instance($value)->utc()->format('Y-m-d\TH:i:sP');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['coverage' => $message]);
    }
}
