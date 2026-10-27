<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reports;

use App\Models\Accounting\FiscalYear;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class AccountingBalanceSheetWorkspace
{
    public const PERMISSION = 'erp.balance-sheet.view';

    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingBusinessDate $dates, private readonly AccountingLedgerReader $reader, private readonly AccountingBalanceSheetContract $contract) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function page(User $actor, array $input): array
    {
        if (! $actor->can(self::PERMISSION)) {
            throw new AuthorizationException;
        }
        $data = Validator::make($input, ['company_id' => ['nullable', 'integer', 'min:1'], 'as_of' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01'], 'from' => ['prohibited'], 'to' => ['prohibited'], 'fiscal_year_id' => ['prohibited'], 'account_id' => ['prohibited'], 'branch_id' => ['prohibited'], 'department_id' => ['prohibited']])->validate();
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
        $fiscalYear = null;
        $asOf = (string) ($data['as_of'] ?? '');
        if ($id !== null) {
            $this->access->authorize($actor, $id, self::PERMISSION);
            $owner = Company::query()->findOrFail($id);
            if ($asOf === '') {
                $asOf = $this->dates->today($owner);
            }
            $result = DB::transaction(function () use ($actor, $id, $asOf): array {
                $company = Company::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                $this->access->authorize($actor, $id, self::PERMISSION);
                Validator::make(['as_of' => $asOf], ['as_of' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.$this->dates->today($company)]])->validate();
                $matches = FiscalYear::query()->where('company_id', $id)->whereDate('starts_on', '<=', $asOf)->whereDate('ends_on', '>=', $asOf)->orderBy('starts_on')->limit(2)->get();
                $year = null;
                if ($matches->count() === 1) {
                    $row = $matches->firstOrFail();
                    $year = ['id' => (int) $row->getKey(), 'code' => (string) $row->getAttribute('code'), 'starts_on' => $row->getAttribute('starts_on')->format('Y-m-d'), 'ends_on' => $row->getAttribute('ends_on')->format('Y-m-d'), 'state' => (string) $row->getAttribute('state')];
                }
                $source = $this->reader->balanceSource($actor, $id, ['from' => $year === null ? '1900-01-01' : $year['starts_on'], 'to' => $asOf]);
                $report = [...$this->contract->project($source['accounts'], $source['period_opening_movements'], $year), 'currency' => $source['currency'], 'journal_evidence_checked' => $source['journal_evidence_checked'], 'native_source_coverage_certified' => false, 'coverage_note' => $source['coverage_note'], 'classification_basis' => 'current_account_group_and_statement_role', 'fiscal_year_matches' => $matches->count()];

                return ['report' => $report, 'fiscalYear' => $year];
            });
            $report = $result['report'];
            $fiscalYear = $result['fiscalYear'];
        }

        return ['companies' => $companies, 'company' => $owner === null ? null : ['id' => (int) $owner->getKey(), 'code' => (string) $owner->getAttribute('code'), 'name' => (string) $owner->getAttribute('name')], 'today' => $owner === null ? null : $this->dates->today($owner), 'asOf' => $asOf, 'fiscalYear' => $fiscalYear, 'report' => $report, 'canCashFlow' => $actor->can('erp.cash-flow.view'), 'canLedger' => $actor->can(AccountingLedgerReader::LEDGER_PERMISSION), 'canTrial' => $actor->can(AccountingLedgerReader::TRIAL_PERMISSION), 'canProfit' => $actor->can('erp.profit-loss.view'), 'canAccounts' => $actor->can('erp.accounts.view')];
    }
}
