<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Sales;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ERP\Sales\SalesReportCatalog;
use App\Services\ERP\Sales\SalesReportCsv;
use App\Services\ERP\Sales\SalesReportService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SalesReportController extends Controller
{
    public function __construct(private readonly SalesReportService $reports, private readonly SalesReportCatalog $catalog, private readonly SalesReportCsv $csv) {}

    public function dashboard(Request $request): Response
    {
        $filters = $this->reports->filters($request->all(), true);
        $data = $this->reports->read($this->actor($request), $filters, array_keys($this->catalog->all()));
        $filters = $data['filters'];
        foreach ($data['reports'] as &$report) {
            unset($report['rows']);
        } unset($report);

        return Inertia::render('ERP/Sales/Reports/Dashboard', [...$data, 'filters' => $filters]);
    }

    public function index(Request $request): Response
    {
        $filters = $this->reports->filters($request->all());
        $data = $this->reports->read($this->actor($request), $filters, [(string) $filters['type']]);
        $filters = $data['filters'];
        $report = $data['reports'][$filters['type']];
        unset($data['reports']);
        $print = (bool) ($filters['print'] ?? false);
        $page = (int) ($filters['page'] ?? 1);
        $rows = $print ? $report['rows'] : new LengthAwarePaginator(array_slice($report['rows'], ($page - 1) * 25, 25), $report['matching'], 25, $page, ['path' => $request->url(), 'query' => $request->except('page')]);
        unset($report['rows']);

        return Inertia::render('ERP/Sales/Reports/Index', [...$data, 'filters' => $filters, 'report' => $report, 'rows' => $rows, 'printMode' => $print]);
    }

    public function export(Request $request): StreamedResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->can('erp.sales-reports.export'), 403);
        $filters = $this->reports->filters($request->all());
        $data = $this->reports->read($actor, $filters, [(string) $filters['type']]);
        $filters = $data['filters'];
        $report = $data['reports'][$filters['type']];
        $columns = [...$report['columns'], ['key' => 'basis', 'label' => 'Report date basis', 'kind' => 'text'], ['key' => 'generated_at', 'label' => 'Generated at', 'kind' => 'text'], ['key' => 'filter_from', 'label' => 'Requested from', 'kind' => 'text'], ['key' => 'filter_to', 'label' => 'Requested through', 'kind' => 'text']];

        return response()->streamDownload(function () use ($data, $report, $columns, $filters): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new \RuntimeException('Unable to open CSV output.');
            }
            try {
                fwrite($stream, "\xEF\xBB\xBF");
                fputcsv($stream, array_column($columns, 'label'), ',', '"', '');
                foreach ($report['rows'] as $row) {
                    $row['filter_from'] = $filters['from'];
                    $row['filter_to'] = $filters['to'];
                    $row['basis'] = $report['basis'];
                    $row['generated_at'] = $data['generatedAt'];
                    $cells = [];
                    foreach ($columns as $column) {
                        $cells[] = $this->csv->cell($row[$column['key']] ?? null, $column['kind']);
                    } fputcsv($stream, $cells, ',', '"', '');
                }
            } finally {
                fclose($stream);
            }
        }, 'sales-'.$filters['type'].'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
