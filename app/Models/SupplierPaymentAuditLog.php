<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Accounting\AccountingImmutableModel;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentModelGuard;

final class SupplierPaymentAuditLog extends AccountingImmutableModel
{
    protected $table = 'supplier_payment_audit_logs';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        parent::booted();
        self::creating(static function (self $row): void {
            app(AccountingSupplierPaymentModelGuard::class)->audit($row);
        });
    }
}
