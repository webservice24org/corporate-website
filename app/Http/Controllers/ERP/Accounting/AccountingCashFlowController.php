<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ERP\Accounting\Reports\AccountingCashFlowExport;
use App\Services\ERP\Accounting\Reports\AccountingCashFlowWorkspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class AccountingCashFlowController extends Controller
{
    public function __construct(private readonly AccountingCashFlowWorkspace $workspace, private readonly AccountingCashFlowExport $exports) {}

    public function index(Request $request): Response
    {
        return Inertia::render('ERP/Accounting/CashFlow/Index', $this->workspace->page($this->actor($request), $request->query()));
    }

    public function export(Request $request): HttpResponse
    {
        return $this->exports->download($this->actor($request), $request->query());
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
