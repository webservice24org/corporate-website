<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reports;

use App\Models\User;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

final class AccountingFinancialReportExport
{
    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingLedgerReader $reader) {}

    public function safeCell(string $value): string
    {
        return preg_match('/\A[\x00-\x20]*[=+@-]/', $value) === 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1 ? "'".$value : $value;
    }

    /** @param array<string, mixed> $input */
    public function download(User $actor, string $kind, array $input): Response
    {
        $data = Validator::make($input, ['company_id' => ['required', 'integer', 'min:1'], 'account_id' => [$kind === 'ledger' ? 'required' : 'nullable', 'integer', 'min:1'], 'format' => ['required', 'in:csv,print']])->validate();
        $company = (int) $data['company_id'];
        $this->access->authorize($actor, $company, $kind === 'ledger' ? 'erp.general-ledger.export' : 'erp.trial-balance.export');
        $filters = [...$input, 'page' => 1, 'per_page' => 100];
        $report = $kind === 'ledger' ? $this->reader->ledgerExport($actor, $company, (int) $data['account_id'], $filters) : $this->reader->trial($actor, $company, $filters);
        $headers = $kind === 'ledger' ? ['Date', 'Journal', 'Line', 'Debit', 'Credit', 'Running balance (debit positive)'] : ['Account code', 'Account name', 'Opening debit', 'Opening credit', 'Period debit', 'Period credit', 'Closing debit', 'Closing credit'];
        $rows = [];
        if ($kind === 'ledger') {
            $rows[] = ['', 'Opening balance', '', '', '', $report['opening']];
            foreach ($report['data'] as $line) {
                $rows[] = [$line['date'], $line['number'], (string) $line['line_number'], $line['debit'], $line['credit'], $line['balance']];
            }
            $rows[] = ['', 'Period totals / closing balance', '', $report['period_debit'], $report['period_credit'], $report['closing']];
        } else {
            foreach ($report['accounts'] as $account) {
                $rows[] = [$account['code'], $account['name'], $account['opening_debit'], $account['opening_credit'], $account['period_debit'], $account['period_credit'], $account['closing_debit'], $account['closing_credit']];
            }
            $t = $report['totals'];
            $rows[] = ['', 'Totals', $t['opening_debit'], $t['opening_credit'], $t['period_debit'], $t['period_credit'], $t['closing_debit'], $t['closing_credit']];
        }
        $title = $kind === 'ledger' ? 'General Ledger' : 'Trial Balance';
        $metadata = [['Report', $title], ['Company', $report['company']['code'], $report['company']['name']], ['Currency', $report['currency']], ['From', $report['filters']['from'], 'To', $report['filters']['to']], ['Coverage', $report['coverage_note']]];
        if ($kind === 'ledger') {
            $metadata[] = ['Account', $report['account']['code'], $report['account']['name']];
        }
        if ($kind === 'trial') {
            $metadata[] = ['Differences', 'Opening', $report['differences']['opening'], 'Period', $report['differences']['period'], 'Closing', $report['differences']['closing']];
        }
        if ($data['format'] === 'print') {
            return response()->view('erp.accounting.financial-report-print', compact('title', 'metadata', 'headers', 'rows'))->withHeaders($this->privateHeaders());
        }
        $safeRows = [];
        foreach ([...$metadata, [], $headers, ...$rows] as $row) {
            $safeRow = [];
            foreach ($row as $cell) {
                $safeRow[] = $this->safeCell((string) $cell);
            } $safeRows[] = $safeRow;
        }

        return response()->streamDownload(static function () use ($safeRows): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new \RuntimeException('Cannot open report output.');
            }
            fwrite($stream, "\xEF\xBB\xBF");
            foreach ($safeRows as $row) {
                fputcsv($stream, $row, ',', '"', '');
            }
            fclose($stream);
        }, ($kind === 'ledger' ? 'general-ledger' : 'trial-balance').'-company-'.$company.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', ...$this->privateHeaders()]);
    }

    /** @return array<string, string> */
    private function privateHeaders(): array
    {
        return ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer'];
    }
}
