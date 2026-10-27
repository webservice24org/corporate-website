<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\Account;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCompanyAccess;
use App\Services\ERP\Accounting\AccountingLedgerPrototype;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AccountingLedgerController extends Controller
{
    public function __construct(private readonly AccountingCompanyAccess $access, private readonly AccountingLedgerPrototype $ledger) {}

    public function index(Request $request): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'account_id' => ['nullable', 'integer', 'min:1'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])], 'page' => ['nullable', 'integer', 'min:1', 'max:1000000']]);
        $companies = $this->access->companies($actor, 'erp.accounting-reports.view')->orderBy('name')->get(['id', 'code', 'name', 'currency_code']);
        $id = isset($data['company_id']) ? (int) $data['company_id'] : null;
        if ($id !== null) {
            $this->access->authorize($actor, $id, 'erp.accounting-reports.view');
        }
        if ($id === null && $companies->count() === 1) {
            $id = (int) $companies->firstOrFail()->getKey();
        }
        $company = $id === null ? null : $companies->firstWhere('id', $id);
        $accounts = $company === null ? [] : Account::query()->where('company_id', $id)->orderBy('code')->get(['id', 'code', 'name', 'status'])->map(static fn (Account $row): array => ['id' => (int) $row->getKey(), 'label' => $row->getAttribute('code').' — '.$row->getAttribute('name').' ('.$row->getAttribute('status').')'])->all();
        $result = $company === null || empty($data['account_id']) ? null : $this->ledger->read($actor, (int) $id, $data);

        return Inertia::render('ERP/Accounting/Ledger/Index', ['companies' => $companies->map(static fn (Company $row): array => $row->only(['id', 'code', 'name', 'currency_code']))->all(), 'company' => $company?->only(['id', 'code', 'name', 'currency_code']), 'accounts' => $accounts, 'filters' => ['account_id' => isset($data['account_id']) ? (string) $data['account_id'] : '', 'from' => $data['from'] ?? '', 'to' => $data['to'] ?? ''], 'result' => $result]);
    }
}
