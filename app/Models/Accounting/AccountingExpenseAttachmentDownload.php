<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use Illuminate\Support\Facades\DB;

final class AccountingExpenseAttachmentDownload extends AccountingImmutableModel
{
    protected $table = 'accounting_expense_attachment_downloads';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'attachment_revision' => 'integer', 'size_bytes' => 'integer'];
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
