<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reports;

use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;

final class AccountingCashFlowWorkspace
{
    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingBusinessDate $dates, private readonly AccountingCashFlowReader $reader) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function page(User $actor, array $input): array
    {
        if (! $actor->can(AccountingCashFlowReader::PERMISSION)) {
            throw new AuthorizationException;
        }
        $data = Validator::make($input, ['company_id' => ['nullable', 'integer', 'min:1'], 'from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01'], 'to' => ['nullable', 'date_format:Y-m-d'], 'account_id' => ['prohibited'], 'branch_id' => ['prohibited'], 'department_id' => ['prohibited']])->validate();
        $query = Company::query();
        if (! $actor->hasRole('super-admin')) {
            $query->whereIn('id', $actor->employee()->select('company_id'));
        }
        $companies = [];
        foreach ($query->orderBy('code')->get(['id', 'code', 'name', 'status']) as $company) {
            $companies[] = ['id' => (int) $company->getKey(), 'code' => (string) $company->getAttribute('code'), 'name' => (string) $company->getAttribute('name'), 'status' => (string) $company->getAttribute('status')];
        }
        $id = isset($data['company_id']) ? (int) $data['company_id'] : null;
        if ($id === null && ! $actor->hasRole('super-admin') && count($companies) === 1) {
            $id = $companies[0]['id'];
        }
        $owner = null;
        $report = null;
        $filters = ['from' => (string) ($data['from'] ?? ''), 'to' => (string) ($data['to'] ?? '')];
        if ($id !== null) {
            $this->access->authorize($actor, $id, AccountingCashFlowReader::PERMISSION);
            $owner = Company::query()->findOrFail($id);
            $today = $this->dates->today($owner);
            if ($filters['from'] === '') {
                $filters['from'] = substr($today, 0, 7).'-01';
            }
            if ($filters['to'] === '') {
                $filters['to'] = $today;
            }
            $report = $this->reader->report($actor, $id, $filters);
        }

        return ['companies' => $companies, 'company' => $owner === null ? null : ['id' => (int) $owner->getKey(), 'code' => (string) $owner->getAttribute('code'), 'name' => (string) $owner->getAttribute('name')], 'today' => $owner === null ? null : $this->dates->today($owner), 'filters' => $filters, 'report' => $report, 'canExport' => $actor->can('erp.cash-flow.export'), 'canLedger' => $actor->can(AccountingLedgerReader::LEDGER_PERMISSION), 'canTrial' => $actor->can(AccountingLedgerReader::TRIAL_PERMISSION), 'canProfit' => $actor->can('erp.profit-loss.view'), 'canBalance' => $actor->can('erp.balance-sheet.view'), 'canAccounts' => $actor->can('erp.accounts.view')];
    }
}
