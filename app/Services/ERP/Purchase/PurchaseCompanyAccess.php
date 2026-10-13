<?php

declare(strict_types=1);

namespace App\Services\ERP\Purchase;

use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class PurchaseCompanyAccess
{
    public function authorize(User $actor, int $companyId, string $permission): void
    {
        if (! $actor->exists || ! $actor->can($permission)) {
            throw new AuthorizationException('You do not have permission for this purchasing action.');
        }

        $companyExists = Company::query()->whereKey($companyId)->exists();
        $belongsToCompany = $actor->hasRole('super-admin') || $actor->employee()
            ->where('company_id', $companyId)->exists();

        if (! $companyExists || ! $belongsToCompany) {
            throw new AuthorizationException('You cannot access purchasing records for this company.');
        }
    }
}
