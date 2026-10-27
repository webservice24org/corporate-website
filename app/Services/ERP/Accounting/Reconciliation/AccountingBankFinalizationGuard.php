<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reconciliation;

use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalEntryLine;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Shared cutoff guard. No user permission can bypass a live finalized cutoff; reopen first. */
final class AccountingBankFinalizationGuard
{
    public function __construct(private readonly AccountingBankFinalizationEvidence $evidence) {}

    public function assertDate(int $company, int $bank, string $date): void
    {
        $this->lock($company);
        if ($this->frozen($company, $bank, $date)) {
            throw ValidationException::withMessages(['bank_reconciliation' => 'This bank cutoff is finalized. Reopen dependent finalized reconciliations in reverse order before changing it.']);
        }
    }

    public function frozen(int $company, int $bank, string $date): bool
    {
        foreach ($this->evidence->bank($company, $bank) as $state) {
            if ($state['state'] === 'finalized' && $date <= $state['cutoff']) {
                return true;
            }
        }

        return false;
    }

    public function assertJournal(JournalEntry $journal): void
    {
        $company = (int) $journal->getAttribute('company_id');
        if (! Schema::hasTable('accounting_bank_finalization_events')) {
            if (Schema::hasColumn('accounting_bank_reconciliations', 'finalization_revision') && DB::table('accounting_bank_reconciliations')->where('company_id', $company)->where('finalization_revision', '>', 0)->exists()) {
                throw ValidationException::withMessages(['finalization_integrity' => 'Protected finalization history is missing. Bank posting is blocked.']);
            }

            return;
        }
        if (! DB::table('accounting_bank_finalization_events')->where('company_id', $company)->exists() && ! DB::table('accounting_bank_reconciliations')->where('company_id', $company)->where('finalization_revision', '>', 0)->exists()) {
            return;
        }
        $lines = JournalEntryLine::query()->where('company_id', $company)->where('journal_entry_id', $journal->getKey())->get(['account_id', 'cash_account_id']);
        $banks = [];
        foreach ($lines as $line) {
            foreach (AccountingCashAccount::query()->where('company_id', $company)->where('kind', 'bank')
                ->where(function ($query) use ($line): void {
                    $query->where('account_id', $line->getAttribute('account_id'));
                    if ($line->getAttribute('cash_account_id') !== null) {
                        $query->orWhere('id', $line->getAttribute('cash_account_id'));
                    }
                })->pluck('id')->all() as $bank) {
                $banks[(int) $bank] = true;
            }
        }
        foreach (array_keys($banks) as $bank) {
            $this->assertDate($company, $bank, $journal->getAttribute('accounting_date')->format('Y-m-d'));
        }
    }

    private function lock(int $company): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Bank cutoff changes require a company transaction.');
        }
        Company::query()->whereKey($company)->lockForUpdate()->firstOrFail();
    }
}
