<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class DealerApplication extends Model
{
    protected $fillable = [
        'public_token',

        'company_id',
        'depot_id',
        'upazila_id',
        'dealer_id',

        'business_name',
        'proprietor_name',

        'phone',
        'alternate_phone',
        'email',

        'trade_license_number',
        'nid_number',
        'tin_number',
        'vat_number',

        'business_address',
        'postal_code',

        'years_in_business',
        'requested_credit_limit',

        'business_description',

        'status',

        'reviewed_by_user_id',
        'reviewed_at',

        'approved_by_user_id',
        'approved_at',

        'rejected_by_user_id',
        'rejected_at',

        'admin_notes',
        'proprietor_photo',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',

            'requested_credit_limit' =>
                'decimal:2',

            'years_in_business' =>
                'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(
            function (DealerApplication $application) {
                if (
                    !$application->public_token
                ) {
                    $application->public_token =
                        (string) Str::uuid();
                }
            }
        );
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(
            Company::class
        );
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(
            Depot::class
        );
    }

    public function upazila(): BelongsTo
    {
        return $this->belongsTo(
            Upazila::class
        );
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(
            Dealer::class
        );
    }

    public function documents(): HasMany
    {
        return $this->hasMany(
            DealerApplicationDocument::class
        );
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'reviewed_by_user_id'
        );
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'approved_by_user_id'
        );
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'rejected_by_user_id'
        );
    }
}