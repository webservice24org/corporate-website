<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\User;
use App\Services\ERP\Accounting\Books\AccountingCashBookDocument;
use App\Services\ERP\Accounting\Books\AccountingCashBookReader;
use App\Services\ERP\Accounting\Books\AccountingCashBookWorkspace;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AccountingCashBookController extends Controller
{
    public function __construct(
        private readonly AccountingCashBookWorkspace $workspace,
        private readonly AccountingCashBookReader $reader,
        private readonly AccountingCashBookDocument $documents,
    ) {}

    public function cash(Request $request): Response
    {
        return $this->screen($request, 'cash');
    }

    public function bank(Request $request): Response
    {
        return $this->screen($request, 'bank');
    }

    public function cashCsv(Request $request): StreamedResponse
    {
        return $this->csv($request, 'cash');
    }

    public function bankCsv(Request $request): StreamedResponse
    {
        return $this->csv($request, 'bank');
    }

    public function cashPrint(Request $request): HttpResponse
    {
        return $this->printable($request, 'cash');
    }

    public function bankPrint(Request $request): HttpResponse
    {
        return $this->printable($request, 'bank');
    }

    private function screen(Request $request, string $kind): Response
    {
        return Inertia::render('ERP/Accounting/CashBooks/Book', $this->load($request, $kind, false));
    }

    private function csv(Request $request, string $kind): StreamedResponse
    {
        $data = $this->load($request, $kind, true);
        abort_if($data['report'] === null, 422, 'Select a company and finance account.');
        $document = $this->documents->make($data['report']);

        return response()->streamDownload(function () use ($document): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new \RuntimeException('CSV stream is unavailable.');
            }
            try {
                $this->documents->writeCsv($stream, $document);
            } finally {
                fclose($stream);
            }
        }, $kind === 'cash' ? 'cashbook.csv' : 'bankbook.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function printable(Request $request, string $kind): HttpResponse
    {
        $data = $this->load($request, $kind, true);
        abort_if($data['report'] === null, 422, 'Select a company and finance account.');

        return response()->view('erp.accounting.cash-book-print', ['document' => $this->documents->make($data['report'])])
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    /** @return array<string, mixed> */
    private function load(Request $request, string $kind, bool $export): array
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        if (array_diff(array_keys($request->query()), ['company_id', 'binding_id', 'from', 'to', 'page', 'per_page']) !== []) {
            throw ValidationException::withMessages(['filters' => 'Unsupported book filter.']);
        }
        $data = $request->validate([
            'company_id' => ['nullable', 'integer', 'min:1'], 'binding_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $context = $this->workspace->context($actor, isset($data['company_id']) ? (int) $data['company_id'] : null, $kind);
        if ($export) {
            abort_unless($context['canExport'], 403);
        }
        $company = $context['company'];
        $bindingId = isset($data['binding_id']) ? (int) $data['binding_id'] : null;
        $report = null;
        if ($company !== null && $bindingId !== null) {
            AccountingCashAccount::query()->where('company_id', $company['id'])->where('kind', $kind)->findOrFail($bindingId);
            $input = array_diff_key($data, array_flip(['company_id', 'binding_id']));
            if ($export) {
                unset($input['page'], $input['per_page']);
            }
            $report = $export
                ? $this->reader->bookExport($actor, (int) $company['id'], $bindingId, $input)
                : $this->reader->book($actor, (int) $company['id'], $bindingId, $input);
        }

        return [...$context, 'report' => $report, 'filters' => [
            'binding_id' => $bindingId === null ? '' : (string) $bindingId,
            'from' => $report['from'] ?? $data['from'] ?? '1900-01-01',
            'to' => $report['to'] ?? $data['to'] ?? $context['today'] ?? '',
            'per_page' => (int) ($data['per_page'] ?? 25),
        ]];
    }
}
