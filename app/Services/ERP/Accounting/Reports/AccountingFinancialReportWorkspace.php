<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reports;

use App\Models\Accounting\Account;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;

final class AccountingFinancialReportWorkspace
{
    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingBusinessDate $dates, private readonly AccountingLedgerReader $reader) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function page(User $actor, string $kind, array $input): array
    {
        $permission = $kind === 'ledger' ? AccountingLedgerReader::LEDGER_PERMISSION : AccountingLedgerReader::TRIAL_PERMISSION;
        if (! $actor->can($permission)) {
            throw new AuthorizationException;
        }
        $data = Validator::make($input, ['company_id' => ['nullable', 'integer', 'min:1'], 'account_id' => ['nullable', 'integer', 'min:1'], 'q' => ['nullable', 'string', 'max:100'], 'from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01'], 'to' => ['nullable', 'date_format:Y-m-d'], 'page' => ['nullable', 'integer', 'min:1', 'max:1000000'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']])->validate();
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
        $accounts = [];
        $report = null;
        $truncated = false;
        $filters = ['from' => (string) ($input['from'] ?? ''), 'to' => (string) ($input['to'] ?? ''), 'account_id' => isset($data['account_id']) ? (string) $data['account_id'] : '', 'per_page' => (string) ($input['per_page'] ?? 25), 'q' => (string) ($data['q'] ?? '')];
        if ($id !== null) {
            $this->access->authorize($actor, $id, $permission);
            $owner = Company::query()->findOrFail($id);
            $today = $this->dates->today($owner);
            if ($filters['from'] === '') {
                $filters['from'] = substr($today, 0, 7).'-01';
            }
            if ($filters['to'] === '') {
                $filters['to'] = $today;
            }
            Validator::make($filters, ['from' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.$today]])->validate();
            if ($kind === 'ledger') {
                $accountQuery = Account::query()->where('company_id', $id);
                if ($filters['q'] !== '') {
                    $term = '%'.$filters['q'].'%';
                    $accountQuery->where(function ($query) use ($term): void {
                        $query->where('code', 'like', $term)->orWhere('name', 'like', $term);
                    });
                }
                $selected = isset($data['account_id']) ? Account::query()->where('company_id', $id)->findOrFail((int) $data['account_id']) : null;
                $found = $accountQuery->orderBy('code')->orderBy('id')->limit(501)->get();
                $truncated = $found->count() > 500;
                foreach ($found->take(500) as $account) {
                    $accounts[(int) $account->getKey()] = $this->choice($account);
                }
                if ($selected !== null) {
                    $accounts[(int) $selected->getKey()] = $this->choice($selected);
                }
                if ($selected !== null) {
                    $report = $this->reader->ledger($actor, $id, (int) $selected->getKey(), [...$filters, 'page' => $input['page'] ?? 1]);
                }
            } else {
                $report = $this->reader->trial($actor, $id, $filters);
            }
        }

        return ['kind' => $kind, 'companies' => $companies, 'company' => $owner === null ? null : ['id' => (int) $owner->getKey(), 'code' => (string) $owner->getAttribute('code'), 'name' => (string) $owner->getAttribute('name')], 'today' => $owner === null ? null : $this->dates->today($owner), 'filters' => $filters, 'accounts' => array_values($accounts), 'accountsTruncated' => $truncated, 'report' => $report, 'canProfit' => $actor->can('erp.profit-loss.view'), 'canBalance' => $actor->can('erp.balance-sheet.view'), 'canCashFlow' => $actor->can('erp.cash-flow.view'), 'canLedger' => $actor->can(AccountingLedgerReader::LEDGER_PERMISSION), 'canTrial' => $actor->can(AccountingLedgerReader::TRIAL_PERMISSION), 'canExport' => $actor->can($kind === 'ledger' ? 'erp.general-ledger.export' : 'erp.trial-balance.export')];
    }

    /** @return array{id: int, label: string} */
    private function choice(Account $account): array
    {
        return ['id' => (int) $account->getKey(), 'label' => (string) $account->getAttribute('code').' — '.(string) $account->getAttribute('name').($account->getAttribute('status') !== 'active' ? ' (inactive)' : '')];
    }
}
