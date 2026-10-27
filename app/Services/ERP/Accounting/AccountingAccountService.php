<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingAccountMapping;
use App\Models\Accounting\AccountingMappingVersion;
use App\Models\Accounting\JournalEntryLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class AccountingAccountService
{
    public function __construct(private readonly AccountingCompanyAccess $access, private readonly AccountingConfigurationGuard $configuration, private readonly AccountingAccountCatalog $catalog, private readonly AccountingAuditService $audit) {}

    /**
     * Explicit write action; reads never seed groups.
     *
     * @return list<AccountGroup>
     */
    public function initializeGroups(User $actor, int $companyId): array
    {
        return DB::transaction(function () use ($actor, $companyId): array {
            $company = $this->access->authorize($actor, $companyId, 'erp.accounts.create', true);
            $this->configuration->lockForSetup($company);
            $groups = [];
            foreach ($this->catalog->groups() as $category => $definition) {
                $group = AccountGroup::query()->where('company_id', $companyId)->where(fn ($query) => $query->where('category', $category)->orWhere('code', $definition['code']))->lockForUpdate()->first();
                if ($group !== null) {
                    if ($group->getAttribute('category') !== $category || $group->getAttribute('code') !== $definition['code'] || $group->getAttribute('normal_side') !== $definition['normal_side']) {
                        $this->fail('account_group_id', 'An existing account group conflicts with the standard classification. Review it before initialization.');
                    }
                } else {
                    $group = AccountGroup::query()->create(['company_id' => $companyId, 'category' => $category, ...$definition, 'revision' => 1]);
                    $this->audit->record($actor, $companyId, 'account_group', (int) $group->getKey(), 'create', [], ['code' => $definition['code'], 'name' => $definition['name'], 'category' => $category, 'normal_side' => $definition['normal_side'], 'revision' => 1], 'Initialize standard Chart of Accounts group.');
                }
                $groups[] = $group;
            }

            return $groups;
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function save(User $actor, array $input, ?int $id = null): Account
    {
        if (isset($input['code']) && is_string($input['code'])) {
            $input['code'] = strtoupper(trim($input['code']));
        }
        if (isset($input['name']) && is_string($input['name'])) {
            $input['name'] = trim($input['name']);
        }
        $roles = array_merge(...array_values($this->catalog->statementRoles()));
        $rules = ['company_id' => ['required', 'integer', 'min:1'], 'account_group_id' => ['required', 'integer', 'min:1'], 'parent_id' => ['nullable', 'integer', 'min:1'], 'code' => ['required', 'regex:/\A[A-Z0-9][A-Z0-9_.-]{0,39}\z/'], 'name' => ['required', 'string', 'max:160', 'regex:/\S/u'], 'normal_side' => ['required', 'in:debit,credit'], 'is_postable' => ['required', 'boolean'], 'control_role' => ['nullable', 'in:'.implode(',', array_keys($this->catalog->controlRoles()))], 'statement_role' => ['required', 'in:'.implode(',', $roles)], 'cash_flow_role' => ['nullable', 'in:operating,investing,financing,cash,noncash'], 'revision' => [$id === null ? 'prohibited' : 'required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u']];
        foreach (['id', 'status', 'balance', 'amount', 'currency_code', 'created_by', 'updated_by', 'posted_at'] as $field) {
            $rules[$field] = ['prohibited'];
        }
        $data = Validator::make($input, $rules)->validate();

        return DB::transaction(function () use ($actor, $data, $id): Account {
            $permission = $id === null ? 'erp.accounts.create' : 'erp.accounts.edit';
            $companyId = (int) $data['company_id'];
            $company = $this->access->authorize($actor, $companyId, $permission, true);
            $this->configuration->lockForSetup($company);
            $row = $id === null ? null : Account::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            if ($row !== null) {
                $this->revision($row, (int) $data['revision']);
            }
            $group = AccountGroup::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail((int) $data['account_group_id']);
            $category = (string) $group->getAttribute('category');
            $this->assertGroup($group);
            if (! in_array($data['statement_role'], $this->catalog->statementRoles()[$category], true)) {
                $this->fail('statement_role', 'Statement classification must match the account group.');
            }
            $postable = (bool) $data['is_postable'];
            $control = empty($data['control_role']) ? null : (string) $data['control_role'];
            $cashFlow = empty($data['cash_flow_role']) ? null : (string) $data['cash_flow_role'];
            if ($control !== null && (! $postable || $this->catalog->controlRoles()[$control] !== $category)) {
                $this->fail('control_role', 'A control account must be postable and belong to the correct category.');
            }
            if ($cashFlow === 'cash' && ($category !== 'asset' || ! $postable || $control !== null)) {
                $this->fail('cash_flow_role', 'Cash classification requires a postable asset account without another control role.');
            }
            $parentId = empty($data['parent_id']) ? null : (int) $data['parent_id'];
            $requireActiveParent = $row === null || $row->getAttribute('status') === 'active' || $parentId !== ($row->getAttribute('parent_id') === null ? null : (int) $row->getAttribute('parent_id')) || (int) $row->getAttribute('account_group_id') !== (int) $group->getKey();
            $depth = $this->parentDepth($companyId, (int) $group->getKey(), $parentId, $id, $requireActiveParent);
            $height = $row === null ? 0 : $this->descendantHeight($row, (int) $group->getKey());
            if ($depth + $height > 32) {
                $this->fail('parent_id', 'The account hierarchy is limited to 32 levels.');
            }
            if ($postable && $height > 0) {
                $this->fail('is_postable', 'An account with children cannot accept postings.');
            }
            if (Account::query()->where('company_id', $companyId)->where('code', $data['code'])->when($id !== null, fn ($query) => $query->where('id', '<>', $id))->exists()) {
                $this->fail('code', 'This account code is already used by the company.');
            }
            $attributes = ['account_group_id' => (int) $group->getKey(), 'parent_id' => $parentId, 'code' => (string) $data['code'], 'name' => (string) $data['name'], 'normal_side' => (string) $data['normal_side'], 'is_postable' => $postable, 'control_role' => $control, 'statement_role' => (string) $data['statement_role'], 'cash_flow_role' => $cashFlow];
            $before = $row === null ? [] : $this->snapshot($row);
            if ($row !== null && $this->hasProtectedReferences($row)) {
                foreach (array_diff(array_keys($attributes), ['name']) as $key) {
                    if ($before[$key] !== $attributes[$key]) {
                        $this->fail($key, 'Referenced account semantics are frozen. Create a new account or use a reviewed correction.');
                    }
                }
            }
            if ($row === null) {
                $row = Account::query()->create(['company_id' => $companyId, ...$attributes, 'status' => 'active', 'revision' => 1]);
            } else {
                $row->forceFill([...$attributes, 'revision' => (int) $row->getAttribute('revision') + 1])->save();
            }
            $this->audit->record($actor, $companyId, 'account', (int) $row->getKey(), $id === null ? 'create' : 'edit', $before, $this->snapshot($row), trim((string) $data['reason']));

            return $row;
        }, 3);
    }

    public function setStatus(User $actor, int $companyId, int $id, string $status, int $revision, string $reason): Account
    {
        Validator::make(['status' => $status, 'revision' => $revision, 'reason' => $reason], ['status' => ['required', 'in:active,inactive'], 'revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u']])->validate();

        return DB::transaction(function () use ($actor, $companyId, $id, $status, $revision, $reason): Account {
            $company = $this->access->authorize($actor, $companyId, 'erp.accounts.deactivate', true);
            $this->configuration->lockForSetup($company);
            $row = Account::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            $this->revision($row, $revision);
            if ($row->getAttribute('status') === $status) {
                return $row;
            }
            if ($status === 'inactive') {
                if (Account::query()->where('parent_id', $id)->where('status', 'active')->exists()) {
                    $this->fail('status', 'Deactivate active child accounts before their parent.');
                }
                if (AccountingAccountMapping::query()->where('account_id', $id)->whereIn('mapping_version_id', AccountingMappingVersion::query()->where('state', 'published')->select('id'))->exists()) {
                    $this->fail('status', 'Retire or replace published mappings before deactivating this account.');
                }
            } else {
                $group = AccountGroup::query()->where('company_id', $companyId)->findOrFail((int) $row->getAttribute('account_group_id'));
                $this->assertGroup($group);
                $category = (string) $group->getAttribute('category');
                $control = $row->getAttribute('control_role');
                if (! in_array($row->getAttribute('statement_role'), $this->catalog->statementRoles()[$category], true) || ($control !== null && ((! (bool) $row->getAttribute('is_postable')) || ($this->catalog->controlRoles()[(string) $control] ?? null) !== $category))) {
                    $this->fail('status', 'Review account classifications before reactivation.');
                }
                if ($row->getAttribute('cash_flow_role') === 'cash' && ($category !== 'asset' || ! (bool) $row->getAttribute('is_postable') || $control !== null)) {
                    $this->fail('status', 'Cash classification requires review before reactivation.');
                }
                $parent = $row->getAttribute('parent_id');
                $depth = $this->parentDepth($companyId, (int) $group->getKey(), $parent === null ? null : (int) $parent, $id);
                $height = $this->descendantHeight($row, (int) $group->getKey());
                if ($depth + $height > 32 || ((bool) $row->getAttribute('is_postable') && $height > 0)) {
                    $this->fail('status', 'Review the account hierarchy before reactivation.');
                }
            }
            $before = $this->snapshot($row);
            $row->forceFill(['status' => $status, 'revision' => $revision + 1])->save();
            $this->audit->record($actor, $companyId, 'account', $id, $status === 'inactive' ? 'deactivate' : 'reactivate', $before, $this->snapshot($row), trim($reason));

            return $row;
        }, 3);
    }

    /** @return array<string, mixed> */
    public function read(User $actor, int $companyId): array
    {
        $this->access->authorize($actor, $companyId, 'erp.accounts.view');
        $groups = AccountGroup::query()->where('company_id', $companyId)->orderBy('code')->get();
        $rows = Account::query()->where('company_id', $companyId)->orderBy('code')->orderBy('id')->get();
        $children = [];
        foreach ($rows as $row) {
            $children[(int) ($row->getAttribute('parent_id') ?? 0)][] = $row;
        }
        /** @var list<array{Account, int}> $stack */
        $stack = [];
        $seen = [];
        $tree = [];
        foreach (array_reverse($children[0] ?? []) as $row) {
            $stack[] = [$row, 0];
        }
        while ($stack !== []) {
            $entry = array_pop($stack);
            [$row, $depth] = $entry;
            $id = (int) $row->getKey();
            if (isset($seen[$id]) || $depth >= 32) {
                $this->fail('parent_id', 'Account hierarchy requires review.');
            }
            $seen[$id] = true;
            $tree[] = ['id' => $id, ...$this->snapshot($row), 'depth' => $depth];
            foreach (array_reverse($children[$id] ?? []) as $child) {
                $stack[] = [$child, $depth + 1];
            }
        }
        if (count($seen) !== $rows->count()) {
            $this->fail('parent_id', 'Account hierarchy has an orphan or cycle that requires review.');
        }

        return ['groups' => $groups->map(static fn (AccountGroup $group): array => $group->only(['id', 'code', 'name', 'category', 'normal_side', 'revision']))->all(), 'accounts' => $tree];
    }

    private function parentDepth(int $companyId, int $groupId, ?int $parentId, ?int $accountId, bool $requireActive = true): int
    {
        $seen = [];
        $depth = 1;
        while ($parentId !== null) {
            if ($parentId === $accountId || isset($seen[$parentId]) || ++$depth > 32) {
                $this->fail('parent_id', 'An account cannot contain itself or create a hierarchy cycle/depth overflow.');
            }
            $seen[$parentId] = true;
            $parent = Account::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($parentId);
            if ((int) $parent->getAttribute('account_group_id') !== $groupId || ($requireActive && $parent->getAttribute('status') !== 'active') || (bool) $parent->getAttribute('is_postable')) {
                $this->fail('parent_id', 'Choose an active non-postable parent in the same account group.');
            }
            $next = $parent->getAttribute('parent_id');
            $parentId = $next === null ? null : (int) $next;
        }

        return $depth;
    }

    private function descendantHeight(Account $account, int $groupId): int
    {
        $rows = Account::query()->where('company_id', $account->getAttribute('company_id'))->get(['id', 'parent_id', 'account_group_id']);
        $frontier = [(int) $account->getKey()];
        $seen = [(int) $account->getKey() => true];
        $height = 0;
        while ($frontier !== []) {
            $next = [];
            foreach ($rows as $row) {
                if ($row->getAttribute('parent_id') === null || ! in_array((int) $row->getAttribute('parent_id'), $frontier, true)) {
                    continue;
                }
                $id = (int) $row->getKey();
                if (isset($seen[$id]) || (int) $row->getAttribute('account_group_id') !== $groupId) {
                    $this->fail('account_group_id', 'Reclassification cannot invalidate descendant accounts or conceal a cycle.');
                }
                $seen[$id] = true;
                $next[] = $id;
            }
            if ($next !== []) {
                $height++;
            }
            if ($height > 32) {
                $this->fail('parent_id', 'Account hierarchy requires review.');
            }
            $frontier = $next;
        }

        return $height;
    }

    private function hasProtectedReferences(Account $account): bool
    {
        $children = [];
        foreach (Account::query()->where('company_id', $account->getAttribute('company_id'))->get(['id', 'parent_id']) as $row) {
            if ($row->getAttribute('parent_id') !== null) {
                $children[(int) $row->getAttribute('parent_id')][] = (int) $row->getKey();
            }
        }
        $stack = [(int) $account->getKey()];
        $seen = [];
        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($seen[$id])) {
                $this->fail('parent_id', 'Account hierarchy requires review.');
            }
            $seen[$id] = true;
            foreach ($children[$id] ?? [] as $child) {
                $stack[] = $child;
            }
        }
        $ids = array_keys($seen);

        return JournalEntryLine::query()->whereIn('account_id', $ids)->exists() || AccountingAccountMapping::query()->whereIn('account_id', $ids)->whereIn('mapping_version_id', AccountingMappingVersion::query()->whereIn('state', ['published', 'retired'])->select('id'))->exists();
    }

    private function assertGroup(AccountGroup $group): void
    {
        $definition = $this->catalog->groups()[(string) $group->getAttribute('category')] ?? null;
        if ($definition === null || $group->getAttribute('code') !== $definition['code'] || $group->getAttribute('normal_side') !== $definition['normal_side']) {
            $this->fail('account_group_id', 'Account group classification requires review.');
        }
    }

    private function revision(Account $account, int $revision): void
    {
        if ((int) $account->getAttribute('revision') !== $revision) {
            $this->fail('revision', 'This account changed. Reload before continuing.');
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Account $account): array
    {
        return ['account_group_id' => (int) $account->getAttribute('account_group_id'), 'parent_id' => $account->getAttribute('parent_id') === null ? null : (int) $account->getAttribute('parent_id'), 'code' => (string) $account->getAttribute('code'), 'name' => (string) $account->getAttribute('name'), 'normal_side' => (string) $account->getAttribute('normal_side'), 'is_postable' => (bool) $account->getAttribute('is_postable'), 'control_role' => $account->getAttribute('control_role'), 'statement_role' => (string) $account->getAttribute('statement_role'), 'cash_flow_role' => $account->getAttribute('cash_flow_role'), 'status' => (string) $account->getAttribute('status'), 'revision' => (int) $account->getAttribute('revision')];
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
