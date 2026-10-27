<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingAccountMapping;
use App\Models\Accounting\AccountingMappingVersion;
use App\Models\Accounting\AccountingSetting;
use App\Models\Accounting\JournalEntry;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Company lock serializes publication with accounts, setup and future journal writers. */
final class AccountingMappingService
{
    public function __construct(
        private readonly AccountingCompanyAccess $access,
        private readonly AccountingConfigurationGuard $configuration,
        private readonly AccountingMappingCatalog $catalog,
        private readonly AccountingAccountCatalog $accounts,
        private readonly AccountingCanonicalPayload $canonical,
        private readonly AccountingAuditService $audit,
    ) {}

    /** @param array<string, mixed> $input */
    public function publish(User $actor, array $input): AccountingMappingVersion
    {
        $data = Validator::make($input, [
            'company_id' => ['required', 'integer', 'min:1'],
            'base_version_id' => ['present', 'nullable', 'integer', 'min:1'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'mappings' => ['required', 'array', 'max:50'],
            'mappings.*' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u'],
        ])->validate();
        if (array_diff(array_keys($input), ['company_id', 'base_version_id', 'effective_from', 'mappings', 'reason']) !== []) {
            $this->fail('mappings', 'Publication contains unsupported fields.');
        }
        $mapping = [];
        foreach ($data['mappings'] as $role => $id) {
            if (! is_string($role) || $this->catalog->category($role) === null) {
                $this->fail('mappings', 'Unknown posting role.');
            }
            $mapping[$role] = (int) $id;
        }
        $this->complete($mapping);
        ksort($mapping, SORT_STRING);

        return DB::transaction(function () use ($actor, $data, $mapping): AccountingMappingVersion {
            $companyId = (int) $data['company_id'];
            $company = $this->access->authorize($actor, $companyId, 'erp.accounting-mappings.publish', true);
            $setting = $this->configuration->lockForSetup($company);
            $latest = AccountingMappingVersion::query()->where('company_id', $companyId)->orderByDesc('version')->lockForUpdate()->first();
            $base = $data['base_version_id'] === null ? null : (int) $data['base_version_id'];
            if (($latest === null ? null : (int) $latest->getKey()) !== $base) {
                $this->fail('base_version_id', 'The posting profile changed. Reload before publishing.');
            }
            if ($latest !== null && $latest->getAttribute('state') !== 'published') {
                $this->fail('mappings', 'Existing draft or retired profile requires review before publication.');
            }
            $date = (string) $data['effective_from'];
            if ($latest !== null && $date <= $latest->getAttribute('effective_from')->format('Y-m-d')) {
                $this->fail('effective_from', 'Each published version must start after the latest published version.');
            }
            if (JournalEntry::query()->where('company_id', $companyId)->where('status', 'posted')->whereDate('accounting_date', '>=', $date)->exists()) {
                $this->fail('effective_from', 'A new profile cannot reinterpret dates with posted journals. Choose a later effective date.');
            }
            $this->validateAccounts($companyId, $mapping);
            $version = AccountingMappingVersion::query()->create(['company_id' => $companyId, 'version' => $latest === null ? 1 : (int) $latest->getAttribute('version') + 1, 'state' => 'draft', 'effective_from' => $date, 'revision' => 1]);
            foreach ($mapping as $role => $id) {
                AccountingAccountMapping::query()->create(['company_id' => $companyId, 'mapping_version_id' => $version->getKey(), 'role' => $role, 'account_id' => $id]);
            }
            $hash = $this->hash($companyId, $date, (string) $setting->getAttribute('functional_currency'), $mapping);
            $version->fill(['state' => 'published', 'published_by' => $actor->getKey(), 'published_at' => now(), 'payload_hash' => $hash])->save();
            $this->audit->record($actor, $companyId, 'mapping', (int) $version->getKey(), 'publish', $latest === null ? [] : $this->snapshot($latest), [...$this->snapshot($version), 'mapping_count' => count($mapping), 'cash_count' => count(array_filter(array_keys($mapping), fn (string $role): bool => $this->catalog->cashRole($role)))], trim((string) $data['reason']));

            return $version;
        });
    }

    /**
     * Internal posting prerequisite; caller owns the transaction. No HTTP route.
     * Replays must return their original stored effect before calling this method.
     *
     * @param  list<string>  $requiredRoles
     * @return array{version_id: int, payload_hash: string, accounts: array<string, int>}
     */
    public function resolveForPosting(User $actor, int $companyId, string $date, array $requiredRoles): array
    {
        $this->access->requireTransaction();
        $company = $this->access->authorize($actor, $companyId, 'erp.journal-entries.post', true);

        return $this->resolveLocked($company, $date, $requiredRoles, false);
    }

    /** Internal prerequisite only, after trusted source authorization and company lock. @param list<string> $requiredRoles @return array{version_id: int, payload_hash: string, accounts: array<string, int>} */
    public function resolveForSource(Company $company, string $date, array $requiredRoles): array
    {
        $this->access->requireTransaction();

        return $this->resolveLocked($company, $date, $requiredRoles, true);
    }

    /** @param list<string> $requiredRoles @return array{version_id: int, payload_hash: string, accounts: array<string, int>} */
    private function resolveLocked(Company $company, string $date, array $requiredRoles, bool $allowEmpty): array
    {
        $companyId = (int) $company->getKey();
        $setting = $this->configuration->lockForSetup($company);
        Validator::make(['date' => $date, 'roles' => $requiredRoles], ['date' => ['required', 'date_format:Y-m-d'], 'roles' => [$allowEmpty ? 'present' : 'required', 'array', $allowEmpty ? 'min:0' : 'min:1', 'max:50'], 'roles.*' => ['required', 'string', 'distinct']])->validate();
        $version = AccountingMappingVersion::query()->where('company_id', $companyId)->where('state', 'published')->whereDate('effective_from', '<=', $date)->orderByDesc('effective_from')->orderByDesc('version')->lockForUpdate()->first();
        if ($version === null) {
            $this->fail('mappings', 'No published posting profile covers the accounting date.');
        }
        $mapping = [];
        foreach (AccountingAccountMapping::query()->where('company_id', $companyId)->where('mapping_version_id', $version->getKey())->orderBy('id')->lockForUpdate()->get() as $row) {
            $mapping[(string) $row->getAttribute('role')] = (int) $row->getAttribute('account_id');
        }
        $this->complete($mapping);
        $hash = $this->hash($companyId, $version->getAttribute('effective_from')->format('Y-m-d'), (string) $setting->getAttribute('functional_currency'), $mapping);
        if (! hash_equals((string) $version->getAttribute('payload_hash'), $hash)) {
            $this->fail('mappings', 'Published profile evidence does not match its immutable hash.');
        }
        $selected = [];
        foreach ($requiredRoles as $role) {
            if (! isset($mapping[$role]) || $this->catalog->category($role) === null) {
                $this->fail('mappings', 'The selected profile does not configure every requested role.');
            }
            $selected[$role] = $mapping[$role];
        }
        $this->validateAccounts($companyId, $selected);

        return ['version_id' => (int) $version->getKey(), 'payload_hash' => $hash, 'accounts' => $selected];
    }

    /** Historical evidence only, after caller scope/company authorization; no current readiness or account-activity check. */
    public function verifyRecordedProfile(int $companyId, int $versionId, string $currency, string $contractVersion, string $expectedHash): AccountingMappingVersion
    {
        $this->access->requireTransaction();
        $version = AccountingMappingVersion::query()->where('company_id', $companyId)->findOrFail($versionId);
        $mapping = [];
        foreach (AccountingAccountMapping::query()->where('company_id', $companyId)->where('mapping_version_id', $versionId)->orderBy('id')->get() as $row) {
            $mapping[(string) $row->getAttribute('role')] = (int) $row->getAttribute('account_id');
        }
        $hash = $this->canonical->hash(['contract_version' => $contractVersion, 'company_id' => $companyId, 'effective_from' => $version->getAttribute('effective_from')->format('Y-m-d'), 'currency_code' => $currency, 'mappings' => $mapping]);
        if (! in_array($version->getAttribute('state'), ['published', 'retired'], true) || ! hash_equals((string) $version->getAttribute('payload_hash'), $hash) || ! hash_equals($expectedHash, $hash)) {
            $this->fail('mappings', 'Historical published profile does not match its recorded evidence.');
        }

        return $version;
    }

    /** Read-only profile check. Does not activate posting or acquire write locks.
     * @return array<string, mixed>
     */
    public function readiness(User $actor, int $companyId, string $date): array
    {
        $company = $this->access->authorize($actor, $companyId, 'erp.accounting-mappings.view');
        Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();
        $version = AccountingMappingVersion::query()->where('company_id', $companyId)->where('state', 'published')->whereDate('effective_from', '<=', $date)->orderByDesc('effective_from')->orderByDesc('version')->first();
        if ($version === null) {
            return ['status' => 'review', 'version_id' => null, 'version' => null, 'effective_from' => null, 'issues' => ['No published profile covers the selected accounting date.']];
        }
        $issues = [];
        try {
            $setting = AccountingSetting::query()->where('company_id', $companyId)->first();
            $issues = $this->configuration->issues($company, $setting);
            $mapping = [];
            foreach (AccountingAccountMapping::query()->where('company_id', $companyId)->where('mapping_version_id', $version->getKey())->get() as $row) {
                $mapping[(string) $row->getAttribute('role')] = (int) $row->getAttribute('account_id');
            }
            $this->complete($mapping);
            if ($setting !== null && ! hash_equals((string) $version->getAttribute('payload_hash'), $this->hash($companyId, $version->getAttribute('effective_from')->format('Y-m-d'), (string) $setting->getAttribute('functional_currency'), $mapping))) {
                $this->fail('mappings', 'Published profile evidence does not match its immutable hash.');
            }
            $this->validateAccounts($companyId, $mapping, false);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $issues[] = $message;
                }
            }
        }

        return ['status' => $issues === [] ? 'ready' : 'review', 'version_id' => (int) $version->getKey(), 'version' => (int) $version->getAttribute('version'), 'effective_from' => $version->getAttribute('effective_from')->format('Y-m-d'), 'issues' => array_values(array_unique($issues))];
    }

    /** @param array<string, int> $mapping */
    private function complete(array $mapping): void
    {
        if (array_diff(array_keys($this->catalog->roles()), array_keys($mapping)) !== []) {
            $this->fail('mappings', 'Configure every standard posting role before publication.');
        }
        $cashIds = [];
        foreach ($mapping as $role => $id) {
            if ($this->catalog->category($role) === null) {
                $this->fail('mappings', 'Unknown posting role.');
            }
            if ($this->catalog->cashRole($role)) {
                if (in_array($id, $cashIds, true)) {
                    $this->fail('mappings', 'Each cash/bank code must have its own GL account.');
                }
                $cashIds[] = $id;
            }
        }
        if ($cashIds === []) {
            $this->fail('mappings', 'Configure at least one cash or bank GL account.');
        }
    }

    /** @param array<string, int> $mapping */
    private function validateAccounts(int $companyId, array $mapping, bool $locking = true): void
    {
        $query = Account::query()->where('company_id', $companyId)->orderBy('id');
        if ($locking) {
            $query->lockForUpdate();
        }
        $rows = $query->get();
        $byId = [];
        $parents = [];
        foreach ($rows as $row) {
            $byId[(int) $row->getKey()] = $row;
            if ($row->getAttribute('parent_id') !== null) {
                $parents[(int) $row->getAttribute('parent_id')] = true;
            }
        }
        $groups = AccountGroup::query()->where('company_id', $companyId)->get()->keyBy('id');
        foreach ($mapping as $role => $id) {
            $row = $byId[$id] ?? null;
            if ($row === null) {
                $this->fail('mappings', 'A selected account is outside this company.');
            }
            $group = $groups->get($row->getAttribute('account_group_id'));
            if ($group === null) {
                $this->fail('mappings', 'Account group is missing.');
            }
            $category = (string) $group->getAttribute('category');
            $definition = $this->accounts->groups()[$category] ?? null;
            if ($definition === null || $group->getAttribute('code') !== $definition['code'] || $group->getAttribute('normal_side') !== $definition['normal_side'] || $category !== $this->catalog->category($role)) {
                $this->fail('mappings', 'Account category is incompatible with '.$role.'.');
            }
            if ($row->getAttribute('status') !== 'active' || ! $row->getAttribute('is_postable') || isset($parents[$id])) {
                $this->fail('mappings', 'Every mapped account must be an active postable leaf.');
            }
            if ($row->getAttribute('control_role') !== $this->catalog->control($role) || ! in_array($row->getAttribute('statement_role'), $this->catalog->statements($role), true)) {
                $this->fail('mappings', 'Account control or statement role is incompatible with '.$role.'.');
            }
            if ($this->catalog->cashRole($role) && ($row->getAttribute('cash_flow_role') !== 'cash' || $row->getAttribute('normal_side') !== 'debit')) {
                $this->fail('mappings', 'Cash/bank GL accounts require the cash flow role cash and a debit normal side.');
            }
            if (! $this->catalog->cashRole($role) && $row->getAttribute('cash_flow_role') === 'cash') {
                $this->fail('mappings', 'Cash GL accounts cannot also be used as noncash posting roles.');
            }
            $seen = [$id => true];
            $parent = $row->getAttribute('parent_id');
            while ($parent !== null) {
                $parentId = (int) $parent;
                $ancestor = $byId[$parentId] ?? null;
                if ($ancestor === null || isset($seen[$parentId]) || count($seen) >= 32 || $ancestor->getAttribute('status') !== 'active' || $ancestor->getAttribute('is_postable') || (int) $ancestor->getAttribute('account_group_id') !== (int) $row->getAttribute('account_group_id')) {
                    $this->fail('mappings', 'Mapped account hierarchy requires review.');
                }
                $seen[$parentId] = true;
                $parent = $ancestor->getAttribute('parent_id');
            }
        }
    }

    /** @param array<string, int> $mapping */
    private function hash(int $companyId, string $date, string $currency, array $mapping): string
    {
        return $this->canonical->hash(['contract_version' => AccountingDomainRegistry::VERSION, 'company_id' => $companyId, 'effective_from' => $date, 'currency_code' => $currency, 'mappings' => $mapping]);
    }

    /** @return array{mapping_version: int, state: string, effective_from: string, payload_hash: string} */
    private function snapshot(AccountingMappingVersion $version): array
    {
        return ['mapping_version' => (int) $version->getAttribute('version'), 'state' => (string) $version->getAttribute('state'), 'effective_from' => $version->getAttribute('effective_from')->format('Y-m-d'), 'payload_hash' => (string) $version->getAttribute('payload_hash')];
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
