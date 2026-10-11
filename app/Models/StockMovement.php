<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    public const TYPE_OPENING_STOCK =
        'opening_stock';

    public const TYPE_PURCHASE_RECEIPT =
        'purchase_receipt';

    public const TYPE_SALE =
        'sale';

    public const TYPE_SALE_RETURN =
        'sale_return';

    public const TYPE_PURCHASE_RETURN =
        'purchase_return';

    public const TYPE_ADJUSTMENT_IN =
        'adjustment_in';

    public const TYPE_ADJUSTMENT_OUT =
        'adjustment_out';

    public const TYPE_TRANSFER_IN =
        'transfer_in';

    public const TYPE_TRANSFER_OUT =
        'transfer_out';

    protected $fillable = [
        'company_id',
        'warehouse_id',
        'product_id',
        'product_variant_id',
        'movement_type',
        'direction',
        'quantity',
        'unit_cost',
        'total_cost',
        'balance_before',
        'balance_after',
        'reference_type',
        'reference_id',
        'reference_number',
        'occurred_at',
        'created_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:4',
            'balance_before' => 'decimal:4',
            'balance_after' => 'decimal:4',
            'occurred_at' => 'datetime',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    public static function movementTypes(): array
    {
        return [
            self::TYPE_OPENING_STOCK,
            self::TYPE_PURCHASE_RECEIPT,
            self::TYPE_SALE,
            self::TYPE_SALE_RETURN,
            self::TYPE_PURCHASE_RETURN,
            self::TYPE_ADJUSTMENT_IN,
            self::TYPE_ADJUSTMENT_OUT,
            self::TYPE_TRANSFER_IN,
            self::TYPE_TRANSFER_OUT,
        ];
    }

    public static function directions(): array
    {
        return [
            self::DIRECTION_IN,
            self::DIRECTION_OUT,
        ];
    }
}