<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Adjustments;

use App\Models\Accounting\AccountingBankAdjustment;
use App\Models\Accounting\JournalEntryLine;
use Illuminate\Validation\ValidationException;

/** One native bank adjustment is tied to its actual bank statement movement. No automatic match is created. */
final class AccountingBankAdjustmentMatchPolicy
{
    /** Sources were verified by the reconciliation reader in the caller's company transaction. @param array<string, mixed> $plan */
    public function verify(int $company, int $bank, array $plan): void
    {
        $statement = [];
        $book = [];
        foreach ($plan['statement_legs'] as $leg) {
            $statement[$leg['id']] = $leg['amount'];
        }
        foreach ($plan['book_legs'] as $leg) {
            $book[$leg['id']] = $leg['amount'];
        }
        foreach ($statement as $id => $amount) {
            $row = AccountingBankAdjustment::query()->where('company_id', $company)->where('cash_account_id', $bank)->where('active_statement_line_id', $id)->where('status', 'posted')->first();
            if ($row === null) {
                continue;
            }
            $ids = JournalEntryLine::query()->where('company_id', $company)->where('cash_account_id', $bank)->where('source_line_type', 'bank_adjustment')->where('source_line_id', $row->getKey())->pluck('id')->all();
            if (count($ids) !== 1 || ($book[(int) $ids[0]] ?? null) !== $amount) {
                $this->fail();
            }
        }
        foreach ($book as $id => $amount) {
            $line = JournalEntryLine::query()->where('company_id', $company)->where('cash_account_id', $bank)->findOrFail($id);
            if ($line->getAttribute('source_line_type') !== 'bank_adjustment') {
                continue;
            }
            $row = AccountingBankAdjustment::query()->where('company_id', $company)->where('cash_account_id', $bank)->findOrFail((int) $line->getAttribute('source_line_id'));
            if (($statement[(int) $row->getAttribute('statement_line_id')] ?? null) !== $amount) {
                $this->fail();
            }
        }
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['bank_adjustment_match' => 'Match each recognized bank adjustment only to its own statement movement, with equal paired partial amounts.']);
    }
}
