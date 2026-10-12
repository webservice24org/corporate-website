<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DepotApplication extends Model
{
    protected $fillable = [
        'company_id',
        'division_id',
        'district_id',
        'depot_id',
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
            'years_in_business' => 'integer',

            'requested_credit_limit' => 'decimal:2',

            'reviewed_at' => 'datetime',

            'approved_at' => 'datetime',

            'rejected_at' => 'datetime',
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

    public function depot(): BelongsTo
    {
        return $this->belongsTo(
            Depot::class
        );
    }

    public function documents(): HasMany
    {
        return $this->hasMany(
            DepotApplicationDocument::class
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
