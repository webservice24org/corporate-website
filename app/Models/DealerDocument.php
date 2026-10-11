<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealerDocument extends Model
{
    protected $fillable = [
        'dealer_id',
        'source_application_document_id',
        'document_type',
        'title',
        'disk',
        'file_path',
        'original_name',
        'mime_type',
        'file_size',
        'version',
        'is_current',
        'verified_at',
        'verified_by',
        'uploaded_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'version' => 'integer',
            'is_current' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
