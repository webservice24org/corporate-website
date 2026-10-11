<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'branch_id',
        'name',
        'code',
        'description',
        'status',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(
            Company::class
        );
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(
            Branch::class
        );
    }

    public function scopeActive(
        Builder $query
    ): Builder {
        return $query->where(
            'status',
            'active'
        );
    }

    public function designations(): HasMany
    {
        return $this->hasMany(
            Designation::class
        );
    }


    public function employees(): HasMany
    {
        return $this->hasMany(
            Employee::class
        );
    }



}