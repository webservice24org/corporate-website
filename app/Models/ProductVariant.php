<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id',
        'name',
        'sku',
        'barcode',
        'attributes',
        'purchase_price',
        'selling_price',
        'is_active',
    ];

    protected $casts = [
        'attributes' => 'array',
        'purchase_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inventoryBalances(): HasMany
    {
        return $this->hasMany(
            InventoryBalance::class,
            'product_variant_id'
        );
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(
            StockMovement::class,
            'product_variant_id'
        );
    }

    public function openingStockItems(): HasMany
    {
        return $this->hasMany(
            OpeningStockItem::class,
            'product_variant_id'
        );
    }


    public function inventoryReorderSettings(): HasMany
    {
        return $this->hasMany(
            InventoryReorderSetting::class,
            'product_variant_id'
        );
    }


}
