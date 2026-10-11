<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Division extends Model
{
    protected $fillable = [
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

    public function districts(): HasMany
    {
        return $this->hasMany(District::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(
            Warehouse::class
        );
    }

}