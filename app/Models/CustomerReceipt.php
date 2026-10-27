<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CustomerReceipt extends Model
{
    protected $table = 'customer_receipts';

    // Internal foundation model. Later services whitelist request input explicitly.
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(static function (self $receipt): void {
            if ($receipt->getRawOriginal('status') === 'posted' && $receipt->isDirty('cash_account_id')) {
                throw new \LogicException('A posted receipt cannot change its original finance cash account.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'document_date' => 'date:Y-m-d',
            'customer_snapshot' => 'array',
            'billing_address_snapshot' => 'array',
            'shipping_address_snapshot' => 'array',
            'terms_snapshot' => 'array',
            'amount' => 'decimal:2',
            'posted_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function created_byActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updated_byActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function posted_byActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reverses_receipt(): BelongsTo
    {
        return $this->belongsTo(CustomerReceipt::class, 'reverses_receipt_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CustomerReceiptAllocation::class, 'customer_receipt_id');
    }
}
