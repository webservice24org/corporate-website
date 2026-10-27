<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccountingCompanyAccess
{
    public function __construct(private readonly AccountingDomainRegistry $registry) {}

    /** @return Builder<Company> */
    public function companies(User $actor, string $permission): Builder
    {
        if (! in_array($permission, $this->registry->permissions(), true)) {
            throw new \InvalidArgumentException('Unknown accounting permission.');
        }
        if (! $actor->exists || ! User::query()->whereKey($actor->getKey())->exists() || ! $actor->can($permission)) {
            throw new AuthorizationException('Accounting permission required.');
        }
        $query = Company::query();
        if (! $actor->hasRole('super-admin')) {
            $companyId = $actor->employee()->value('company_id');
            if ($companyId === null) {
                throw new AuthorizationException('An employee company is required.');
            }
            $query->whereKey($companyId);
        }

        return $query;
    }

    public function authorize(User $actor, int $companyId, string $permission, bool $writing = false): Company
    {
        $query = $this->companies($actor, $permission);
        if ($writing) {
            $this->requireTransaction();
            $query->lockForUpdate();
        }
        // Explicit company-filter failures are authorization failures, not leaked data.
        if (! $actor->hasRole('super-admin') && ! $actor->employee()->where('company_id', $companyId)->exists()) {
            throw new AuthorizationException('This company is outside your accounting scope.');
        }
        $company = $query->whereKey($companyId)->firstOrFail();
        if ($writing && $company->getAttribute('status') !== 'active') {
            throw ValidationException::withMessages(['company_id' => 'Accounting writes require an active company.']);
        }

        return $company;
    }

    public function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Accounting writes require a caller-owned database transaction.');
        }
    }
}
