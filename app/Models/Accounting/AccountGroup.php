<?php

declare(strict_types=1);

namespace App\Models\Accounting;

final class AccountGroup extends AccountingModel
{
    protected $table = 'account_groups';

    protected function casts(): array
    {
        return ['revision' => 'integer'];
    }
}
