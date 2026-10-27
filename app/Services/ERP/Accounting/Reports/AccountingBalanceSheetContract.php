<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reports;

use App\Services\ERP\Accounting\AccountingAccountCatalog;

/** Posted equity plus only the residual nominal GL balance. No synthetic journal or duplicate earnings. */
final class AccountingBalanceSheetContract
{
    public function __construct(private readonly AccountingAccountCatalog $catalog) {}

    /** @param list<array<string, mixed>> $accounts
     * @param  array<int, array{debit: string, credit: string}>  $openingMovements
     * @param  array<string, mixed>|null  $fiscalYear
     * @return array<string, mixed>
     */
    public function project(array $accounts, array $openingMovements, ?array $fiscalYear): array
    {
        $labels = ['current_asset' => 'Current assets', 'non_current_asset' => 'Non-current assets', 'current_liability' => 'Current liabilities', 'non_current_liability' => 'Non-current liabilities', 'equity' => 'Posted equity', 'retained_earnings' => 'Posted retained earnings'];
        $sections = [];
        foreach ($labels as $role => $label) {
            $sections[$role] = ['role' => $role, 'label' => $label, 'amount' => '0.00', 'accounts' => []];
        }
        $issues = [];
        $warnings = [];
        $roles = $this->catalog->statementRoles();
        $nominal = '0.00';
        $prior = '0.00';
        $nominalOpening = '0.00';
        $current = '0.00';
        if ($fiscalYear === null) {
            $issues[] = ['issue' => 'fiscal_year_missing_or_ambiguous'];
        }
        if ($fiscalYear !== null && ! in_array($fiscalYear['state'] ?? '', ['open', 'closed'], true)) {
            $issues[] = ['issue' => 'fiscal_year_state_unsupported'];
        }
        foreach ($accounts as $account) {
            $id = (int) $account['id'];
            $category = (string) $account['category'];
            $role = (string) $account['statement_role'];
            $base = ['account_id' => $id, 'code' => (string) $account['code'], 'name' => (string) $account['name']];
            $debit = (string) $account['closing_debit'];
            $credit = (string) $account['closing_credit'];
            $valid = isset($roles[$category]) && in_array($role, $roles[$category], true);
            if (! $valid) {
                $issues[] = [...$base, 'issue' => 'classification_missing_or_inconsistent', 'category' => $category, 'statement_role' => $role, 'debit' => $debit, 'credit' => $credit];
            }
            if (! (bool) $account['is_postable'] && (bccomp($debit, '0', 2) !== 0 || bccomp($credit, '0', 2) !== 0 || bccomp((string) $account['period_debit'], '0', 2) !== 0 || bccomp((string) $account['period_credit'], '0', 2) !== 0)) {
                $issues[] = [...$base, 'issue' => 'nonpostable_account_has_balance_or_activity', 'debit' => $debit, 'credit' => $credit];
            }
            if (in_array($category, ['revenue', 'expense'], true)) {
                $opening = $openingMovements[$id] ?? ['debit' => '0.00', 'credit' => '0.00'];
                $net = bcsub($credit, $debit, 2);
                $old = bcsub((string) $account['opening_credit'], (string) $account['opening_debit'], 2);
                $seed = bcsub($opening['credit'], $opening['debit'], 2);
                $movement = bcsub(bcsub((string) $account['period_credit'], (string) $account['period_debit'], 2), $seed, 2);
                $nominal = bcadd($nominal, $net, 2);
                $prior = bcadd($prior, $old, 2);
                $nominalOpening = bcadd($nominalOpening, $seed, 2);
                $current = bcadd($current, $movement, 2);
                if ($fiscalYear !== null && (bccomp($opening['debit'], '0', 2) !== 0 || bccomp($opening['credit'], '0', 2) !== 0)) {
                    $issues[] = [...$base, 'issue' => 'nominal_opening_requires_cutover_review', 'debit' => $opening['debit'], 'credit' => $opening['credit']];
                }
                if ($fiscalYear !== null && bccomp($old, '0', 2) !== 0) {
                    $issues[] = [...$base, 'issue' => 'prior_year_nominal_balance_unclosed', 'amount' => $old];
                }

                continue;
            }
            if (! $valid) {
                continue;
            }
            $amount = $category === 'asset' ? bcsub($debit, $credit, 2) : bcsub($credit, $debit, 2);
            if (bccomp($amount, '0', 2) < 0) {
                $warnings[] = [...$base, 'issue' => 'net_contra_balance', 'amount' => $amount];
            }
            $sections[$role]['accounts'][] = [...$base, 'status' => (string) $account['status'], 'debit' => $debit, 'credit' => $credit, 'amount' => $amount];
            $sections[$role]['amount'] = bcadd($sections[$role]['amount'], $amount, 2);
        }
        $assets = bcadd($sections['current_asset']['amount'], $sections['non_current_asset']['amount'], 2);
        $liabilities = bcadd($sections['current_liability']['amount'], $sections['non_current_liability']['amount'], 2);
        $postedEquity = bcadd($sections['equity']['amount'], $sections['retained_earnings']['amount'], 2);
        $equity = bcadd($postedEquity, $nominal, 2);
        $right = bcadd($liabilities, $equity, 2);
        $difference = bcsub($assets, $right, 2);
        $bridgeDifference = bcsub($nominal, bcadd(bcadd($prior, $nominalOpening, 2), $current, 2), 2);
        if (bccomp($bridgeDifference, '0', 2) !== 0) {
            $issues[] = ['issue' => 'earnings_bridge_does_not_reconcile', 'amount' => $bridgeDifference];
        }
        if (bccomp($difference, '0', 2) !== 0) {
            $issues[] = ['issue' => 'balance_sheet_equation_difference', 'amount' => $difference];
        }
        if ($fiscalYear !== null && ($fiscalYear['state'] ?? null) === 'closed' && bccomp($nominal, '0', 2) !== 0) {
            $issues[] = ['issue' => 'closed_year_has_nominal_residual', 'amount' => $nominal];
        }
        $complete = $issues === [];
        $totals = ['assets' => $assets, 'liabilities' => $liabilities, 'posted_equity' => $postedEquity, 'equity_with_earnings' => $equity, 'liabilities_and_equity' => $right];

        return ['sections' => array_values($sections), 'totals' => $totals, 'confirmed_totals' => $complete ? $totals : null, 'complete' => $complete, 'equation_balanced' => bccomp($difference, '0', 2) === 0, 'difference' => $difference, 'issues' => $issues, 'warnings' => $warnings, 'earnings' => ['residual_total' => $nominal, 'prior_year_unclosed' => $fiscalYear === null ? null : $prior, 'current_year_nominal_opening' => $fiscalYear === null ? null : $nominalOpening, 'current_year_unclosed_result' => $fiscalYear === null ? null : $current, 'bridge_difference' => $bridgeDifference]];
    }
}
