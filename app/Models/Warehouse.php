<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Warehouse extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'division_id',
        'district_id',
        'upazila_id',
        'code',
        'name',
        'type',
        'contact_person',
        'phone',
        'email',
        'address',
        'postal_code',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(
            Company::class
        );
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(
            Division::class
        );
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(
            District::class
        );
    }

    public function upazila(): BelongsTo
    {
        return $this->belongsTo(
            Upazila::class
        );
    }

    public function inventoryBalances(): HasMany
    {
        return $this->hasMany(
            InventoryBalance::class
        );
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(
            StockMovement::class
        );
    }

    public function openingStocks(): HasMany
    {
        return $this->hasMany(
            OpeningStock::class
        );
    }

    public function outgoingStockTransfers(): HasMany
    {
        return $this->hasMany(
            StockTransfer::class,
            'source_warehouse_id'
        );
    }

    public function incomingStockTransfers(): HasMany
    {
        return $this->hasMany(
            StockTransfer::class,
            'destination_warehouse_id'
        );
    }


    public function inventoryReorderSettings(): HasMany
    {
        return $this->hasMany(
            InventoryReorderSetting::class
        );
    }


}
