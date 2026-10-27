<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reports;

use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;

final class AccountingProfitLossWorkspace
{
    public const PERMISSION = 'erp.profit-loss.view';

    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingBusinessDate $dates, private readonly AccountingLedgerReader $reader, private readonly AccountingProfitLossContract $contract) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function page(User $actor, array $input): array
    {
        if (! $actor->can(self::PERMISSION)) {
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
            $this->access->authorize($actor, $id, self::PERMISSION);
            $owner = Company::query()->findOrFail($id);
            $today = $this->dates->today($owner);
            if ($filters['from'] === '') {
                $filters['from'] = substr($today, 0, 7).'-01';
            }
            if ($filters['to'] === '') {
                $filters['to'] = $today;
            }
            $source = $this->reader->profitSource($actor, $id, $filters);
            $report = [...$this->contract->project($source['accounts'], $source['period_opening_movements']), 'currency' => $source['currency'], 'journal_evidence_checked' => $source['journal_evidence_checked'], 'native_source_coverage_certified' => false, 'coverage_note' => $source['coverage_note'], 'classification_basis' => 'current_account_group_and_statement_role'];
        }

        return ['companies' => $companies, 'company' => $owner === null ? null : ['id' => (int) $owner->getKey(), 'code' => (string) $owner->getAttribute('code'), 'name' => (string) $owner->getAttribute('name')], 'today' => $owner === null ? null : $this->dates->today($owner), 'filters' => $filters, 'report' => $report, 'canBalance' => $actor->can('erp.balance-sheet.view'), 'canCashFlow' => $actor->can('erp.cash-flow.view'), 'canLedger' => $actor->can(AccountingLedgerReader::LEDGER_PERMISSION), 'canTrial' => $actor->can(AccountingLedgerReader::TRIAL_PERMISSION), 'canAccounts' => $actor->can('erp.accounts.view')];
    }
}
