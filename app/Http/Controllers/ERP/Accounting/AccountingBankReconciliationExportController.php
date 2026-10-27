<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ERP\Accounting\Reconciliation\AccountingBankReconciliationDocument;
use App\Services\ERP\Accounting\Reconciliation\AccountingBankReconciliationExportService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AccountingBankReconciliationExportController extends Controller
{
    public function __construct(private readonly AccountingBankReconciliationExportService $exports, private readonly AccountingBankReconciliationDocument $documents) {}

    public function csv(Request $request, int $reconciliation): StreamedResponse
    {
        [$document, $basis] = $this->prepare($request, $reconciliation);

        return response()->streamDownload(function () use ($document): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new \RuntimeException('Export stream unavailable.');
            }
            try {
                $this->documents->csv($stream, $document);
            } finally {
                fclose($stream);
            }
        }, 'bank-reconciliation-'.$reconciliation.'-'.$basis.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function printable(Request $request, int $reconciliation): Response
    {
        [$document] = $this->prepare($request, $reconciliation);

        return response()->view('erp.accounting.bank-reconciliation-print', ['document' => $document])->withHeaders([
            'Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY', 'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
        ]);
    }

    /** @return array{array<string, mixed>, string} */
    private function prepare(Request $request, int $id): array
    {
        if (array_diff(array_keys($request->query()), ['basis', 'company_id']) !== []) {
            throw ValidationException::withMessages(['export' => 'Exports cover the full result. Pagination, arbitrary filters and approval IDs are not accepted.']);
        }
        $data = $request->validate(['basis' => ['sometimes', 'required', 'in:current,approved'], 'company_id' => ['nullable', 'integer', 'min:1']]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $basis = $data['basis'] ?? 'current';
        $report = $this->exports->report($actor, $id, $basis, isset($data['company_id']) ? (int) $data['company_id'] : null);
        try {
            $document = $this->documents->make($report);
        } catch (\InvalidArgumentException $error) {
            throw ValidationException::withMessages(['export' => $error->getMessage()]);
        }

        return [$document, $basis];
    }
}
