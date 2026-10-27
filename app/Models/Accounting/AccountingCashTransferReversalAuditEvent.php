<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use Illuminate\Support\Facades\DB;

final class AccountingCashTransferReversalAuditEvent extends AccountingImmutableModel
{
    protected $table = 'accounting_cash_transfer_reversal_audit_events';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['before_payload' => 'array', 'after_payload' => 'array', 'occurred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        parent::booted();
        self::creating(static function (self $row): void {
            if (DB::transactionLevel() < 1 || ! in_array($row->getAttribute('action'), ['created', 'edited', 'submitted', 'approved', 'rejected', 'cancelled', 'posted'], true)) {
                throw new \LogicException('Reversal audit requires a native transaction and supported action.');
            }
        });
    }
}
