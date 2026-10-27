<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Expenses;

use App\Models\Accounting\AccountingExpenseFinancePlan;
use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/** One full compensation per original instruction; settlement compensation precedes recognition compensation. */
final class AccountingExpenseReversal
{
    public function __construct(private readonly AccountingExpenseFinanceEvidence $evidence, private readonly AccountingExpenseFinanceContract $contract) {}

    /** @return array<string, mixed> */
    public function prepare(User $actor, Company $company, int $original, int $expense, string $date): array
    {
        if (! $actor->can('erp.expense-finance.reverse')) {
            throw new AuthorizationException('Expense reversal authority is required.');
        }
        $proof = $this->evidence->verify((int) $company->getKey(), $original);
        $s = $proof['plan']->getAttribute('snapshot');
        if ($s['expense_id'] !== $expense || $s['kind'] === 'reversal' || $date < $s['date']) {
            $this->contract->fail('Reverse an original recognition/payment in its own expense, on or after its accounting date.');
        }
        if (AccountingExpenseFinancePlan::query()->where('company_id', $company->getKey())->where('active_reversal_id', $original)->exists()) {
            $this->contract->fail('An active reversal instruction already owns this operation.');
        }
        $s = [...$s, 'kind' => 'reversal', 'date' => $date, 'origin_date' => $date, 'reverses_plan' => $original, 'reverses_kind' => $s['kind'], 'original_hash' => (string) $proof['operation']->getAttribute('payload_hash'), 'payment_claim' => null];
        $this->eligible((int) $company->getKey(), $s);

        return $s;
    }

    /** @param array<string, mixed> $s */
    public function eligible(int $company, array $s): void
    {
        $original = $this->evidence->verify($company, $s['reverses_plan']);
        $parent = $original['plan']->getAttribute('snapshot');
        if ($parent['kind'] === 'reversal' || $parent['kind'] !== $s['reverses_kind'] || $parent['expense_id'] !== $s['expense_id'] || $original['operation']->getAttribute('payload_hash') !== $s['original_hash'] || $parent['amount'] !== $s['amount']) {
            $this->contract->fail('Reversal identity must match the complete original operation.');
        }
        $balance = $this->evidence->balance($company, $s['expense_id']);
        if ($balance['status'] === 'reversed' || ($parent['kind'] === 'recognition' && bccomp($balance['settled'], '0', 2) !== 0)) {
            $this->contract->fail('Compensate all posted payments before reversing recognition; recognition already reversed cannot accept another correction.');
        }
    }
}
