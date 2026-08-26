<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GlobalSection extends Model
{
    protected $fillable = [
        'type',
        'variant',
        'settings',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }
}