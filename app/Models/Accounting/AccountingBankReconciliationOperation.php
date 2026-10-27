<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use Illuminate\Support\Facades\DB;

final class AccountingBankReconciliationOperation extends AccountingImmutableModel
{
    protected $table = 'accounting_bank_reconciliation_operations';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'array', 'revision_from' => 'integer', 'total' => 'decimal:2', 'occurred_at' => 'datetime'];
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
