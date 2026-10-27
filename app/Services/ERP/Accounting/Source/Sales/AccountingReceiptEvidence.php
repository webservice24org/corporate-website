<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Sales;

use App\Models\Accounting\AccountingAccountMapping;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingSalesEvent;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\JournalEntry;
use App\Models\Company;
use App\Models\CustomerReceipt;
use App\Models\CustomerReceiptAllocation;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingCashAccountService;
use App\Services\ERP\Accounting\AccountingDecimal;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\Source\AccountingAutomaticEvidence;
use App\Services\ERP\Accounting\Source\AccountingPreparedSourceEvent;
use App\Services\ERP\Accounting\Source\AccountingSourceEffectPlan;
use App\Services\ERP\Sales\SalesCompanyAccess;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

/** receipt.v1: source-owned cash, settlement and compensating events. No native balance is rewritten. */
final class AccountingReceiptEvidence
{
    public function __construct(private readonly SalesCompanyAccess $access, private readonly AccountingSalesEvidence $sales, private readonly AccountingCashAccountService $cash, private readonly AccountingMappingService $mappings, private readonly AccountingBusinessDate $dates, private readonly AccountingDecimal $decimal, private readonly AccountingCanonicalPayload $canonical, private readonly AccountingAutomaticEvidence $journals, private readonly AccountingLegacyReceivableCoverage $legacy) {}

    public function capture(User $actor, int $company, string $kind, int $id): void
    {
        $this->access->requireTransaction();
        $p = $this->projection($company, $kind, $id);
        $permission = $kind === 'customer_receipt' ? ($p['original_id'] === null ? 'erp.customer-receipts.post' : 'erp.customer-receipts.reverse') : ($p['original_id'] === null ? 'erp.customer-receipts.allocate' : 'erp.customer-receipts.reverse');
        $owned = $this->access->authorize($actor, $company, $permission, true);
        $date = $kind === 'customer_receipt' ? $p['date'] : $this->dates->fromInstant($owned, CarbonImmutable::parse($p['instant']));
        $refs = [];
        $lines = [];
        $amount = $p['amount'];
        $party = $p['customer_id'];
        if ($kind === 'customer_receipt') {
            $this->cash->selection($owned, $p['cash']['id'], $p['method'], true);
            if ($p['original_id'] !== null) {
                $original = $this->recognized($company, 'customer_receipt', $p['original_id']);
                $refs[] = $this->reference('customer_receipt', $p['original_id'], $original['date']);
                $this->sameControls($owned, $date, 'customer_receipt', $p['original_id'], ['unapplied_customer_funds', $p['cash']['role']]);
                $lines[] = $this->line('unapplied_customer_funds', $amount, '0.00', $kind, $id, $party);
                $lines[] = $this->line($p['cash']['role'], '0.00', $amount, $kind, $id, null, $p['cash']['id']);
            } else {
                $lines[] = $this->line($p['cash']['role'], $amount, '0.00', $kind, $id, null, $p['cash']['id']);
                $lines[] = $this->line('unapplied_customer_funds', '0.00', $amount, $kind, $id, $party);
            }
            $effect = 'cash';
        } else {
            $receipt = $this->recognized($company, 'customer_receipt', $p['receipt_id']);
            $legacyInvoice = $this->legacy->isLegacy($company, $p['invoice_id']) ? $this->legacy->verify($owned, $p['invoice_id']) : null;
            $invoice = $legacyInvoice === null ? $this->sales->recognized($company, 'sales_invoice', $p['invoice_id']) : ['projection' => ['date' => $legacyInvoice['original_date']]];
            app(AccountingSalesCorrectionEvidence::class)->creditCoverage($company, $p['invoice_id']);
            $refs[] = $this->reference('customer_receipt', $p['receipt_id'], $receipt['date']);
            $refs[] = $legacyInvoice === null ? $this->reference('sales_invoice', $p['invoice_id'], $invoice['projection']['date']) : $this->reference('opening_journal', $legacyInvoice['opening_journal_id'], $legacyInvoice['cutover_date']);
            if ($date < $receipt['date'] || $date < $invoice['projection']['date']) {
                $this->fail('Settlement date cannot precede its cash receipt or invoice.');
            }
            $this->sameControls($owned, $date, 'customer_receipt', $p['receipt_id'], ['unapplied_customer_funds']);
            $this->sameControls($owned, $date, 'sales_invoice', $p['invoice_id'], ['trade_ar']);
            if ($p['original_id'] !== null) {
                $original = $this->recognized($company, 'customer_receipt_allocation', $p['original_id']);
                $refs[] = $this->reference('customer_receipt_allocation', $p['original_id'], $original['date']);
                $this->sameControls($owned, $date, 'customer_receipt_allocation', $p['original_id'], ['trade_ar', 'unapplied_customer_funds']);
                $lines[] = $this->line('trade_ar', $amount, '0.00', $kind, $id, $party);
                $lines[] = $this->line('unapplied_customer_funds', '0.00', $amount, $kind, $id, $party);
            } else {
                $lines[] = $this->line('unapplied_customer_funds', $amount, '0.00', $kind, $id, $party);
                $lines[] = $this->line('trade_ar', '0.00', $amount, $kind, $id, $party);
            }
            $effect = 'allocation';
        }
        $payload = ['adapter_contract' => 'receipt.v1', 'projection' => $p, 'date' => $date, 'currency' => $p['currency'], 'references' => $refs, 'effect_kind' => $effect, 'lines' => $lines, 'proof' => ['gl_amount' => $amount]];
        AccountingSalesEvent::query()->create(['company_id' => $company, 'source_kind' => $kind, 'source_id' => $id, 'payload' => $payload, 'payload_hash' => $this->canonical->hash($payload), 'created_by' => $actor->getKey()]);
    }

    public function prepare(int $company, string $kind, int $id): AccountingPreparedSourceEvent
    {
        $row = AccountingSalesEvent::query()->where('company_id', $company)->where('source_kind', $kind)->where('source_id', $id)->first();
        if ($row === null) {
            $this->fail('Original receipt/settlement financial admission is missing. Reconcile the source instead of backfilling it.');
        }
        $payload = $this->checked($row);
        $p = $payload['projection'];
        if ($this->canonical->hash($p) !== $this->canonical->hash($this->projection($company, $kind, $id))) {
            $this->fail('Receipt, allocation or referenced source facts changed after recognition.');
        }
        $event = $kind === 'customer_receipt' ? ($p['original_id'] === null ? 'posted' : 'reversed') : ($p['original_id'] === null ? 'created' : 'reversed');

        return new AccountingPreparedSourceEvent($company, $kind, $id, $event, 'original', $payload['currency'], $payload['date'], $payload['date'], $payload['date'], ['source_number' => $p['number'], 'amount' => $p['amount'], 'evidence_hash' => (string) $row->getAttribute('payload_hash')], [new AccountingSourceEffectPlan($payload['effect_kind'], $payload['lines'], [...$payload['proof'], 'evidence_hash' => (string) $row->getAttribute('payload_hash')])], $payload['references']);
    }

    /** Internal dependency reader after operation authorization and company lock.
     * @return array<string, mixed>
     */
    public function recognized(int $company, string $kind, int $id): array
    {
        $this->access->requireTransaction();
        $dto = $this->prepare($company, $kind, $id);
        $record = AccountingSalesEvent::query()->where('company_id', $company)->where('source_kind', $kind)->where('source_id', $id)->firstOrFail();
        $payload = $this->checked($record);
        $rows = AccountingSourceEffect::query()->where('company_id', $company)->where('source_kind', $kind)->where('source_id', $id)->get();
        $row = $rows->count() === 1 ? $rows->first() : null;
        if ($row === null || $row->getAttribute('event_kind') !== $dto->eventKind || $row->getAttribute('event_token') !== 'original' || $row->getAttribute('effect_kind') !== $payload['effect_kind'] || $row->getAttribute('outcome') !== 'posted' || $row->getAttribute('no_journal_reason') !== null || $row->getAttribute('accounting_date')->format('Y-m-d') !== $payload['date'] || $row->getAttribute('source_document_date')?->format('Y-m-d') !== $payload['date']) {
            $this->fail('Receipt/settlement financial bundle is incomplete.');
        }
        $snapshot = $row->getAttribute('source_snapshot');
        $body = is_array($snapshot) ? ($snapshot['evidence'] ?? null) : null;
        if (! is_array($body) || ! is_array($body['source'] ?? null) || ! hash_equals((string) ($snapshot['evidence_hash'] ?? ''), $this->canonical->hash($body)) || ! $this->sameEvidence($body['proof'] ?? null, $dto->effects[0]->proof) || ($body['bundle_effects'] ?? null) !== [$payload['effect_kind']] || ($body['outcome'] ?? null) !== 'posted' || ! hash_equals((string) $row->getAttribute('source_payload_hash'), $this->canonical->hash($body['source']))) {
            $this->fail('Receipt/settlement historical proof is inconsistent.');
        }
        $source = $body['source'];
        if (! is_int($body['recorded_by'] ?? null) || $body['recorded_by'] < 1 || ! is_string($body['recorded_at'] ?? null) || ($body['effect_kind'] ?? null) !== $payload['effect_kind'] || ($body['no_journal_reason'] ?? null) !== null || ($source['event_kind'] ?? null) !== $dto->eventKind || ($source['event_token'] ?? null) !== 'original' || ($source['document_date'] ?? null) !== $payload['date'] || ($source['origin_date'] ?? null) !== $payload['date'] || ($source['company_id'] ?? null) !== $company || ($source['source_kind'] ?? null) !== $kind || ($source['source_id'] ?? null) !== $id || ($source['accounting_date'] ?? null) !== $payload['date'] || ($source['currency'] ?? null) !== $payload['currency'] || ! $this->sameEvidence($source['effect_proofs'][$payload['effect_kind']] ?? null, $dto->effects[0]->proof)) {
            $this->fail('Receipt financial identity differs from admitted source.');
        }
        $this->mappings->verifyRecordedProfile($company, (int) $row->getAttribute('mapping_version_id'), $payload['currency'], (string) $row->getAttribute('contract_version'), (string) $body['mapping_hash']);
        $journal = JournalEntry::query()->where('company_id', $company)->findOrFail((int) $row->getAttribute('journal_entry_id'));
        if ((int) $journal->getAttribute('mapping_version_id') !== (int) $row->getAttribute('mapping_version_id') || $journal->getAttribute('payload_hash') !== ($body['journal_hash'] ?? null) || $journal->getAttribute('currency_code') !== $payload['currency']) {
            $this->fail('Receipt journal/profile identity changed.');
        }
        $this->journals->verify($journal, (string) $row->getAttribute('source_payload_hash'), $payload['effect_kind'], (string) $body['mapping_hash']);

        return $payload;
    }

    /** @return array<string, mixed> */
    private function checked(AccountingSalesEvent $row): array
    {
        $payload = $row->getAttribute('payload');
        if (! is_array($payload) || ($payload['adapter_contract'] ?? null) !== 'receipt.v1' || ! hash_equals((string) $row->getAttribute('payload_hash'), $this->canonical->hash($payload))) {
            $this->fail('Receipt admission evidence is damaged.');
        }

return $payload;
    }

    /** Safe, immutable financial fields, excluding notes, references, personal snapshots and mutable allocation/reversal progress.
     * @return array<string, mixed>
     */
    private function projection(int $company, string $kind, int $id): array
    {
        if ($kind === 'customer_receipt') {
            $r = CustomerReceipt::query()->where('company_id', $company)->where('status', 'posted')->findOrFail($id);
            if ($r->getAttribute('posted_by') === null || ! is_string($r->getAttribute('idempotency_key')) || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $r->getAttribute('payload_hash'))) {
                $this->fail('Final native receipt actor/key/fingerprint are required.');
            }
            $cash = AccountingCashAccount::query()->where('company_id', $company)->find((int) $r->getAttribute('cash_account_id'));
            if ($cash === null || $cash->getAttribute('currency_code') !== $r->getAttribute('currency_code') || $cash->getAttribute('kind') !== ($r->getAttribute('payment_method') === 'cash' ? 'cash' : 'bank')) {
                $this->fail('Receipt needs its original same-company/currency finance cash binding.');
            }
            $p = ['number' => (string) $r->getAttribute('number'), 'customer_id' => (int) $r->getAttribute('customer_id'), 'currency' => (string) $r->getAttribute('currency_code'), 'date' => $this->date($r->getAttribute('document_date')), 'amount' => $this->positive($r->getAttribute('amount')), 'method' => (string) $r->getAttribute('payment_method'), 'cash' => ['id' => (int) $cash->getKey(), 'role' => (string) $cash->getAttribute('role'), 'account_id' => (int) $cash->getAttribute('account_id'), 'currency' => (string) $cash->getAttribute('currency_code')], 'actor' => (int) $r->getAttribute('posted_by'), 'instant' => $this->instant($r->getAttribute('posted_at')), 'native_key_hash' => hash('sha256', (string) $r->getAttribute('idempotency_key')), 'native_payload_hash' => (string) $r->getAttribute('payload_hash'), 'original_id' => $r->getAttribute('reverses_receipt_id') === null ? null : (int) $r->getAttribute('reverses_receipt_id')];
            if ($p['original_id'] !== null) {
                $original = CustomerReceipt::query()->where('company_id', $company)->whereNull('reverses_receipt_id')->findOrFail($p['original_id']);
                foreach (['customer_id', 'currency_code', 'amount', 'payment_method', 'cash_account_id'] as $field) {
                    if ((string) $r->getAttribute($field) !== (string) $original->getAttribute($field)) {
                        $this->fail('Receipt reversal must retain original party, amount, currency and finance account.');
                    }
                }
                $p['original_projection_hash'] = $this->canonical->hash($this->projection($company, $kind, $p['original_id']));
            }

            return $p;
        }
        if ($kind !== 'customer_receipt_allocation') {
            $this->fail('Uninstalled settlement source.');
        }
        $a = CustomerReceiptAllocation::query()->where('company_id', $company)->findOrFail($id);
        $receiptId = (int) $a->getAttribute('customer_receipt_id');
        $r = CustomerReceipt::query()->where('company_id', $company)->where('status', 'posted')->whereNull('reverses_receipt_id')->findOrFail($receiptId);
        $invoiceId = (int) $a->getAttribute('sales_invoice_id');
        $invoice = SalesInvoice::query()->where('company_id', $company)->where('customer_id', $r->getAttribute('customer_id'))->where('currency_code', $r->getAttribute('currency_code'))->where('status', 'issued')->findOrFail($invoiceId);
        if ($a->getAttribute('posted_by') === null || ! is_string($a->getAttribute('idempotency_key')) || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $a->getAttribute('payload_hash'))) {
            $this->fail('Posted allocation actor/key/hash are required.');
        }
        $legacyInvoice = $this->legacy->isLegacy($company, $invoiceId) ? $this->legacy->verify(Company::query()->findOrFail($company), $invoiceId) : null;
        $invoiceHash = $legacyInvoice === null ? $this->sales->prepare($company, 'sales_invoice', $invoiceId)->facts['evidence_hash'] : $legacyInvoice['evidence_hash'];
        $p = ['number' => 'RA-'.$id, 'receipt_id' => $receiptId, 'invoice_id' => $invoiceId, 'customer_id' => (int) $r->getAttribute('customer_id'), 'currency' => (string) $r->getAttribute('currency_code'), 'amount' => $this->positive($a->getAttribute('amount')), 'actor' => (int) $a->getAttribute('posted_by'), 'instant' => $this->instant($a->getAttribute('posted_at')), 'native_key_hash' => hash('sha256', (string) $a->getAttribute('idempotency_key')), 'native_payload_hash' => (string) $a->getAttribute('payload_hash'), 'original_id' => $a->getAttribute('reverses_allocation_id') === null ? null : (int) $a->getAttribute('reverses_allocation_id'), 'receipt_projection_hash' => $this->canonical->hash($this->projection($company, 'customer_receipt', $receiptId)), 'invoice_admission_hash' => $invoiceHash];
        if ($legacyInvoice !== null) {
            unset($p['invoice_admission_hash']);
            $p['invoice_coverage_hash'] = $legacyInvoice['evidence_hash'];
            $p['invoice_coverage'] = $legacyInvoice;
        }
        if ($p['original_id'] !== null) {
            $original = CustomerReceiptAllocation::query()->where('company_id', $company)->whereNull('reverses_allocation_id')->findOrFail($p['original_id']);
            foreach (['customer_receipt_id', 'sales_invoice_id', 'amount'] as $field) {
                if ((string) $a->getAttribute($field) !== (string) $original->getAttribute($field)) {
                    $this->fail('Allocation compensation must exactly match its original source.');
                }
            }
            $p['original_projection_hash'] = $this->canonical->hash($this->projection($company, $kind, $p['original_id']));
        }

        return $p;
    }

    /** @param list<string> $roles */
    private function sameControls(Company $company, string $date, string $kind, int $id, array $roles): void
    {
        $companyId = (int) $company->getKey();
        $mapping = $this->mappings->resolveForSource($company, $date, $roles);
        if ($kind === 'sales_invoice' && $this->legacy->isLegacy($companyId, $id)) {
            $legacy = $this->legacy->verify($company, $id);
            if ($roles !== ['trade_ar'] || $mapping['accounts']['trade_ar'] !== $legacy['account_id']) {
                $this->fail('Legacy opening AR account differs from the settlement mapping; a verified account transition is required.');
            }

            return;
        }
        $effect = AccountingSourceEffect::query()->where('company_id', $companyId)->where('source_kind', $kind)->where('source_id', $id)->where('event_token', 'original')->where('effect_kind', $kind === 'sales_invoice' ? 'commercial' : ($kind === 'customer_receipt' ? 'cash' : 'allocation'))->firstOrFail();
        foreach ($roles as $role) {
            $account = AccountingAccountMapping::query()->where('company_id', $companyId)->where('mapping_version_id', $effect->getAttribute('mapping_version_id'))->where('role', $role)->value('account_id');
            if ((int) $account !== $mapping['accounts'][$role]) {
                $this->fail('A settlement control account was remapped. A verified source-linked transition is required before settling or reversing this source.');
            }
        }
    }

    /** @return array{source_kind: string, source_id: int, origin_date: string} */
    private function reference(string $kind, int $id, string $date): array
    {
        return ['source_kind' => $kind, 'source_id' => $id, 'origin_date' => $date];
    }

    /** @return array<string, mixed> */
    private function line(string $role, string $debit, string $credit, string $kind, int $id, ?int $party, ?int $cash = null): array
    {
        return ['role' => $role, 'debit' => $debit, 'credit' => $credit, 'party_type' => $party === null ? null : 'customer', 'party_id' => $party, 'source_line_type' => $kind, 'source_line_id' => $id, 'cash_account_id' => $cash];
    }

    private function positive(mixed $value): string
    {
        $amount = $this->decimal->money((string) $value);
        if ($amount === '0.00') {
            $this->fail('Receipt effects must have positive exact amounts.');
        }

return $amount;
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
            $this->fail('Source event timestamp is required.');
        }

return CarbonImmutable::instance($value)->utc()->format('Y-m-d\TH:i:sP');
    }

    private function sameEvidence(mixed $left, mixed $right): bool
    {
        return hash_equals($this->canonical->hash(['value' => $left]), $this->canonical->hash(['value' => $right]));
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['accounting_receipt' => $message]);
    }
}
