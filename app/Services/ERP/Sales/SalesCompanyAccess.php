<?php

declare(strict_types=1);

namespace App\Services\ERP\Sales;

use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

final class SalesCompanyAccess
{
    public function __construct(private readonly SalesDomainRegistry $registry) {}

    public function authorize(User $actor, int $companyId, string $permission, bool $writing = false): Company
    {
        if (! in_array($permission, $this->registry->permissions(), true)) {
            throw new InvalidArgumentException('Unknown Sales permission.');
        }
        if (! $actor->exists || ! User::query()->whereKey($actor->getKey())->exists() || ! $actor->can($permission)) {
            throw new AuthorizationException('You do not have permission for this Sales action.');
        }
        if (! $actor->hasRole('super-admin') && ! $actor->employee()->where('company_id', $companyId)->exists()) {
            throw new AuthorizationException('You cannot access Sales records for this company.');
        }
        $query = Company::query()->whereKey($companyId);
        if ($writing) {
            $this->requireTransaction();
            $query->lockForUpdate();
        }
        $company = $query->firstOrFail();
        if ($writing && $company->getAttribute('status') !== 'active') {
            throw ValidationException::withMessages(['company_id' => 'Sales writes require an active company.']);
        }

        return $company;
    }

    public function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Sales writes require a caller-owned database transaction.');
        }
    }
}
