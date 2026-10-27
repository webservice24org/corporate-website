<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ERP\Accounting\Reports\AccountingProfitLossWorkspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AccountingProfitLossController extends Controller
{
    public function __construct(private readonly AccountingProfitLossWorkspace $workspace) {}

    public function index(Request $request): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return Inertia::render('ERP/Accounting/ProfitLoss/Index', $this->workspace->page($actor, $request->query()));
    }
}
