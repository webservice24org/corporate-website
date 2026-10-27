<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingSetting;
use App\Models\Company;
use Illuminate\Validation\ValidationException;

/** Internal gate: company and settings locks are already held in the writer transaction. */
final class AccountingPostingReadiness
{
    public function __construct(private readonly AccountingCompanyAccess $access) {}

    public function check(Company $company, AccountingSetting $setting, string $date): void
    {
        $this->access->requireTransaction();
        if ($setting->getAttribute('state') !== 'enabled' || $setting->getAttribute('activation_date') === null || $setting->getAttribute('activated_by') === null || $setting->getAttribute('activated_at') === null) {
            $this->fail('Accounting must be activated through the approved cutover workflow before live journal posting.');
        }
        $activation = $setting->getAttribute('activation_date')->format('Y-m-d');
        if ($date < $activation) {
            throw ValidationException::withMessages(['accounting_date' => 'Live journals cannot predate the approved accounting activation date.']);
        }
        $cutovers = AccountingCutover::query()->where('company_id', $company->getKey())->where('status', 'activated')->lockForUpdate()->get();
        $cutover = $cutovers->count() === 1 ? $cutovers->first() : null;
        if ($cutover === null || ! in_array($cutover->getAttribute('strategy'), ['snapshot', 'replay'], true) || $cutover->getAttribute('cutover_date')->format('Y-m-d') !== $activation || $cutover->getAttribute('approved_by') === null || $cutover->getAttribute('approved_at') === null || $cutover->getAttribute('activated_by') === null || $cutover->getAttribute('activated_at') === null || (int) $cutover->getAttribute('approved_by') === (int) $cutover->getAttribute('created_by') || (int) $cutover->getAttribute('activated_by') !== (int) $setting->getAttribute('activated_by') || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $cutover->getAttribute('manifest_hash')) || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $cutover->getAttribute('reconciliation_hash'))) {
            $this->fail('A single activated, independently approved cutover with reconciliation evidence is required.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['company_id' => $message]);
    }
}
