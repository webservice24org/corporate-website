<?php

declare(strict_types=1);

namespace App\Models\Accounting;

final class AccountingSupplierCredit extends AccountingModel
{
    protected $table = 'accounting_supplier_credits';

    protected function casts(): array
    {
        return ['document_date' => 'date:Y-m-d', 'amount' => 'decimal:2', 'approved_at' => 'datetime', 'issued_at' => 'datetime', 'revision' => 'integer'];
    }

    protected static function booted(): void
    {
        self::updating(static function (self $credit): void {
            if ($credit->getRawOriginal('status') === 'issued') {
                throw new \LogicException('Issued supplier credits are immutable.');
            }
        });
        self::deleting(static function (self $credit): void {
            throw new \LogicException('Supplier credit history cannot be deleted.');
        });
    }
}
