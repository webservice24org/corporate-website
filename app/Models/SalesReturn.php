<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SalesReturn extends Model
{
    protected $table = 'sales_returns';

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
            'inspected_at' => 'datetime',
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

    public function sales_delivery(): BelongsTo
    {
        return $this->belongsTo(SalesDelivery::class, 'sales_delivery_id');
    }

    public function inspected_byActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    public function posted_byActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class, 'sales_return_id');
    }
}
