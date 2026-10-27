<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use Illuminate\Support\Facades\DB;

final class AccountingBankReconciliationLeg extends AccountingImmutableModel
{
    protected $table = 'accounting_bank_reconciliation_legs';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        parent::booted();
        self::creating(static function (self $row): void {
            if (DB::transactionLevel() < 1) {
                throw new \LogicException('Reconciliation evidence requires its company transaction.');
            }
        });
    }
}
