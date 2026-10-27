<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Adjustments;

use App\Models\Accounting\AccountingCashAccount;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Accounting\Books\AccountingCashBookReader;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Support\Facades\DB;

/** Reports source-contract gaps without recognizing or rewriting legacy/manual/opening cash history. */
final class AccountingBankAdjustmentCoverageAudit
{
    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingCashBookReader $books, private readonly AccountingBusinessDate $dates) {}

    /** @return array<string, mixed> */
    public function review(User $actor, int $companyId, int $bankId): array
    {
        foreach (['erp.bank-adjustments.view', 'erp.bank-statements.view', 'erp.cash-books.view', 'erp.bank-reconciliations.view'] as $permission) {
            $this->access->authorize($actor, $companyId, $permission);
        }

        return DB::transaction(function () use ($actor, $companyId, $bankId): array {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            foreach (['erp.bank-adjustments.view', 'erp.bank-statements.view', 'erp.cash-books.view', 'erp.bank-reconciliations.view'] as $permission) {
                $this->access->authorize($actor, $companyId, $permission);
            }
            $bank = AccountingCashAccount::query()->where('company_id', $companyId)->where('kind', 'bank')->findOrFail($bankId);
            $today = $this->dates->today($company);
            $book = $this->books->reconciliationBook($actor, $companyId, $bankId, ['from' => '1900-01-01', 'to' => $today]);
            $rows = DB::table('journal_entry_lines as l')->join('journal_entries as j', function ($join): void {
                $join->on('j.id', '=', 'l.journal_entry_id')->on('j.company_id', '=', 'l.company_id');
            })
                ->where('j.company_id', $companyId)->whereDate('j.accounting_date', '<=', $today)
                ->where(function ($q) use ($bankId, $bank): void {
                    $q->where('l.cash_account_id', $bankId)->orWhere('l.account_id', $bank->getAttribute('account_id'));
                })
                ->where(function ($q): void {
                    $q->where('j.status', 'posted')->orWhereNotNull('j.posted_at')->orWhereNotNull('j.posted_by');
                })->get(['j.entry_kind', 'l.cash_account_id']);
            $opening = 0;
            $manual = 0;
            $unbound = 0;
            foreach ($rows as $row) {
                if ($row->entry_kind === 'opening') {
                    $opening++;
                } if (in_array($row->entry_kind, ['manual', 'reversal', 'period_adjustment'], true)) {
                    $manual++;
                } if ($row->cash_account_id === null || (int) $row->cash_account_id !== $bankId) {
                    $unbound++;
                }
            }

            return ['company_id' => $companyId, 'cash_account_id' => $bankId, 'currency_code' => (string) $bank->getAttribute('currency_code'), 'through' => $today,
                'bankbook_sources_complete' => (bool) $book['complete'], 'exceptions' => $book['exceptions'], 'opening_cash_lines' => $opening, 'manual_cash_lines' => $manual, 'unbound_cash_lines' => $unbound,
                'opening_cash_contract' => 'requires_verified_cutover_cash_opening_contract', 'manual_cash_contract' => 'requires_separate_bound_manual_cash_contract', 'finalized' => false,
                'message' => 'Bank charges/interest use the new native approved adapter. Legacy opening/manual/unbound cash entries stay in review until their dedicated evidence contracts exist. This audit never finalizes a reconciliation.'];
        }, 3);
    }
}
