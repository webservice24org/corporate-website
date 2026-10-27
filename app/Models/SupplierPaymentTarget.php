<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Accounting\AccountingModel;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentModelGuard;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SupplierPaymentTarget extends AccountingModel
{
    protected $table = 'supplier_payment_targets';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        self::saving(static function (self $row): void {
            app(AccountingSupplierPaymentModelGuard::class)->target($row);
        });
        self::deleting(static function (self $row): void {
            app(AccountingSupplierPaymentModelGuard::class)->target($row, true);
        });
    }

    /** @return BelongsTo<SupplierPayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class, 'supplier_payment_id');
    }

    /** @return BelongsTo<SupplierBill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class, 'supplier_bill_id');
    }
}
