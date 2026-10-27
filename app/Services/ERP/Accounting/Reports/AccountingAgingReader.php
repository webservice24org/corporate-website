<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reports;

use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class AccountingAgingReader
{
    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingBusinessDate $dates, private readonly AccountingLedgerReader $ledger, private readonly AccountingAgingContract $contract, private readonly AccountingAgingGlBridge $bridge, private readonly AccountingAgingSalesSource $sales, private readonly AccountingAgingPurchaseSource $purchase) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function report(User $actor, int $companyId, string $kind, array $input): array
    {
        $data = Validator::make([...$input, 'kind' => $kind], ['kind' => ['required', 'in:ar,ap'], 'as_of' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01'], 'party_id' => ['prohibited'], 'account_id' => ['prohibited'], 'branch_id' => ['prohibited'], 'department_id' => ['prohibited']])->validate();
        $permission = 'erp.'.$kind.'-aging.view';
        $this->access->authorize($actor, $companyId, $permission);

        return DB::transaction(function () use ($actor, $companyId, $kind, $data, $permission): array {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $companyId, $permission);
            $today = $this->dates->today($company);
            $asOf = (string) $data['as_of'];
            Validator::make(['as_of' => $asOf], ['as_of' => ['before_or_equal:'.$today]])->validate();
            $source = $kind === 'ar' ? $this->sales->read($company) : $this->purchase->read($actor, $company);
            $projection = $this->contract->project($companyId, (string) $company->getAttribute('currency_code'), $source['documents'], $source['events'], $asOf, $today);
            $gl = $this->ledger->agingSource($actor, $companyId, ['from' => '1900-01-01', 'to' => $asOf], $kind);
            $bridge = $this->bridge->reconcile($kind, $source['controls'], $gl['aging_lines'], $gl['control_role_accounts'], $asOf, $projection['totals']['signed_documents']);
            $mappingIssues = [];
            $owned = array_column($gl['accounts'], 'id');
            foreach (array_keys($gl['control_role_accounts']) as $accountId) {
                if (! in_array($accountId, $owned)) {
                    $mappingIssues[] = ['issue' => 'control_mapping_account_not_owned', 'account_id' => $accountId];
                }
            }
            $issues = [...$source['issues'], ...$projection['issues'], ...$bridge['issues'], ...$mappingIssues];
            $complete = $issues === [] && $bridge['reconciled'];

            return [...$projection, 'kind' => $kind, 'company' => $gl['company'], 'currency' => $gl['currency'], 'complete' => $complete, 'issues' => $issues, 'confirmed_totals' => $complete ? $projection['totals'] : null, 'reconciliation' => $bridge, 'coverage_basis' => 'verified_live_trade_documents_and_dated_events', 'legacy_opening_coverage_integrated' => false, 'due_date_policy' => $kind === 'ar' ? 'admitted_invoice_terms' : 'current_native_bill_terms_with_historical_review', 'funds_policy' => 'unapplied_funds_and_advances_are_separate_not_net_aged', 'excluded_cohorts' => ['expense_payables', 'payroll_payables'], 'journal_evidence_checked' => true];
        });
    }
}
