<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Transfers;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Caller owns the company lock and native transaction; sequence increments roll back with a failed draft. */
final class AccountingCashTransferNumberService
{
    public function next(int $company, string $date): string
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Transfer numbering requires its company transaction.');
        }
        if ($company < 1 || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date) || $date < '1900-01-01' || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            throw ValidationException::withMessages(['cash_transfer' => 'Use a real company/date for numbering.']);
        }
        $year = (int) substr($date, 0, 4);
        $row = DB::table('accounting_cash_transfer_sequences')->where('company_id', $company)->where('year', $year)->lockForUpdate()->first();
        if ($row === null) {
            DB::table('accounting_cash_transfer_sequences')->insert(['company_id' => $company, 'year' => $year, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $number = 1;
        } else {
            $number = (int) $row->last_number + 1;
        }
        if ($number > 999999) {
            throw ValidationException::withMessages(['cash_transfer' => 'The annual transfer numbering range is exhausted.']);
        }
        DB::table('accounting_cash_transfer_sequences')->where('company_id', $company)->where('year', $year)->update(['last_number' => $number, 'updated_at' => now()]);

        return 'CT-'.$year.'-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }
}
