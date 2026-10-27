<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ERP\Accounting\Reports\AccountingSupplierReportDocument;
use App\Services\ERP\Accounting\Reports\AccountingSupplierReportWorkspace;
use App\Services\ERP\Accounting\Reports\AccountingSupplierStatementReader;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AccountingSupplierReportController extends Controller
{
    public function __construct(private readonly AccountingSupplierReportWorkspace $workspace, private readonly AccountingSupplierStatementReader $reader, private readonly AccountingSupplierReportDocument $documents) {}

    public function statement(Request $request): Response
    {
        return $this->screen($request, 'statement');
    }

    public function payables(Request $request): Response
    {
        return $this->screen($request, 'payables');
    }

    public function reconciliation(Request $request): Response
    {
        return $this->screen($request, 'reconciliation');
    }

    public function statementCsv(Request $request): StreamedResponse
    {
        return $this->csv($request, 'statement');
    }

    public function payablesCsv(Request $request): StreamedResponse
    {
        return $this->csv($request, 'payables');
    }

    public function reconciliationCsv(Request $request): StreamedResponse
    {
        return $this->csv($request, 'reconciliation');
    }

    public function statementPrint(Request $request): HttpResponse
    {
        return $this->printable($request, 'statement');
    }

    public function payablesPrint(Request $request): HttpResponse
    {
        return $this->printable($request, 'payables');
    }

    public function reconciliationPrint(Request $request): HttpResponse
    {
        return $this->printable($request, 'reconciliation');
    }

    private function screen(Request $request, string $kind): Response
    {
        $data = $this->load($request, $kind, false);
        $page = match ($kind) {
            'statement' => 'Statement', 'payables' => 'Payables', 'reconciliation' => 'Reconciliation', default => throw new \LogicException('Unknown report.')
        };

        return Inertia::render('ERP/Accounting/SupplierReports/'.$page, $data);
    }

    private function csv(Request $request, string $kind): StreamedResponse
    {
        $data = $this->load($request, $kind, true);
        abort_if($data['report'] === null, 422, 'Select a company and, for a statement, a supplier.');
        $document = $this->documents->make($kind, $data['report']);

        return response()->streamDownload(function () use ($document): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new \RuntimeException('CSV stream is unavailable.');
            } try {
                $this->documents->writeCsv($stream, $document);
            } finally {
                fclose($stream);
            }
        }, 'supplier-'.$kind.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function printable(Request $request, string $kind): HttpResponse
    {
        $data = $this->load($request, $kind, true);
        abort_if($data['report'] === null, 422, 'Select a company and, for a statement, a supplier.');

        return response()->view('erp.accounting.supplier-report-print', ['document' => $this->documents->make($kind, $data['report'])])->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    /** @return array<string, mixed> */
    private function load(Request $request, string $kind, bool $export): array
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $allowed = $kind === 'statement' ? ['company_id', 'supplier_id', 'from', 'to', 'page', 'per_page'] : ($kind === 'payables' ? ['company_id', 'supplier_id', 'as_of', 'page', 'per_page'] : ['company_id', 'as_of']);
        if (array_diff(array_keys($request->query()), $allowed) !== []) {
            throw ValidationException::withMessages(['filters' => 'Unsupported supplier report filter.']);
        }
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'supplier_id' => ['nullable', 'integer', 'min:1'], 'from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01'], 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])], 'as_of' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $context = $this->workspace->context($actor, isset($data['company_id']) ? (int) $data['company_id'] : null, $kind === 'reconciliation');
        if ($export) {
            abort_unless($context['canExport'], 403);
        }
        $companyId = $context['company'] === null ? null : (int) $context['company']['id'];
        $supplierId = isset($data['supplier_id']) ? (int) $data['supplier_id'] : null;
        $input = array_diff_key($data, array_flip(['company_id', ...($kind === 'statement' ? ['supplier_id'] : [])]));
        if ($export) {
            unset($input['page'], $input['per_page']);
        }
        $report = null;
        if ($companyId !== null) {
            $report = match ($kind) {
                'statement' => $supplierId === null ? null : ($export ? $this->reader->statementExport($actor, $companyId, $supplierId, $input) : $this->reader->statement($actor, $companyId, $supplierId, $input)),
                'payables' => $export ? $this->reader->payablesExport($actor, $companyId, $input) : $this->reader->payables($actor, $companyId, $input),
                'reconciliation' => $export ? $this->reader->reconciliationExport($actor, $companyId, $input) : $this->reader->reconcile($actor, $companyId, $input),
                default => throw new \LogicException('Unknown supplier report.'),
            };
        }

        return [...$context, 'kind' => $kind, 'report' => $report, 'filters' => ['supplier_id' => $supplierId === null ? '' : (string) $supplierId, 'from' => $report['from'] ?? $data['from'] ?? '1900-01-01', 'to' => $report['to'] ?? $data['to'] ?? $context['today'] ?? '', 'as_of' => $report['as_of'] ?? $data['as_of'] ?? $context['today'] ?? '', 'per_page' => (int) ($data['per_page'] ?? 25)]];
    }
}
