<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use App\Models\SupplierPayment;
use App\Services\ERP\Accounting\AccountingDecimal;
use App\Services\ERP\Accounting\Adjustments\AccountingBankAdjustmentMapping;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseFinanceMapping;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentReversalMapping;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferReversalMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class JournalEntryLine extends AccountingModel
{
    protected $table = 'journal_entry_lines';

    protected function casts(): array
    {
        return ['debit' => 'decimal:2', 'credit' => 'decimal:2', 'line_number' => 'integer'];
    }

    protected static function booted(): void
    {
        // Eloquent fires saving before creating/updating: block posted mutations before amount validation.
        foreach (['saving', 'deleting'] as $event) {
            self::$event(static function (self $row): void {
                $ids = array_unique(array_filter([$row->getRawOriginal('journal_entry_id'), $row->getAttribute('journal_entry_id'), $row->exists ? self::query()->whereKey($row->getKey())->value('journal_entry_id') : null]));
                foreach ($ids as $id) {
                    $journal = JournalEntry::query()->findOrFail($id);
                    if ($journal->getAttribute('status') === 'posted' || $journal->getAttribute('posted_at') !== null) {
                        throw new \LogicException('Posted journal lines are immutable.');
                    }
                }
            });
        }
        self::saving(static function (self $row): void {
            $money = app(AccountingDecimal::class);
            $attributes = $row->getAttributes();
            $debit = $money->money($attributes['debit'] ?? '0');
            $credit = $money->money($attributes['credit'] ?? '0');
            if ((bccomp($debit, '0', 2) > 0) === (bccomp($credit, '0', 2) > 0)) {
                throw ValidationException::withMessages(['lines' => 'Each journal line requires exactly one positive side.']);
            }
            $row->setAttribute('debit', $debit);
            $row->setAttribute('credit', $credit);
            if ($row->getAttribute('cash_account_id') !== null) {
                $binding = AccountingCashAccount::query()->where('company_id', $row->getAttribute('company_id'))->where('account_id', $row->getAttribute('account_id'))->find((int) $row->getAttribute('cash_account_id'));
                if ($binding === null || ($binding->getAttribute('status') !== 'active' && ! self::verifiedSupplierRefund($row, $debit, $credit) && ! self::verifiedTransferReversal($row, $debit, $credit) && ! self::verifiedBankAdjustmentReversal($row, $debit, $credit) && ! self::verifiedExpenseReversal($row, $debit, $credit))) {
                    throw new \LogicException('Journal cash binding must be active and match the company and GL account, except for an exact verified native supplier refund, transfer reversal or bank adjustment refund.');
                }
            }
        });
    }

    private static function verifiedExpenseReversal(self $row, string $debit, string $credit): bool
    {
        if ($row->getAttribute('source_line_type') !== 'expense_finance') {
            return false;
        }
        $company = (int) $row->getAttribute('company_id');
        $journal = JournalEntry::query()->where('company_id', $company)->find((int) $row->getAttribute('journal_entry_id'));
        if ($journal === null || $row->getAttribute('party_type') !== null || $row->getAttribute('party_id') !== null || $row->getAttribute('description') !== null) {
            return false;
        }
        $plan = AccountingExpenseFinancePlan::query()->where('company_id', $company)->find((int) $row->getAttribute('source_line_id'));
        if ($plan === null) {
            return false;
        } $s = $plan->getAttribute('snapshot');
        foreach (['branch_id', 'department_id'] as $field) {
            $value = $row->getAttribute($field);
            if (($value === null ? null : (int) $value) !== $s[$field]) {
                return false;
            }
        }

        return app(AccountingExpenseFinanceMapping::class)->line($company, 'expense_finance', (int) $row->getAttribute('source_line_id'), (int) $row->getAttribute('account_id'), (int) $row->getAttribute('cash_account_id'), $debit, $credit, null, $journal);
    }

    private static function verifiedBankAdjustmentReversal(self $row, string $debit, string $credit): bool
    {
        if ($row->getAttribute('source_line_type') !== 'bank_adjustment' || DB::transactionLevel() < 1) {
            return false;
        }
        foreach (['party_type', 'party_id', 'branch_id', 'department_id', 'description'] as $field) {
            if ($row->getAttribute($field) !== null) {
                return false;
            }
        }
        $company = (int) $row->getAttribute('company_id');
        $journal = JournalEntry::query()->where('company_id', $company)->find((int) $row->getAttribute('journal_entry_id'));
        if ($journal === null) {
            return false;
        }

        return app(AccountingBankAdjustmentMapping::class)->line($company, 'bank_adjustment', (int) $row->getAttribute('source_line_id'), (int) $row->getAttribute('account_id'), (int) $row->getAttribute('cash_account_id'), $debit, $credit, null, $journal);
    }

    /** Both original transfer endpoints require the exact native reverse side and parent journal identity. */
    private static function verifiedTransferReversal(self $row, string $debit, string $credit): bool
    {
        if ($row->getAttribute('source_line_type') !== 'cash_transfer_reversal' || DB::transactionLevel() < 1) {
            return false;
        }
        foreach (['party_type', 'party_id', 'branch_id', 'department_id', 'description'] as $field) {
            if ($row->getAttribute($field) !== null) {
                return false;
            }
        }
        $company = (int) $row->getAttribute('company_id');
        $journal = JournalEntry::query()->where('company_id', $company)->find((int) $row->getAttribute('journal_entry_id'));
        if ($journal === null) {
            return false;
        }

        return app(AccountingCashTransferReversalMapping::class)->line($company, 'cash_transfer_reversal', (int) $row->getAttribute('source_line_id'), (int) $row->getAttribute('account_id'), (int) $row->getAttribute('cash_account_id'), $debit, $credit, null, $journal);
    }

    /** An inactive binding is usable only for the exact cash debit of a proven native full refund. */
    private static function verifiedSupplierRefund(self $row, string $debit, string $credit): bool
    {
        if (DB::transactionLevel() < 1 || $row->getAttribute('source_line_type') !== 'supplier_payment' || bccomp($credit, '0', 2) !== 0) {
            return false;
        }
        $company = (int) $row->getAttribute('company_id');
        $sourceId = (int) $row->getAttribute('source_line_id');
        $journal = JournalEntry::query()->where('company_id', $company)->find((int) $row->getAttribute('journal_entry_id'));
        if ($journal === null || $journal->getAttribute('entry_kind') !== 'automatic') {
            return false;
        }
        $cash = SupplierPayment::query()->where('company_id', $company)->find($sourceId);
        if ($cash === null || $cash->getAttribute('reverses_payment_id') === null || (int) $cash->getAttribute('cash_account_id') !== (int) $row->getAttribute('cash_account_id') || bccomp($debit, (string) $cash->getAttribute('amount'), 2) !== 0) {
            return false;
        }

        // Reconstructs the complete independently approved native refund; a source label alone is insufficient.
        return app(AccountingSupplierPaymentReversalMapping::class)->cashBinding($company, 'supplier_payment', $sourceId);
    }
}
