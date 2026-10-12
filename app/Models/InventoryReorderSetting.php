<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryReorderSetting extends Model
{
    protected $fillable = [
        'company_id',
        'warehouse_id',
        'product_id',
        'product_variant_id',
        'variant_key',
        'reorder_level',
        'minimum_stock',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'warehouse_id' => 'integer',
            'product_id' => 'integer',
            'product_variant_id' => 'integer',
            'variant_key' => 'integer',
            'reorder_level' => 'decimal:4',
            'minimum_stock' => 'decimal:4',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(
            ProductVariant::class,
            'product_variant_id'
        );
    }
}
