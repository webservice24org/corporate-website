<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes;

    protected $fillable = ['company_id', 'customer_code', 'customer_type', 'name', 'business_name', 'contact_person', 'phone', 'alternate_phone', 'email', 'nid_number', 'tin_number', 'vat_number', 'credit_limit', 'payment_terms_days', 'is_active', 'notes'];

    protected function casts(): array
    {
        return ['credit_limit' => 'decimal:2', 'payment_terms_days' => 'integer', 'is_active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }
}
