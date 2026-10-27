<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reports;

use App\Models\User;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

final class AccountingAgingExport
{
    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingAgingReader $reader, private readonly AccountingFinancialReportExport $cells, private readonly AccountingAgingLabels $labels) {}

    /** @param array<string, mixed> $input */
    public function download(User $actor, string $kind, array $input): Response
    {
        $data = Validator::make([...$input, 'kind' => $kind], ['kind' => ['required', 'in:ar,ap'], 'company_id' => ['required', 'integer', 'min:1'], 'format' => ['required', 'in:csv,print'], 'as_of' => ['required', 'date_format:Y-m-d'], 'from' => ['prohibited'], 'to' => ['prohibited']])->validate();
        $company = (int) $data['company_id'];
        $this->access->authorize($actor, $company, 'erp.'.$kind.'-aging.export');
        $filters = $input;
        unset($filters['page'], $filters['per_page']);
        $report = $this->labels->decorate($actor, $company, $kind, $this->reader->report($actor, $company, $kind, $filters));
        $title = $kind === 'ar' ? 'Dated Accounts Receivable Aging' : 'Dated Trade Payables Aging';
        $status = $report['complete'] ? 'Checks pass within represented live trade scope' : 'PROVISIONAL — NOT CONFIRMED';
        $metadata = [['Report', $title], ['Status', $status], ['Company', $report['company']['code'], $report['company']['name']], ['Currency', $report['currency']], ['As of', $report['as_of']], ['Coverage', $report['coverage_basis']], ['Due-date policy', $report['due_date_policy']], ['Legacy opening coverage', 'Not integrated; unverified balances stay unknown'], ['Funds policy', 'Document credits and unapplied funds/advances remain separate'], ['Excluded cohorts', implode(', ', $report['excluded_cohorts'])], ['Rows', 'All documents, buckets and coverage issues; display pagination ignored']];
        $headers = ['Section', 'Document/reference', 'Party / ID', 'Recognized date', 'Due date', 'Terms basis', 'Days overdue', 'Bucket/control', 'Outstanding/amount', 'State', 'Detail', 'Status'];
        $rows = [];
        foreach (['open_documents', 'document_credits', 'signed_documents', 'unknown_documents'] as $key) {
            $rows[] = ['Totals', '', '', '', '', '', '', $key, (string) $report['totals'][$key], '', '', $status];
        }
        foreach ($report['totals']['buckets'] as $key => $amount) {
            $rows[] = ['Aging bucket', '', '', '', '', '', '', $key, $amount, '', '', $status];
        }
        foreach ($report['rows'] as $row) {
            $rows[] = ['Document', $row['number'].' (#'.$row['document_id'].')', $row['party_label'].' (#'.$row['party_id'].')', $row['recognized_on'] ?? 'Unknown', $row['due_date'] ?? 'Unknown', $row['due_basis'], $row['days_overdue'] === null ? 'Unknown' : (string) $row['days_overdue'], $row['bucket'] ?? 'not_aged', $row['outstanding'] === null ? 'Unknown' : $row['outstanding'], $row['balance_kind'], 'Negative overdue days mean not yet due', $status];
        }
        $bridge = $report['reconciliation'];
        foreach (['source', 'gl', 'difference'] as $key) {
            foreach ($bridge[$key] as $role => $amount) {
                $rows[] = ['Control reconciliation', '', '', '', '', '', '', $role, $amount, $key, '', $status];
            }
        }
        $rows[] = ['Separate funds/advances', '', '', '', '', '', '', $kind === 'ar' ? 'unapplied_customer_funds' : 'supplier_advances', $bridge['separate_unapplied_or_advance_balance'], '', 'Not netted against overdue documents', $status];
        $rows[] = ['Document-to-control check', '', '', '', '', '', '', 'signed_document_difference', $bridge['document_difference'], '', '', $status];
        foreach ($report['issues'] as $issue) {
            $detail = [];
            foreach (['source_kind', 'source_id', 'account_id', 'journal_id', 'role', 'supplier_id', 'code'] as $field) {
                if (isset($issue[$field]) && is_scalar($issue[$field])) {
                    $detail[] = $field.': '.(string) $issue[$field];
                }
            }
            $rows[] = ['Coverage issue', isset($issue['document_id']) ? (string) ($issue['document_number'] ?? '').' #'.$issue['document_id'] : '', '', '', '', '', '', (string) $issue['issue'], $issue['amount'] ?? '', 'review_required', implode('; ', $detail), $status];
        }
        if ($data['format'] === 'print') {
            return response()->view('erp.accounting.aging-report-print', compact('title', 'metadata', 'headers', 'rows'))->withHeaders($this->privateHeaders());
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
                throw new \RuntimeException('Cannot open aging output.');
            }
            fwrite($stream, "\xEF\xBB\xBF");
            foreach ($safeRows as $row) {
                fputcsv($stream, $row, ',', '"', '');
            } fclose($stream);
        }, $kind.'-aging-company-'.$company.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', ...$this->privateHeaders()]);
    }

    /** @return array<string, string> */
    private function privateHeaders(): array
    {
        return ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer'];
    }
}
