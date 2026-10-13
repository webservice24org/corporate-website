<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Purchase;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ERP\Purchase\PurchaseReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PurchaseReportController extends Controller
{
    public function __construct(private readonly PurchaseReportService $service) {}

    public function dashboard(Request $request): Response
    {
        $actor = $this->actor($request);
        $filters = $this->filters($request);

        return DB::transaction(function () use ($actor, $filters): Response {
            $options = $this->service->options($actor, $filters);

            return Inertia::render('ERP/Purchase/Reports/Dashboard', ['filters' => $filters, ...$options, ...$this->service->dashboard($actor, $filters)]);
        });
    }

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $filters = $this->filters($request);
        $type = (string) $filters['type'];

        return DB::transaction(function () use ($actor, $filters, $type): Response {
            $options = $this->service->options($actor, $filters);
            $rows = $this->service->query($actor, $filters, $type)->paginate(25)->withQueryString();
            $rows->getCollection()->transform(fn ($row): array => $this->service->row($row, $type));

            return Inertia::render('ERP/Purchase/Reports/Index', ['filters' => $filters, ...$options, 'rows' => $rows, 'columns' => $this->columns($type),
                'asOf' => now()->toDateString(), 'timezone' => config('app.timezone')]);
        });
    }

    public function export(Request $request): StreamedResponse
    {
        $actor = $this->actor($request);
        $filters = $this->filters($request);
        $this->service->options($actor, $filters); // Same explicit company/supplier authorization as the pages.
        $type = (string) $filters['type'];
        $columns = $this->columns($type);
        $query = $this->service->query($actor, $filters, $type);
        $filename = 'purchase-'.$type.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($columns, $query, $type): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }
            try {
                fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM for Bangla names in Excel.
                fputcsv($output, array_column($columns, 'label'), ',', '"', '');
                foreach ($query->cursor() as $raw) {
                    $row = $this->service->row($raw, $type);
                    $values = [];
                    foreach ($columns as $column) {
                        $value = $row[$column['key']] ?? '';
                        $text = is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value;
                        // Document numbers and supplier/product names can be untrusted spreadsheet formulas.
                        if (preg_match('/^[\x00-\x20\x7F\x{FEFF}\s]*[=+\-@]/u', $text) || preg_match('/^[\x00-\x1F]/', $text)) {
                            $text = "'".$text;
                        }
                        $values[] = $text;
                    }
                    fputcsv($output, $values, ',', '"', '');
                }
            } finally {
                fclose($output);
            }
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $request->merge(['from_date' => $request->input('from_date') ?: now()->startOfMonth()->toDateString(), 'to_date' => $request->input('to_date') ?: now()->toDateString(),
            'type' => $request->input('type') ?: 'orders']);

        return $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'supplier_id' => ['nullable', 'integer', 'min:1'], 'currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/D'],
            'from_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:to_date'], 'to_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'type' => ['required', 'in:orders,receipts,returns,bills,payables']]);
    }

    /** @return list<array{key: string, label: string}> */
    private function columns(string $type): array
    {
        $fields = ['number' => 'Document', 'company_name' => 'Company', 'supplier_name' => 'Supplier', 'currency_code' => 'Currency'];
        $specific = match ($type) {
            'orders' => ['event_at' => 'Released at', 'due_date' => 'Expected delivery', 'status' => 'Status', 'amount' => 'Order total'],
            'receipts' => ['event_at' => 'Received at', 'order_number' => 'PO', 'warehouse_name' => 'Warehouse', 'product_name' => 'Product', 'variant_name' => 'Variant',
                'quantity' => 'Accepted', 'quantity_rejected' => 'Rejected', 'unit_cost' => 'GRN unit cost', 'amount' => 'Stock-in value', 'stock_movement_id' => 'Movement', 'integrity' => 'Link check'],
            'returns' => ['event_at' => 'Posted at', 'order_number' => 'PO', 'receipt_number' => 'Source GRN', 'warehouse_name' => 'Warehouse', 'product_name' => 'Product', 'variant_name' => 'Variant',
                'quantity' => 'Returned', 'source_unit_cost' => 'Original GRN cost', 'unit_cost' => 'Stock-out unit cost', 'amount' => 'Stock-out value', 'stock_movement_id' => 'Movement', 'integrity' => 'Link check'],
            'bills' => ['event_at' => 'Approved at', 'supplier_invoice_number' => 'Supplier invoice', 'invoice_date' => 'Invoice date', 'due_date' => 'Due date', 'amount' => 'Approved total', 'integrity' => 'Payable check'],
            default => ['supplier_invoice_number' => 'Supplier invoice', 'event_at' => 'Payable recorded at', 'due_date' => 'Due date', 'amount' => 'Original amount', 'paid_amount' => 'Paid', 'outstanding' => 'Outstanding', 'overdue' => 'Overdue'],
        };
        foreach ($specific as $key => $label) {
            $fields[$key] = $label;
        }
        $columns = [];
        foreach ($fields as $key => $label) {
            $columns[] = ['key' => $key, 'label' => $label];
        }

        return $columns;
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(403);
        }

        return $actor;
    }
}
