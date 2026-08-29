<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Page extends Model
{
    protected $fillable = [
        'title',
        'slug',

        'description',
        'featured_image',
        'layout',

        'status',
        'template',

        'meta_title',
        'meta_description',

        'published_at',
        'is_home',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'is_home' => 'boolean',
        ];
    }
    public function sections(): HasMany
    {
        return $this->hasMany(PageSection::class)
            ->orderBy('sort_order');
    }

}