<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use App\Services\ERP\Accounting\Expenses\AccountingExpenseGuard;

final class AccountingExpense extends AccountingModel
{
    protected $table = 'accounting_expenses';

    protected $attributes = ['status' => 'draft', 'revision' => 1];

    protected function casts(): array
    {
        return ['document_date' => 'date:Y-m-d', 'accounting_date' => 'date:Y-m-d', 'revision' => 'integer', 'category_snapshot' => 'array', 'payee_snapshot' => 'array', 'cash_snapshot' => 'array', 'tax_snapshot' => 'array', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::saving(static function (self $row): void {
            app(AccountingExpenseGuard::class)->expense($row);
        });

        self::deleting(static function (self $row): void {
            throw new \LogicException('Expense documents are retained; cancel an eligible unposted document.');
        });
    }
}
