<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use App\Services\ERP\Accounting\Reconciliation\AccountingBankFinalizationGuard;
use Illuminate\Support\Facades\DB;

final class AccountingBankStatementLine extends AccountingImmutableModel
{
    protected $table = 'accounting_bank_statement_lines';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['transaction_date' => 'date:Y-m-d', 'value_date' => 'date:Y-m-d', 'money_in' => 'decimal:2', 'money_out' => 'decimal:2', 'reported_balance' => 'decimal:2', 'running_balance' => 'decimal:2', 'line_number' => 'integer'];
    }

    protected static function booted(): void
    {
        parent::booted();
        self::creating(static function (self $row): void {
            if (DB::transactionLevel() < 1) {
                throw new \LogicException('Bank statement evidence requires a complete import transaction.');
            }
            app(AccountingBankFinalizationGuard::class)->assertDate((int) $row->getAttribute('company_id'), (int) $row->getAttribute('cash_account_id'), $row->getAttribute('transaction_date')->format('Y-m-d'));
        });
    }
}
