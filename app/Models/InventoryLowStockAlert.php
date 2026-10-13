<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryLowStockAlert extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'company_id',
        'warehouse_id',
        'product_id',
        'product_variant_id',
        'variant_key',
        'status',
        'severity',
        'quantity_available',
        'minimum_stock',
        'reorder_level',
        'suggested_reorder_quantity',
        'first_detected_at',
        'last_detected_at',
        'resolved_at',
        'occurrence_count',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'warehouse_id' => 'integer',
            'product_id' => 'integer',
            'product_variant_id' => 'integer',
            'variant_key' => 'integer',
            'quantity_available' => 'decimal:4',
            'minimum_stock' => 'decimal:4',
            'reorder_level' => 'decimal:4',
            'suggested_reorder_quantity' => 'decimal:4',
            'first_detected_at' => 'datetime',
            'last_detected_at' => 'datetime',
            'resolved_at' => 'datetime',
            'occurrence_count' => 'integer',
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
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
