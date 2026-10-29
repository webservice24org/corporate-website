<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\YearEnd;

use App\Models\Accounting\AccountingYearEndEvent;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalEntryLine;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingJournalAmounts;
use App\Services\ERP\Accounting\AccountingMappingService;
use Illuminate\Validation\ValidationException;

final class AccountingYearEndJournalEvidence
{
    public function __construct(private readonly AccountingYearEndEvidence $evidence, private readonly AccountingCanonicalPayload $canonical, private readonly AccountingJournalAmounts $amounts, private readonly AccountingMappingService $mappings) {}

    /** @return list<array<string, mixed>> */
    public function lines(JournalEntry $journal): array
    {
        $lines = [];
        foreach (JournalEntryLine::query()->where('company_id', $journal->getAttribute('company_id'))->where('journal_entry_id', $journal->getKey())->orderBy('line_number')->get() as $row) {
            $line = ['line_number' => (int) $row->getAttribute('line_number'), 'account_id' => (int) $row->getAttribute('account_id'), 'debit' => (string) $row->getAttribute('debit'), 'credit' => (string) $row->getAttribute('credit')];
            foreach (['party_type', 'party_id', 'source_line_type', 'source_line_id', 'cash_account_id', 'branch_id', 'department_id', 'description'] as $field) {
                $line[$field] = $row->getAttribute($field);
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /** @param list<array<string, mixed>> $lines @return array<string, mixed> */
    public function payload(JournalEntry $journal, array $lines, AccountingYearEndEvent $preparation): array
    {
        return ['contract' => 'year-end-v1', 'company_id' => (int) $journal->getAttribute('company_id'), 'preparation_id' => (int) $preparation->getKey(), 'preparation_hash' => $preparation->getAttribute('payload_hash'), 'kind' => $journal->getAttribute('entry_kind'), 'description' => $journal->getAttribute('description'), 'document_date' => $journal->getAttribute('document_date')?->format('Y-m-d'), 'period_id' => (int) $journal->getAttribute('accounting_period_id'), 'mapping_id' => (int) $journal->getAttribute('mapping_version_id'), 'number' => $journal->getAttribute('number'), 'date' => $journal->getAttribute('accounting_date')->format('Y-m-d'), 'currency' => $journal->getAttribute('currency_code'), 'reverses_id' => $journal->getAttribute('reverses_journal_id') === null ? null : (int) $journal->getAttribute('reverses_journal_id'), 'lines' => $lines];
    }

    /** @return list<array<string, mixed>> */
    public function verify(JournalEntry $journal): array
    {
        $owner = AccountingYearEndEvent::query()->where('company_id', $journal->getAttribute('company_id'))->where('journal_entry_id', $journal->getKey())->first();
        if ($owner === null) {
            $this->fail('Closing journal has no retained year-end operation.');
        }
        $year = FiscalYear::query()->where('company_id', $owner->getAttribute('company_id'))->findOrFail((int) $owner->getAttribute('fiscal_year_id'));
        $read = $this->evidence->read($year);
        $prep = null;
        $approval = null;
        foreach ($read['events'] as $event) {
            if ((int) $event->getKey() === $owner->getAttribute('payload')['preparation_id']) {
                $prep = $event;
            } if ((int) $event->getKey() === $owner->getAttribute('payload')['approval_id']) {
                $approval = $event;
            }
        }
        if ($prep === null || $approval === null) {
            $this->fail('Closing journal approval membership is missing.');
        }
        $s = $prep->getAttribute('payload')['snapshot'];
        $mode = $owner->getAttribute('event_kind');
        $kind = $mode === 'close' ? 'closing' : 'closing_reversal';
        $lines = $this->lines($journal);
        $this->amounts->balanced($lines);
        if ($journal->getAttribute('entry_kind') !== $kind || $journal->getAttribute('contract_version') !== '1.0.0' || $journal->getAttribute('status') !== 'posted' || $journal->getAttribute('posted_at') === null || (int) $journal->getAttribute('posted_by') !== (int) $owner->getAttribute('actor_id') || (int) $journal->getAttribute('created_by') !== (int) $prep->getAttribute('actor_id') || (int) $journal->getAttribute('updated_by') !== (int) $prep->getAttribute('actor_id') || (int) $journal->getAttribute('approved_by') !== (int) $approval->getAttribute('actor_id') || (int) $journal->getAttribute('submitted_by') !== (int) $prep->getAttribute('actor_id') || $journal->getAttribute('submitted_at') === null || $journal->getAttribute('approved_at') === null || $journal->getAttribute('currency_code') !== $s['currency'] || $journal->getAttribute('accounting_date')->format('Y-m-d') !== $s['date'] || $journal->getAttribute('document_date')?->format('Y-m-d') !== $s['date'] || (int) $journal->getAttribute('accounting_period_id') !== $s['period_id'] || (int) $journal->getAttribute('mapping_version_id') !== $s['mapping']['version_id'] || $journal->getAttribute('correction_of_journal_id') !== null || $this->canonical->hash($lines) !== $this->canonical->hash($s['plan']['lines']) || $journal->getAttribute('payload_hash') !== $owner->getAttribute('payload')['journal_hash'] || $journal->getAttribute('payload_hash') !== $this->canonical->hash($this->payload($journal, $lines, $prep))) {
            $this->fail('Year-end journal differs from its independently approved exact plan.');
        }
        $this->mappings->verifyRecordedProfile((int) $year->getAttribute('company_id'), $s['mapping']['version_id'], $s['currency'], '1.0.0', $s['mapping']['payload_hash']);
        if ($mode === 'reopen') {
            $original = AccountingYearEndEvent::query()->where('company_id', $year->getAttribute('company_id'))->where('fiscal_year_id', $year->getKey())->findOrFail($s['original_close_id']);
            $id = $original->getAttribute('journal_entry_id');
            if ($id === null || (int) $journal->getAttribute('reverses_journal_id') !== (int) $id) {
                $this->fail('Year reopening must reverse the original close journal.');
            }
            $parent = JournalEntry::query()->where('company_id', $year->getAttribute('company_id'))->findOrFail((int) $id);
            $expected = array_map(static fn (array $line): array => [...$line, 'debit' => $line['credit'], 'credit' => $line['debit']], $this->verify($parent));
            if ($this->canonical->hash($lines) !== $this->canonical->hash($expected)) {
                $this->fail('Year reopening cannot remap or reinterpret original closing lines.');
            }
        } elseif ($journal->getAttribute('reverses_journal_id') !== null) {
            $this->fail('A closing journal cannot masquerade as a reversal.');
        }

        return $lines;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['year_end' => $message]);
    }
}
