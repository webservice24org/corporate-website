<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepotApplicationDocument extends Model
{
    protected $fillable = [
        'depot_application_id',
        'document_type',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'file_size',
        'verified_by_user_id',
        'verified_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(
            DepotApplication::class,
            'depot_application_id'
        );
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'verified_by_user_id'
        );
    }
}