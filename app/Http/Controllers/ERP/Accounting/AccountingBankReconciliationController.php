<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ERP\Accounting\Banking\AccountingBankingWorkspace;
use App\Services\ERP\Accounting\Reconciliation\AccountingBankReconciliationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class AccountingBankReconciliationController extends Controller
{
    public function __construct(private readonly AccountingBankingWorkspace $workspace, private readonly AccountingBankReconciliationService $service) {}

    public function index(Request $request): Response
    {
        $data = $this->query($request);

        return Inertia::render('ERP/Accounting/BankReconciliations/Index', $this->workspace->index($this->actor($request), 'reconciliations', $data));
    }

    public function create(Request $request): Response
    {
        $data = $this->query($request);

        return Inertia::render('ERP/Accounting/BankReconciliations/Form', $this->workspace->create($this->actor($request), 'reconciliations', $data));
    }

    public function show(Request $request, int $reconciliation): Response
    {
        $data = $this->query($request);

        return Inertia::render('ERP/Accounting/BankReconciliations/Show', $this->workspace->detail($this->actor($request), 'reconciliations', $reconciliation, $data));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->keys($request, ['statement_id', 'reason', 'key']);
        $data = $request->validate(['statement_id' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000'], 'key' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{16,64}\z/']]);
        $row = $this->service->open($this->actor($request), (int) $data['statement_id'], $data['reason'], $data['key']);

        return $this->done((int) $row->getKey());
    }

    public function match(Request $request, int $reconciliation): RedirectResponse
    {
        $this->keys($request, ['revision', 'key', 'reason', 'statement_allocations', 'book_allocations']);
        $this->service->match($this->actor($request), $reconciliation, $request->except(['_token', '_method']));

        return $this->done($reconciliation);
    }

    public function unmatch(Request $request, int $reconciliation): RedirectResponse
    {
        $this->keys($request, ['revision', 'key', 'reason', 'target_match_id']);
        $this->service->unmatch($this->actor($request), $reconciliation, $request->except(['_token', '_method']));

        return $this->done($reconciliation);
    }

    private function done(int $id): RedirectResponse
    {
        return redirect()->route('erp.accounting.bank-reconciliations.show', ['reconciliation' => $id])->with('success', 'Bank workspace updated.');
    }

    /** @return array<string, mixed> */
    private function query(Request $request): array
    {
        $this->keys($request, ['company_id', 'cash_account_id', 'statement_id', 'status', 'page', 'statement_page', 'book_page', 'history_page', 'direction', 'outstanding_only']);

        return $request->validate([
            'company_id' => ['nullable', 'integer', 'min:1'], 'cash_account_id' => ['nullable', 'integer', 'min:1'], 'statement_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:draft,submitted,approved,posted,cancelled'], 'page' => ['nullable', 'integer', 'min:1'],
            'statement_page' => ['nullable', 'integer', 'min:1'], 'book_page' => ['nullable', 'integer', 'min:1'], 'history_page' => ['nullable', 'integer', 'min:1'],
            'direction' => ['nullable', 'in:in,out'], 'outstanding_only' => ['nullable', 'boolean'],
        ]);
    }

    /** @param list<string> $allowed */
    private function keys(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->except(['_token', '_method'])), $allowed) !== []) {
            throw ValidationException::withMessages(['workspace' => 'Unexpected bank workspace input.']);
        }
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
