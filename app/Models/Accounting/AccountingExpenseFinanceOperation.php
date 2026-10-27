<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use Illuminate\Support\Facades\DB;

final class AccountingExpenseFinanceOperation extends AccountingImmutableModel
{
    protected $table = 'accounting_expense_finance_operations';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    protected static function booted(): void
    {
        parent::booted();

        self::creating(static function (self $row): void {
            if (DB::transactionLevel() < 1) {
                throw new \LogicException('Expense finance requires its company transaction.');
            }
        });
    }
}
