<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reports;

use App\Models\User;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

final class AccountingTaxExport
{
    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingTaxReader $reader, private readonly AccountingFinancialReportExport $cells) {}

    /** @param array<string, mixed> $input */
    public function download(User $actor, array $input): Response
    {
        $data = Validator::make($input, ['company_id' => ['required', 'integer', 'min:1'], 'format' => ['required', 'in:csv,print']])->validate();
        $company = (int) $data['company_id'];
        $this->access->authorize($actor, $company, 'erp.tax-reconciliation.export');
        $filters = $input;
        unset($filters['page'], $filters['per_page']);
        $report = $this->reader->report($actor, $company, $filters);
        $title = 'Tax Account Reconciliation';
        $status = $report['complete'] ? 'Checks pass within configured supported tax GL scope' : 'PROVISIONAL — NOT CONFIRMED';
        $metadata = [['Report', $title], ['Status', $status], ['Company', $report['company']['code'], $report['company']['name']], ['Currency', $report['currency']], ['From', $report['filters']['from'], 'To', $report['filters']['to']], ['Coverage', $report['coverage_basis']], ['Policy', 'Input/output tax remain separate; no tax return, rate or payment amount inferred'], ['Legacy openings', 'Coverage not integrated'], ['Rows', 'All period lines, source disclosures and issues; display pagination ignored']];
        $headers = ['Section', 'Reference', 'Role / field', 'Debit / value', 'Credit / detail', 'Status'];
        $rows = [];
        foreach (['gl', 'source', 'difference'] as $group) {
            foreach ($report[$group] as $role => $totals) {
                foreach ($totals as $field => $value) {
                    $rows[] = [$group, $role, $field, $value, '', $status];
                }
            }
        }
        foreach ($report['accounts'] as $account) {
            foreach (['opening', 'period_debit', 'period_credit', 'closing'] as $field) {
                $rows[] = ['Tax account', $account['code'].' — '.$account['name'], $account['role'].' / '.$field, $account[$field], $account['status'], $status];
            }
        }
        foreach ($report['unclassified_lines'] as $key => $value) {
            $rows[] = ['Unclassified tax scope', '', $key, (string) $value, 'Do not treat unclassified lines as zero tax', $status];
        }
        foreach ($report['rows'] as $line) {
            $rows[] = ['Period tax GL line', $line['date'].' '.$line['number'].' / '.$line['account_code'], $line['role'], $line['debit'], $line['credit'], $line['native_recipe_matched'] ? $status : 'Native recipe review required'];
        }
        foreach ($report['sources'] as $source) {
            $rows[] = ['Verified native source', $source['date'].' '.$source['number'], $source['source_kind'].' #'.$source['source_id'], $source['tax_debit'], $source['tax_credit'], $source['date_scope'].'; '.$status];
        }
        foreach ($report['issues'] as $issue) {
            $detail = [];
            foreach (['source_kind', 'source_id', 'journal_id', 'account_id', 'role'] as $field) {
                if (isset($issue[$field])) {
                    $detail[] = $field.': '.(string) $issue[$field];
                }
            }
            $rows[] = ['Coverage issue', '', $issue['issue'], '', implode('; ', $detail), $status];
        }
        if ($data['format'] === 'print') {
            return response()->view('erp.accounting.financial-report-print', compact('title', 'metadata', 'headers', 'rows'))->withHeaders($this->privateHeaders());
        }
        $safeRows = [];
        foreach ([...$metadata, [], $headers, ...$rows] as $row) {
            $safe = [];
            foreach ($row as $cell) {
                $safe[] = $this->cells->safeCell((string) $cell);
            } $safeRows[] = $safe;
        }

        return response()->streamDownload(static function () use ($safeRows): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new \RuntimeException('Cannot open tax report output.');
            }
            fwrite($stream, "\xEF\xBB\xBF");
            foreach ($safeRows as $row) {
                fputcsv($stream, $row, ',', '"', '');
            } fclose($stream);
        }, 'tax-reconciliation-company-'.$company.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', ...$this->privateHeaders()]);
    }

    /** @return array<string, string> */
    private function privateHeaders(): array
    {
        return ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer'];
    }
}
