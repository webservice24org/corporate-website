<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SalesStockReservation extends Model
{
    protected $table = 'sales_stock_reservations';

    // Internal foundation model. Later services whitelist request input explicitly.
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'variant_key' => 'integer',
            'allocated_quantity' => 'decimal:4',
            'released_quantity' => 'decimal:4',
            'consumed_quantity' => 'decimal:4',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function sales_order_item(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class, 'sales_order_item_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function product_variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
