<?php

declare(strict_types=1);

namespace App\Models\Accounting;

final class Account extends AccountingModel
{
    protected $table = 'accounts';

    protected function casts(): array
    {
        return ['is_postable' => 'boolean', 'revision' => 'integer'];
    }
}
