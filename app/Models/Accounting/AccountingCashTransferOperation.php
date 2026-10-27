<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use Illuminate\Support\Facades\DB;

/** Append-only record of the complete native transfer and its single financial effect. */
final class AccountingCashTransferOperation extends AccountingImmutableModel
{
    protected $table = 'accounting_cash_transfer_operations';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'array', 'request_revision' => 'integer', 'occurred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        parent::booted();
        self::creating(static function (self $row): void {
            if (DB::transactionLevel() < 1 || $row->getAttribute('contract_version') !== 'cash-transfer-post.v1') {
                throw new \LogicException('Transfer operation requires its native posting transaction and supported contract.');
            }
        });
    }
}
