<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadStatus extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'code',
        'probability',
        'is_won',
        'is_lost',
        'description',
        'sort_order',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'probability' => 'integer',
            'is_won' => 'boolean',
            'is_lost' => 'boolean',
            'sort_order' => 'integer',
            'status' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(
            Company::class
        );
    }

    public function leads(): HasMany
    {
        return $this->hasMany(
            Lead::class
        );
    }
    
}