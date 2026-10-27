<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reports;

use App\Models\User;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

final class AccountingCashFlowExport
{
    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingCashFlowReader $reader, private readonly AccountingFinancialReportExport $cells) {}

    /** @param array<string, mixed> $input */
    public function download(User $actor, array $input): Response
    {
        $data = Validator::make($input, ['company_id' => ['required', 'integer', 'min:1'], 'format' => ['required', 'in:csv,print']])->validate();
        $company = (int) $data['company_id'];
        $this->access->authorize($actor, $company, 'erp.cash-flow.export');
        $filters = $input;
        unset($filters['page'], $filters['per_page']);
        $report = $this->reader->report($actor, $company, $filters);
        $status = $report['complete'] ? 'Confirmed within declared GL scope' : 'PROVISIONAL — NOT CONFIRMED';
        $title = 'Cash Flow — Indirect Method';
        $metadata = [['Report', $title], ['Status', $status], ['Company', $report['company']['code'], $report['company']['name']], ['Currency', $report['currency']], ['From', $report['filters']['from'], 'To', $report['filters']['to']], ['Policy', $report['classification_basis']], ['Coverage', $report['coverage_note']], ['Rows', 'All filtered disclosures and readiness issues; page controls ignored']];
        $headers = ['Section', 'Reference', 'Description', 'Amount', 'Detail'];
        $rows = [];
        foreach ($report['indirect_bridge'] as $key => $amount) {
            $rows[] = ['Indirect operating bridge', '', $key, $amount, $status];
        }
        foreach ($report['provisional_flows'] as $key => $amount) {
            $rows[] = ['Activity totals', '', $key, $amount, $status];
        }
        foreach (['beginning_cash', 'closing_cash', 'opening_cash_movements', 'economic_cash_movement', 'unallocated_cash_counterparts', 'reconciliation_difference', 'snapshot_difference', 'indirect_operating_difference'] as $key) {
            $rows[] = ['Cash reconciliation', '', $key, $report[$key], $status];
        }
        foreach ($report['cash_accounts'] as $account) {
            $rows[] = ['Declared cash account', $account['code'], $account['name'], $account['beginning'], 'Beginning cash; '.$account['status']];
            $rows[] = ['Declared cash account', $account['code'], $account['name'], $account['closing'], 'Closing cash; '.$account['status']];
        }
        foreach (['issues' => 'Readiness issue', 'warnings' => 'Review notice'] as $key => $label) {
            foreach ($report[$key] as $issue) {
                $detail = [];
                foreach (['account_id', 'journal_id', 'binding_id', 'category', 'statement_role', 'period_debit', 'period_credit', 'debit', 'credit'] as $field) {
                    if (isset($issue[$field])) {
                        $detail[] = $field.': '.(string) $issue[$field];
                    }
                }
                $rows[] = [$label, $issue['code'] ?? '', trim((string) ($issue['name'] ?? '').' — '.(string) $issue['issue']), $issue['amount'] ?? '', implode('; ', $detail)];
            }
        }
        foreach ($report['noncash_journals'] as $journal) {
            foreach (['profit_movement', 'operating_balance_adjustment', 'investing_balance_change', 'financing_balance_change'] as $key) {
                $rows[] = ['Noncash disclosure', $journal['number'], $key, $journal[$key], $journal['date'].'; journal #'.$journal['journal_id'].'; not a cash flow'];
            }
        }
        foreach ($report['cash_journals'] as $journal) {
            foreach (['cash_movement', 'operating', 'investing', 'financing'] as $key) {
                $rows[] = ['Cash journal control', $journal['number'], $key, $journal[$key], $journal['date'].'; journal #'.$journal['journal_id'].'; '.$status];
            }
        }
        $rows[] = ['Internal cash transfers', '', 'Cash-only journal count', (string) $report['cash_transfer_count'], 'Excluded from activity totals'];
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
                throw new \RuntimeException('Cannot open cash-flow output.');
            }
            fwrite($stream, "\xEF\xBB\xBF");
            foreach ($safeRows as $row) {
                fputcsv($stream, $row, ',', '"', '');
            } fclose($stream);
        }, 'cash-flow-company-'.$company.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', ...$this->privateHeaders()]);
    }

    /** @return array<string, string> */
    private function privateHeaders(): array
    {
        return ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer'];
    }
}
