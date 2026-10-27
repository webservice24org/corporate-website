<?php

declare(strict_types=1);

namespace App\Models\Accounting;

final class AccountingSalesEvent extends AccountingImmutableModel
{
    protected $table = 'accounting_sales_events';

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
