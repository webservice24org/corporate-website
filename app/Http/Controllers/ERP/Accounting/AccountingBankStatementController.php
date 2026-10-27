<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ERP\Accounting\Statements\AccountingBankStatementContract;
use App\Services\ERP\Accounting\Statements\AccountingBankStatementDocument;
use App\Services\ERP\Accounting\Statements\AccountingBankStatementService;
use App\Services\ERP\Accounting\Statements\AccountingBankStatementWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AccountingBankStatementController extends Controller
{
    public function __construct(private readonly AccountingBankStatementWorkspace $workspace, private readonly AccountingBankStatementService $service, private readonly AccountingBankStatementDocument $documents) {}

    public function index(Request $request): Response
    {
        $this->keys($request, ['company_id', 'cash_account_id', 'from', 'to', 'page']);
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'cash_account_id' => ['nullable', 'integer', 'min:1'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])], 'page' => ['nullable', 'integer', 'min:1']]);

        return Inertia::render('ERP/Accounting/BankStatements/Index', $this->workspace->index($this->actor($request), isset($data['company_id']) ? (int) $data['company_id'] : null, $data));
    }

    public function create(Request $request): Response
    {
        $this->keys($request, ['company_id']);
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1']]);

        return Inertia::render('ERP/Accounting/BankStatements/Form', $this->workspace->create($this->actor($request), isset($data['company_id']) ? (int) $data['company_id'] : null));
    }

    public function show(Request $request, int $statement): Response
    {
        $this->keys($request, ['company_id', 'page']);
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'page' => ['nullable', 'integer', 'min:1']]);

        return Inertia::render('ERP/Accounting/BankStatements/Show', $this->workspace->detail($this->actor($request), $statement, (int) ($data['page'] ?? 1), isset($data['company_id']) ? (int) $data['company_id'] : null));
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $this->input($request, false);
        $actor = $this->actor($request);

        return response()->json($this->service->preview($actor, $data['mode'], $data['metadata'], $data['lines'], $data['source'], $data['key']), 200, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function import(Request $request): RedirectResponse
    {
        return $this->record($request, 'csv');
    }

    public function manual(Request $request): RedirectResponse
    {
        return $this->record($request, 'manual');
    }

    public function template(Request $request): StreamedResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->can('erp.bank-statements.import') || $actor->can('erp.bank-statements.create'), 403);

        return $this->download(null, 'bank-statement-template.csv');
    }

    public function csv(Request $request, int $statement): StreamedResponse
    {
        $this->keys($request, []);

        return $this->download($this->workspace->export($this->actor($request), $statement), 'bank-statement.csv');
    }

    private function record(Request $request, string $mode): RedirectResponse
    {
        $data = $this->input($request, true);
        if ($data['mode'] !== $mode) {
            throw ValidationException::withMessages(['mode' => 'Input mode does not match this command.']);
        }
        $row = $this->service->record($this->actor($request), $mode, $data['metadata'], $data['lines'], $data['source'], $data['key'], $data['preview_hash']);

        return redirect()->route('erp.accounting.bank-statements.show', ['statement' => (int) $row->getKey()])->with('success', 'Statement evidence recorded. No journal was created.');
    }

    /** @return array{mode: string, metadata: array<string, mixed>, lines: array<int|string, array<string, mixed>>, source: string|null, key: string, preview_hash: string} */
    private function input(Request $request, bool $record): array
    {
        $this->keys($request, $record ? ['mode', 'metadata', 'lines', 'file', 'key', 'preview_hash'] : ['mode', 'metadata', 'lines', 'file', 'key']);
        $rules = ['mode' => ['required', 'in:csv,manual'], 'metadata' => ['required', 'array'], 'lines' => ['sometimes', 'array', 'max:'.AccountingBankStatementContract::MAX_ROWS], 'lines.*' => ['array'], 'file' => ['nullable', 'file', 'max:512']];
        $rules['key'] = ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{16,64}\z/'];
        if ($record) {
            $rules['preview_hash'] = ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'];
        }
        $data = $request->validate($rules);
        $source = null;
        $file = $request->file('file');
        if ($data['mode'] === 'csv') {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                throw ValidationException::withMessages(['file' => 'Choose a valid UTF-8 CSV file.']);
            }
            $path = $file->getRealPath();
            $raw = $path === false ? false : file_get_contents($path);
            if ($raw === false || strlen($raw) > AccountingBankStatementContract::MAX_BYTES) {
                throw ValidationException::withMessages(['file' => 'Statement file could not be read or exceeds 512 KiB.']);
            }
            $source = $raw;
        } elseif ($file !== null) {
            throw ValidationException::withMessages(['file' => 'Manual entry cannot include a file.']);
        }

        return ['mode' => $data['mode'], 'metadata' => $data['metadata'], 'lines' => $data['lines'] ?? [], 'source' => $source, 'key' => $data['key'] ?? '', 'preview_hash' => $data['preview_hash'] ?? ''];
    }

    /** @param array<string, mixed>|null $payload */
    private function download(?array $payload, string $name): StreamedResponse
    {
        return response()->streamDownload(function () use ($payload): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new \RuntimeException('Statement CSV stream unavailable.');
            }
            try {
                $this->documents->csv($stream, $payload);
            } finally {
                fclose($stream);
            }
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** @param list<string> $allowed */
    private function keys(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->except(['_token', '_method'])), $allowed) !== []) {
            throw ValidationException::withMessages(['statement' => 'Unexpected statement input.']);
        }
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
