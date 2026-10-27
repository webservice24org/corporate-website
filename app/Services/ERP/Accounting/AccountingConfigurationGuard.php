<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Accounting\AccountingSetting;
use App\Models\Company;
use Illuminate\Validation\ValidationException;

/** Internal guard: caller first authorizes and locks the company in the same transaction. */
final class AccountingConfigurationGuard
{
    public function __construct(private readonly AccountingCompanyAccess $access, private readonly AccountingSetupService $setup) {}

    public function lockForSetup(Company $company): AccountingSetting
    {
        $this->access->requireTransaction();
        $setting = AccountingSetting::query()->where('company_id', $company->getKey())->lockForUpdate()->first();
        if ($setting === null) {
            $this->fail('Initialize this company in Accounting Setup first.');
        }
        $issues = $this->issues($company, $setting);
        if ($issues !== []) {
            $this->fail($issues[0]);
        }

        return $setting;
    }

    /**
     * Read-only readiness hints. Caller authorizes company scope first.
     *
     * @return list<string>
     */
    public function issues(Company $company, ?AccountingSetting $setting): array
    {
        $issues = [];
        if ($company->getAttribute('status') !== 'active') {
            $issues[] = 'Account changes require an active company.';
        }
        if ($setting === null) {
            $issues[] = 'Initialize this company in Accounting Setup first.';

            return $issues;
        }
        if ($setting->getAttribute('cutover_locked_at') !== null) {
            $issues[] = 'Accounting configuration is locked for cutover.';
        }
        if (! in_array($setting->getAttribute('state'), ['disabled', 'ready', 'enabled'], true) || $setting->getAttribute('functional_currency') !== $company->getAttribute('currency_code') || ! $this->setup->supportsCurrency((string) $company->getAttribute('currency_code')) || (int) $setting->getAttribute('currency_precision') !== 2 || $setting->getAttribute('contract_version') !== AccountingDomainRegistry::VERSION || $setting->getAttribute('recognition_policy') !== 'invoice_cogs') {
            $issues[] = 'Company accounting configuration requires review before changing the Chart of Accounts.';
        }

        return $issues;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['company_id' => $message]);
    }
}
