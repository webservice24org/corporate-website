<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ERP\Accounting\Reconciliation\AccountingBankFinalizationWorkflow;
use App\Services\ERP\Accounting\Reconciliation\AccountingBankReconciliationExportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class AccountingBankFinalizationController extends Controller
{
    public function __construct(private readonly AccountingBankFinalizationWorkflow $workflow) {}

    public function show(Request $request, int $reconciliation): Response
    {
        if (array_diff(array_keys($request->query()), ['company_id']) !== []) {
            throw ValidationException::withMessages(['finalization' => 'Unexpected finalization filter.']);
        }
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1']]);
        $actor = $this->actor($request);
        $props = $this->workflow->review($actor, $reconciliation, isset($data['company_id']) ? (int) $data['company_id'] : null);
        $props['exportLinks'] = app(AccountingBankReconciliationExportService::class)->links($actor, $reconciliation, $props['state'] !== 'review');

        return Inertia::render('ERP/Accounting/BankReconciliations/Finalization', $props);
    }

    public function submit(Request $request, int $reconciliation): RedirectResponse
    {
        return $this->command($request, $reconciliation, 'submit');
    }

    public function approve(Request $request, int $reconciliation): RedirectResponse
    {
        return $this->command($request, $reconciliation, 'approve');
    }

    public function reject(Request $request, int $reconciliation): RedirectResponse
    {
        return $this->command($request, $reconciliation, 'reject');
    }

    public function reopen(Request $request, int $reconciliation): RedirectResponse
    {
        return $this->command($request, $reconciliation, 'reopen');
    }

    private function command(Request $request, int $id, string $kind): RedirectResponse
    {
        $this->workflow->execute($this->actor($request), $id, $kind, $request->except(['_token', '_method']));

        return redirect()->route('erp.accounting.bank-reconciliations.finalization', ['reconciliation' => $id])->with('success', 'Finalization history updated. No journal was created.');
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
