<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reports;

use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class AccountingTaxReader
{
    public const PERMISSION = 'erp.tax-reconciliation.view';

    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingLedgerReader $ledger, private readonly AccountingTaxSources $sources, private readonly AccountingTaxContract $contract) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function report(User $actor, int $companyId, array $input): array
    {
        $this->access->authorize($actor, $companyId, self::PERMISSION);
        Validator::make($input, ['account_id' => ['prohibited'], 'party_id' => ['prohibited'], 'branch_id' => ['prohibited'], 'department_id' => ['prohibited']])->validate();

        return DB::transaction(function () use ($actor, $companyId, $input): array {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $companyId, self::PERMISSION);
            $gl = $this->ledger->taxSource($actor, $companyId, $input);
            $from = $gl['filters']['from'];
            $to = $gl['filters']['to'];
            $source = $this->sources->collect($company, $gl['tax_lines'], $gl['tax_role_accounts'], $to);
            $projection = $this->contract->project($gl['accounts'], $gl['tax_lines'], $source['expected'], $gl['tax_role_accounts'], $from, $to);
            foreach ($source['sources'] as &$row) {
                $row['date_scope'] = $row['date'] < $from ? 'opening_evidence' : 'period';
            } unset($row);
            $issues = [...$source['issues'], ...$projection['issues']];
            $complete = $issues === [] && $projection['complete'];

            return [...$projection, 'company' => $gl['company'], 'currency' => $gl['currency'], 'filters' => ['from' => $from, 'to' => $to], 'complete' => $complete, 'issues' => $issues, 'confirmed_balances' => $complete ? $projection['gl'] : null, 'sources' => $source['sources'], 'coverage_basis' => 'configured_tax_accounts_and_verified_supported_native_recipes', 'legacy_opening_coverage_integrated' => false, 'statutory_filing_supported' => false, 'netting_policy' => 'input_and_output_are_separate_no_return_or_payment_amount_inferred'];
        });
    }
}
