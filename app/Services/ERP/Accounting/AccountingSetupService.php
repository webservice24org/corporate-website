<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Accounting\AccountingSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccountingSetupService
{
    // Explicit first-version scope. Adding currencies is a reviewed contract change, never implicit FX.
    private const TWO_DECIMAL_CURRENCIES = ['BDT', 'USD', 'EUR', 'GBP', 'INR', 'AUD', 'CAD', 'SGD', 'AED', 'SAR', 'CHF', 'CNY', 'HKD', 'MYR', 'NZD', 'THB'];

    public function __construct(private readonly AccountingCompanyAccess $access, private readonly AccountingAuditService $audit) {}

    public function supportsCurrency(string $currency): bool
    {
        return in_array($currency, self::TWO_DECIMAL_CURRENCIES, true);
    }

    /** Initializes DISABLED only. No activation or source adapters are implemented in 6.1C. */
    public function initialize(User $actor, int $companyId): AccountingSetting
    {
        return DB::transaction(function () use ($actor, $companyId): AccountingSetting {
            $company = $this->access->authorize($actor, $companyId, 'erp.accounting-settings.update', true);
            $existing = AccountingSetting::query()->where('company_id', $companyId)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }
            $currency = (string) $company->getAttribute('currency_code');
            if (! $this->supportsCurrency($currency)) {
                throw ValidationException::withMessages(['currency_code' => 'This currency is outside the two-decimal accounting foundation scope.']);
            }
            $setting = AccountingSetting::query()->create(['company_id' => $companyId, 'state' => 'disabled', 'functional_currency' => $currency, 'currency_precision' => 2, 'contract_version' => AccountingDomainRegistry::VERSION, 'recognition_policy' => 'invoice_cogs', 'configured_by' => $actor->getKey(), 'revision' => 1]);
            $this->audit->record($actor, $companyId, 'settings', (int) $setting->getKey(), 'configure', [], ['state' => 'disabled', 'functional_currency' => $currency, 'contract_version' => AccountingDomainRegistry::VERSION, 'revision' => 1], 'Initialize accounting foundation with posting disabled.');

            return $setting;
        }, 3);
    }
}
