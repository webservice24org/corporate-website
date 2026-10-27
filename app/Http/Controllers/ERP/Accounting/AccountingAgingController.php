<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ERP\Accounting\Reports\AccountingAgingExport;
use App\Services\ERP\Accounting\Reports\AccountingAgingWorkspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class AccountingAgingController extends Controller
{
    public function __construct(private readonly AccountingAgingWorkspace $workspace, private readonly AccountingAgingExport $exports) {}

    public function ar(Request $request): Response
    {
        return Inertia::render('ERP/Accounting/Aging/Index', $this->workspace->page($this->actor($request), 'ar', $request->query()));
    }

    public function ap(Request $request): Response
    {
        return Inertia::render('ERP/Accounting/Aging/Index', $this->workspace->page($this->actor($request), 'ap', $request->query()));
    }

    public function arExport(Request $request): HttpResponse
    {
        return $this->exports->download($this->actor($request), 'ar', $request->query());
    }

    public function apExport(Request $request): HttpResponse
    {
        return $this->exports->download($this->actor($request), 'ap', $request->query());
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
