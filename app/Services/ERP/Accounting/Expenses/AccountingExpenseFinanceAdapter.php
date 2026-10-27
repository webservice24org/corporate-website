<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Expenses;

use App\Models\Accounting\AccountingExpenseFinancePlan;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\Source\AccountingPreparedSourceEvent;
use App\Services\ERP\Accounting\Source\AccountingSourceAdapter;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Auth\Access\AuthorizationException;

final class AccountingExpenseFinanceAdapter implements AccountingSourceAdapter
{
    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingExpenseFinanceProof $proof, private readonly AccountingExpenseFinanceContract $contract) {}

    public function authorize(User $actor, Company $company, int $sourceId, string $eventKind, string $eventToken): void
    {
        foreach (['erp.expenses.view', 'erp.expense-finance.view', 'erp.expense-finance.post'] as $permission) {
            $this->access->authorize($actor, (int) $company->getKey(), $permission);
        }

        $plan = AccountingExpenseFinancePlan::query()->where('company_id', $company->getKey())->findOrFail($sourceId);
        $this->proof->verify($plan);
        if ($plan->getAttribute('kind') === 'reversal' && ! $actor->can('erp.expense-finance.reverse')) {
            throw new AuthorizationException('Expense reversal authority is required.');
        }

        if ($eventKind !== 'posted' || $plan->getAttribute('posting_key_hash') !== $eventToken || $plan->getAttribute('status') !== 'posted') {
            $this->contract->fail('Use the approved native expense-finance post operation.');
        }

        if (! AccountingExpenseFinanceContext::permits((int) $company->getKey(), $sourceId)) {
            app(AccountingExpenseFinanceEvidence::class)->verify((int) $company->getKey(), $sourceId);
        }
    }

    public function prepare(User $actor, Company $company, int $sourceId, string $eventKind, string $eventToken): AccountingPreparedSourceEvent
    {
        $this->authorize($actor, $company, $sourceId, $eventKind, $eventToken);

        return $this->proof->event(AccountingExpenseFinancePlan::query()->where('company_id', $company->getKey())->findOrFail($sourceId));
    }
}
