<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use App\Services\ERP\Accounting\Transfers\AccountingCashTransferReversalModelGuard;

/** Native reversal intent and immutable posted metadata. The original transfer remains unchanged. */
final class AccountingCashTransferReversalRequest extends AccountingModel
{
    protected $table = 'accounting_cash_transfer_reversal_requests';

    protected $attributes = ['status' => 'draft', 'revision' => 1];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'amount' => 'decimal:2', 'document_date' => 'date:Y-m-d', 'original_snapshot' => 'array', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime', 'posted_at' => 'datetime', 'posting_revision' => 'integer'];
    }

    protected static function booted(): void
    {
        self::saving(static function (self $row): void {
            app(AccountingCashTransferReversalModelGuard::class)->check($row);
        });
        self::deleting(static function (self $row): void {
            throw new \LogicException('Reversal requests cannot be deleted; cancel an eligible unposted request.');
        });
    }
}
