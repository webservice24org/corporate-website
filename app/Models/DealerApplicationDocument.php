<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealerApplicationDocument extends Model
{
    protected $fillable = [
        'dealer_application_id',

        'document_type',

        'disk',
        'path',

        'original_name',
        'mime_type',
        'file_size',

        'verified_by_user_id',
        'verified_at',

        'verification_notes',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(
            DealerApplication::class,
            'dealer_application_id'
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
