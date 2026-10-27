<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use App\Services\ERP\Accounting\Transfers\AccountingCashTransferModelGuard;

/** Native intent and immutable posted transfer identity; financial evidence belongs to its operation. */
final class AccountingCashTransfer extends AccountingModel
{
    protected $table = 'accounting_cash_transfers';

    protected $attributes = ['status' => 'draft', 'revision' => 1];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2', 'revision' => 'integer', 'document_date' => 'date:Y-m-d',
            'source_snapshot' => 'array', 'destination_snapshot' => 'array',
            'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime', 'posted_at' => 'datetime', 'posting_revision' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::saving(static function (self $row): void {
            app(AccountingCashTransferModelGuard::class)->check($row);
        });
        self::deleting(static function (self $row): void {
            throw new \LogicException('Transfers cannot be deleted; cancel an eligible unposted document.');
        });
    }
}
