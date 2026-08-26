<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\ContactReply;

class ContactMessage extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'email',

        'target',
        'target_label',

        'subject',
        'message',

        'status',
        'read_at',
        'replied_at',

        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'replied_at' => 'datetime',
        ];
    }

    public function replies(): HasMany
    {
        return $this->hasMany(ContactReply::class);
    }
}