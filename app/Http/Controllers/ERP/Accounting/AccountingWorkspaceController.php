<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\AccountingWorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AccountingWorkspaceController extends Controller
{
    public function __construct(private readonly AccountingWorkspaceService $workspace, private readonly AccountingSetupService $setup) {}

    public function index(Request $request): Response
    {
        return $this->display($request, 'ERP/Accounting/Overview');
    }

    public function settings(Request $request): Response
    {
        return $this->display($request, 'ERP/Accounting/Settings/Index');
    }

    public function initialize(Request $request): RedirectResponse
    {
        $data = $request->validate(['company_id' => ['required', 'integer', 'min:1']]);
        $setting = $this->setup->initialize($this->actor($request), (int) $data['company_id']);

        return redirect()->route('erp.accounting.settings', ['company_id' => $setting->getAttribute('company_id')])->with('success', 'Accounting setup initialized. Posting remains disabled.');
    }

    private function display(Request $request, string $component): Response
    {
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1']]);
        $companyId = isset($data['company_id']) ? (int) $data['company_id'] : null;

        return Inertia::render($component, $this->workspace->read($this->actor($request), $companyId));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
