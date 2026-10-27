<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use Illuminate\Support\Facades\DB;

final class AccountingExpenseEvent extends AccountingImmutableModel
{
    protected $table = 'accounting_expense_events';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'array', 'revision' => 'integer', 'occurred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        parent::booted();

        self::creating(static function (self $row): void {
            if (DB::transactionLevel() < 1 || (($row->getAttribute('expense_id') !== null) === ($row->getAttribute('category_id') !== null))) {
                throw new \LogicException('Expense audit belongs to exactly one entity in its native transaction.');
            }
        });
    }
}
