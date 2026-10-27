<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use App\Services\ERP\Accounting\Adjustments\AccountingBankAdjustmentGuard;

final class AccountingBankAdjustment extends AccountingModel
{
    protected $table = 'accounting_bank_adjustments';

    protected $attributes = ['status' => 'draft', 'revision' => 1];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'posting_revision' => 'integer', 'amount' => 'decimal:2', 'document_date' => 'date:Y-m-d',
            'source_snapshot' => 'array', 'binding_snapshot' => 'array', 'counter_snapshot' => 'array',
            'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'posted_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::saving(static function (self $row): void {
            app(AccountingBankAdjustmentGuard::class)->check($row);
        });
        self::deleting(static function (self $row): void {
            throw new \LogicException('Bank adjustments cannot be deleted; cancel an eligible unposted draft.');
        });
    }
}
