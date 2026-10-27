<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingAccountMapping;
use App\Models\Accounting\AccountingAuditEvent;
use App\Models\Accounting\AccountingMappingVersion;
use App\Models\Accounting\AccountingSetting;
use App\Models\Accounting\JournalEntryLine;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonInterface;

/** Read-only UI projections; domain service remains the write authority. */
final class AccountingAccountWorkspace
{
    public function __construct(private readonly AccountingCompanyAccess $access, private readonly AccountingAccountService $accounts, private readonly AccountingConfigurationGuard $configuration, private readonly AccountingAccountCatalog $catalog) {}

    /** @return array<string, mixed> */
    public function context(User $actor, ?int $companyId): array
    {
        $companies = $this->access->companies($actor, 'erp.accounts.view')->orderBy('name')->orderBy('id')->get(['id', 'name', 'code', 'currency_code', 'timezone', 'status']);
        if ($companyId !== null) {
            $this->access->authorize($actor, $companyId, 'erp.accounts.view');
        }
        if ($companyId === null && $companies->count() === 1) {
            $single = $companies->first();
            $companyId = $single === null ? null : (int) $single->getKey();
        }
        $company = $companyId === null ? null : $companies->firstWhere('id', $companyId);
        if ($companyId !== null && $company === null) {
            abort(404);
        }
        $data = $company === null ? ['groups' => [], 'accounts' => []] : $this->accounts->read($actor, (int) $company->getKey());
        $setting = $company === null ? null : AccountingSetting::query()->where('company_id', $company->getKey())->first();
        $issues = $company === null ? [] : $this->configuration->issues($company, $setting);
        $rows = $data['accounts'];
        $byId = [];
        $activeChildren = [];
        $childCounts = [];
        foreach ($rows as $row) {
            $byId[$row['id']] = $row;
            if ($row['parent_id'] !== null) {
                $childCounts[$row['parent_id']] = ($childCounts[$row['parent_id']] ?? 0) + 1;
                if ($row['status'] === 'active') {
                    $activeChildren[$row['parent_id']] = ($activeChildren[$row['parent_id']] ?? 0) + 1;
                }
            }
        }
        $ids = array_keys($byId);
        $protected = [];
        $references = JournalEntryLine::query()->whereIn('account_id', $ids)->pluck('account_id')->all();
        $mappingIds = AccountingMappingVersion::query()->whereIn('state', ['published', 'retired'])->select('id');
        $references = [...$references, ...AccountingAccountMapping::query()->whereIn('account_id', $ids)->whereIn('mapping_version_id', $mappingIds)->pluck('account_id')->all()];
        foreach ($references as $reference) {
            $id = (int) $reference;
            while (isset($byId[$id]) && ! isset($protected[$id])) {
                $protected[$id] = true;
                $parent = $byId[$id]['parent_id'];
                if ($parent === null) {
                    break;
                } $id = (int) $parent;
            }
        }
        $published = array_fill_keys(array_map('intval', AccountingAccountMapping::query()->whereIn('account_id', $ids)->whereIn('mapping_version_id', AccountingMappingVersion::query()->where('state', 'published')->select('id'))->pluck('account_id')->all()), true);
        $ready = $company !== null && $issues === [];
        foreach ($rows as &$row) {
            $id = (int) $row['id'];
            $parent = $row['parent_id'];
            $ancestorsActive = true;
            while ($parent !== null && isset($byId[$parent])) {
                if ($byId[$parent]['status'] !== 'active') {
                    $ancestorsActive = false;
                } $parent = $byId[$parent]['parent_id'];
            }
            $blockers = [];
            if ($row['status'] === 'active' && ($activeChildren[$id] ?? 0) > 0) {
                $blockers[] = 'Deactivate active child accounts first.';
            }
            if ($row['status'] === 'active' && isset($published[$id])) {
                $blockers[] = 'Retire or replace published mappings first.';
            }
            if ($row['status'] === 'inactive' && ! $ancestorsActive) {
                $blockers[] = 'Reactivate parent accounts first.';
            }
            $row['semantic_locked'] = isset($protected[$id]);
            $row['has_children'] = ($childCounts[$id] ?? 0) > 0;
            $row['ancestors_active'] = $ancestorsActive;
            $row['status_blockers'] = $blockers;
            $row['can_edit'] = $ready && $actor->can('erp.accounts.edit');
            $row['can_change_status'] = $ready && $blockers === [] && $actor->can('erp.accounts.deactivate');
        }
        unset($row);

        return ['companies' => $companies->map(static fn (Company $row): array => $row->only(['id', 'name', 'code', 'currency_code', 'timezone', 'status']))->all(), 'company' => $company === null ? null : $company->only(['id', 'name', 'code', 'currency_code', 'timezone', 'status']), 'groups' => $data['groups'], 'accounts' => $rows, 'issues' => $issues, 'ready' => $ready, 'canCreate' => $ready && count($data['groups']) === 5 && $actor->can('erp.accounts.create'), 'canInitializeGroups' => $ready && count($data['groups']) < 5 && $actor->can('erp.accounts.create'), 'canViewSetup' => $actor->can('erp.accounting-settings.view'), 'statementRoles' => $this->catalog->statementRoles(), 'controlRoles' => $this->catalog->controlRoles()];
    }

    public function find(User $actor, int $id): Account
    {
        return Account::query()->whereIn('company_id', $this->access->companies($actor, 'erp.accounts.view')->select('id'))->findOrFail($id);
    }

    /** @return list<array<string, mixed>> */
    public function audit(User $actor, Account $account): array
    {
        if (! $actor->can('erp.accounting-audit.view')) {
            return [];
        }
        $companyId = (int) $account->getAttribute('company_id');
        $this->access->authorize($actor, $companyId, 'erp.accounting-audit.view');

        return AccountingAuditEvent::query()->where('company_id', $companyId)->where('entity_kind', 'account')->where('entity_id', $account->getKey())->orderByDesc('id')->limit(50)->get()->map(static function (AccountingAuditEvent $event): array {
            $time = $event->getAttribute('occurred_at');
            $actor = $event->getAttribute('actor_snapshot') ?? [];

            return ['id' => (int) $event->getKey(), 'action' => (string) $event->getAttribute('action'), 'actor' => (string) ($actor['name'] ?? 'Unknown actor'), 'reason' => (string) $event->getAttribute('reason'), 'before' => $event->getAttribute('before_payload') ?? [], 'after' => $event->getAttribute('after_payload') ?? [], 'occurred_at' => $time instanceof CarbonInterface ? $time->toIso8601String() : null];
        })->all();
    }
}
