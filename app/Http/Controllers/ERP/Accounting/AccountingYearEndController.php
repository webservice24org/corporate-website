<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ERP\Accounting\YearEnd\AccountingYearEndWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AccountingYearEndController extends Controller
{
    public function index(Request $request, AccountingYearEndWorkflow $workflow): Response
    {
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'year_id' => ['nullable', 'integer', 'min:1']]);

        return Inertia::render('ERP/Accounting/YearEnd/Index', $workflow->page($this->actor($request), isset($data['company_id']) ? (int) $data['company_id'] : null, isset($data['year_id']) ? (int) $data['year_id'] : null));
    }

    public function prepare(Request $request, int $company, int $year, string $mode, AccountingYearEndWorkflow $workflow): RedirectResponse
    {
        return $this->command($request, $workflow, $company, $year, $mode, 'prepare');
    }

    public function approve(Request $request, int $company, int $year, string $mode, AccountingYearEndWorkflow $workflow): RedirectResponse
    {
        return $this->command($request, $workflow, $company, $year, $mode, 'approve');
    }

    public function post(Request $request, int $company, int $year, string $mode, AccountingYearEndWorkflow $workflow): RedirectResponse
    {
        return $this->command($request, $workflow, $company, $year, $mode, 'post');
    }

    private function command(Request $request, AccountingYearEndWorkflow $workflow, int $company, int $year, string $mode, string $action): RedirectResponse
    {
        $workflow->change($this->actor($request), $company, $year, $mode, $action, $request->except('_token'));

        return redirect('/erp/accounting/year-end?'.http_build_query(['company_id' => $company, 'year_id' => $year]))->with('success', 'Year-end '.$mode.' '.$action.' recorded. Exact retries reuse their retained operation.');
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
