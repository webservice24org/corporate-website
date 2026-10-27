<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\AccountingCashTransferReversalRequest;
use App\Models\User;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferReversalPosting;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferReversalWorkflow;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferReversalWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class AccountingCashTransferReversalController extends Controller
{
    public function __construct(
        private readonly AccountingCashTransferReversalWorkspace $workspace,
        private readonly AccountingCashTransferReversalWorkflow $workflow,
        private readonly AccountingCashTransferReversalPosting $posting,
    ) {}

    public function index(Request $request): Response
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'integer', 'min:1'], 'transfer_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:draft,submitted,approved,posted,cancelled'],
            'from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return Inertia::render('ERP/Accounting/CashTransferReversals/Index', $this->workspace->index($this->actor($request), isset($data['company_id']) ? (int) $data['company_id'] : null, $data));
    }

    public function create(Request $request): Response
    {
        return Inertia::render('ERP/Accounting/CashTransferReversals/Form', $this->workspace->create($this->actor($request), $this->originalId($request), $this->company($request)));
    }

    public function show(Request $request, int $transfer): Response
    {
        return Inertia::render('ERP/Accounting/CashTransferReversals/Show', $this->workspace->detail($this->actor($request), $transfer, false, $this->company($request)));
    }

    public function edit(Request $request, int $transfer): Response
    {
        return Inertia::render('ERP/Accounting/CashTransferReversals/Form', $this->workspace->detail($this->actor($request), $transfer, true, $this->company($request)));
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->saved($this->workflow->prepare($this->actor($request), $this->originalId($request), $request->except(['_token', '_method', 'transfer_id'])), 'Reversal draft saved.');
    }

    public function update(Request $request, int $transfer): RedirectResponse
    {
        return $this->saved($this->workflow->prepare($this->actor($request), (int) $this->workflow->find($this->actor($request), $transfer, 'erp.cash-transfer-reversals.edit')->getAttribute('original_transfer_id'), $request->except(['_token', '_method']), $transfer), 'Reversal draft updated.');
    }

    public function submit(Request $request, int $transfer): RedirectResponse
    {
        return $this->transition($request, $transfer, 'submit');
    }

    public function approve(Request $request, int $transfer): RedirectResponse
    {
        return $this->transition($request, $transfer, 'approve');
    }

    public function reject(Request $request, int $transfer): RedirectResponse
    {
        return $this->transition($request, $transfer, 'reject');
    }

    public function cancel(Request $request, int $transfer): RedirectResponse
    {
        return $this->transition($request, $transfer, 'cancel');
    }

    public function post(Request $request, int $transfer): RedirectResponse
    {
        return $this->transition($request, $transfer, 'post');
    }

    private function transition(Request $request, int $transfer, string $action): RedirectResponse
    {
        $allowed = ['revision'];
        $rules = ['revision' => ['required', 'integer', 'min:1']];
        if (in_array($action, ['reject', 'cancel'], true)) {
            $allowed[] = 'reason';
            $rules['reason'] = ['required', 'string', 'max:2000'];
        }
        if ($action === 'post') {
            $allowed[] = 'key';
            $rules['key'] = ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{16,64}\z/'];
        }
        if (array_diff(array_keys($request->except(['_token', '_method'])), $allowed) !== []) {
            throw ValidationException::withMessages(['transfer' => 'Unexpected workflow input. Reload the transfer before continuing.']);
        }
        $data = $request->validate($rules);
        $actor = $this->actor($request);
        $revision = (int) $data['revision'];
        $row = match ($action) {
            'submit' => $this->workflow->submit($actor, $transfer, $revision),
            'approve' => $this->workflow->approve($actor, $transfer, $revision),
            'reject' => $this->workflow->reject($actor, $transfer, $revision, $data['reason']),
            'cancel' => $this->workflow->cancel($actor, $transfer, $revision, $data['reason']),
            'post' => $this->posting->post($actor, $transfer, $revision, $data['key']),
            default => throw new \LogicException('Unsupported transfer action.'),
        };

        return $this->saved($row, $action === 'post' ? 'Reversal posted and verified.' : 'Reversal workflow updated.');
    }

    private function saved(AccountingCashTransferReversalRequest $row, string $message): RedirectResponse
    {
        return redirect()->route('erp.accounting.cash-transfer-reversals.show', ['transfer' => (int) $row->getKey()])->with('success', $message);
    }

    private function originalId(Request $request): int
    {
        $data = $request->validate(['transfer_id' => ['required', 'integer', 'min:1']]);

        return (int) $data['transfer_id'];
    }

    private function company(Request $request): ?int
    {
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1']]);

        return isset($data['company_id']) ? (int) $data['company_id'] : null;
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
