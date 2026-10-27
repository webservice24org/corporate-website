<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

final class AccountingAccountCatalog
{
    /** @return array<string, array{code: string, name: string, normal_side: string}> */
    public function groups(): array
    {
        return [
            'asset' => ['code' => 'ASSET', 'name' => 'Assets', 'normal_side' => 'debit'],
            'liability' => ['code' => 'LIABILITY', 'name' => 'Liabilities', 'normal_side' => 'credit'],
            'equity' => ['code' => 'EQUITY', 'name' => 'Equity', 'normal_side' => 'credit'],
            'revenue' => ['code' => 'REVENUE', 'name' => 'Revenue', 'normal_side' => 'credit'],
            'expense' => ['code' => 'EXPENSE', 'name' => 'Expenses', 'normal_side' => 'debit'],
        ];
    }

    /** @return array<string, list<string>> */
    public function statementRoles(): array
    {
        return ['asset' => ['current_asset', 'non_current_asset'], 'liability' => ['current_liability', 'non_current_liability'], 'equity' => ['equity', 'retained_earnings'], 'revenue' => ['operating_revenue', 'other_revenue', 'sales_allowances'], 'expense' => ['cogs', 'operating_expense', 'other_expense']];
    }

    /** @return array<string, string> */
    public function controlRoles(): array
    {
        return ['inventory' => 'asset', 'dispatched_inventory' => 'asset', 'trade_ar' => 'asset', 'trade_ap' => 'liability', 'unapplied_customer_funds' => 'liability', 'supplier_advances' => 'asset', 'grni' => 'liability', 'supplier_return_clearing' => 'asset', 'retained_earnings' => 'equity'];
    }
}
