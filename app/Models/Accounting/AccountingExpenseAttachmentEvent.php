<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use Illuminate\Support\Facades\DB;

final class AccountingExpenseAttachmentEvent extends AccountingImmutableModel
{
    protected $table = 'accounting_expense_attachment_events';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'sequence' => 'integer', 'payload' => 'array'];
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
