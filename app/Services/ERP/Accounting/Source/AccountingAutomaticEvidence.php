<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source;

use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalEntryLine;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingJournalAmounts;
use Illuminate\Validation\ValidationException;

final class AccountingAutomaticEvidence
{
    private const SUPPORTED_CONTRACTS = ['1.0.0'];

    public function __construct(private readonly AccountingCanonicalPayload $canonical, private readonly AccountingJournalAmounts $amounts) {}

    /** @return list<array<string, mixed>> */
    public function lines(JournalEntry $journal): array
    {
        return JournalEntryLine::query()->where('company_id', $journal->getAttribute('company_id'))->where('journal_entry_id', $journal->getKey())->orderBy('line_number')->get()->map(static fn (JournalEntryLine $row): array => ['line_number' => (int) $row->getAttribute('line_number'), 'account_id' => (int) $row->getAttribute('account_id'), 'debit' => (string) $row->getAttribute('debit'), 'credit' => (string) $row->getAttribute('credit'), 'party_type' => $row->getAttribute('party_type'), 'party_id' => $row->getAttribute('party_id') === null ? null : (int) $row->getAttribute('party_id'), 'source_line_type' => $row->getAttribute('source_line_type'), 'source_line_id' => $row->getAttribute('source_line_id') === null ? null : (int) $row->getAttribute('source_line_id'), 'branch_id' => $row->getAttribute('branch_id') === null ? null : (int) $row->getAttribute('branch_id'), 'department_id' => $row->getAttribute('department_id') === null ? null : (int) $row->getAttribute('department_id'), 'description' => $row->getAttribute('description'), 'cash_account_id' => $row->getAttribute('cash_account_id') === null ? null : (int) $row->getAttribute('cash_account_id')])->all();
    }

    /** @param list<array<string, mixed>> $lines @return array<string, mixed> */
    public function payload(JournalEntry $journal, array $lines, string $sourceHash, string $effect, string $mappingHash): array
    {
        return ['contract_version' => (string) $journal->getAttribute('contract_version'), 'company_id' => (int) $journal->getAttribute('company_id'), 'number' => (string) $journal->getAttribute('number'), 'entry_kind' => 'automatic', 'accounting_period_id' => (int) $journal->getAttribute('accounting_period_id'), 'mapping_version_id' => (int) $journal->getAttribute('mapping_version_id'), 'mapping_hash' => $mappingHash, 'accounting_date' => $journal->getAttribute('accounting_date')->format('Y-m-d'), 'document_date' => $journal->getAttribute('document_date')?->format('Y-m-d'), 'currency_code' => (string) $journal->getAttribute('currency_code'), 'description' => (string) $journal->getAttribute('description'), 'source_payload_hash' => $sourceHash, 'effect_kind' => $effect, 'lines' => $lines];
    }

    public function verify(JournalEntry $journal, string $sourceHash, string $effect, string $mappingHash): void
    {
        if ($journal->getAttribute('entry_kind') !== 'automatic' || $journal->getAttribute('status') !== 'posted' || $journal->getAttribute('posted_at') === null || $journal->getAttribute('posted_by') === null || $journal->getAttribute('reverses_journal_id') !== null || $journal->getAttribute('correction_of_journal_id') !== null || ! in_array($journal->getAttribute('contract_version'), self::SUPPORTED_CONTRACTS, true)) {
            $this->fail();
        }
        $lines = $this->lines($journal);
        $this->amounts->balanced($lines);
        if (! hash_equals((string) $journal->getAttribute('payload_hash'), $this->canonical->hash($this->payload($journal, $lines, $sourceHash, $effect, $mappingHash)))) {
            $this->fail();
        }
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['source_integrity' => 'Historical automatic journal proof is inconsistent.']);
    }
}
