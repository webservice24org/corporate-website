<?php

declare(strict_types=1);

namespace App\Models\Accounting;

final class AccountingSetting extends AccountingModel
{
    protected $table = 'accounting_company_settings';

    protected function casts(): array
    {
        return ['currency_precision' => 'integer', 'activation_date' => 'date:Y-m-d', 'cutover_locked_at' => 'datetime', 'activated_at' => 'datetime', 'revision' => 'integer'];
    }
}
