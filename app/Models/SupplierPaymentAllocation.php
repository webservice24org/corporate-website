<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Accounting\AccountingImmutableModel;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentModelGuard;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SupplierPaymentAllocation extends AccountingImmutableModel
{
    protected $table = 'supplier_payment_allocations';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'posted_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        parent::booted();
        self::creating(static function (self $row): void {
            app(AccountingSupplierPaymentModelGuard::class)->allocation($row);
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

    /** @return BelongsTo<SupplierPaymentAllocation, $this> */
    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_allocation_id');
    }
}
