<?php

declare(strict_types=1);

namespace App\Models\Accounting;

final class AccountingSalesCostSlice extends AccountingImmutableModel
{
    protected $table = 'accounting_sales_cost_slices';

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'quantity_before' => 'decimal:4', 'quantity_after' => 'decimal:4', 'amount' => 'decimal:2'];
    }
}
