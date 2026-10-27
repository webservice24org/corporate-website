<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingMappingVersion;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\AccountingSetting;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class AccountingDomainRegistry
{
    public const VERSION = '1.0.0';

    /** @return list<string> */
    public function permissions(): array
    {
        return [
            'erp.accounting-settings.view',
            'erp.accounting-settings.update',
            'erp.accounting-settings.activate',
            'erp.accounts.view',
            'erp.accounts.create',
            'erp.accounts.edit',
            'erp.accounts.deactivate',
            'erp.accounting-mappings.view',
            'erp.accounting-mappings.publish',
            'erp.fiscal-years.view',
            'erp.fiscal-years.manage',
            'erp.accounting-periods.view',
            'erp.accounting-periods.manage',
            'erp.accounting-periods.close',
            'erp.accounting-periods.reopen',
            'erp.accounting-periods.adjust',
            'erp.journal-entries.view',
            'erp.journal-entries.create',
            'erp.journal-entries.edit',
            'erp.journal-entries.submit',
            'erp.journal-entries.approve',
            'erp.journal-entries.post',
            'erp.journal-entries.reverse',
            'erp.accounting-openings.view',
            'erp.accounting-openings.manage',
            'erp.accounting-openings.approve',
            'erp.accounting-reports.view',
            'erp.accounting-reports.export',
            'erp.accounting-audit.view',
        ];
    }

    /** @return array<string, string> */
    public function mappingRoles(): array
    {
        return [
            'inventory' => 'asset',
            'dispatched_inventory' => 'asset',
            'grni' => 'liability',
            'trade_ap' => 'liability',
            'trade_ar' => 'asset',
            'sales_revenue' => 'revenue',
            'sales_allowances' => 'revenue',
            'cogs' => 'expense',
            'inventory_loss' => 'expense',
            'input_tax' => 'asset',
            'output_tax' => 'liability',
            'unapplied_customer_funds' => 'liability',
            'supplier_advances' => 'asset',
            'purchase_variance' => 'expense',
            'supplier_return_clearing' => 'asset',
            'rounding' => 'expense',
            'general_expense' => 'expense',
            'payroll_expense' => 'expense',
            'payroll_payable' => 'liability',
            'retained_earnings' => 'equity',
        ];
    }

    /** @return class-string<Model> */
    public function model(string $entity): string
    {
        return match ($entity) {
            'settings' => AccountingSetting::class,
            'fiscal_year' => FiscalYear::class,
            'period' => AccountingPeriod::class,
            'account_group' => AccountGroup::class,
            'account' => Account::class,
            'mapping' => AccountingMappingVersion::class,
            'journal' => JournalEntry::class,
            'cutover' => AccountingCutover::class,
            'opening' => JournalEntry::class,
            default => throw new InvalidArgumentException('Unknown accounting entity.'),
        };
    }

    public function permission(string $entity, string $action): string
    {
        $actions = [
            'settings' => ['configure' => 'erp.accounting-settings.update'],
            'fiscal_year' => ['manage' => 'erp.fiscal-years.manage'],
            'period' => ['manage' => 'erp.accounting-periods.manage', 'close' => 'erp.accounting-periods.close', 'reopen' => 'erp.accounting-periods.reopen', 'adjust' => 'erp.accounting-periods.adjust'],
            'account_group' => ['create' => 'erp.accounts.create', 'edit' => 'erp.accounts.edit'],
            'account' => ['create' => 'erp.accounts.create', 'edit' => 'erp.accounts.edit', 'deactivate' => 'erp.accounts.deactivate', 'reactivate' => 'erp.accounts.deactivate'],
            'mapping' => ['publish' => 'erp.accounting-mappings.publish'],
            'journal' => ['create' => 'erp.journal-entries.create', 'edit' => 'erp.journal-entries.edit', 'submit' => 'erp.journal-entries.submit', 'approve' => 'erp.journal-entries.approve', 'reject' => 'erp.journal-entries.approve', 'cancel' => 'erp.journal-entries.edit', 'post' => 'erp.journal-entries.post', 'reverse' => 'erp.journal-entries.reverse'],
            'opening' => ['create' => 'erp.accounting-openings.manage', 'edit' => 'erp.accounting-openings.manage', 'submit' => 'erp.accounting-openings.manage', 'reject' => 'erp.accounting-openings.manage', 'cancel' => 'erp.accounting-openings.manage', 'approve' => 'erp.accounting-openings.approve'],
            'cutover' => ['manage' => 'erp.accounting-openings.manage', 'approve' => 'erp.accounting-openings.approve', 'activate' => 'erp.accounting-settings.activate'],
        ];

        return $actions[$entity][$action] ?? throw new InvalidArgumentException('Unknown accounting action.');
    }
}
