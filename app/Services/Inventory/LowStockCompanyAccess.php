<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Company;
use App\Models\User;

/**
 * Shared company authorization rules for low-stock reports and notifications.
 * This service does not select a default company or trust a requested company ID.
 */
final class LowStockCompanyAccess
{
    public function canView(User $user, int $companyId): bool
    {
        if ($companyId <= 0 || ! $user->can('erp.low-stock-reports.view')) {
            return false;
        }

        if ($user->hasRole('super-admin')) {
            return Company::query()->whereKey($companyId)->exists();
        }

        return $user->employee()
            ->where('company_id', $companyId)
            ->exists();
    }

    /** @return array<int, int> */
    public function allowedCompanyIds(User $user): array
    {
        if (! $user->can('erp.low-stock-reports.view')) {
            return [];
        }

        if ($user->hasRole('super-admin')) {
            return Company::query()
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }

        return $user->employee()
            ->pluck('company_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
