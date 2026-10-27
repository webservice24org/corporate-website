<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Accounting\AccountingSetting;
use App\Models\Company;
use App\Models\User;

/** Authorized read-only projection. GET requests never initialize settings or write audit. */
final class AccountingWorkspaceService
{
    public function __construct(private readonly AccountingCompanyAccess $access, private readonly AccountingSetupService $setup) {}

    /** @return array<string, mixed> */
    public function read(User $actor, ?int $companyId): array
    {
        $permission = 'erp.accounting-settings.view';
        $companies = $this->access->companies($actor, $permission)->orderBy('name')->orderBy('id')->get(['id', 'name', 'code', 'currency_code', 'timezone', 'status']);
        if ($companyId !== null) {
            $this->access->authorize($actor, $companyId, $permission);
        }
        if ($companyId === null && $companies->count() === 1) {
            $single = $companies->first();
            $companyId = $single === null ? null : (int) $single->getKey();
        }
        $company = $companyId === null ? null : $companies->firstWhere('id', $companyId);
        if ($companyId !== null && $company === null) {
            abort(404);
        }
        $setting = $company === null ? null : AccountingSetting::query()->where('company_id', $company->getKey())->first();
        $supportsCurrency = $company !== null && $this->setup->supportsCurrency((string) $company->getAttribute('currency_code'));
        $issues = [];
        if ($company !== null) {
            if ($company->getAttribute('status') !== 'active') {
                $issues[] = 'This company is inactive. Existing setup is available for viewing; initialization is blocked.';
            }
            if (! $supportsCurrency) {
                $issues[] = 'This currency is outside the supported two-decimal accounting scope.';
            }
        }
        if ($setting !== null) {
            if ($setting->getAttribute('functional_currency') !== $company->getAttribute('currency_code') || (int) $setting->getAttribute('currency_precision') !== 2 || $setting->getAttribute('contract_version') !== AccountingDomainRegistry::VERSION || $setting->getAttribute('recognition_policy') !== 'invoice_cogs') {
                $issues[] = 'The saved accounting configuration differs from the current company or supported contract. Review it before continuing setup.';
            }
            if (! in_array($setting->getAttribute('state'), ['disabled', 'ready', 'enabled'], true)) {
                $issues[] = 'The saved accounting state requires review.';
            }
        }
        $selected = $company === null ? null : $company->only(['id', 'name', 'code', 'currency_code', 'timezone', 'status']);
        $configuration = $setting === null ? null : $setting->only(['state', 'functional_currency', 'currency_precision', 'contract_version', 'recognition_policy', 'revision']);

        return [
            'companies' => $companies->map(static fn (Company $row): array => $row->only(['id', 'name', 'code', 'currency_code', 'timezone', 'status']))->values()->all(),
            'company' => $selected,
            'filters' => ['company_id' => $companyId],
            'configuration' => $configuration,
            'issues' => $issues,
            'canInitialize' => $company !== null && $company->getAttribute('status') === 'active' && $setting === null && $supportsCurrency && $actor->can('erp.accounting-settings.update'),
            'postingAvailable' => false,
        ];
    }
}
