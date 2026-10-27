<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source;

use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\FiscalYear;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class AccountingSourcePeriod
{
    public function __construct(private readonly AccountingBusinessDate $businessDate) {}

    /** Caller already holds company/settings/activation locks. @return array{FiscalYear, AccountingPeriod} */
    public function lock(User $actor, Company $company, string $date): array
    {
        $years = FiscalYear::query()->where('company_id', $company->getKey())->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->orderBy('id')->lockForUpdate()->get();
        $periods = AccountingPeriod::query()->where('company_id', $company->getKey())->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->orderBy('id')->lockForUpdate()->get();
        if ($years->count() !== 1 || $periods->count() !== 1) {
            $this->fail();
        }
        $year = $years->firstOrFail();
        $period = $periods->firstOrFail();
        if ($year->getAttribute('state') !== 'open' || $period->getAttribute('state') !== 'open' || (int) $period->getAttribute('fiscal_year_id') !== (int) $year->getKey() || $period->getAttribute('starts_on')->format('Y-m-d') < $year->getAttribute('starts_on')->format('Y-m-d') || $period->getAttribute('ends_on')->format('Y-m-d') > $year->getAttribute('ends_on')->format('Y-m-d')) {
            $this->fail();
        }
        if ($date < $this->businessDate->today($company) && ! $actor->can('erp.accounting-periods.adjust')) {
            throw new AuthorizationException('Backdated source accounting requires period-adjust authority.');
        }

        return [$year, $period];
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['accounting_date' => 'Source accounting requires exactly one open fiscal year and wholly contained open period.']);
    }
}
