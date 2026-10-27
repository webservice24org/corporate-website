<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use Illuminate\Support\Facades\DB;

final class AccountingBankFinalizationEvent extends AccountingImmutableModel
{
    protected $table = 'accounting_bank_finalization_events';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'request_revision' => 'integer', 'payload' => 'array', 'occurred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        parent::booted();
        self::creating(static function (self $row): void {
            if (DB::transactionLevel() < 1) {
                throw new \LogicException('Finalization evidence requires the complete company transaction.');
            }
        });
    }
}
