<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

final class AccountingMappingCatalog
{
    public function __construct(private readonly AccountingDomainRegistry $registry) {}

    /** @return array<string, string> */
    public function roles(): array
    {
        return $this->registry->mappingRoles();
    }

    public function cashRole(string $role): bool
    {
        return preg_match('/\A(?:cash|bank):[A-Z0-9][A-Z0-9_-]{0,29}\z/D', $role) === 1;
    }

    public function category(string $role): ?string
    {
        return $this->cashRole($role) ? 'asset' : ($this->roles()[$role] ?? null);
    }

    public function control(string $role): ?string
    {
        return in_array($role, ['inventory', 'dispatched_inventory', 'grni', 'trade_ap', 'trade_ar', 'unapplied_customer_funds', 'supplier_advances', 'supplier_return_clearing', 'retained_earnings'], true) ? $role : null;
    }

    /** @return list<string> */
    public function statements(string $role): array
    {
        return match ($role) {
            'inventory', 'dispatched_inventory', 'trade_ar', 'input_tax', 'supplier_advances', 'supplier_return_clearing' => ['current_asset'],
            'trade_ap', 'grni', 'output_tax', 'unapplied_customer_funds', 'payroll_payable' => ['current_liability'],
            'sales_revenue' => ['operating_revenue', 'other_revenue'],
            'sales_allowances' => ['sales_allowances'],
            'cogs' => ['cogs'],
            'inventory_loss', 'purchase_variance', 'rounding', 'general_expense', 'payroll_expense' => ['operating_expense', 'other_expense'],
            'retained_earnings' => ['retained_earnings'],
            default => $this->cashRole($role) ? ['current_asset'] : [],
        };
    }
}
