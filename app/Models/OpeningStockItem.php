<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpeningStockItem extends Model
{
    protected $fillable = [
        'opening_stock_id',
        'product_id',
        'product_variant_id',
        'quantity',
        'unit_cost',
        'total_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',

            'unit_cost' => 'decimal:4',

            'total_cost' => 'decimal:4',
        ];
    }

    public function openingStock(): BelongsTo
    {
        return $this->belongsTo(
            OpeningStock::class
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
}
