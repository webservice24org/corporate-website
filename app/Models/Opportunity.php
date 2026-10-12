<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Opportunity extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'opportunity_number',
        'lead_id',
        'party_type',
        'party_id',
        'opportunity_stage_id',
        'salesperson_user_id',
        'title',
        'description',
        'expected_value',
        'currency',
        'probability',
        'expected_close_date',
        'won_at',
        'lost_at',
        'lost_reason',
        'is_active',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'expected_value' => 'decimal:2',
        'probability' => 'integer',
        'expected_close_date' => 'date',
        'won_at' => 'datetime',
        'lost_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(
            OpportunityStage::class,
            'opportunity_stage_id'
        );
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'salesperson_user_id'
        );
    }

    public function party(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(
            CrmActivity::class,
            'activityable'
        )->latest();
    }
}
