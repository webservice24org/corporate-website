<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalEntryLine;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/** Internal evidence reader; callers first authorize company and acquire write locks as needed. */
final class AccountingJournalEvidence
{
    public function __construct(private readonly AccountingJournalAmounts $amounts, private readonly AccountingCanonicalPayload $canonical) {}

    /** @return array<string, mixed> */
    public function verify(JournalEntry $row): array
    {
        if ($row->getAttribute('contract_version') !== AccountingDomainRegistry::VERSION) {
            $this->fail('journal', 'Journal contract version is not supported by this engine.');
        }
        foreach (['mapping_version_id', 'correction_of_journal_id'] as $field) {
            if ($row->getAttribute($field) !== null) {
                $this->fail('journal', 'Reserved mapping and linked-journal metadata requires a separate controlled workflow.');
            }
        }
        if (! in_array($row->getAttribute('entry_kind'), ['manual', 'reversal'], true)) {
            $this->fail('journal', 'This evidence reader supports manual journals and their controlled reversals only.');
        }
        if ($row->getAttribute('entry_kind') === 'manual' && $row->getAttribute('reverses_journal_id') !== null) {
            $this->fail('journal', 'A manual journal cannot contain a reversal link.');
        }
        $lines = $this->lines($row);
        $totals = $this->amounts->balanced($lines);
        $hash = $this->canonical->hash($this->payload($row, $lines));
        if (! hash_equals((string) $row->getAttribute('payload_hash'), $hash)) {
            $this->fail('journal', 'Journal evidence does not match its saved hash.');
        }
        if ($row->getAttribute('entry_kind') === 'reversal') {
            $source = JournalEntry::query()->where('company_id', $row->getAttribute('company_id'))->findOrFail($row->getAttribute('reverses_journal_id'));
            if ($source->getAttribute('entry_kind') !== 'manual' || $source->getAttribute('status') !== 'posted' || $source->getAttribute('posted_by') === null || $source->getAttribute('posted_at') === null || $source->getAttribute('currency_code') !== $row->getAttribute('currency_code') || $row->getAttribute('accounting_date')->format('Y-m-d') < $source->getAttribute('accounting_date')->format('Y-m-d')) {
                $this->fail('journal', 'Reversals require a posted same-currency manual original and cannot predate it.');
            }
            $original = $this->verify($source);
            $expected = array_map(static fn (array $line): array => [...$line, 'debit' => $line['credit'], 'credit' => $line['debit']], $original['lines']);
            if ($lines !== $expected) {
                $this->fail('journal', 'Reversal lines must be an exact debit/credit swap of the immutable original.');
            }
        }

        return ['lines' => $lines, 'total_debit' => $totals['total_debit'], 'total_credit' => $totals['total_credit']];
    }

    /** @return list<array<string, mixed>> */
    public function lines(JournalEntry $row): array
    {
        $lines = [];
        foreach (JournalEntryLine::query()->where('company_id', $row->getAttribute('company_id'))->where('journal_entry_id', $row->getKey())->orderBy('line_number')->get() as $line) {
            if ($line->getAttribute('party_type') !== null || $line->getAttribute('party_id') !== null || $line->getAttribute('source_line_type') !== null || $line->getAttribute('source_line_id') !== null || $line->getAttribute('cash_account_id') !== null) {
                $this->fail('journal', 'Reserved source, cash or party metadata is not valid in this manual draft API.');
            }
            $lines[] = ['line_number' => (int) $line->getAttribute('line_number'), 'account_id' => (int) $line->getAttribute('account_id'), 'debit' => (string) $line->getAttribute('debit'), 'credit' => (string) $line->getAttribute('credit'), 'branch_id' => $line->getAttribute('branch_id') === null ? null : (int) $line->getAttribute('branch_id'), 'department_id' => $line->getAttribute('department_id') === null ? null : (int) $line->getAttribute('department_id'), 'description' => $line->getAttribute('description')];
        }

        return $lines;
    }

    /** @param list<array<string, mixed>> $lines @return array<string, mixed> */
    public function payload(JournalEntry $row, array $lines): array
    {
        $payload = ['contract_version' => (string) $row->getAttribute('contract_version'), 'company_id' => (int) $row->getAttribute('company_id'), 'accounting_period_id' => (int) $row->getAttribute('accounting_period_id'), 'number' => (string) $row->getAttribute('number'), 'entry_kind' => (string) $row->getAttribute('entry_kind'), 'accounting_date' => $row->getAttribute('accounting_date')->format('Y-m-d'), 'document_date' => $row->getAttribute('document_date')?->format('Y-m-d'), 'currency_code' => (string) $row->getAttribute('currency_code'), 'description' => (string) $row->getAttribute('description'), 'lines' => $lines];
        if ($row->getAttribute('entry_kind') === 'reversal') {
            $payload['reverses_journal_id'] = (int) $row->getAttribute('reverses_journal_id');
        }

        return $payload;
    }

    /** @param array<string, mixed> $checked @return array<string, mixed> */
    public function snapshot(JournalEntry $row, array $checked): array
    {
        return ['number' => (string) $row->getAttribute('number'), 'entry_kind' => (string) $row->getAttribute('entry_kind'), 'status' => (string) $row->getAttribute('status'), 'revision' => (int) $row->getAttribute('revision'), 'accounting_date' => $row->getAttribute('accounting_date')->format('Y-m-d'), 'currency_code' => (string) $row->getAttribute('currency_code'), 'total_debit' => $checked['total_debit'], 'total_credit' => $checked['total_credit'], 'line_count' => count($checked['lines']), 'payload_hash' => (string) $row->getAttribute('payload_hash'), 'submitted_by' => $row->getAttribute('submitted_by') === null ? null : (int) $row->getAttribute('submitted_by'), 'submitted_at' => $this->timestamp($row->getAttribute('submitted_at')), 'approved_by' => $row->getAttribute('approved_by') === null ? null : (int) $row->getAttribute('approved_by'), 'approved_at' => $this->timestamp($row->getAttribute('approved_at')), 'posted_by' => $row->getAttribute('posted_by') === null ? null : (int) $row->getAttribute('posted_by'), 'posted_at' => $this->timestamp($row->getAttribute('posted_at')), 'reverses_journal_id' => $row->getAttribute('reverses_journal_id') === null ? null : (int) $row->getAttribute('reverses_journal_id')];
    }

    public function timestamp(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : null;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
