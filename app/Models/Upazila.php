<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Upazila extends Model
{
    protected $fillable = [
        'district_id',
        'name',
        'name_bn',
        'code',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function dealers()
    {
        return $this->hasMany(
            Dealer::class
        );
    }

    public function dealerApplications()
    {
        return $this->hasMany(
            DealerApplication::class
        );
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(
            Warehouse::class
        );
    }



}