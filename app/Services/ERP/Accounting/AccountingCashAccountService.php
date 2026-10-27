<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingAccountMapping;
use App\Models\Accounting\AccountingAuditEvent;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingMappingVersion;
use App\Models\Accounting\AccountingSetting;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\Adjustments\AccountingBankAdjustmentMapping;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseFinanceMapping;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentReversalMapping;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferReversalMapping;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Concrete finance bindings, derived from an authorized published profile, never editable balances. */
final class AccountingCashAccountService
{
    public function __construct(private readonly AccountingCompanyAccess $access, private readonly AccountingMappingService $mappings, private readonly AccountingMappingCatalog $catalog, private readonly AccountingBusinessDate $dates) {}

    public function initialize(User $actor, int $companyId, string $reason): int
    {
        if (trim($reason) === '' || mb_strlen($reason) > 2000) {
            $this->fail('Provide a setup reason of up to 2,000 characters.');
        }

        return DB::transaction(function () use ($actor, $companyId, $reason): int {
            $company = $this->access->authorize($actor, $companyId, 'erp.accounting-mappings.publish', true);
            $date = $this->dates->today($company);
            $version = AccountingMappingVersion::query()->where('company_id', $companyId)->where('state', 'published')->whereDate('effective_from', '<=', $date)->orderByDesc('effective_from')->orderByDesc('version')->first();
            if ($version === null) {
                $this->fail('Publish a complete posting profile before initializing cash accounts.');
            }
            $roles = [];
            foreach (AccountingAccountMapping::query()->where('company_id', $companyId)->where('mapping_version_id', $version->getKey())->pluck('role') as $role) {
                if ($this->catalog->cashRole((string) $role)) {
                    $roles[] = (string) $role;
                }
            }
            $mapping = $this->mappings->resolveForSource($company, $date, $roles);
            $count = 0;
            foreach ($mapping['accounts'] as $role => $accountId) {
                $role = (string) $role;
                $accountId = (int) $accountId;
                $existing = AccountingCashAccount::query()->where('company_id', $companyId)->where('role', $role)->first();
                if ($existing !== null) {
                    if ((int) $existing->getAttribute('account_id') !== $accountId || $existing->getAttribute('currency_code') !== $company->getAttribute('currency_code')) {
                        $this->fail('A cash role was remapped. A verified account transition is required; existing binding identity cannot be overwritten.');
                    }

continue;
                }
                if (AccountingCashAccount::query()->where('company_id', $companyId)->where('account_id', $accountId)->exists()) {
                    $this->fail('This GL account already has a different cash-role identity. A verified transition is required instead of renaming its binding.');
                }
                $account = Account::query()->where('company_id', $companyId)->findOrFail($accountId);
                $created = AccountingCashAccount::query()->create(['company_id' => $companyId, 'role' => $role, 'kind' => str_starts_with($role, 'cash:') ? 'cash' : 'bank', 'name' => (string) $account->getAttribute('name'), 'currency_code' => (string) $company->getAttribute('currency_code'), 'account_id' => $accountId, 'status' => 'active', 'revision' => 1]);
                $count++;
                $this->audit($actor, $companyId, (int) $created->getKey(), 'initialize', $reason, ['role' => $role, 'account_id' => $accountId, 'currency_code' => (string) $company->getAttribute('currency_code'), 'mapping_version_id' => $mapping['version_id']]);
            }

            return $count;
        }, 3);
    }

    public function status(User $actor, int $companyId, int $id, int $revision, string $status, string $reason): void
    {
        if (! in_array($status, ['active', 'inactive'], true) || trim($reason) === '' || mb_strlen($reason) > 2000) {
            $this->fail('Choose a valid status and provide a reason.');
        }
        DB::transaction(function () use ($actor, $companyId, $id, $revision, $status, $reason): void {
            $this->access->authorize($actor, $companyId, 'erp.accounting-mappings.publish', true);
            $setting = AccountingSetting::query()->where('company_id', $companyId)->lockForUpdate()->first();
            if ($setting !== null && $setting->getAttribute('cutover_locked_at') !== null) {
                $this->fail('Cash binding changes are blocked by the cutover lock.');
            }
            $row = AccountingCashAccount::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            if ((int) $row->getAttribute('revision') !== $revision) {
                $this->fail('Cash binding changed; reload before updating it.');
            }
            $before = ['status' => $row->getAttribute('status'), 'revision' => (int) $row->getAttribute('revision')];
            $row->fill(['status' => $status, 'revision' => $revision + 1])->save();
            $this->audit($actor, $companyId, $id, $status, $reason, ['status' => $status, 'revision' => $revision + 1], $before);
        }, 3);
    }

    public function selection(Company $company, mixed $id, string $method, bool $required): ?int
    {
        if ($id === null || $id === '') {
            if ($required) {
                $this->fail('Select the finance cash/bank account receiving this money.');
            }

return null;
        }
        $row = AccountingCashAccount::query()->where('company_id', $company->getKey())->where('status', 'active')->find((int) $id);
        if ($row === null || $row->getAttribute('currency_code') !== $company->getAttribute('currency_code') || $row->getAttribute('kind') !== ($method === 'cash' ? 'cash' : 'bank')) {
            $this->fail('Select an active same-company/currency finance account matching the receipt method.');
        }

        return (int) $row->getKey();
    }

    /** Current new-line validation; a historical exception must prove an exact supported native reversal line.
     * @param  array{kind: string, id: int, debit?: string, credit?: string}|null  $source
     */
    public function line(int $company, string $role, int $account, mixed $id, string $field, ?array $source = null): ?int
    {
        if (! $this->catalog->cashRole($role)) {
            if ($id !== null) {
                throw ValidationException::withMessages([$field => 'Only mapped cash/bank lines may carry a finance cash-account ID.']);
            }

return null;
        }
        $allowInactiveBinding = $source !== null && app(AccountingSupplierPaymentReversalMapping::class)->cashBinding($company, $source['kind'], $source['id']);
        $allowInactiveBinding = $allowInactiveBinding || ($source !== null && app(AccountingCashTransferReversalMapping::class)->line($company, $source['kind'], $source['id'], $account, (int) $id, $source['debit'] ?? '', $source['credit'] ?? '', $role));
        $allowInactiveBinding = $allowInactiveBinding || ($source !== null && app(AccountingBankAdjustmentMapping::class)->line($company, $source['kind'], $source['id'], $account, (int) $id, $source['debit'] ?? '', $source['credit'] ?? '', $role));
        $allowInactiveBinding = $allowInactiveBinding || ($source !== null && app(AccountingExpenseFinanceMapping::class)->line($company, $source['kind'], $source['id'], $account, (int) $id, $source['debit'] ?? '', $source['credit'] ?? '', $role));
        $row = $id === null ? null : AccountingCashAccount::query()->where('company_id', $company)->when(! $allowInactiveBinding, static fn ($query) => $query->where('status', 'active'))->find((int) $id);
        $currency = Company::query()->findOrFail($company)->getAttribute('currency_code');
        if ($row === null || $row->getAttribute('role') !== $role || (int) $row->getAttribute('account_id') !== $account || $row->getAttribute('currency_code') !== $currency) {
            throw ValidationException::withMessages([$field => 'Cash/bank posting requires its matching active concrete finance-account binding.']);
        }

        return (int) $row->getKey();
    }

    /**
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $before
     */
    private function audit(User $actor, int $company, int $id, string $action, string $reason, array $after, array $before = []): void
    {
        AccountingAuditEvent::query()->create(['company_id' => $company, 'entity_kind' => 'cash_account', 'entity_id' => $id, 'action' => $action, 'actor_id' => $actor->getKey(), 'actor_snapshot' => ['id' => $actor->getKey(), 'name' => (string) $actor->getAttribute('name')], 'reason' => trim($reason), 'before_payload' => $before, 'after_payload' => $after, 'occurred_at' => CarbonImmutable::now('UTC')->toDateTimeString()]);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['cash_account_id' => $message]);
    }
}
