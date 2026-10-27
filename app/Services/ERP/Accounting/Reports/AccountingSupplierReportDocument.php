<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reports;

/** Shared whitelisted content for CSV and escaped server-rendered print output. */
final class AccountingSupplierReportDocument
{
    /** @param array<string, mixed> $report @return array<string, mixed> */
    public function make(string $kind, array $report): array
    {
        $title = match ($kind) {
            'statement' => 'Supplier Statement', 'payables' => 'Payable Register', 'reconciliation' => 'AP Reconciliation', default => throw new \InvalidArgumentException('Unknown supplier report.')
        };
        $meta = [['Company', (string) $report['company']['code'].' — '.(string) $report['company']['name']], ['Currency', (string) $report['currency']], ['Coverage', 'Verified live sources only; legacy openings require dedicated coverage verification'], ['Status', (string) $report['balance_status']], ['Completeness', $report['complete'] ? 'Complete within declared live-source coverage' : 'REVIEW — figures are provisional; see exceptions'], ['Date basis', 'Immutable accounting date'], ['Export scope', 'All filtered rows; pagination ignored']];
        $totals = [];
        $headers = [];
        $rows = [];
        if ($kind === 'statement') {
            $meta[] = ['Supplier', (string) $report['supplier']['code'].' — '.(string) $report['supplier']['name']];
            $meta[] = ['From', $report['from']];
            $meta[] = ['To', $report['to']];
            $meta[] = ['GL check', $report['reconciliation']['reconciled'] ? 'Reconciled within supplier-assigned controls' : 'REVIEW — supplier control reconciliation not proven'];
            $headers = ['Date', 'Document', 'Event', 'Source kind', 'Source ID', 'Bill ID', 'Payment ID', 'Amount', 'AP delta', 'Advance delta', 'Net delta', 'AP balance', 'Advance balance', 'Net balance'];
            foreach ($report['rows'] as $row) {
                $rows[] = [$row['date'], $row['number'], $row['event'], $row['source_kind'], (string) $row['source_id'], $row['bill_id'] === null ? '' : (string) $row['bill_id'], $row['payment_id'] === null ? '' : (string) $row['payment_id'], $row['amount'], $row['ap_delta'], $row['advance_delta'], $row['net_delta'], $row['ap_balance'], $row['advance_balance'], $row['net_balance']];
            }
            foreach (['opening', 'activity', 'closing'] as $section) {
                foreach (['ap', 'advance', 'net', 'cash_paid', 'cash_reversed'] as $field) {
                    $totals[] = [ucfirst($section).' '.$field, $report[$section][$field]];
                }
            }
        } elseif ($kind === 'payables') {
            $meta[] = ['As of', $report['as_of']];
            $meta[] = ['Due date basis', 'Current native terms; historical aging not asserted'];
            $meta[] = ['GL check', 'Source register only; use company AP Reconciliation for the GL gate'];
            $headers = ['Bill', 'Supplier ID', 'Supplier', 'Invoice date', 'Current due date', 'Native currency', 'Original amount', 'Credits as of', 'Allocated as of', 'Outstanding as of', 'Balance kind'];
            foreach ($report['rows'] as $row) {
                $rows[] = [$row['number'], (string) $row['supplier_id'], $row['supplier_name'] ?? 'Unknown', $row['invoice_date'] ?? '', $row['due_date'] ?? '', $row['native_currency'], $row['native_amount'] ?? 'Unknown — review', $row['credited_as_of'] ?? 'Unknown — review', $row['allocated_as_of'] ?? 'Unknown — review', $row['outstanding_as_of'] ?? 'Unknown — review', $row['balance_kind']];
            }
            foreach ($report['totals'] as $key => $value) {
                $totals[] = [$key, $value];
            }
        } else {
            $bridge = $report['reconciliation'];
            $meta[] = ['As of', $report['as_of']];
            $meta[] = ['GL check', $bridge['reconciled'] ? 'Reconciled within declared live-source coverage' : 'REVIEW — reconciliation not proven'];
            $meta[] = ['Numeric match', $bridge['numeric_match'] ? 'Yes; evidence checks still required' : 'No'];
            $headers = ['Control role', 'Account code', 'Account name', 'Account ID', 'Source expected', 'Posted GL', 'Difference'];
            foreach ($bridge['accounts'] as $row) {
                $rows[] = [$row['role'], $row['code'] ?? '', $row['name'] ?? '', (string) $row['account_id'], $row['expected'], $row['actual'], $row['difference']];
            }
            foreach (['ap', 'advance'] as $role) {
                foreach (['source', 'gl', 'difference'] as $field) {
                    $totals[] = [$role.' '.$field, $bridge[$field][$role]];
                }
            }
            $totals[] = ['Supplier net position', $report['source_position']['net']];
        }
        $exceptions = [];
        foreach ($report['exceptions'] as $issue) {
            $exceptions[] = [(string) $issue['code'], isset($issue['supplier_id']) ? (string) $issue['supplier_id'] : '', (string) ($issue['source_kind'] ?? ''), isset($issue['source_id']) ? (string) $issue['source_id'] : '', isset($issue['journal_line_id']) ? (string) $issue['journal_line_id'] : '', isset($issue['account_id']) ? (string) $issue['account_id'] : ''];
        }

        return ['title' => $title, 'meta' => $meta, 'headers' => $headers, 'rows' => $rows, 'totals' => $totals, 'exceptions' => $exceptions, 'review' => ! $report['complete'] || (isset($report['reconciliation']) && ! $report['reconciliation']['reconciled'])];
    }

    /** All untrusted cells are text. Protect formula prefixes even after leading whitespace/control characters. */
    public function cell(string $value): string
    {
        $safe = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';

        return preg_match('/\A[\x00-\x20\x{FEFF}]*[=+\-@]/u', $safe) === 1 ? "'".$safe : $safe;
    }

    /** @param resource $stream @param array<string, mixed> $document */
    public function writeCsv($stream, array $document): void
    {
        fwrite($stream, "\xEF\xBB\xBF");
        $this->writeRow($stream, [$document['title']]);
        foreach ($document['meta'] as $row) {
            $this->writeRow($stream, $row);
        } $this->writeRow($stream, []);
        $this->writeRow($stream, $document['headers']);
        foreach ($document['rows'] as $row) {
            $this->writeRow($stream, $row);
        }
        $this->writeRow($stream, []);
        $this->writeRow($stream, ['Full filtered totals']);
        foreach ($document['totals'] as $row) {
            $this->writeRow($stream, $row);
        }
        $this->writeRow($stream, []);
        $this->writeRow($stream, ['Exceptions']);
        $this->writeRow($stream, ['Code', 'Supplier ID', 'Source kind', 'Source ID', 'Journal line ID', 'Account ID']);
        foreach ($document['exceptions'] as $row) {
            $this->writeRow($stream, $row);
        }
    }

    /** @param resource $stream @param array<array-key, mixed> $row */
    private function writeRow($stream, array $row): void
    {
        $safe = [];
        foreach ($row as $value) {
            $safe[] = $this->cell((string) $value);
        }
        fputcsv($stream, $safe, ',', '"', '');
    }
}
