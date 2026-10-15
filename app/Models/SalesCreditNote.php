<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SalesCreditNote extends Model
{
    protected $table = 'sales_credit_notes';

    // Internal foundation model. Later services whitelist request input explicitly.
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        $guard = static function (self $note): void {
            if ($note->getRawOriginal('status') === 'issued' && $note->getRawOriginal('payload_hash') !== null) {
                throw new \LogicException('Issued credit notes are immutable; use a reviewed compensating workflow.');
            }
        };
        self::updating($guard);
        self::deleting($guard);
    }

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'issue_sequence' => 'integer',
            'document_date' => 'date:Y-m-d',
            'customer_snapshot' => 'array',
            'billing_address_snapshot' => 'array',
            'shipping_address_snapshot' => 'array',
            'terms_snapshot' => 'array',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'issued_at' => 'datetime',
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

    public function sales_invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function sales_return(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'sales_return_id');
    }

    public function issued_byActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesCreditNoteItem::class, 'sales_credit_note_id');
    }
}
