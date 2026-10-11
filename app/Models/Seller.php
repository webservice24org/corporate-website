<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Seller extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'dealer_id',
        'seller_code',

        'business_name',
        'proprietor_name',
        'seller_type',

        'phone',
        'alternate_phone',
        'email',

        'trade_license_number',
        'nid_number',
        'tin_number',
        'vat_number',

        'business_address',
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

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'manager_user_id'
        );
    }
}