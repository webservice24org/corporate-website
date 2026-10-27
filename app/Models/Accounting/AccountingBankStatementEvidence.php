<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use Illuminate\Support\Facades\DB;

final class AccountingBankStatementEvidence extends AccountingImmutableModel
{
    protected $table = 'accounting_bank_statement_evidence';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'array', 'recorded_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        parent::booted();
        self::creating(static function (self $row): void {
            if (DB::transactionLevel() < 1) {
                throw new \LogicException('Bank statement evidence requires a complete import transaction.');
            }
        });
    }
}
