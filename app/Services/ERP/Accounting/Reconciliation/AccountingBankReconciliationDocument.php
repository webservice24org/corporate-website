<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reconciliation;

/** Shared whitelisted document for CSV and escaped HTML. Financial amounts stay decimal strings. */
final class AccountingBankReconciliationDocument
{
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function make(array $data): array
    {
        $report = $data['report'];
        $approved = $data['basis'] === 'approved';
        $meta = [
            ['Company', $data['company']], ['Bank', $data['bank']], ['Statement', $data['statement_reference']], ['Reconciliation ID', (string) $data['id']],
            ['Currency', $data['currency']], ['Statement cutoff', $data['cutoff']], ['Export basis', $approved ? 'Preserved approved matching prefix and financial source commitments' : 'Current matching state with statement-date cutoff'],
            ['Workflow state at generation', $data['current_state']], ['Report matching revision', (string) $data['matching_revision']], ['Current matching revision', (string) $data['current_matching_revision']], ['Finalization revision', (string) $data['finalization_revision']],
            ['Technical checks for selected basis', $data['technical_ready'] ? 'Pass' : 'Review required'], ['Generated at', $data['generated_at']],
            ['Statement rows — full result', (string) count($report['statement_rows'])], ['Book rows — full result', (string) count($report['book_rows'])],
            ['Availability basis', $approved ? 'Approved cutoff only; present-day availability and later-statement allocations are not asserted' : 'Through-cutoff balances plus separately labelled current availability and later allocations'],
            ['Display labels', 'Company/bank labels and drilldown permissions are current; the selected report basis determines amounts and matching'],
        ];
        if ($data['approval'] !== null) {
            $meta[] = [$approved ? 'Selected approval' : 'Latest recorded approval — may be historical', '#'.$data['approval']['id'].' · Actor #'.$data['approval']['actor_id'].' · '.$data['approval']['occurred_at']];
        }
        $tables = [];
        $rows = [];
        foreach ($report['bridge'] as $label => $value) {
            $rows[] = [$this->cell(str_replace('_', ' ', $label)), $this->cell($value)];
        }
        $tables[] = $this->table('Complete bridge', ['Measure', 'Amount'], $rows);
        $rows = [];
        foreach ($report['statement_rows'] as $row) {
            $rows[] = [$this->cell((string) $row['id']), $this->cell($row['date']), $this->cell($row['reference']), $this->cell($row['description']), $this->cell($row['direction']), $this->cell($row['amount']), $this->cell($row['matched']), $this->cell($row['remaining'])];
        }
        $tables[] = $this->table('Statement movements — all rows', ['ID', 'Date', 'Reference', 'Description', 'Direction', 'Amount', 'Matched', 'Remaining'], $rows);
        $headers = ['ID', 'Date', 'Direction', 'Amount', 'Matched through cutoff', 'Outstanding through cutoff', 'Source', 'Source link', 'Journal link'];
        if (! $approved) {
            $headers[] = 'Currently available';
            $headers[] = 'Allocated to later statements';
        }
        $rows = [];
        foreach ($report['book_rows'] as $row) {
            $cells = [$this->cell((string) $row['id']), $this->cell($row['date']), $this->cell($row['direction']), $this->cell($row['amount']), $this->cell($row['matched_through_cutoff']), $this->cell($row['outstanding_through_cutoff']), $this->cell($row['source']), $this->cell($row['source_url'] ?? '', $row['source_url']), $this->cell($row['journal_url'] ?? '', $row['journal_url'])];
            if (! $approved) {
                $cells[] = $this->cell($row['currently_available']);
                $cells[] = $this->cell($row['allocated_to_later_statements']);
            }
            $rows[] = $cells;
        }
        $tables[] = $this->table('Book movements — all rows', $headers, $rows);
        $rows = [];
        foreach ($report['history'] as $row) {
            $rows[] = [$this->cell((string) $row['id']), $this->cell($row['kind']), $this->cell($row['active'] ? 'Yes' : 'No'), $this->cell($row['total']), $this->cell($row['direction']), $this->cell((string) $row['actor_id']), $this->cell($row['occurred_at']), $this->cell($row['reason']), $this->cell($row['target_match_id'] === null ? '' : (string) $row['target_match_id'])];
        }
        $tables[] = $this->table($approved ? 'Matching history — approved prefix' : 'Matching history — current prefix', ['ID', 'Kind', 'Active for selected basis', 'Total', 'Direction', 'Actor ID', 'Time', 'Reason', 'Compensated group'], $rows);
        $rows = [];
        foreach ($data['checks'] as $check) {
            $rows[] = [$this->cell($check['label']), $this->cell($check['state']), $this->cell($check['detail'])];
        }
        $tables[] = $this->table($approved ? 'Coverage checks recorded with approval' : 'Current coverage checks', ['Check', 'Result', 'Scope'], $rows);
        $rows = [];
        foreach ($data['exceptions'] as $issue) {
            $rows[] = [$this->cell($issue['code']), $this->cell($issue['statement_id'] === null ? '' : (string) $issue['statement_id']), $this->cell($issue['row_id'] === null ? '' : (string) $issue['row_id'])];
        }
        $tables[] = $this->table('Coverage exceptions', ['Code', 'Statement ID', 'Record ID'], $rows);
        foreach (['timeline' => $approved ? 'Finalization events through selected approval' : 'Finalization events — complete history', 'later_events' => 'Later finalization events — current context'] as $field => $title) {
            $rows = [];
            foreach ($data[$field] as $event) {
                $rows[] = [$this->cell((string) $event['id']), $this->cell($event['kind']), $this->cell((string) $event['actor_id']), $this->cell($event['occurred_at']), $this->cell($event['reason'])];
            }
            $tables[] = $this->table($title, ['ID', 'Action', 'Actor ID', 'Time', 'Reason'], $rows);
        }
        foreach ($meta as $pair) {
            foreach ($pair as $value) {
                $this->text($value);
            }
        }

        return ['title' => 'Bank reconciliation #'.$data['id'], 'status' => $data['status_label'], 'meta' => $meta, 'tables' => $tables,
            'scope' => $approved ? 'Workflow status is observed at generation time. Historical approval basis is explicit. Reopening does not erase approval evidence. This snapshot does not assert present-day availability or an audit-time reconstruction of every later event.' : 'Workflow status is observed at generation time. Full-result totals and movements are independent of screen pagination. Export does not approve or finalize a working reconciliation.'];
    }

    /** @param resource $stream @param array<string, mixed> $document */
    public function csv($stream, array $document): void
    {
        fwrite($stream, "\xEF\xBB\xBF");
        $this->row($stream, [$document['title'], $document['status']]);
        foreach ($document['meta'] as $pair) {
            $this->row($stream, $pair);
        }
        $this->row($stream, ['Scope', $document['scope']]);
        foreach ($document['tables'] as $table) {
            $this->row($stream, []);
            $this->row($stream, [$table['title']]);
            $this->row($stream, $table['headers']);
            foreach ($table['rows'] as $row) {
                $this->row($stream, array_map(static fn (array $cell): string => $cell['value'], $row));
            }
        }
    }

    /** @param resource $stream @param list<string> $cells */
    private function row($stream, array $cells): void
    {
        fputcsv($stream, array_map($this->safe(...), $cells), ',', '"', '');
    }

    private function safe(string $value): string
    {
        // Only exact numeric literals bypass the text guard, preserving signed exact decimal output.
        if (preg_match('/\A-?[0-9]+(?:\.[0-9]{1,2})?\z/', $value)) {
            return $value;
        }
        $check = preg_replace('/\A[\s\p{Z}\p{Cf}\p{Cc}]+/u', '', $value) ?? $value;

        return preg_match('/\A[=+\-@]/', $check) ? "'".$value : $value;
    }

    /** @return array{value: string, href: string|null} */
    private function cell(string $value, ?string $href = null): array
    {
        $this->text($value);
        if ($href !== null && ! preg_match('~\A/erp/(?:accounting/(?:journals|bank-adjustments|cash-transfers|cash-transfer-reversals|supplier-payments)|sales/receipts)/[1-9][0-9]*\z~', $href)) {
            throw new \InvalidArgumentException('An export drilldown failed its route allowlist.');
        }

        return ['value' => $value, 'href' => $href];
    }

    private function text(string $value): void
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw new \InvalidArgumentException('Export text must be valid UTF-8. Review the source before downloading.');
        }
    }

    /** @param list<string> $headers @param list<list<array{value: string, href: string|null}>> $rows @return array<string, mixed> */
    private function table(string $title, array $headers, array $rows): array
    {
        return ['title' => $title, 'headers' => $headers, 'rows' => $rows];
    }
}
