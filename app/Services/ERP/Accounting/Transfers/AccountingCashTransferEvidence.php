<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Transfers;

use App\Models\Accounting\AccountingCashTransfer;
use App\Models\Accounting\AccountingCashTransferOperation;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalEntryLine;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingDomainRegistry;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\Source\AccountingAutomaticEvidence;
use Illuminate\Validation\ValidationException;

/** Read-only verifier. Missing operation/effect/line evidence is never recreated. No live setup gates on history. */
final class AccountingCashTransferEvidence
{
    public function __construct(private readonly AccountingCashTransferFinancialContract $contract, private readonly AccountingCanonicalPayload $canonical, private readonly AccountingMappingService $mappings, private readonly AccountingAutomaticEvidence $automatic) {}

    /** @return array{transfer: AccountingCashTransfer, effect: AccountingSourceEffect, journal: JournalEntry} */
    public function verify(int $company, int $id): array
    {
        $row = AccountingCashTransfer::query()->where('company_id', $company)->findOrFail($id);
        $event = $this->contract->event($row);
        $operations = AccountingCashTransferOperation::query()->where('company_id', $company)->where('cash_transfer_id', $id)->get();
        $effects = AccountingSourceEffect::query()->where('company_id', $company)->where('source_kind', 'cash_transfer')->where('source_id', $id)->get();
        if ($operations->count() !== 1 || $effects->count() !== 1) {
            $this->fail();
        }
        $operation = $operations->firstOrFail();
        $effect = $effects->firstOrFail();
        if ($effect->getAttribute('event_kind') !== 'posted' || $effect->getAttribute('event_token') !== $event->eventToken || $effect->getAttribute('effect_kind') !== 'cash' || $effect->getAttribute('outcome') !== 'posted' || $effect->getAttribute('no_journal_reason') !== null || $effect->getAttribute('contract_version') !== AccountingDomainRegistry::VERSION || (int) $effect->getAttribute('mapping_version_id') !== (int) $row->getAttribute('mapping_version_id') || $effect->getAttribute('journal_entry_id') === null || $effect->getAttribute('source_document_date')?->format('Y-m-d') !== $event->documentDate || $effect->getAttribute('accounting_date')->format('Y-m-d') !== $event->accountingDate) {
            $this->fail();
        }
        $envelope = $this->contract->envelope($event);
        $sourceHash = $this->canonical->hash($envelope);
        $snapshot = $effect->getAttribute('source_snapshot');
        $body = is_array($snapshot) ? ($snapshot['evidence'] ?? null) : null;
        if (! is_array($body) || ! hash_equals((string) ($snapshot['evidence_hash'] ?? ''), $this->canonical->hash($body)) || ! hash_equals((string) $effect->getAttribute('source_payload_hash'), $sourceHash) || ! is_array($body['source'] ?? null) || $this->canonical->hash($body['source']) !== $sourceHash || ($body['bundle_effects'] ?? null) !== ['cash'] || ($body['effect_kind'] ?? null) !== 'cash' || ($body['outcome'] ?? null) !== 'posted' || ! array_key_exists('no_journal_reason', $body) || $body['no_journal_reason'] !== null || ! is_array($body['proof'] ?? null) || $this->canonical->hash($body['proof']) !== $this->canonical->hash($event->effects[0]->proof) || ($body['mapping_hash'] ?? null) !== $row->getAttribute('mapping_hash') || ($body['recorded_by'] ?? null) !== (int) $row->getAttribute('posted_by') || ! is_string($body['recorded_at'] ?? null) || $body['recorded_at'] === '') {
            $this->fail();
        }
        $this->mappings->verifyRecordedProfile($company, (int) $row->getAttribute('mapping_version_id'), $event->currency, AccountingDomainRegistry::VERSION, (string) $row->getAttribute('mapping_hash'));
        $journal = JournalEntry::query()->where('company_id', $company)->findOrFail((int) $effect->getAttribute('journal_entry_id'));
        if ($journal->getAttribute('currency_code') !== $event->currency || $journal->getAttribute('accounting_date')->format('Y-m-d') !== $event->accountingDate || $journal->getAttribute('document_date')?->format('Y-m-d') !== $event->documentDate || (int) $journal->getAttribute('mapping_version_id') !== (int) $row->getAttribute('mapping_version_id') || (int) $journal->getAttribute('posted_by') !== (int) $row->getAttribute('posted_by') || $journal->getAttribute('payload_hash') !== ($body['journal_hash'] ?? null)) {
            $this->fail();
        }
        $this->automatic->verify($journal, $sourceHash, 'cash', (string) $row->getAttribute('mapping_hash'));
        if (AccountingSourceEffect::query()->where('journal_entry_id', $journal->getKey())->count() !== 1) {
            $this->fail();
        }
        $lines = $this->automatic->lines($journal);
        if (count($lines) !== 2) {
            $this->fail();
        }
        $source = $row->getAttribute('source_snapshot');
        $destination = $row->getAttribute('destination_snapshot');
        foreach ([$destination, $source] as $index => $binding) {
            $line = $lines[$index];
            $debit = $index === 0 ? (string) $row->getAttribute('amount') : '0.00';
            $credit = $index === 1 ? (string) $row->getAttribute('amount') : '0.00';
            if ($line['line_number'] !== $index + 1 || $line['account_id'] !== $binding['account_id'] || (int) $line['cash_account_id'] !== $binding['id'] || $line['debit'] !== $debit || $line['credit'] !== $credit || $line['source_line_type'] !== 'cash_transfer' || $line['source_line_id'] !== $id || $line['party_type'] !== null || $line['party_id'] !== null || $line['branch_id'] !== null || $line['department_id'] !== null || $line['description'] !== null) {
                $this->fail();
            }
        }
        // Reject extra orphan source lines even if the recorded journal itself still balances.
        if (JournalEntryLine::query()->where('company_id', $company)->where('source_line_type', 'cash_transfer')->where('source_line_id', $id)->count() !== 2) {
            $this->fail();
        }
        $payload = $operation->getAttribute('payload');
        if ($operation->getAttribute('contract_version') !== AccountingCashTransferFinancialContract::VERSION || (int) $operation->getAttribute('source_effect_id') !== (int) $effect->getKey() || (int) $operation->getAttribute('journal_entry_id') !== (int) $journal->getKey() || (int) $operation->getAttribute('actor_id') !== (int) $row->getAttribute('posted_by') || $operation->getAttribute('key_hash') !== $event->eventToken || (int) $operation->getAttribute('request_revision') !== (int) $row->getAttribute('posting_revision') || $operation->getAttribute('occurred_at')?->format('Y-m-d H:i:s') !== $row->getAttribute('posted_at')->format('Y-m-d H:i:s') || ! is_array($payload) || ! hash_equals((string) $operation->getAttribute('payload_hash'), $this->canonical->hash($payload)) || $this->canonical->hash($payload) !== $this->canonical->hash($this->operationPayload($row, $effect, $journal))) {
            $this->fail();
        }

        return ['transfer' => $row, 'effect' => $effect, 'journal' => $journal];
    }

    /** @return array<string, mixed> */
    public function operationPayload(AccountingCashTransfer $row, AccountingSourceEffect $effect, JournalEntry $journal): array
    {
        return ['contract' => AccountingCashTransferFinancialContract::VERSION, 'company_id' => (int) $row->getAttribute('company_id'), 'transfer_id' => (int) $row->getKey(), 'key_hash' => (string) $row->getAttribute('posting_key_hash'), 'revision' => (int) $row->getAttribute('posting_revision'), 'approved_hash' => (string) $row->getAttribute('approved_payload_hash'), 'actor_id' => (int) $row->getAttribute('posted_by'), 'posted_at' => $row->getAttribute('posted_at')->format('Y-m-d H:i:s'), 'effect_id' => (int) $effect->getKey(), 'source_hash' => (string) $effect->getAttribute('source_payload_hash'), 'journal_id' => (int) $journal->getKey(), 'journal_hash' => (string) $journal->getAttribute('payload_hash'), 'mapping_version_id' => (int) $row->getAttribute('mapping_version_id'), 'mapping_hash' => (string) $row->getAttribute('mapping_hash')];
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['cash_transfer_integrity' => 'Complete transfer operation, native intent and two-leg journal evidence must agree. Reconcile damage; retries cannot repair it.']);
    }
}
