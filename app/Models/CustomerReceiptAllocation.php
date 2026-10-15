<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CustomerReceiptAllocation extends Model
{
    protected $table = 'customer_receipt_allocations';

    // Internal foundation model. Later services whitelist request input explicitly.
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(static function (self $allocation): void {
            throw new \LogicException('Receipt allocations are append-only; create a compensating reversal.');
        });
        self::deleting(static function (self $allocation): void {
            throw new \LogicException('Receipt allocation history cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'posted_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function customer_receipt(): BelongsTo
    {
        return $this->belongsTo(CustomerReceipt::class, 'customer_receipt_id');
    }

    public function sales_invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function reverses_allocation(): BelongsTo
    {
        return $this->belongsTo(CustomerReceiptAllocation::class, 'reverses_allocation_id');
    }

    public function posted_byActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
