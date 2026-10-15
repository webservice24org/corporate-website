<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SalesQuotation extends Model
{
    protected $table = 'sales_quotations';

    // Internal foundation model. Later services whitelist request input explicitly.
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'document_date' => 'date:Y-m-d',
            'customer_snapshot' => 'array',
            'billing_address_snapshot' => 'array',
            'shipping_address_snapshot' => 'array',
            'terms_snapshot' => 'array',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'valid_until' => 'date:Y-m-d',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
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

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class, 'opportunity_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function approved_byActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesQuotationItem::class, 'sales_quotation_id');
    }
}
