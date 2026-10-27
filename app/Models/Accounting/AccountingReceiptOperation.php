<?php

declare(strict_types=1);

namespace App\Models\Accounting;

final class AccountingReceiptOperation extends AccountingImmutableModel
{
    protected $table = 'accounting_receipt_operations';

    protected function casts(): array
    {
        return ['members' => 'array'];
    }
}
