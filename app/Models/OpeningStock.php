<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpeningStock extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    protected $fillable = [
        'company_id',
        'warehouse_id',
        'document_number',
        'document_date',
        'status',
        'total_items',
        'total_quantity',
        'total_value',
        'notes',
        'created_by',
        'posted_by',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',

            'total_items' => 'integer',

            'total_quantity' =>
                'decimal:4',

            'total_value' =>
                'decimal:4',

            'posted_at' =>
                'datetime',
        ];
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_POSTED,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(
            Company::class
        );
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(
            Warehouse::class
        );
    }

    public function items(): HasMany
    {
        return $this->hasMany(
            OpeningStockItem::class
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'posted_by'
        );
    }

    public function scopeDraft(
        Builder $query
    ): Builder {
        return $query->where(
            'status',
            self::STATUS_DRAFT
        );
    }

    public function scopePosted(
        Builder $query
    ): Builder {
        return $query->where(
            'status',
            self::STATUS_POSTED
        );
    }

    public function isDraft(): bool
    {
        return $this->status ===
            self::STATUS_DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status ===
            self::STATUS_POSTED;
    }

    public function canBeEdited(): bool
    {
        return $this->isDraft();
    }

    public function canBeDeleted(): bool
    {
        return $this->isDraft();
    }

    public function canBePosted(): bool
    {
        return $this->isDraft();
    }
}