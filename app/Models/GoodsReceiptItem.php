<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class GoodsReceiptItem extends Model
{
    protected $table = 'goods_receipt_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity_received' => 'decimal:4',
            'quantity_rejected' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'is_stock_item' => 'boolean',
        ];
    }

    public function goods_receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function purchase_order_item(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }

    public function stock_movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }
}
