<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

/** Stable, reviewable starting chart; not a statutory chart or a posting activation. */
final class AccountingStarterTemplate
{
    public const ID = 'general_v1';

    public const TITLE = 'General business starter chart v1';

    public function __construct(private readonly AccountingAccountCatalog $accounts, private readonly AccountingMappingCatalog $mappings) {}

    /** @return list<array<string, mixed>> */
    public function definitions(): array
    {
        $roots = ['asset' => '1000', 'liability' => '2000', 'equity' => '3000', 'revenue' => '4000', 'expense' => '5000'];
        $definitions = [];
        foreach ($this->accounts->groups() as $category => $group) {
            $definitions[] = ['code' => $roots[$category], 'name' => $group['name'], 'category' => $category, 'parent_code' => null, 'normal_side' => $group['normal_side'], 'is_postable' => false, 'control_role' => null, 'statement_role' => $this->accounts->statementRoles()[$category][0], 'cash_flow_role' => null, 'role' => null];
        }
        $roles = [
            'trade_ar' => ['1100', 'Trade Receivables'],
            'input_tax' => ['1110', 'Recoverable Input Tax'],
            'inventory' => ['1200', 'Warehouse Inventory'],
            'dispatched_inventory' => ['1210', 'Dispatched Inventory'],
            'supplier_advances' => ['1300', 'Supplier Advances'],
            'supplier_return_clearing' => ['1310', 'Supplier Return Clearing'],
            'grni' => ['2100', 'Goods Received Not Invoiced'],
            'trade_ap' => ['2200', 'Trade Payables'],
            'output_tax' => ['2300', 'Output Tax Payable'],
            'unapplied_customer_funds' => ['2400', 'Unapplied Customer Funds'],
            'payroll_payable' => ['2500', 'Payroll Payable'],
            'retained_earnings' => ['3100', 'Retained Earnings'],
            'sales_revenue' => ['4100', 'Sales Revenue'],
            'sales_allowances' => ['4200', 'Sales Returns and Allowances'],
            'cogs' => ['5100', 'Cost of Goods Sold'],
            'inventory_loss' => ['5200', 'Inventory Loss'],
            'purchase_variance' => ['5300', 'Purchase Variance'],
            'rounding' => ['5400', 'Rounding Differences'],
            'general_expense' => ['5500', 'General Operating Expenses'],
            'payroll_expense' => ['5600', 'Payroll Expense'],
        ];
        if (array_diff(array_keys($this->mappings->roles()), array_keys($roles)) !== [] || array_diff(array_keys($roles), array_keys($this->mappings->roles())) !== []) {
            throw new \LogicException('Review the starter template when posting roles change.');
        }
        foreach ($roles as $role => [$code, $name]) {
            $category = $this->mappings->roles()[$role];
            $definitions[] = ['code' => $code, 'name' => $name, 'category' => $category, 'parent_code' => $roots[$category], 'normal_side' => $role === 'sales_allowances' ? 'debit' : $this->accounts->groups()[$category]['normal_side'], 'is_postable' => true, 'control_role' => $this->mappings->control($role), 'statement_role' => $this->mappings->statements($role)[0], 'cash_flow_role' => null, 'role' => $role];
        }
        foreach (['cash:MAIN' => ['1010', 'Cash on Hand'], 'bank:CURRENT' => ['1020', 'Current Bank Account']] as $role => [$code, $name]) {
            $definitions[] = ['code' => $code, 'name' => $name, 'category' => 'asset', 'parent_code' => '1000', 'normal_side' => 'debit', 'is_postable' => true, 'control_role' => null, 'statement_role' => 'current_asset', 'cash_flow_role' => 'cash', 'role' => $role];
        }
        $definitions[] = ['code' => '3200', 'name' => 'Owner Capital', 'category' => 'equity', 'parent_code' => '3000', 'normal_side' => 'credit', 'is_postable' => true, 'control_role' => null, 'statement_role' => 'equity', 'cash_flow_role' => null, 'role' => null];

        return $definitions;
    }

    /**
     * Suggestions do not publish anything; publication still validates the full chart.
     *
     * @param  list<array<string, mixed>>  $accounts
     * @return array<string, int>
     */
    public function suggestions(array $accounts): array
    {
        $byCode = [];
        foreach ($accounts as $account) {
            $byCode[(string) $account['code']] = $account;
        }
        $result = [];
        foreach ($this->definitions() as $definition) {
            if ($definition['role'] === null) {
                continue;
            }
            $row = $byCode[$definition['code']] ?? null;
            if ($row === null || $row['status'] !== 'active' || ! $row['is_postable'] || $row['category'] !== $definition['category']) {
                continue;
            }
            foreach (['normal_side', 'control_role', 'statement_role', 'cash_flow_role'] as $field) {
                if ($row[$field] !== $definition[$field]) {
                    continue 2;
                }
            }
            $result[$definition['role']] = (int) $row['id'];
        }

        return $result;
    }
}
