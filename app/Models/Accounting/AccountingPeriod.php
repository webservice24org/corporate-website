<?php

declare(strict_types=1);

namespace App\Models\Accounting;

final class AccountingPeriod extends AccountingModel
{
    protected $table = 'accounting_periods';

    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'closed_at' => 'datetime', 'revision' => 'integer'];
    }
}
