<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use App\Services\ERP\Accounting\Reconciliation\AccountingBankFinalizationGuard;
use Illuminate\Support\Facades\DB;

final class AccountingBankStatement extends AccountingImmutableModel
{
    protected $table = 'accounting_bank_statements';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['binding_snapshot' => 'array', 'starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'opening_balance' => 'decimal:2', 'closing_balance' => 'decimal:2', 'money_in' => 'decimal:2', 'money_out' => 'decimal:2', 'row_count' => 'integer', 'imported_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        parent::booted();
        self::creating(static function (self $row): void {
            if (DB::transactionLevel() < 1) {
                throw new \LogicException('Bank statement evidence requires a complete import transaction.');
            }
            app(AccountingBankFinalizationGuard::class)->assertDate((int) $row->getAttribute('company_id'), (int) $row->getAttribute('cash_account_id'), $row->getAttribute('starts_on')->format('Y-m-d'));
        });
    }
}
