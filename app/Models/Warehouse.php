<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

}