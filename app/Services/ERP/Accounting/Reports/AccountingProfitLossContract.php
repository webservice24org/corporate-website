<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reports;

use App\Services\ERP\Accounting\AccountingAccountCatalog;

/** Current account classifications, period movements only; no fabricated tax or retained-earnings entries. */
final class AccountingProfitLossContract
{
    public function __construct(private readonly AccountingAccountCatalog $catalog) {}

    /** @param list<array<string, mixed>> $accounts
     * @param  array<int, array{debit: string, credit: string}>  $openingMovements
     * @return array<string, mixed>
     */
    public function project(array $accounts, array $openingMovements = []): array
    {
        $labels = ['operating_revenue' => 'Operating revenue', 'sales_allowances' => 'Sales allowances', 'cogs' => 'Cost of goods sold', 'operating_expense' => 'Operating expenses', 'other_revenue' => 'Other income', 'other_expense' => 'Other expenses'];
        $sections = [];
        foreach ($labels as $role => $label) {
            $sections[$role] = ['role' => $role, 'label' => $label, 'amount' => '0.00', 'accounts' => []];
        }
        $issues = [];
        $warnings = [];
        $roles = $this->catalog->statementRoles();
        $excludedDebit = '0.00';
        $excludedCredit = '0.00';
        foreach ($accounts as $account) {
            $id = (int) $account['id'];
            $category = (string) $account['category'];
            $role = (string) $account['statement_role'];
            $base = ['account_id' => $id, 'code' => (string) $account['code'], 'name' => (string) $account['name']];
            $opening = $openingMovements[$id] ?? ['debit' => '0.00', 'credit' => '0.00'];
            $debit = bcsub((string) $account['period_debit'], $opening['debit'], 2);
            $credit = bcsub((string) $account['period_credit'], $opening['credit'], 2);
            if (! isset($roles[$category]) || ! in_array($role, $roles[$category], true)) {
                $issues[] = [...$base, 'issue' => 'classification_missing_or_inconsistent', 'category' => $category, 'statement_role' => $role, 'period_debit' => $debit, 'period_credit' => $credit];

                continue;
            }
            if (! in_array($category, ['revenue', 'expense'], true)) {
                continue;
            }
            $excludedDebit = bcadd($excludedDebit, $opening['debit'], 2);
            $excludedCredit = bcadd($excludedCredit, $opening['credit'], 2);
            if (bccomp($opening['debit'], '0', 2) !== 0 || bccomp($opening['credit'], '0', 2) !== 0) {
                $issues[] = [...$base, 'issue' => 'nominal_opening_movement_excluded', 'category' => $category, 'statement_role' => $role, 'period_debit' => $opening['debit'], 'period_credit' => $opening['credit']];
            }
            if (! (bool) $account['is_postable'] && (bccomp($debit, '0', 2) !== 0 || bccomp($credit, '0', 2) !== 0)) {
                $issues[] = [...$base, 'issue' => 'nonpostable_account_has_activity', 'category' => $category, 'statement_role' => $role, 'period_debit' => $debit, 'period_credit' => $credit];
            }
            $creditPositive = in_array($role, ['operating_revenue', 'other_revenue'], true);
            $amount = $creditPositive ? bcsub($credit, $debit, 2) : bcsub($debit, $credit, 2);
            if (bccomp($amount, '0', 2) < 0) {
                $warnings[] = [...$base, 'issue' => 'net_contra_movement', 'amount' => $amount];
            }
            $row = [...$base, 'status' => (string) $account['status'], 'debit' => $debit, 'credit' => $credit, 'amount' => $amount];
            $sections[$role]['accounts'][] = $row;
            $sections[$role]['amount'] = bcadd($sections[$role]['amount'], $amount, 2);
        }
        $netRevenue = bcsub($sections['operating_revenue']['amount'], $sections['sales_allowances']['amount'], 2);
        $gross = bcsub($netRevenue, $sections['cogs']['amount'], 2);
        $operating = bcsub($gross, $sections['operating_expense']['amount'], 2);
        $result = bcsub(bcadd($operating, $sections['other_revenue']['amount'], 2), $sections['other_expense']['amount'], 2);
        $complete = $issues === [];

        return ['sections' => array_values($sections), 'classification_complete' => $complete, 'issues' => $issues, 'warnings' => $warnings, 'excluded_nominal_opening_debit' => $excludedDebit, 'excluded_nominal_opening_credit' => $excludedCredit, 'net_revenue' => $netRevenue, 'gross_profit' => $gross, 'operating_result' => $operating, 'provisional_result' => $result, 'period_result' => $complete ? $result : null];
    }
}
