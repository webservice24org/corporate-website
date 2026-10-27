<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCashAccountService;
use App\Services\ERP\Accounting\AccountingCompanyAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AccountingCashAccountController extends Controller
{
    public function __construct(private readonly AccountingCompanyAccess $access, private readonly AccountingCashAccountService $service) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1']]);
        $companies = $this->access->companies($actor, 'erp.accounting-mappings.view')->orderBy('name')->get(['id', 'name', 'currency_code']);
        $id = isset($data['company_id']) ? (int) $data['company_id'] : null;
        if ($id === null && $companies->count() === 1) {
            $row = $companies->first();
            $id = $row === null ? null : (int) $row->getKey();
        }
        if ($id !== null) {
            $this->access->authorize($actor, $id, 'erp.accounting-mappings.view');
        }

        return Inertia::render('ERP/Accounting/CashAccounts/Index', ['companies' => $companies, 'companyId' => $id, 'accounts' => $id === null ? [] : AccountingCashAccount::query()->where('company_id', $id)->orderBy('role')->get(['id', 'company_id', 'name', 'role', 'kind', 'currency_code', 'account_id', 'status', 'revision']), 'canManage' => $actor->can('erp.accounting-mappings.publish')]);
    }

    public function initialize(Request $request): RedirectResponse
    {
        $data = $request->validate(['company_id' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000']]);
        $this->service->initialize($this->actor($request), (int) $data['company_id'], (string) $data['reason']);

        return redirect('/erp/accounting/mappings/cash-accounts?company_id='.(int) $data['company_id'])->with('success', 'Finance cash/bank bindings checked and initialized from the published profile.');
    }

    public function status(Request $request, int $cash): RedirectResponse
    {
        $data = $request->validate(['company_id' => ['required', 'integer', 'min:1'], 'revision' => ['required', 'integer', 'min:1'], 'status' => ['required', 'in:active,inactive'], 'reason' => ['required', 'string', 'max:2000']]);
        $this->service->status($this->actor($request), (int) $data['company_id'], $cash, (int) $data['revision'], (string) $data['status'], (string) $data['reason']);

        return redirect('/erp/accounting/mappings/cash-accounts?company_id='.(int) $data['company_id'])->with('success', 'Finance account availability updated.');
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
