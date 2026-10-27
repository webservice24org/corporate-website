<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Transfers;

use App\Models\Accounting\AccountingCashTransferReversalOperation;
use App\Models\Accounting\AccountingCashTransferReversalRequest;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalEntryLine;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingDomainRegistry;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\Source\AccountingAutomaticEvidence;
use Illuminate\Validation\ValidationException;

/** Read-only complete operation verification. Never calls the writer, repairs members or evaluates current setup gates. */
final class AccountingCashTransferReversalEvidence
{
    public function __construct(private readonly AccountingCashTransferReversalNativeProof $native, private readonly AccountingCashTransferEvidence $originals, private readonly AccountingCanonicalPayload $canonical, private readonly AccountingMappingService $mappings, private readonly AccountingAutomaticEvidence $automatic) {}

    /** @return array{request: AccountingCashTransferReversalRequest, effect: AccountingSourceEffect, journal: JournalEntry} */
    public function verify(int $company, int $id): array
    {
        $proof = $this->native->verify($company, $id);
        $row = $proof['request'];
        $original = $proof['original'];
        $event = $this->native->event($proof);
        $operations = AccountingCashTransferReversalOperation::query()->where('company_id', $company)->where('reversal_request_id', $id)->get();
        $effects = AccountingSourceEffect::query()->where('company_id', $company)->where('source_kind', 'cash_transfer_reversal')->where('source_id', $id)->get();
        if ($operations->count() !== 1 || $effects->count() !== 1 || AccountingCashTransferReversalOperation::query()->where('company_id', $company)->where('original_transfer_id', $row->getAttribute('original_transfer_id'))->count() !== 1) {
            $this->fail();
        }
        $operation = $operations->firstOrFail();
        $effect = $effects->firstOrFail();
        $sourceHash = $this->canonical->hash($this->native->envelope($event));
        if ($effect->getAttribute('event_kind') !== 'posted' || $effect->getAttribute('event_token') !== $event->eventToken || $effect->getAttribute('effect_kind') !== 'cash' || $effect->getAttribute('outcome') !== 'posted' || $effect->getAttribute('no_journal_reason') !== null || $effect->getAttribute('contract_version') !== AccountingDomainRegistry::VERSION || (int) $effect->getAttribute('mapping_version_id') !== $original['mapping_version_id'] || $effect->getAttribute('journal_entry_id') === null || $effect->getAttribute('source_document_date')?->format('Y-m-d') !== $event->documentDate || $effect->getAttribute('accounting_date')->format('Y-m-d') !== $event->accountingDate || ! hash_equals((string) $effect->getAttribute('source_payload_hash'), $sourceHash)) {
            $this->fail();
        }
        $snapshot = $effect->getAttribute('source_snapshot');
        $body = is_array($snapshot) ? ($snapshot['evidence'] ?? null) : null;
        if (! is_array($body) || ! hash_equals((string) ($snapshot['evidence_hash'] ?? ''), $this->canonical->hash($body)) || ! is_array($body['source'] ?? null) || $this->canonical->hash($body['source']) !== $sourceHash || ($body['bundle_effects'] ?? null) !== ['cash'] || ($body['effect_kind'] ?? null) !== 'cash' || ($body['outcome'] ?? null) !== 'posted' || ! array_key_exists('no_journal_reason', $body) || $body['no_journal_reason'] !== null || ! is_array($body['proof'] ?? null) || $this->canonical->hash($body['proof']) !== $this->canonical->hash($event->effects[0]->proof) || ($body['mapping_hash'] ?? null) !== $original['mapping_hash'] || ($body['recorded_by'] ?? null) !== (int) $row->getAttribute('posted_by') || ! is_string($body['recorded_at'] ?? null) || $body['recorded_at'] === '') {
            $this->fail();
        }
        $this->mappings->verifyRecordedProfile($company, $original['mapping_version_id'], $event->currency, AccountingDomainRegistry::VERSION, $original['mapping_hash']);
        $journal = JournalEntry::query()->where('company_id', $company)->findOrFail((int) $effect->getAttribute('journal_entry_id'));
        if ((int) $journal->getKey() === $original['journal_id'] || $journal->getAttribute('currency_code') !== $event->currency || $journal->getAttribute('accounting_date')->format('Y-m-d') !== $event->accountingDate || $journal->getAttribute('document_date')?->format('Y-m-d') !== $event->documentDate || (int) $journal->getAttribute('mapping_version_id') !== $original['mapping_version_id'] || (int) $journal->getAttribute('posted_by') !== (int) $row->getAttribute('posted_by') || $journal->getAttribute('payload_hash') !== ($body['journal_hash'] ?? null)) {
            $this->fail();
        }
        $this->automatic->verify($journal, $sourceHash, 'cash', $original['mapping_hash']);
        if (AccountingSourceEffect::query()->where('journal_entry_id', $journal->getKey())->count() !== 1) {
            $this->fail();
        }
        $lines = $this->automatic->lines($journal);
        if (count($lines) !== 2 || JournalEntryLine::query()->where('company_id', $company)->where('source_line_type', 'cash_transfer_reversal')->where('source_line_id', $id)->count() !== 2) {
            $this->fail();
        }
        foreach ($proof['plan']['lines'] as $index => $expected) {
            $line = $lines[$index];
            if ($line['line_number'] !== $index + 1 || $line['account_id'] !== $expected['account_id'] || (int) $line['cash_account_id'] !== $expected['cash_account_id'] || $line['debit'] !== $expected['debit'] || $line['credit'] !== $expected['credit'] || $line['source_line_type'] !== 'cash_transfer_reversal' || $line['source_line_id'] !== $id || $line['party_type'] !== null || $line['party_id'] !== null || $line['branch_id'] !== null || $line['department_id'] !== null || $line['description'] !== null) {
                $this->fail();
            }
        }
        $payload = $operation->getAttribute('payload');
        if ($operation->getAttribute('contract_version') !== AccountingCashTransferReversalNativeProof::VERSION || (int) $operation->getAttribute('original_transfer_id') !== $original['transfer_id'] || (int) $operation->getAttribute('original_journal_id') !== $original['journal_id'] || (int) $operation->getAttribute('source_effect_id') !== (int) $effect->getKey() || (int) $operation->getAttribute('journal_entry_id') !== (int) $journal->getKey() || (int) $operation->getAttribute('actor_id') !== (int) $row->getAttribute('posted_by') || $operation->getAttribute('key_hash') !== $event->eventToken || (int) $operation->getAttribute('request_revision') !== (int) $row->getAttribute('posting_revision') || $operation->getAttribute('occurred_at')?->format('Y-m-d H:i:s') !== $row->getAttribute('posted_at')->format('Y-m-d H:i:s') || ! is_array($payload) || ! hash_equals((string) $operation->getAttribute('payload_hash'), $this->canonical->hash($payload)) || $this->canonical->hash($payload) !== $this->canonical->hash($this->operationPayload($row, $effect, $journal))) {
            $this->fail();
        }

        return ['request' => $row, 'effect' => $effect, 'journal' => $journal];
    }

    /** Entire original/reversal family for books and reconciliation. Includes orphan/inconsistent candidates. */
    public function verifyForOriginal(int $company, int $original): void
    {
        $this->originals->verify($company, $original);
        $ids = AccountingCashTransferReversalRequest::query()->where('company_id', $company)->where('original_transfer_id', $original)->pluck('id')->all();
        $completed = AccountingCashTransferReversalRequest::query()->where('company_id', $company)->where('original_transfer_id', $original)
            ->where(function ($query): void {
                $query->where('status', 'posted')->orWhereNotNull('posted_at')->orWhereNotNull('posted_by')->orWhereNotNull('posting_key_hash')->orWhereNotNull('posting_revision');
            })->pluck('id')->all();
        $operationIds = AccountingCashTransferReversalOperation::query()->where('company_id', $company)->where('original_transfer_id', $original)->pluck('reversal_request_id')->all();
        $effectIds = AccountingSourceEffect::query()->where('company_id', $company)->where('source_kind', 'cash_transfer_reversal')
            ->where(function ($query) use ($ids, $original): void {
                $query->whereIn('source_id', $ids)->orWhere('source_snapshot->evidence->source->facts->original_id', $original);
            })->pluck('source_id')->all();
        $lineIds = JournalEntryLine::query()->where('company_id', $company)->where('source_line_type', 'cash_transfer_reversal')->whereIn('source_line_id', $ids)->pluck('source_line_id')->all();
        $candidates = array_values(array_unique(array_map('intval', [...$completed, ...$operationIds, ...$effectIds, ...$lineIds])));
        if (count($candidates) > 1) {
            $this->fail();
        }
        foreach ($candidates as $id) {
            $proof = $this->verify($company, $id);
            if ((int) $proof['request']->getAttribute('original_transfer_id') !== $original) {
                $this->fail();
            }
        }
    }

    /** @return array<string, mixed> */
    public function operationPayload(AccountingCashTransferReversalRequest $row, AccountingSourceEffect $effect, JournalEntry $journal): array
    {
        return ['contract' => AccountingCashTransferReversalNativeProof::VERSION, 'company_id' => (int) $row->getAttribute('company_id'), 'reversal_request_id' => (int) $row->getKey(), 'original_transfer_id' => (int) $row->getAttribute('original_transfer_id'), 'original_journal_id' => (int) $row->getAttribute('original_journal_id'), 'original_hash' => (string) $row->getAttribute('original_snapshot_hash'), 'approved_hash' => (string) $row->getAttribute('approved_payload_hash'), 'key_hash' => (string) $row->getAttribute('posting_key_hash'), 'revision' => (int) $row->getAttribute('posting_revision'), 'actor_id' => (int) $row->getAttribute('posted_by'), 'posted_at' => $row->getAttribute('posted_at')->format('Y-m-d H:i:s'), 'effect_id' => (int) $effect->getKey(), 'source_hash' => (string) $effect->getAttribute('source_payload_hash'), 'journal_id' => (int) $journal->getKey(), 'journal_hash' => (string) $journal->getAttribute('payload_hash')];
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['cash_transfer_reversal_integrity' => 'Original and reversal native proof, complete operation and both journal legs must agree. Missing evidence cannot be repaired by retry.']);
    }
}
