<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ERP\Accounting\Dashboard\AccountingFinanceDashboard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AccountingFinanceDashboardController extends Controller
{
    public function index(Request $request, AccountingFinanceDashboard $dashboard): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return Inertia::render('ERP/Accounting/Dashboard/Index', $dashboard->page($actor, $request->query()));
    }
}
