<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpportunityStage extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'code',
        'probability',
        'sort_order',
        'is_won',
        'is_lost',
        'is_active',
    ];

    protected $casts = [
        'probability' => 'integer',
        'sort_order' => 'integer',
        'is_won' => 'boolean',
        'is_lost' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }
}
