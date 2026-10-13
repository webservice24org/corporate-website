<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'product_category_id',
        'brand_id',
        'unit_id',
        'product_type',
        'name',
        'product_code',
        'sku',
        'barcode',
        'featured_image',
        'gallery_images',
        'short_description',
        'description',
        'purchase_price',
        'selling_price',
        'track_inventory',
        'allow_negative_stock',
        'reorder_level',
        'minimum_stock',
        'has_variants',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'gallery_images' => 'array',
        'purchase_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'track_inventory' => 'boolean',
        'allow_negative_stock' => 'boolean',
        'reorder_level' => 'decimal:4',
        'minimum_stock' => 'decimal:4',
        'has_variants' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'featured_image_url',
        'gallery_image_urls',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function inventoryBalances(): HasMany
    {
        return $this->hasMany(
            InventoryBalance::class
        );
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(
            StockMovement::class
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function getFeaturedImageUrlAttribute(): ?string
    {
        return $this->featured_image
            ? Storage::url($this->featured_image)
            : null;
    }

    public function getGalleryImageUrlsAttribute(): array
    {
        return collect($this->gallery_images ?? [])
            ->map(fn (string $path) => [
                'path' => $path,
                'url' => Storage::url($path),
            ])
            ->values()
            ->all();
    }

    public function isStockItem(): bool
    {
        return $this->product_type === 'physical' && $this->track_inventory;
    }

    public function openingStockItems(): HasMany
    {
        return $this->hasMany(
            OpeningStockItem::class
        );
    }

    public function inventoryReorderSettings(): HasMany
    {
        return $this->hasMany(
            InventoryReorderSetting::class
        );
    }
}
