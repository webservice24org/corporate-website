<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingModel;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentModelGuard;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Native storage only; creation is not authorization or recognized financial posting. */
final class SupplierPayment extends AccountingModel
{
    protected $table = 'supplier_payments';

    protected $attributes = ['status' => 'draft', 'revision' => 1, 'advance_amount' => '0.00'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'advance_amount' => 'decimal:2', 'revision' => 'integer', 'document_date' => 'date:Y-m-d', 'supplier_snapshot' => 'array', 'issuer_snapshot' => 'array', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'posted_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::saving(static function (self $row): void {
            app(AccountingSupplierPaymentModelGuard::class)->header($row);
        });
        self::deleting(static function (self $row): void {
            throw new \LogicException('Payment headers cannot be deleted; cancel an eligible unposted document.');
        });
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<AccountingCashAccount, $this> */
    public function cash_account(): BelongsTo
    {
        return $this->belongsTo(AccountingCashAccount::class, 'cash_account_id');
    }

    /** @return HasMany<SupplierPaymentTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(SupplierPaymentTarget::class);
    }

    /** @return HasMany<SupplierPaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    /** @return BelongsTo<SupplierPayment, $this> */
    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_payment_id');
    }
}
