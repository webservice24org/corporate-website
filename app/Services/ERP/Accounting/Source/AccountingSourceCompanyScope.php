<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source;

use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Scope only. The registered adapter MUST separately authorize the actual source operation. */
final class AccountingSourceCompanyScope
{
    public function lock(User $actor, int $companyId): Company
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Source orchestration requires the caller-owned source transaction.');
        }
        if (! $actor->exists || ! User::query()->whereKey($actor->getKey())->exists()) {
            throw new AuthorizationException('Authenticated persisted source actor required.');
        }
        if (! $actor->hasRole('super-admin') && ! $actor->employee()->where('company_id', $companyId)->exists()) {
            throw new AuthorizationException('Source company is outside the actor scope.');
        }
        $company = Company::query()->lockForUpdate()->findOrFail($companyId);
        if ($company->getAttribute('status') !== 'active') {
            throw ValidationException::withMessages(['company_id' => 'Source accounting requires an active company.']);
        }

        return $company;
    }
}
