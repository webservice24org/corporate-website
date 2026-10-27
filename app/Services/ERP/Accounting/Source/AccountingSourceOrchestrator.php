<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingAuditEvent;
use App\Models\Accounting\AccountingSetting;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalEntryLine;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingCashAccountService;
use App\Services\ERP\Accounting\AccountingConfigurationGuard;
use App\Services\ERP\Accounting\AccountingDecimal;
use App\Services\ERP\Accounting\AccountingDocumentNumberService;
use App\Services\ERP\Accounting\AccountingDomainRegistry;
use App\Services\ERP\Accounting\AccountingJournalAmounts;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\AccountingPostingReadiness;
use App\Services\ERP\Accounting\Adjustments\AccountingBankAdjustmentMapping;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseFinanceMapping;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferReversalMapping;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Internal source writer. Trusted installed adapters authorize their own source operations; no HTTP endpoint. */
final class AccountingSourceOrchestrator
{
    public function __construct(private readonly AccountingSourceEventRegistry $registry, private readonly AccountingSourceCompanyScope $scope, private readonly AccountingConfigurationGuard $configuration, private readonly AccountingPostingReadiness $readiness, private readonly AccountingSourcePeriod $periods, private readonly AccountingMappingService $mappings, private readonly AccountingAutomaticLineGuard $lineGuard, private readonly AccountingAutomaticEvidence $evidence, private readonly AccountingJournalAmounts $amounts, private readonly AccountingDecimal $decimal, private readonly AccountingCanonicalPayload $canonical, private readonly AccountingDocumentNumberService $numbers, private readonly AccountingNoJournalPolicy $noJournal) {}

    /** Caller must propagate exceptions through the transaction that mutates its source.
     * @return array{mode: string, effect_ids: list<int>}
     */
    public function execute(User $actor, int $companyId, string $sourceKind, int $sourceId, string $eventKind, string $eventToken): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Keep source mutation and accounting orchestration inside the same caller-owned transaction.');
        }
        $definition = $this->registry->definition($sourceKind, $eventKind);
        $adapter = $this->registry->adapter($sourceKind, $eventKind);

        return DB::transaction(function () use ($actor, $companyId, $sourceKind, $sourceId, $eventKind, $eventToken, $definition, $adapter): array {
            $company = $this->scope->lock($actor, $companyId);
            $adapter->authorize($actor, $company, $sourceId, $eventKind, $eventToken);
            $event = $adapter->prepare($actor, $company, $sourceId, $eventKind, $eventToken);
            if ($event->companyId !== $companyId || $event->sourceKind !== $sourceKind || $event->sourceId !== $sourceId || $event->eventKind !== $eventKind || $event->eventToken !== $eventToken) {
                $this->fail('source_identity', 'The adapter returned a different source/event identity.');
            }
            $this->validate($event, $definition);
            $envelope = $this->envelope($event);
            $hash = $this->canonical->hash($envelope);
            $expected = array_keys($definition);
            sort($expected, SORT_STRING);
            $existing = AccountingSourceEffect::query()->where('company_id', $companyId)->where('source_kind', $sourceKind)->where('source_id', $sourceId)->where('event_kind', $eventKind)->where('event_token', $eventToken)->orderBy('effect_kind')->lockForUpdate()->get();
            if ($existing->isNotEmpty()) {
                $actual = $existing->map(static fn (AccountingSourceEffect $row): string => (string) $row->getAttribute('effect_kind'))->all();
                sort($actual, SORT_STRING);
                if ($actual !== $expected) {
                    $this->fail('source_bundle', 'A partial or conflicting historical bundle exists. Reconcile it; never fill in missing effects as a retry.');
                }
                $ids = [];
                foreach ($existing as $row) {
                    $this->verifyHistorical($row, $event, $hash, $expected);
                    $ids[] = (int) $row->getKey();
                }

                return ['mode' => 'replayed', 'effect_ids' => $ids];
            }
            // A disabled/ready company retains its operational behavior; no skipped financial effect is invented.
            $setting = AccountingSetting::query()->where('company_id', $companyId)->lockForUpdate()->first();
            if ($setting !== null && $setting->getAttribute('cutover_locked_at') !== null) {
                $this->fail('cutover', 'Source operations are blocked by the accounting cutover lock.');
            }
            if ($setting === null || in_array($setting->getAttribute('state'), ['disabled', 'ready'], true)) {
                return ['mode' => 'bypassed', 'effect_ids' => []];
            }
            $setting = $this->configuration->lockForSetup($company);
            $this->readiness->check($company, $setting, $event->accountingDate);
            if ($event->currency !== $setting->getAttribute('functional_currency')) {
                $this->fail('currency_code', 'Source currency differs from activated functional currency. No implicit FX conversion.');
            }
            $activation = $setting->getAttribute('activation_date')->format('Y-m-d');
            if ($event->originDate < $activation) {
                $this->fail('coverage', 'Pre-cutover original recognition requires the dedicated verified-coverage adapter; it cannot be posted as a new event here.');
            }
            foreach ($event->references as $reference) {
                if ($reference['origin_date'] < $activation) {
                    $this->fail('coverage', 'A pre-cutover parent reference requires the later coverage-aware adapter. Missing proof cannot be assumed.');
                }
            }
            [$year, $period] = $this->periods->lock($actor, $company, $event->accountingDate);
            $roles = [];
            foreach ($event->effects as $effect) {
                foreach ($effect->lines as $line) {
                    if (isset($line['role']) && is_string($line['role'])) {
                        $roles[] = $line['role'];
                    }
                }
            }
            $mapping = app(AccountingExpenseFinanceMapping::class)->resolve($event, array_values(array_unique($roles))) ?? app(AccountingBankAdjustmentMapping::class)->resolve($event, array_values(array_unique($roles))) ?? app(AccountingCashTransferReversalMapping::class)->resolve($event, array_values(array_unique($roles))) ?? app(Purchase\AccountingSupplierPaymentReversalMapping::class)->resolve($event, array_values(array_unique($roles))) ?? $this->mappings->resolveForSource($company, $event->accountingDate, array_values(array_unique($roles)));
            $ids = [];
            $effects = $event->effects;
            usort($effects, static fn (AccountingSourceEffectPlan $a, AccountingSourceEffectPlan $b): int => $a->kind <=> $b->kind);
            foreach ($effects as $effect) {
                $journal = null;
                if ($effect->noJournalReason !== null) {
                    $this->noJournal->verify($companyId, $effect, $definition[$effect->kind]);
                } else {
                    $lines = $this->normalize($companyId, $effect, $mapping['accounts']);
                    $totals = $this->amounts->balanced($lines);
                    if ($this->decimal->money($effect->proof['gl_amount'] ?? null) !== $totals['total_debit']) {
                        $this->fail('source_amount', 'Source effect proof and recomputed journal magnitude disagree.');
                    }
                    $this->lineGuard->validate($companyId, $lines);
                    $number = $this->numbers->next($companyId, (int) $year->getKey(), 'journal', $event->accountingDate);
                    if (JournalEntry::query()->where('company_id', $companyId)->where('number', $number)->exists()) {
                        $this->fail('number', 'Automatic journal numbering requires reconciliation.');
                    }
                    $journal = JournalEntry::query()->create(['company_id' => $companyId, 'accounting_period_id' => $period->getKey(), 'mapping_version_id' => $mapping['version_id'], 'number' => $number, 'entry_kind' => 'automatic', 'status' => 'draft', 'accounting_date' => $event->accountingDate, 'document_date' => $event->documentDate, 'currency_code' => $event->currency, 'description' => $sourceKind.' '.$sourceId.' '.$eventKind.' '.$effect->kind, 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(), 'revision' => 1, 'reason' => 'Trusted source event '.$eventToken, 'contract_version' => AccountingDomainRegistry::VERSION]);
                    foreach ($lines as $line) {
                        JournalEntryLine::query()->create(['company_id' => $companyId, 'journal_entry_id' => $journal->getKey(), ...$line]);
                    }
                    $expectedHash = $this->canonical->hash($this->evidence->payload($journal, $lines, $hash, $effect->kind, $mapping['payload_hash']));
                    $actualHash = $this->canonical->hash($this->evidence->payload($journal, $this->evidence->lines($journal), $hash, $effect->kind, $mapping['payload_hash']));
                    if (! hash_equals($expectedHash, $actualHash)) {
                        $this->fail('source_amount', 'Automatic journal values did not round-trip exactly.');
                    }
                    $journal->fill(['status' => 'posted', 'posted_by' => $actor->getKey(), 'posted_at' => CarbonImmutable::now('UTC'), 'payload_hash' => $expectedHash])->save();
                    $this->evidence->verify($journal, $hash, $effect->kind, $mapping['payload_hash']);
                    // Private writer under the already-authorized adapter/company transaction, not manual journal authority.
                    AccountingAuditEvent::query()->create(['company_id' => $companyId, 'entity_kind' => 'automatic', 'entity_id' => $journal->getKey(), 'action' => 'post', 'actor_id' => $actor->getKey(), 'actor_snapshot' => ['id' => $actor->getKey(), 'name' => (string) $actor->getAttribute('name')], 'reason' => 'Trusted '.$sourceKind.' '.$eventKind.' '.$eventToken, 'before_payload' => [], 'after_payload' => ['number' => $number, 'status' => 'posted', 'total_debit' => $totals['total_debit'], 'total_credit' => $totals['total_credit'], 'payload_hash' => $expectedHash], 'occurred_at' => CarbonImmutable::now('UTC')->toDateTimeString()]);
                }
                $body = ['recorded_by' => (int) $actor->getKey(), 'recorded_at' => CarbonImmutable::now('UTC')->toIso8601String(), 'source' => $envelope, 'bundle_effects' => $expected, 'effect_kind' => $effect->kind, 'proof' => $effect->proof, 'outcome' => $journal === null ? 'verified_no_journal' : 'posted', 'no_journal_reason' => $effect->noJournalReason, 'mapping_hash' => $mapping['payload_hash'], 'journal_hash' => $journal === null ? null : (string) $journal->getAttribute('payload_hash')];
                $row = AccountingSourceEffect::query()->create(['company_id' => $companyId, 'source_kind' => $sourceKind, 'source_id' => $sourceId, 'event_kind' => $eventKind, 'event_token' => $eventToken, 'effect_kind' => $effect->kind, 'source_payload_hash' => $hash, 'contract_version' => AccountingDomainRegistry::VERSION, 'mapping_version_id' => $mapping['version_id'], 'journal_entry_id' => $journal?->getKey(), 'outcome' => $journal === null ? 'verified_no_journal' : 'posted', 'no_journal_reason' => $effect->noJournalReason, 'source_document_date' => $event->documentDate, 'accounting_date' => $event->accountingDate, 'source_snapshot' => ['evidence' => $body, 'evidence_hash' => $this->canonical->hash($body)]]);
                $ids[] = (int) $row->getKey();
            }

            return ['mode' => 'recorded', 'effect_ids' => $ids];
        });
    }

    /** @param array<string, list<string>> $definition */
    private function validate(AccountingPreparedSourceEvent $event, array $definition): void
    {
        Validator::make(['source_id' => $event->sourceId, 'token' => $event->eventToken, 'currency' => $event->currency, 'document_date' => $event->documentDate, 'accounting_date' => $event->accountingDate, 'origin_date' => $event->originDate, 'references' => $event->references], ['source_id' => ['required', 'integer', 'min:1'], 'token' => ['required', 'regex:/\A[A-Za-z0-9_-]{1,64}\z/'], 'currency' => ['required', 'regex:/\A[A-Z]{3}\z/'], 'document_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:9999-12-31'], 'accounting_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:9999-12-31'], 'origin_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:accounting_date'], 'references' => ['present', 'array', 'max:50'], 'references.*' => ['array:source_kind,source_id,origin_date'], 'references.*.source_kind' => ['required', 'regex:/\A[A-Za-z0-9_-]{1,40}\z/'], 'references.*.source_id' => ['required', 'integer', 'min:1'], 'references.*.origin_date' => ['required', 'date_format:Y-m-d']])->validate();
        if (! array_is_list($event->effects) || ! array_is_list($event->references) || $event->facts === [] || array_is_list($event->facts)) {
            $this->fail('source_facts', 'Source facts and ordered effects/references are required.');
        }
        $keys = [];
        foreach ($event->effects as $effect) {
            $keys[] = $effect->kind;
            if (! array_key_exists('gl_amount', $effect->proof)) {
                $this->fail('source_facts', 'Every named effect requires a keyed monetary proof.');
            } $this->facts($effect->proof);
        }
        $actual = $keys;
        $expected = array_keys($definition);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            $this->fail('source_bundle', 'The complete registered named effect bundle is required, without duplicates or extras.');
        }
        $this->facts($event->facts);
        if (isset($event->facts['currency_code']) && $event->facts['currency_code'] !== $event->currency) {
            $this->fail('source_facts', 'Source fact currency and event currency disagree.');
        }
        $this->canonical->hash($event->facts);
    }

    /** Closed safe field vocabulary, recursively applied; adapters must normalize amounts before constructing DTOs. @param array<array-key, mixed> $facts */
    private function facts(array $facts, int $depth = 0): void
    {
        if ($depth > 10 || count($facts) > 1000) {
            $this->fail('source_facts', 'Source fact nesting/width exceeds the evidence contract.');
        }
        $allowed = ['cash_account_id', 'source_number', 'amount', 'source_cost', 'source_quantity', 'party_type', 'party_id', 'currency_code', 'recognition_date', 'movement_ids', 'line_facts', 'allocation_id', 'original_id', 'from_customer_id', 'to_customer_id', 'fulfillment_type', 'billed', 'restocked_quantity', 'original_cost_recognized', 'gl_amount', 'evidence_hash', 'product_id', 'product_variant_id', 'unit_id', 'warehouse_id', 'quantity', 'cost', 'net', 'tax', 'total', 'source_line_id', 'branch_id', 'department_id'];
        foreach ($facts as $key => $value) {
            if ($depth === 0 && ! is_string($key)) {
                $this->fail('source_facts', 'Top-level financial facts and proofs require named fields.');
            }
            if (is_string($key) && ! in_array($key, $allowed, true)) {
                $this->fail('source_facts', 'Source facts contain a field outside the safe financial vocabulary.');
            }
            if (in_array($key, ['amount', 'net', 'tax', 'total', 'gl_amount'], true) && (! is_string($value) || $this->decimal->money($value) !== $value)) {
                $this->fail('source_facts', 'Money facts must be normalized exact two-decimal strings.');
            }
            if (in_array($key, ['source_cost', 'source_quantity', 'quantity', 'cost', 'restocked_quantity'], true) && (! is_string($value) || $this->decimal->source($value) !== $value)) {
                $this->fail('source_facts', 'Quantity/cost facts must be normalized exact four-decimal strings.');
            }
            if (is_array($value)) {
                $this->facts($value, $depth + 1);
            } elseif (! is_string($value) && ! is_int($value) && ! is_bool($value) && $value !== null) {
                $this->fail('source_facts', 'Exact scalar facts are required; floats/objects are forbidden.');
            } elseif (is_string($value) && mb_strlen($value) > 500) {
                $this->fail('source_facts', 'Financial source scalar is too long.');
            }
        }
        $this->canonical->hash($facts);
    }

    /** @return array<string, mixed> */
    private function envelope(AccountingPreparedSourceEvent $event): array
    {
        $proofs = [];
        foreach ($event->effects as $effect) {
            $proofs[$effect->kind] = $effect->proof;
        } ksort($proofs, SORT_STRING);

        return ['source_contract' => 'source.v1', 'company_id' => $event->companyId, 'source_kind' => $event->sourceKind, 'source_id' => $event->sourceId, 'event_kind' => $event->eventKind, 'event_token' => $event->eventToken, 'currency' => $event->currency, 'document_date' => $event->documentDate, 'accounting_date' => $event->accountingDate, 'origin_date' => $event->originDate, 'facts' => $event->facts, 'effect_proofs' => $proofs, 'references' => $event->references];
    }

    /** @param array<string, int> $mapping @return list<array<string, mixed>> */
    private function normalize(int $companyId, AccountingSourceEffectPlan $effect, array $mapping): array
    {
        Validator::make(['lines' => $effect->lines], ['lines' => ['required', 'array', 'min:2', 'max:200'], 'lines.*' => ['array:role,debit,credit,party_type,party_id,source_line_type,source_line_id,branch_id,department_id,description,cash_account_id'], 'lines.*.role' => ['required', 'string', 'max:40'], 'lines.*.debit' => ['present'], 'lines.*.credit' => ['present'], 'lines.*.party_type' => ['nullable', 'in:customer,supplier'], 'lines.*.party_id' => ['nullable', 'integer', 'min:1'], 'lines.*.source_line_type' => ['required', 'regex:/\A[A-Za-z0-9_-]{1,40}\z/'], 'lines.*.source_line_id' => ['required', 'integer', 'min:1'], 'lines.*.branch_id' => ['nullable', 'integer', 'min:1'], 'lines.*.department_id' => ['nullable', 'integer', 'min:1'], 'lines.*.description' => ['nullable', 'string', 'max:500'], 'lines.*.cash_account_id' => ['nullable', 'integer', 'min:1']])->validate();
        $totals = $this->amounts->balanced($effect->lines);
        $lines = [];
        foreach ($effect->lines as $index => $line) {
            if (! isset($mapping[$line['role']])) {
                $this->fail('mappings', 'Every automatic line needs its mapped role.');
            }
            $account = Account::query()->where('company_id', $companyId)->findOrFail($mapping[$line['role']]);
            $partyType = $line['party_type'] ?? null;
            $partyId = isset($line['party_id']) ? (int) $line['party_id'] : null;
            if (($partyType === null) !== ($partyId === null)) {
                $this->fail('source_party', 'Party type and ID must be supplied together.');
            }
            if ($partyType !== null) {
                $class = $partyType === 'customer' ? Customer::class : Supplier::class;
                if (! $class::query()->when($partyType === 'customer', static fn ($query) => $query->withoutGlobalScope(SoftDeletingScope::class))->where('company_id', $companyId)->whereKey($partyId)->exists()) {
                    $this->fail('source_party', 'Source party is outside this company.');
                }
            }
            $cashId = app(AccountingCashAccountService::class)->line($companyId, (string) $line['role'], (int) $account->getKey(), $line['cash_account_id'] ?? null, 'lines.'.$index.'.account_id', ['kind' => (string) $line['source_line_type'], 'id' => (int) $line['source_line_id'], 'debit' => $totals['amounts'][$index]['debit'], 'credit' => $totals['amounts'][$index]['credit']]);
            $control = $account->getAttribute('control_role');
            if (in_array($control, ['trade_ar', 'unapplied_customer_funds'], true) && $partyType !== 'customer') {
                $this->fail('source_party', 'Customer control lines require a company-owned customer.');
            }
            if (in_array($control, ['trade_ap', 'supplier_advances'], true) && $partyType !== 'supplier') {
                $this->fail('source_party', 'Supplier control lines require a company-owned supplier.');
            }
            $lines[] = ['line_number' => $index + 1, 'account_id' => (int) $account->getKey(), ...$totals['amounts'][$index], 'party_type' => $partyType, 'party_id' => $partyId, 'source_line_type' => (string) $line['source_line_type'], 'source_line_id' => (int) $line['source_line_id'], 'branch_id' => isset($line['branch_id']) ? (int) $line['branch_id'] : null, 'department_id' => isset($line['department_id']) ? (int) $line['department_id'] : null, 'description' => empty($line['description']) ? null : trim((string) $line['description']), 'cash_account_id' => $cashId];
        }

        return $lines;
    }

    /** @param list<string> $expected */
    private function verifyHistorical(AccountingSourceEffect $row, AccountingPreparedSourceEvent $event, string $hash, array $expected): void
    {
        if (! hash_equals((string) $row->getAttribute('source_payload_hash'), $hash) || $row->getAttribute('accounting_date')->format('Y-m-d') !== $event->accountingDate || $row->getAttribute('source_document_date')?->format('Y-m-d') !== $event->documentDate) {
            $this->fail('source_conflict', 'Source facts changed under an already-recognized event identity.');
        }
        $snapshot = $row->getAttribute('source_snapshot');
        $body = is_array($snapshot) ? ($snapshot['evidence'] ?? null) : null;
        if (! is_array($body) || ! is_int($body['recorded_by'] ?? null) || $body['recorded_by'] < 1 || ! is_string($body['recorded_at'] ?? null) || ! isset($body['source'], $body['bundle_effects'], $body['effect_kind'], $body['proof'], $body['mapping_hash']) || ! is_array($body['source']) || ! hash_equals((string) ($snapshot['evidence_hash'] ?? ''), $this->canonical->hash($body)) || ! hash_equals($hash, $this->canonical->hash($body['source'])) || $body['bundle_effects'] !== $expected || $body['effect_kind'] !== $row->getAttribute('effect_kind') || ($body['outcome'] ?? null) !== $row->getAttribute('outcome') || ($body['no_journal_reason'] ?? null) !== $row->getAttribute('no_journal_reason') || ! hash_equals($this->canonical->hash(['proof' => $body['proof']]), $this->canonical->hash(['proof' => $body['source']['effect_proofs'][$body['effect_kind']] ?? null]))) {
            $this->fail('source_integrity', 'Historical source-effect evidence is inconsistent.');
        }
        try {
            $profile = $this->mappings->verifyRecordedProfile($event->companyId, (int) $row->getAttribute('mapping_version_id'), $event->currency, (string) $row->getAttribute('contract_version'), (string) $body['mapping_hash']);
        } catch (ModelNotFoundException|ValidationException) {
            $this->fail('source_integrity', 'Historical mapping identity no longer matches the recorded effect.');
        }
        if ($row->getAttribute('outcome') === 'posted') {
            if ($row->getAttribute('no_journal_reason') !== null || $row->getAttribute('journal_entry_id') === null) {
                $this->fail('source_integrity', 'Posted source effect lacks journal evidence.');
            }
            $journal = JournalEntry::query()->where('company_id', $event->companyId)->findOrFail($row->getAttribute('journal_entry_id'));
            if ((int) $journal->getAttribute('mapping_version_id') !== (int) $profile->getKey() || $journal->getAttribute('payload_hash') !== ($body['journal_hash'] ?? null) || $journal->getAttribute('currency_code') !== $event->currency) {
                $this->fail('source_integrity', 'Historical source/journal mapping or fingerprint mismatch.');
            }
            $this->evidence->verify($journal, $hash, (string) $row->getAttribute('effect_kind'), (string) $body['mapping_hash']);
        } elseif ($row->getAttribute('outcome') === 'verified_no_journal') {
            $reason = $row->getAttribute('no_journal_reason');
            $allowed = $this->registry->definition($event->sourceKind, $event->eventKind)[(string) $row->getAttribute('effect_kind')];
            if ($row->getAttribute('journal_entry_id') !== null || ($body['journal_hash'] ?? null) !== null || ! in_array($reason, $allowed, true) || $this->decimal->money($body['proof']['gl_amount'] ?? null) !== '0.00') {
                $this->fail('source_integrity', 'Historical no-journal outcome lacks registered zero-GL proof.');
            }
        } else {
            $this->fail('source_integrity', 'Unknown historical source effect outcome.');
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
