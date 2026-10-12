<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryBalance extends Model
{
    protected $fillable = [
        'company_id',
        'warehouse_id',
        'product_id',
        'product_variant_id',
        'variant_key',
        'quantity_on_hand',
        'quantity_reserved',
        'average_cost',
        'last_movement_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'decimal:4',
            'quantity_reserved' => 'decimal:4',
            'average_cost' => 'decimal:4',
            'last_movement_at' => 'datetime',
            'variant_key' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(
            Company::class
        );
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(
            Warehouse::class
        );
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(
            Product::class
        );
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(
            ProductVariant::class,
            'product_variant_id'
        );
    }

    public function getQuantityAvailableAttribute(): string
    {
        return bcsub(
            (string) $this->quantity_on_hand,
            (string) $this->quantity_reserved,
            4
        );
    }
}
