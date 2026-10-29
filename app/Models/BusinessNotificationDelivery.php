<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class BusinessNotificationDelivery extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['recipient', 'subject', 'body'];

    protected function casts(): array
    {
        return ['recipient' => 'encrypted', 'subject' => 'encrypted', 'body' => 'encrypted',
            'claimed_at' => 'datetime', 'submitted_at' => 'datetime', 'attempts' => 'integer'];
    }
}
