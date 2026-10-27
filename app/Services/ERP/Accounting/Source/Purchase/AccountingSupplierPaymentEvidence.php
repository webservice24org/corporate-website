<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Purchase;

use App\Models\Accounting\AccountingAccountMapping;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingPurchaseEvent;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\JournalEntry;
use App\Models\Company;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\Source\AccountingAutomaticEvidence;
use App\Services\ERP\Accounting\Source\AccountingPreparedSourceEvent;
use App\Services\ERP\Accounting\Source\AccountingSourceEffectPlan;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Initial payment/settlement evidence. No mutable outstanding totals or private references enter finance. */
final class AccountingSupplierPaymentEvidence
{
    public function __construct(private readonly AccountingSupplierPaymentIntent $intent, private readonly AccountingSupplierPaymentContract $contract, private readonly AccountingPurchaseEvidence $purchase, private readonly AccountingCanonicalPayload $canonical, private readonly AccountingBusinessDate $dates, private readonly AccountingMappingService $mappings, private readonly AccountingAutomaticEvidence $journals, private readonly PurchaseCompanyAccess $access, private readonly AccountingSupplierPaymentOperations $operations) {}

    public function capture(User $actor, int $company, string $kind, int $id): void
    {
        $this->transaction();
        $native = $kind === 'supplier_payment_allocation' ? SupplierPaymentAllocation::query()->where('company_id', $company)->findOrFail($id) : null;
        $reverse = $native !== null && str_contains((string) $native->getAttribute('idempotency_key'), ':reverse:');
        $this->access->authorize($actor, $company, $kind === 'supplier_payment' ? 'erp.supplier-payments.post' : ($reverse ? 'erp.supplier-payments.reverse' : 'erp.supplier-payments.allocate'));
        $p = $this->projection($company, $kind, $id);
        $owned = Company::query()->findOrFail($company);
        $refs = [];
        if ($kind === 'supplier_payment') {
            $date = $p['date'];
            $cashRole = $p['cash']['role'];
            $effect = 'cash';
        } else {
            $date = $this->dates->fromInstant($owned, CarbonImmutable::parse($p['instant']));
            $cashRole = null;
            $effect = 'allocation';
            $parent = $this->recognized($company, 'supplier_payment', $p['payment_id']);
            $bill = $this->purchase->recognized($company, 'supplier_bill', $p['bill_id']);
            if ($date < $parent['date'] || $date < $bill['date']) {
                $this->fail('Allocation cannot precede recognized cash or AP.');
            }
            $refs = [['source_kind' => 'supplier_payment', 'source_id' => $p['payment_id'], 'origin_date' => $parent['date']], ['source_kind' => 'supplier_bill', 'source_id' => $p['bill_id'], 'origin_date' => $bill['date']]];
            if (! isset($p['reversal_request_id'])) {
                $mapping = $this->mappings->resolveForSource($owned, $date, ['trade_ap', 'supplier_advances']);
                $this->sameControl($company, 'supplier_payment', $p['payment_id'], 'cash', 'supplier_advances', $mapping['accounts']['supplier_advances']);
                $this->sameControl($company, 'supplier_bill', $p['bill_id'], 'commercial', 'trade_ap', $mapping['accounts']['trade_ap']);
            }
        }
        $compensating = isset($p['reverses_allocation_id']);
        if ($compensating) {
            $original = $this->recognized($company, 'supplier_payment_allocation', $p['reverses_allocation_id']);
            if ($date < $original['date']) {
                $this->fail('Compensation cannot precede its recognized original allocation.');
            }
            $refs[] = ['source_kind' => 'supplier_payment_allocation', 'source_id' => $p['reverses_allocation_id'], 'origin_date' => $original['date']];
            if (! isset($p['reversal_request_id'])) {
                $mapping = $this->mappings->resolveForSource($owned, $date, ['trade_ap', 'supplier_advances']);
                $this->sameControl($company, 'supplier_payment_allocation', $p['reverses_allocation_id'], 'allocation', 'trade_ap', $mapping['accounts']['trade_ap']);
                $this->sameControl($company, 'supplier_payment_allocation', $p['reverses_allocation_id'], 'allocation', 'supplier_advances', $mapping['accounts']['supplier_advances']);
            }
        }
        $plan = $this->contract->plan($effect === 'cash' ? 'cash_paid' : ($compensating ? 'allocation_compensated' : 'allocated'), $p['amount'], $cashRole);
        $lines = [];
        foreach ($plan['lines'] as $line) {
            $cash = $effect === 'cash' && $line['role'] === $cashRole;
            $lines[] = [...$line, 'party_type' => $cash ? null : 'supplier', 'party_id' => $cash ? null : $p['supplier_id'], 'cash_account_id' => $cash ? $p['cash']['id'] : null, 'source_line_type' => $kind, 'source_line_id' => $id];
        }
        $payload = ['adapter_contract' => AccountingSupplierPaymentContract::VERSION, 'projection' => $p, 'date' => $date, 'currency' => $p['currency'], 'references' => $refs, 'effect_kind' => $effect, 'lines' => $lines];
        AccountingPurchaseEvent::query()->create(['company_id' => $company, 'source_kind' => $kind, 'source_id' => $id, 'payload' => $payload, 'payload_hash' => $this->canonical->hash($payload), 'created_by' => $actor->getKey()]);
    }

    public function prepare(int $company, string $kind, int $id): AccountingPreparedSourceEvent
    {
        $this->transaction();
        $row = AccountingPurchaseEvent::query()->where('company_id', $company)->where('source_kind', $kind)->where('source_id', $id)->first();
        if ($row === null) {
            $this->fail('Original supplier payment admission is missing; retries cannot backfill it.');
        }
        $p = $this->checked($row);
        if ($this->canonical->hash($p['projection']) !== $this->canonical->hash($this->projection($company, $kind, $id))) {
            $this->fail('Immutable native payment or bill dependency changed.');
        }

        return new AccountingPreparedSourceEvent($company, $kind, $id, $kind === 'supplier_payment' ? (! empty($p['projection']['reversal']) ? 'reversed' : 'posted') : (isset($p['projection']['reverses_allocation_id']) ? 'reversed' : 'created'), 'original', $p['currency'], $p['date'], $p['date'], $p['date'], ['source_number' => $p['projection']['number'], 'amount' => $p['projection']['amount'], 'evidence_hash' => (string) $row->getAttribute('payload_hash'), ...(isset($p['projection']['operation_hash']) ? ['original_id' => $p['projection']['payment_id']] : [])], [new AccountingSourceEffectPlan($p['effect_kind'], $p['lines'], ['gl_amount' => $p['projection']['amount'], 'evidence_hash' => (string) $row->getAttribute('payload_hash')])], $p['references']);
    }

    /** Authorized dependency read; does not call an orchestrator or repair finance.
     * @return array<string, mixed>
     */
    public function recognized(int $company, string $kind, int $id): array
    {
        $dto = $this->prepare($company, $kind, $id);
        $admission = AccountingPurchaseEvent::query()->where('company_id', $company)->where('source_kind', $kind)->where('source_id', $id)->firstOrFail();
        $payload = $this->checked($admission);
        $rows = AccountingSourceEffect::query()->where('company_id', $company)->where('source_kind', $kind)->where('source_id', $id)->get();
        $row = $rows->count() === 1 ? $rows->first() : null;
        if ($row === null || $row->getAttribute('event_kind') !== $dto->eventKind || $row->getAttribute('event_token') !== 'original' || $row->getAttribute('effect_kind') !== $payload['effect_kind'] || $row->getAttribute('outcome') !== 'posted' || $row->getAttribute('no_journal_reason') !== null || $row->getAttribute('journal_entry_id') === null || $row->getAttribute('accounting_date')->format('Y-m-d') !== $dto->accountingDate || $row->getAttribute('source_document_date')?->format('Y-m-d') !== $dto->documentDate) {
            $this->fail('Supplier payment effect bundle is incomplete.');
        }
        $snapshot = $row->getAttribute('source_snapshot');
        $body = is_array($snapshot) ? ($snapshot['evidence'] ?? null) : null;
        $expected = $this->envelope($dto);
        $sourceHash = $this->canonical->hash($expected);
        if (! is_array($body) || ! is_array($body['source'] ?? null) || ! hash_equals((string) ($snapshot['evidence_hash'] ?? ''), $this->canonical->hash($body)) || ! hash_equals($sourceHash, (string) $row->getAttribute('source_payload_hash')) || ! hash_equals($sourceHash, $this->canonical->hash($body['source'])) || ! is_int($body['recorded_by'] ?? null) || $body['recorded_by'] < 1 || ! is_string($body['recorded_at'] ?? null) || ! is_string($body['mapping_hash'] ?? null) || ($body['bundle_effects'] ?? null) !== [$payload['effect_kind']] || ($body['effect_kind'] ?? null) !== $payload['effect_kind'] || ($body['outcome'] ?? null) !== 'posted' || ($body['no_journal_reason'] ?? null) !== null || $this->canonical->hash(['proof' => $body['proof'] ?? null]) !== $this->canonical->hash(['proof' => $dto->effects[0]->proof])) {
            $this->fail('Supplier payment historical evidence is inconsistent.');
        }
        $this->mappings->verifyRecordedProfile($company, (int) $row->getAttribute('mapping_version_id'), $dto->currency, (string) $row->getAttribute('contract_version'), (string) $body['mapping_hash']);
        $journal = JournalEntry::query()->where('company_id', $company)->findOrFail((int) $row->getAttribute('journal_entry_id'));
        if ((int) $journal->getAttribute('mapping_version_id') !== (int) $row->getAttribute('mapping_version_id') || $journal->getAttribute('payload_hash') !== ($body['journal_hash'] ?? null) || $journal->getAttribute('currency_code') !== $dto->currency) {
            $this->fail('Supplier payment journal identity changed.');
        }
        $this->journals->verify($journal, $sourceHash, $payload['effect_kind'], (string) $body['mapping_hash']);

        return $payload;
    }

    /** @return array<string, mixed> */
    private function projection(int $company, string $kind, int $id): array
    {
        if ($kind === 'supplier_payment' && SupplierPayment::query()->where('company_id', $company)->whereKey($id)->whereNotNull('reverses_payment_id')->exists()) {
            return app(AccountingSupplierPaymentReversalEvidence::class)->projection($company, $id);
        }
        if ($kind === 'supplier_payment') {
            $row = SupplierPayment::query()->where('company_id', $company)->where('status', 'posted')->whereNull('reverses_payment_id')->findOrFail($id);
            $hash = $this->intent->hash($row);
            if ($row->getAttribute('payload_hash') !== $hash || $row->getAttribute('approved_payload_hash') !== $hash || $row->getAttribute('submitted_payload_hash') !== $hash || (int) $row->getAttribute('approved_by') === (int) $row->getAttribute('created_by') || (int) $row->getAttribute('approved_by') === (int) $row->getAttribute('last_edited_by') || ! is_string($row->getAttribute('idempotency_key'))) {
                $this->fail('Native posted payment does not match its independently approved intent.');
            }
            $cash = AccountingCashAccount::query()->where('company_id', $company)->where('currency_code', $row->getAttribute('currency_code'))->findOrFail((int) $row->getAttribute('cash_account_id'));
            if ($cash->getAttribute('kind') !== ($row->getAttribute('payment_method') === 'cash' ? 'cash' : 'bank')) {
                $this->fail('Original finance binding kind differs.');
            }
            $p = ['number' => (string) $row->getAttribute('number'), 'supplier_id' => (int) $row->getAttribute('supplier_id'), 'currency' => (string) $row->getAttribute('currency_code'), 'date' => $this->date($row->getAttribute('document_date')), 'amount' => (string) $row->getAttribute('amount'), 'advance' => (string) $row->getAttribute('advance_amount'), 'method' => (string) $row->getAttribute('payment_method'), 'intent_hash' => $hash, 'native_key_hash' => hash('sha256', (string) $row->getAttribute('idempotency_key')), 'targets' => $this->intent->targets($row), 'cash' => ['id' => (int) $cash->getKey(), 'role' => (string) $cash->getAttribute('role'), 'account_id' => (int) $cash->getAttribute('account_id'), 'currency' => (string) $cash->getAttribute('currency_code')]];
            foreach (['created_by', 'last_edited_by', 'submitted_by', 'approved_by', 'posted_by'] as $actor) {
                if ((int) $row->getAttribute($actor) < 1) {
                    $this->fail('Native workflow actor is missing.');
                } $p[$actor] = (int) $row->getAttribute($actor);
            }
            foreach (['submitted_at', 'approved_at', 'posted_at'] as $time) {
                $p[$time] = $this->instant($row->getAttribute($time));
            }

return $p;
        }
        if ($kind !== 'supplier_payment_allocation') {
            $this->fail('Unsupported supplier settlement source.');
        }
        $row = SupplierPaymentAllocation::query()->where('company_id', $company)->findOrFail($id);
        $parentId = (int) $row->getAttribute('supplier_payment_id');
        $parent = $this->recognized($company, 'supplier_payment', $parentId);
        $p = $parent['projection'];
        $billId = (int) $row->getAttribute('supplier_bill_id');
        $bill = $this->purchase->recognized($company, 'supplier_bill', $billId);
        if ($bill['projection']['supplier_id'] !== $p['supplier_id'] || $bill['currency'] !== $p['currency'] || (int) $row->getAttribute('posted_by') < 1 || $this->instant($row->getAttribute('posted_at')) < $bill['projection']['approved_at']) {
            $this->fail('Allocation party/currency/actor differs from recognized sources.');
        }
        $payable = DB::table('supplier_payables')->where('id', $row->getAttribute('supplier_payable_id'))->where('company_id', $company)->where('supplier_bill_id', $billId)->where('supplier_id', $p['supplier_id'])->where('currency_code', $p['currency'])->exists();
        if (! $payable) {
            $this->fail('Allocation payable identity differs.');
        }
        $nativeParent = SupplierPayment::query()->where('company_id', $company)->findOrFail($parentId);
        $matched = false;
        foreach ($p['targets'] as $index => $target) {
            if ($target['supplier_bill_id'] !== $billId) {
                continue;
            }
            $matched = $target['supplier_payable_id'] === (int) $row->getAttribute('supplier_payable_id') && $target['amount'] === (string) $row->getAttribute('amount') && $row->getAttribute('idempotency_key') === $nativeParent->getAttribute('idempotency_key').':post:'.$index && $row->getAttribute('payload_hash') === $p['intent_hash'] && (int) $row->getAttribute('posted_by') === $p['posted_by'] && $this->instant($row->getAttribute('posted_at')) >= $p['posted_at'];
        }
        $change = [];
        if (! $matched) {
            if (str_contains((string) $row->getAttribute('idempotency_key'), ':post:')) {
                $this->fail('Original initial allocation membership changed.');
            }
            $proof = str_contains((string) $row->getAttribute('idempotency_key'), ':reverse:') ? app(AccountingSupplierPaymentReversalOperations::class)->allocation($nativeParent, $id) : $this->operations->allocation($nativeParent, $id);
            $reverse = $row->getAttribute('reverses_allocation_id');
            $change = [...$proof, 'reverses_allocation_id' => $reverse === null ? null : (int) $reverse];
            if ($reverse !== null) {
                $original = SupplierPaymentAllocation::query()->where('company_id', $company)->where('supplier_payment_id', $parentId)->whereNull('reverses_allocation_id')->findOrFail((int) $reverse);
                foreach (['supplier_bill_id', 'supplier_payable_id', 'amount'] as $field) {
                    if ((string) $original->getAttribute($field) !== (string) $row->getAttribute($field)) {
                        $this->fail('Compensation identity differs from its exact original.');
                    }
                }
                if ($row->getAttribute('posted_at') < $original->getAttribute('posted_at')) {
                    $this->fail('Compensation predates its original allocation.');
                }
                $change['original_admission_hash'] = $this->canonical->hash($this->recognized($company, 'supplier_payment_allocation', (int) $reverse));
            }
        } elseif ($row->getAttribute('reverses_allocation_id') !== null) {
            $this->fail('An original initial allocation cannot be a compensation.');
        }

        return [...$change, 'number' => 'SPA-'.$id, 'payment_id' => $parentId, 'bill_id' => $billId, 'payable_id' => (int) $row->getAttribute('supplier_payable_id'), 'supplier_id' => $p['supplier_id'], 'currency' => $p['currency'], 'amount' => (string) $row->getAttribute('amount'), 'actor' => (int) $row->getAttribute('posted_by'), 'instant' => $this->instant($row->getAttribute('posted_at')), 'native_key_hash' => hash('sha256', (string) $row->getAttribute('idempotency_key')), 'native_payload_hash' => (string) $row->getAttribute('payload_hash'), 'payment_projection_hash' => $this->canonical->hash($p), 'bill_admission_hash' => $this->canonical->hash($bill)];
    }

    private function sameControl(int $company, string $kind, int $id, string $effect, string $role, int $expected): void
    {
        $row = AccountingSourceEffect::query()->where('company_id', $company)->where('source_kind', $kind)->where('source_id', $id)->where('effect_kind', $effect)->where('event_token', 'original')->firstOrFail();
        $account = AccountingAccountMapping::query()->where('company_id', $company)->where('mapping_version_id', $row->getAttribute('mapping_version_id'))->where('role', $role)->value('account_id');
        if ((int) $account !== $expected) {
            $this->fail('Recognized supplier control was remapped; a verified transition is required.');
        }
    }

    /** @return array<string, mixed> */
    private function checked(AccountingPurchaseEvent $row): array
    {
        $p = $row->getAttribute('payload');
        if (! is_array($p) || ($p['adapter_contract'] ?? null) !== AccountingSupplierPaymentContract::VERSION || ! is_array($p['projection'] ?? null) || ! is_string($p['date'] ?? null) || ! is_string($p['currency'] ?? null) || ! is_array($p['references'] ?? null) || ! is_array($p['lines'] ?? null) || ! in_array($p['effect_kind'] ?? null, ['cash', 'allocation'], true) || ! hash_equals((string) $row->getAttribute('payload_hash'), $this->canonical->hash($p))) {
            $this->fail('Supplier payment admission is damaged.');
        }

return $p;
    }

    /** Same closed source.v1 envelope used by the orchestrator, independently recomputed for read-only verification.
     * @return array<string, mixed>
     */
    private function envelope(AccountingPreparedSourceEvent $event): array
    {
        $proofs = [];
        foreach ($event->effects as $effect) {
            $proofs[$effect->kind] = $effect->proof;
        } ksort($proofs, SORT_STRING);

        return ['source_contract' => 'source.v1', 'company_id' => $event->companyId, 'source_kind' => $event->sourceKind, 'source_id' => $event->sourceId, 'event_kind' => $event->eventKind, 'event_token' => $event->eventToken, 'currency' => $event->currency, 'document_date' => $event->documentDate, 'accounting_date' => $event->accountingDate, 'origin_date' => $event->originDate, 'facts' => $event->facts, 'effect_proofs' => $proofs, 'references' => $event->references];
    }

    private function transaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Supplier payment evidence requires an authorized caller transaction.');
        }
    }

    private function date(mixed $value): string
    {
        if (! $value instanceof DateTimeInterface) {
            $this->fail('Missing native date.');
        }

return $value->format('Y-m-d');
    }

    private function instant(mixed $value): string
    {
        if (! $value instanceof DateTimeInterface) {
            $this->fail('Missing native event instant.');
        }

return CarbonImmutable::instance($value)->utc()->format('Y-m-d\TH:i:sP');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['supplier_payment' => $message]);
    }
}
