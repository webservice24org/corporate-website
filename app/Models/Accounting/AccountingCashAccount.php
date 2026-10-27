<?php

declare(strict_types=1);

namespace App\Models\Accounting;

final class AccountingCashAccount extends AccountingModel
{
    protected $table = 'accounting_cash_accounts';

    protected function casts(): array
    {
        return ['revision' => 'integer'];
    }

    protected static function booted(): void
    {
        self::updating(static function (self $row): void {
            if ($row->isDirty(['company_id', 'role', 'kind', 'currency_code', 'account_id'])) {
                throw new \LogicException('Cash binding identity cannot be reassigned; reconcile mappings explicitly.');
            }
        });
        self::deleting(static function (self $row): void {
            throw new \LogicException('Cash binding history cannot be deleted.');
        });
    }
}
