<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Expenses;

use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingExpenseFinanceOperation;
use App\Models\Accounting\AccountingExpenseFinancePlan;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\JournalEntry;
use App\Models\Company;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\Source\AccountingPreparedSourceEvent;

final class AccountingExpenseFinanceMapping
{
    public function __construct(private readonly AccountingExpenseFinanceProof $proof, private readonly AccountingExpenseFinanceContract $contract, private readonly AccountingCanonicalPayload $canonical) {}

    /**
     * @param  list<string>  $roles
     * @return array{version_id: int, payload_hash: string, accounts: array<string, int>}|null
     */
    public function resolve(AccountingPreparedSourceEvent $event, array $roles): ?array
    {
        if ($event->sourceKind !== 'expense_finance') {
            return null;
        }

        $plan = AccountingExpenseFinancePlan::query()->where('company_id', $event->companyId)->findOrFail($event->sourceId);
        $expected = $this->proof->event($plan);

        if ($this->canonical->hash($event->facts) !== $this->canonical->hash($expected->facts) || $this->canonical->hash($event->effects[0]->lines) !== $this->canonical->hash($expected->effects[0]->lines) || $event->eventToken !== $expected->eventToken) {
            $this->contract->fail('Expense adapter and approved finance lines disagree.');
        }

        $s = $plan->getAttribute('snapshot');
        $this->contract->live(Company::query()->findOrFail($event->companyId), $s);
        $accounts = [];

        foreach ($this->proof->lines($plan) as $line) {
            $accounts[$line['role']] = $line['account_id'];
        }

        $required = array_keys($accounts);
        sort($required);
        sort($roles);
        if ($required !== $roles) {
            $this->contract->fail('Unexpected expense finance roles.');
        }

        return ['version_id' => $s['mapping']['version_id'], 'payload_hash' => $s['mapping']['payload_hash'], 'accounts' => $accounts];
    }

    /** Only an exact, independently approved compensation leg may refund its original inactive binding. */
    public function line(int $company, string $kind, int $id, int $account, int $cash, string $debit, string $credit, ?string $role = null, ?JournalEntry $journal = null): bool
    {
        if ($kind !== 'expense_finance' || ! AccountingExpenseFinanceContext::permits($company, $id)) {
            return false;
        }
        $plan = AccountingExpenseFinancePlan::query()->where('company_id', $company)->find($id);
        if ($plan === null || $plan->getAttribute('kind') !== 'reversal' || $plan->getAttribute('status') !== 'posted' || AccountingExpenseFinanceOperation::query()->where('company_id', $company)->where('plan_id', $id)->exists() || AccountingSourceEffect::query()->where('company_id', $company)->where('source_kind', $kind)->where('source_id', $id)->exists()) {
            return false;
        }
        $binding = AccountingCashAccount::query()->where('company_id', $company)->find($cash);
        if ($binding === null || ! in_array($binding->getAttribute('status'), ['active', 'inactive'], true)) {
            return false;
        }
        $this->proof->verify($plan);
        $s = $plan->getAttribute('snapshot');
        app(AccountingExpenseFinanceEvidence::class)->verify($company, $s['reverses_plan']);
        if ($journal !== null && ($journal->getAttribute('entry_kind') !== 'automatic' || $journal->getAttribute('status') !== 'draft' || (int) $journal->getAttribute('company_id') !== $company || (int) $journal->getAttribute('mapping_version_id') !== $s['mapping']['version_id'] || $journal->getAttribute('currency_code') !== $s['currency'] || $journal->getAttribute('accounting_date')->format('Y-m-d') !== $s['date'])) {
            return false;
        }
        foreach ($this->proof->lines($plan) as $line) {
            if ($line['cash_account_id'] === $cash && $line['account_id'] === $account && $line['debit'] === $debit && $line['credit'] === $credit && ($role === null || $role === $line['role'])) {
                return true;
            }
        }

        return false;
    }
}
