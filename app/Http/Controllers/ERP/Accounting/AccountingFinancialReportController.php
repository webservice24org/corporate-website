<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ERP\Accounting\Reports\AccountingFinancialReportExport;
use App\Services\ERP\Accounting\Reports\AccountingFinancialReportWorkspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class AccountingFinancialReportController extends Controller
{
    public function __construct(private readonly AccountingFinancialReportWorkspace $workspace, private readonly AccountingFinancialReportExport $exports) {}

    public function ledger(Request $request): Response
    {
        return Inertia::render('ERP/Accounting/FinancialReports/Index', $this->workspace->page($this->actor($request), 'ledger', $request->query()));
    }

    public function trial(Request $request): Response
    {
        return Inertia::render('ERP/Accounting/FinancialReports/Index', $this->workspace->page($this->actor($request), 'trial', $request->query()));
    }

    public function ledgerExport(Request $request): HttpResponse
    {
        return $this->exports->download($this->actor($request), 'ledger', $request->query());
    }

    public function trialExport(Request $request): HttpResponse
    {
        return $this->exports->download($this->actor($request), 'trial', $request->query());
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
