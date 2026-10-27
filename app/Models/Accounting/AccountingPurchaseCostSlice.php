<?php

declare(strict_types=1);

namespace App\Models\Accounting;

final class AccountingPurchaseCostSlice extends AccountingImmutableModel
{
    protected $table = 'accounting_purchase_cost_slices';

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'grni_quantity' => 'decimal:4', 'billed_return_quantity' => 'decimal:4', 'grni_amount' => 'decimal:2', 'return_clearing_amount' => 'decimal:2'];
    }
}
