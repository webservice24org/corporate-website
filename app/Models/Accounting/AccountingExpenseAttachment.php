<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use Illuminate\Support\Facades\DB;

final class AccountingExpenseAttachment extends AccountingImmutableModel
{
    protected $table = 'accounting_expense_attachments';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['uploaded_at' => 'datetime', 'size_bytes' => 'integer'];
    }

    protected static function booted(): void
    {
        parent::booted();

        self::creating(static function (self $row): void {
            if (DB::transactionLevel() < 1) {
                throw new \LogicException('Expense evidence needs its complete company transaction.');
            }
        });
    }
}
