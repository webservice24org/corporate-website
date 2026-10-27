<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use Illuminate\Support\Facades\DB;

final class AccountingBankAdjustmentOperation extends AccountingImmutableModel
{
    protected $table = 'accounting_bank_adjustment_operations';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'array', 'request_revision' => 'integer', 'occurred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        parent::booted();
        self::creating(static function (self $row): void {
            if (DB::transactionLevel() < 1) {
                throw new \LogicException('Bank adjustment history requires its native transaction.');
            }
        });
    }
}
