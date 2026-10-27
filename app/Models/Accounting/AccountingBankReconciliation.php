<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use App\Services\ERP\Accounting\Reconciliation\AccountingBankFinalizationGuard;
use Illuminate\Support\Facades\DB;

/** Creation identity is immutable. Only a complete appended operation may advance the protected history head. */
final class AccountingBankReconciliation extends AccountingModel
{
    protected $table = 'accounting_bank_reconciliations';

    public $timestamps = false;

    protected $attributes = ['revision' => 1];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'revision' => 'integer', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $row): void {
            if (DB::transactionLevel() < 1 || (int) $row->getAttribute('revision') !== 1 || $row->getAttribute('last_operation_id') !== null || $row->getAttribute('last_operation_hash') !== $row->getAttribute('snapshot_hash')) {
                throw new \LogicException('A reconciliation must start with its original evidence head in a company transaction.');
            }
            if ((int) $row->getAttribute('finalization_revision') !== 0 || $row->getAttribute('finalization_head_id') !== null || $row->getAttribute('finalization_hash') !== null) {
                throw new \LogicException('New workspaces cannot import a finalization head.');
            }
            $statement = AccountingBankStatement::query()->where('company_id', $row->getAttribute('company_id'))->findOrFail((int) $row->getAttribute('statement_id'));
            app(AccountingBankFinalizationGuard::class)->assertDate((int) $row->getAttribute('company_id'), (int) $row->getAttribute('cash_account_id'), $statement->getAttribute('ends_on')->format('Y-m-d'));
        });
        self::updating(static function (self $row): void {
            if (DB::transactionLevel() < 1 || array_diff(array_keys($row->getDirty()), ['revision', 'last_operation_id', 'last_operation_hash', 'updated_by', 'updated_at']) !== [] || (int) $row->getAttribute('revision') !== (int) $row->getRawOriginal('revision') + 1) {
                throw new \LogicException('Only a complete new reconciliation operation may advance the history head.');
            }
            $operation = AccountingBankReconciliationOperation::query()->where('company_id', $row->getAttribute('company_id'))->where('reconciliation_id', $row->getKey())->find((int) $row->getAttribute('last_operation_id'));
            if ($operation === null || (int) $operation->getAttribute('revision_from') !== (int) $row->getRawOriginal('revision') || $operation->getAttribute('payload_hash') !== $row->getAttribute('last_operation_hash') || (int) $operation->getAttribute('actor_id') !== (int) $row->getAttribute('updated_by') || $operation->getAttribute('occurred_at')->format('Y-m-d H:i:s') !== $row->getAttribute('updated_at')->format('Y-m-d H:i:s')) {
                throw new \LogicException('Reconciliation head must point to its exact owned operation.');
            }
            $statement = AccountingBankStatement::query()->where('company_id', $row->getAttribute('company_id'))->findOrFail((int) $row->getAttribute('statement_id'));
            app(AccountingBankFinalizationGuard::class)->assertDate((int) $row->getAttribute('company_id'), (int) $row->getAttribute('cash_account_id'), $statement->getAttribute('ends_on')->format('Y-m-d'));
        });
        self::deleting(static function (self $row): void {
            throw new \LogicException('Reconciliation history cannot be deleted.');
        });
    }
}
