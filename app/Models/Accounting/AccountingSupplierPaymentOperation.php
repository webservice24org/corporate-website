<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentModelGuard;

final class AccountingSupplierPaymentOperation extends AccountingImmutableModel
{
    protected $table = 'accounting_supplier_payment_operations';

    protected function casts(): array
    {
        return ['members' => 'array'];
    }

    protected static function booted(): void
    {
        parent::booted();
        self::creating(static function (self $row): void {
            app(AccountingSupplierPaymentModelGuard::class)->operation($row);
        });
    }
}
