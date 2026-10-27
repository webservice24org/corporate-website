<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use App\Services\ERP\Accounting\Expenses\AccountingExpenseGuard;

final class AccountingExpenseCategory extends AccountingModel
{
    protected $table = 'accounting_expense_categories';

    protected $attributes = ['revision' => 1];

    protected function casts(): array
    {
        return ['revision' => 'integer'];
    }

    protected static function booted(): void
    {
        self::saving(static function (self $row): void {
            app(AccountingExpenseGuard::class)->category($row);
        });

        self::deleting(static function (self $row): void {
            throw new \LogicException('Expense categories are retained; deactivate them.');
        });
    }
}
