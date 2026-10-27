<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ERP\Accounting\Reports\AccountingTaxExport;
use App\Services\ERP\Accounting\Reports\AccountingTaxWorkspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class AccountingTaxController extends Controller
{
    public function __construct(private readonly AccountingTaxWorkspace $workspace, private readonly AccountingTaxExport $exports) {}

    public function index(Request $request): Response
    {
        return Inertia::render('ERP/Accounting/Tax/Index', $this->workspace->page($this->actor($request), $request->query()));
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
