<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ERP\Accounting\Adjustments\AccountingBankAdjustmentWorkflow;
use App\Services\ERP\Accounting\Banking\AccountingBankingWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class AccountingBankAdjustmentController extends Controller
{
    public function __construct(private readonly AccountingBankingWorkspace $workspace, private readonly AccountingBankAdjustmentWorkflow $service) {}

    public function index(Request $request): Response
    {
        $data = $this->query($request);

        return Inertia::render('ERP/Accounting/BankAdjustments/Index', $this->workspace->index($this->actor($request), 'adjustments', $data));
    }

    public function create(Request $request): Response
    {
        $data = $this->query($request);

        return Inertia::render('ERP/Accounting/BankAdjustments/Form', $this->workspace->create($this->actor($request), 'adjustments', $data));
    }

    public function show(Request $request, int $adjustment): Response
    {
        $data = $this->query($request);

        return Inertia::render('ERP/Accounting/BankAdjustments/Show', $this->workspace->detail($this->actor($request), 'adjustments', $adjustment, $data));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->keys($request, ['statement_line_id', 'kind', 'counter_account_id', 'document_date', 'reason', 'key']);
        $row = $this->service->prepare($this->actor($request), $request->except(['_token', '_method']));

        return $this->done((int) $row->getKey());
    }

    public function refund(Request $request, int $adjustment): Response
    {
        return Inertia::render('ERP/Accounting/BankAdjustments/Form', $this->workspace->create($this->actor($request), 'adjustments', $this->query($request), $adjustment));
    }

    public function storeRefund(Request $request, int $adjustment): RedirectResponse
    {
        $this->keys($request, ['statement_line_id', 'document_date', 'reason', 'key']);
        $row = $this->service->prepareReversal($this->actor($request), $adjustment, $request->except(['_token', '_method']));

        return $this->done((int) $row->getKey());
    }

    public function edit(Request $request, int $adjustment): Response
    {
        return Inertia::render('ERP/Accounting/BankAdjustments/Form', $this->workspace->detail($this->actor($request), 'adjustments', $adjustment, $this->query($request), true));
    }

    public function update(Request $request, int $adjustment): RedirectResponse
    {
        $this->keys($request, ['revision', 'document_date', 'reason']);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'document_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:2000']]);
        $this->service->edit($this->actor($request), $adjustment, (int) $data['revision'], $data['document_date'], $data['reason']);

        return $this->done($adjustment);
    }

    public function submit(Request $request, int $adjustment): RedirectResponse
    {
        $this->keys($request, ['revision']);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $this->service->submit($this->actor($request), $adjustment, (int) $data['revision']);

        return $this->done($adjustment);
    }

    public function approve(Request $request, int $adjustment): RedirectResponse
    {
        $this->keys($request, ['revision']);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $this->service->approve($this->actor($request), $adjustment, (int) $data['revision']);

        return $this->done($adjustment);
    }

    public function reject(Request $request, int $adjustment): RedirectResponse
    {
        $this->keys($request, ['revision', 'reason']);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000']]);
        $this->service->reject($this->actor($request), $adjustment, (int) $data['revision'], $data['reason']);

        return $this->done($adjustment);
    }

    public function cancel(Request $request, int $adjustment): RedirectResponse
    {
        $this->keys($request, ['revision', 'reason']);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000']]);
        $this->service->cancel($this->actor($request), $adjustment, (int) $data['revision'], $data['reason']);

        return $this->done($adjustment);
    }

    public function post(Request $request, int $adjustment): RedirectResponse
    {
        $this->keys($request, ['revision', 'key']);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'key' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{16,64}\z/']]);
        $this->service->post($this->actor($request), $adjustment, (int) $data['revision'], $data['key']);

        return $this->done($adjustment);
    }

    private function done(int $id): RedirectResponse
    {
        return redirect()->route('erp.accounting.bank-adjustments.show', ['adjustment' => $id])->with('success', 'Bank workspace updated.');
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
