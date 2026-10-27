<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use App\Services\ERP\Accounting\AccountingDecimal;
use Illuminate\Support\Facades\DB;

final class AccountingExpenseFinancePlan extends AccountingModel
{
    protected $table = 'accounting_expense_finance_plans';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'revision' => 'integer', 'document_date' => 'date:Y-m-d', 'created_at' => 'datetime', 'approved_at' => 'datetime', 'posted_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::saving(static function (self $row): void {
            if (DB::transactionLevel() < 1 || ! in_array($row->getAttribute('kind'), ['recognition', 'settlement', 'reversal'], true) || app(AccountingDecimal::class)->money($row->getAttribute('amount')) !== $row->getAttribute('amount')) {
                throw new \LogicException('Invalid exact expense finance instruction.');
            }

            if (! $row->exists) {
                if ($row->getAttribute('status') !== 'submitted' || (int) $row->getAttribute('revision') !== 1) {
                    throw new \LogicException('New finance instructions begin submitted at revision one.');
                }
            } else {
                $old = $row->getRawOriginal('status');
                $allowed = match ($old) {
                    'submitted' => ['approved', 'cancelled'], 'approved' => ['posted', 'cancelled'], default => []
                };

                if (! in_array($row->getAttribute('status'), $allowed, true) || (int) $row->getAttribute('revision') !== (int) $row->getRawOriginal('revision') + 1 || array_diff(array_keys($row->getDirty()), ['status', 'revision', 'active_recognition_id', 'active_reversal_id', 'payment_claim_hash', 'approved_by', 'approved_at', 'posted_by', 'posted_at', 'posting_key_hash']) !== []) {
                    throw new \LogicException('Finance intent is immutable and transitions require the next native revision.');
                }
            }

            if (in_array($row->getAttribute('status'), ['approved', 'posted'], true) && ((int) $row->getAttribute('approved_by') < 1 || $row->getAttribute('approved_at') === null || (int) $row->getAttribute('approved_by') === (int) $row->getAttribute('created_by'))) {
                throw new \LogicException('Expense finance requires independent approval.');
            }

            if ($row->getAttribute('status') === 'posted' && ($row->getAttribute('posted_at') === null || (int) $row->getAttribute('posted_by') < 1 || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $row->getAttribute('posting_key_hash')))) {
                throw new \LogicException('Posted expense finance needs its native posting identity.');
            }
        });

        self::deleting(static function (): void {
            throw new \LogicException('Finance instructions are retained.');
        });
    }
}
