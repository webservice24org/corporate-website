<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use Illuminate\Support\Facades\DB;

final class AccountingCashTransferAuditEvent extends AccountingImmutableModel
{
    protected $table = 'accounting_cash_transfer_audit_events';

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
                throw new \LogicException('Transfer audit events require an authorized native transaction and supported action.');
            }
        });
    }
}
