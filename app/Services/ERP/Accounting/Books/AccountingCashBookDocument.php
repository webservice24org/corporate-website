<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Books;

/** Whitelisted content shared by full CSV and escaped HTML print output. */
final class AccountingCashBookDocument
{
    /** @param array<string, mixed> $report @return array<string, mixed> */
    public function make(array $report): array
    {
        $totals = [];
        foreach (['posted_gl' => 'Posted GL', 'source_verified' => 'Source verified', 'difference' => 'Difference'] as $key => $title) {
            foreach (['opening', 'debits', 'credits', 'closing'] as $field) {
                $totals[] = [$title.' '.$field, $report[$key][$field]];
            }
        }
        $rows = [];
        foreach ($report['rows'] as $row) {
            $rows[] = [
                $row['date'], $row['number'] ?? '', (string) $row['journal_id'], (string) $row['journal_line_id'],
                $row['source_kind'] ?? '', $row['source_id'] === null ? '' : (string) $row['source_id'],
                $row['event'] ?? '', $row['debit'], $row['credit'], $row['movement'],
                $row['posted_balance'], $row['verified_balance'], $row['verified'] ? 'Source verified' : 'REVIEW',
            ];
        }
        $exceptions = [];
        foreach ($report['exceptions'] as $issue) {
            $exceptions[] = [$issue['code'], $issue['journal_id'] === null ? '' : (string) $issue['journal_id'], $issue['journal_line_id'] === null ? '' : (string) $issue['journal_line_id']];
        }

        return [
            'title' => $report['binding']['kind'] === 'cash' ? 'Cashbook' : 'Bankbook',
            'review' => ! $report['complete'] || ! $report['posted_totals_complete'],
            'meta' => [
                ['Company', $report['company']['code'].' — '.$report['company']['name']],
                ['Finance account', $report['binding']['name']], ['Binding ID', (string) $report['binding']['id']],
                ['Role', $report['binding']['role']], ['Availability', $report['binding']['status']],
                ['Currency', $report['binding']['currency_code']], ['GL account ID', (string) $report['binding']['account_id']],
                ['From', $report['from']], ['To', $report['to']],
                ['Date basis', 'Immutable journal accounting date'],
                ['Coverage', 'Posted GL; source verification covers installed native receipt and supplier cash adapters only'],
                ['Source verification state', $report['complete'] ? 'Verified within supported scope' : 'REVIEW — figures are provisional'],
                ['Posted GL values', $report['posted_totals_complete'] ? 'No unsafe posted line values excluded' : 'INCOMPLETE — relevant posted lines were excluded'],
                ['Excluded posted lines', (string) $report['excluded_posted_lines']],
                ['Export scope', 'All filtered rows; pagination ignored'],
                ['Boundary', 'Opening/manual/other adapters and unbound legacy coverage remain explicit review boundaries. These figures are not bank statement agreement or available funds.'],
            ],
            'headers' => ['Date', 'Document', 'Journal ID', 'Journal line ID', 'Source kind', 'Source ID', 'Event', 'Debit', 'Credit', 'Movement', 'Posted GL balance', 'Source-verified balance', 'Verification'],
            'rows' => $rows, 'totals' => $totals, 'exceptions' => $exceptions,
        ];
    }

    public function cell(string $value): string
    {
        $safe = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';

        return preg_match('/\A[\x00-\x20\x{FEFF}]*[=+\-@]/u', $safe) === 1 ? "'".$safe : $safe;
    }

    /** @param resource $stream @param array<string, mixed> $document */
    public function writeCsv($stream, array $document): void
    {
        fwrite($stream, "\xEF\xBB\xBF");
        $this->row($stream, [$document['title']]);
        foreach ($document['meta'] as $row) {
            $this->row($stream, $row);
        }
        $this->row($stream, []);
        $this->row($stream, $document['headers']);
        foreach ($document['rows'] as $row) {
            $this->row($stream, $row);
        }
        $this->row($stream, []);
        $this->row($stream, ['Full filtered totals']);
        foreach ($document['totals'] as $row) {
            $this->row($stream, $row);
        }
        $this->row($stream, []);
        $this->row($stream, ['Exceptions']);
        $this->row($stream, ['Code', 'Journal ID', 'Journal line ID']);
        foreach ($document['exceptions'] as $row) {
            $this->row($stream, $row);
        }
    }

    /** @param resource $stream @param array<array-key, mixed> $row */
    private function row($stream, array $row): void
    {
        $safe = [];
        foreach ($row as $value) {
            $safe[] = $this->cell((string) $value);
        }
        fputcsv($stream, $safe, ',', '"', '');
    }
}
