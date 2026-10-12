<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Depot extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'division_id',
        'district_id',
        'depot_code',
        'name',
        'proprietor_name',
        'phone',
        'alternate_phone',
        'email',
        'trade_license_number',
        'nid_number',
        'tin_number',
        'vat_number',
        'address',
        'postal_code',
        'credit_limit',
        'payment_terms_days',
        'manager_user_id',
        'is_active',
        'proprietor_photo',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'payment_terms_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'manager_user_id'
        );
    }

    public function applications(): HasMany
    {
        return $this->hasMany(
            DepotApplication::class
        );
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

    public function documents(): HasMany
    {
        return $this->hasMany(DepotDocument::class);
    }

    public function currentDocuments(): HasMany
    {
        return $this->hasMany(DepotDocument::class)
            ->where('is_current', true);
    }
}
