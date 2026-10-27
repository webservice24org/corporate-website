<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ERP\Accounting\Close\AccountingPeriodCloseWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AccountingPeriodCloseController extends Controller
{
    public function index(Request $request, AccountingPeriodCloseWorkflow $workflow): Response
    {
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'period_id' => ['nullable', 'integer', 'min:1']]);

        return Inertia::render('ERP/Accounting/PeriodClose/Index', $workflow->page($this->actor($request), isset($data['company_id']) ? (int) $data['company_id'] : null, isset($data['period_id']) ? (int) $data['period_id'] : null));
    }

    public function prepare(Request $request, int $company, int $period, AccountingPeriodCloseWorkflow $workflow): RedirectResponse
    {
        $workflow->prepare($this->actor($request), $company, $period, $request->except('_token'));

        return $this->back($company, $period, 'Current checklist prepared for independent review.');
    }

    public function approve(Request $request, int $company, int $period, AccountingPeriodCloseWorkflow $workflow): RedirectResponse
    {
        $workflow->approve($this->actor($request), $company, $period, $request->except('_token'));

        return $this->back($company, $period, 'Independent inventory and adjustment review recorded against the current evidence.');
    }

    public function close(Request $request, int $company, int $period, AccountingPeriodCloseWorkflow $workflow): RedirectResponse
    {
        $workflow->close($this->actor($request), $company, $period, $request->except('_token'));

        return $this->back($company, $period, 'Period hard closed after fresh blocking checks.');
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function back(int $company, int $period, string $message): RedirectResponse
    {
        return redirect('/erp/accounting/period-close?'.http_build_query(['company_id' => $company, 'period_id' => $period]))->with('success', $message);
    }
}
