<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;


class Lead extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'lead_source_id',
        'lead_status_id',
        'owner_user_id',
        'lead_number',
        'lead_type',
        'company_name',
        'contact_name',
        'job_title',
        'email',
        'phone',
        'alternate_phone',
        'website',
        'expected_value',
        'currency',
        'next_follow_up_at',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country',
        'notes',
        'follow_up_notification_sent_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'expected_value' => 'decimal:2',

            'next_follow_up_at' =>
                'datetime',

            'follow_up_notification_sent_at' =>
                'datetime',

            'is_active' =>
                'boolean',
            
            'converted_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(
            Company::class
        );
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(
            LeadSource::class,
            'lead_source_id'
        );
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(
            LeadStatus::class,
            'lead_status_id'
        );
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'owner_user_id'
        );
    }

    public function scopeActive(
        Builder $query
    ): Builder {
        return $query->where(
            'is_active',
            true
        );
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(
            CrmActivity::class,
            'activityable'
        );
    }

    protected static function booted(): void
    {
        static::updating(
            function (Lead $lead) {
                if (
                    $lead->isDirty(
                        'next_follow_up_at'
                    )
                ) {
                    $lead->follow_up_notification_sent_at =
                        null;
                }

                if (
                    $lead->isDirty(
                        'owner_user_id'
                    ) &&
                    $lead->next_follow_up_at
                ) {
                    $lead->follow_up_notification_sent_at =
                        null;
                }
            }
        );
    }


    public function convertedTarget(): MorphTo
    {
        return $this->morphTo('converted_to', 'converted_to_type', 'converted_to_id');
    }

    public function converter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_by');
    }


}