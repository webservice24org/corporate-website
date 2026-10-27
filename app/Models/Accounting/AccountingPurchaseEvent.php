<?php

declare(strict_types=1);

namespace App\Models\Accounting;

final class AccountingPurchaseEvent extends AccountingImmutableModel
{
    protected $table = 'accounting_purchase_events';

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
