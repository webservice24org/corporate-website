<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingAccountMapping;
use App\Models\Accounting\AccountingAuditEvent;
use App\Models\Accounting\AccountingMappingVersion;
use App\Models\Accounting\AccountingSetting;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCompanyAccess;
use App\Services\ERP\Accounting\AccountingConfigurationGuard;
use App\Services\ERP\Accounting\AccountingMappingCatalog;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\AccountingStarterTemplate;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AccountingMappingController extends Controller
{
    public function __construct(private readonly AccountingCompanyAccess $access, private readonly AccountingConfigurationGuard $configuration, private readonly AccountingMappingCatalog $catalog, private readonly AccountingMappingService $service, private readonly AccountingStarterTemplate $starter) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate(['company_id' => ['nullable', 'integer', 'min:1']]);
        $actor = $this->actor($request);
        $companies = $this->access->companies($actor, 'erp.accounting-mappings.view')->orderBy('name')->orderBy('id')->get(['id', 'name', 'code', 'currency_code', 'timezone', 'status']);
        $id = isset($validated['company_id']) ? (int) $validated['company_id'] : null;
        if ($id !== null) {
            $this->access->authorize($actor, $id, 'erp.accounting-mappings.view');
        }
        if ($id === null && $companies->count() === 1) {
            $single = $companies->first();
            $id = $single === null ? null : (int) $single->getKey();
        }
        $company = $id === null ? null : $companies->firstWhere('id', $id);
        if ($id !== null && $company === null) {
            abort(404);
        }
        $issues = [];
        if ($company !== null) {
            $issues = $this->configuration->issues($company, AccountingSetting::query()->where('company_id', $company->getKey())->first());
        }
        $versions = $company === null ? [] : AccountingMappingVersion::query()->where('company_id', $id)->orderByDesc('version')->limit(50)->get()->map(fn (AccountingMappingVersion $row): array => $this->version($row))->all();
        $groups = $company === null ? [] : AccountGroup::query()->where('company_id', $id)->get()->keyBy('id')->all();
        $accounts = [];
        if ($company !== null) {
            foreach (Account::query()->where('company_id', $id)->orderBy('code')->get() as $row) {
                $group = $groups[$row->getAttribute('account_group_id')] ?? null;
                $accounts[] = ['id' => (int) $row->getKey(), ...$row->only(['code', 'name', 'status', 'is_postable', 'control_role', 'statement_role', 'cash_flow_role', 'normal_side']), 'category' => $group === null ? null : $group->getAttribute('category')];
            }
        }

        return Inertia::render('ERP/Accounting/Mappings/Index', ['companies' => $companies->map(static fn (Company $row): array => $row->only(['id', 'name', 'code', 'currency_code', 'timezone', 'status']))->all(), 'company' => $company === null ? null : $company->only(['id', 'name', 'code', 'currency_code', 'timezone', 'status']), 'issues' => $issues, 'suggestedMappings' => $this->starter->suggestions($accounts), 'roles' => $this->catalog->roles(), 'controls' => array_combine(array_keys($this->catalog->roles()), array_map(fn (string $role): ?string => $this->catalog->control($role), array_keys($this->catalog->roles()))), 'statements' => array_combine(array_keys($this->catalog->roles()), array_map(fn (string $role): array => $this->catalog->statements($role), array_keys($this->catalog->roles()))), 'versions' => $versions, 'accounts' => $accounts, 'canPublish' => $company !== null && $issues === [] && $actor->can('erp.accounting-mappings.publish'), 'canViewAccounts' => $actor->can('erp.accounts.view'), 'canViewSetup' => $actor->can('erp.accounting-settings.view')]);
    }

    public function show(Request $request, int $mapping): Response
    {
        $actor = $this->actor($request);
        $version = AccountingMappingVersion::query()->whereIn('company_id', $this->access->companies($actor, 'erp.accounting-mappings.view')->select('id'))->findOrFail($mapping);
        $companyId = (int) $version->getAttribute('company_id');
        $company = $this->access->authorize($actor, $companyId, 'erp.accounting-mappings.view');
        $entries = [];
        foreach (AccountingAccountMapping::query()->where('company_id', $companyId)->where('mapping_version_id', $mapping)->orderBy('role')->get() as $row) {
            $account = Account::query()->where('company_id', $companyId)->find($row->getAttribute('account_id'));
            $entries[] = ['role' => (string) $row->getAttribute('role'), 'account' => $account === null ? null : $account->only(['id', 'code', 'name', 'status'])];
        }
        $audit = [];
        if ($actor->can('erp.accounting-audit.view')) {
            foreach (AccountingAuditEvent::query()->where('company_id', $companyId)->where('entity_kind', 'mapping')->where('entity_id', $mapping)->orderByDesc('id')->limit(50)->get() as $event) {
                $snapshot = $event->getAttribute('actor_snapshot') ?? [];
                $time = $event->getAttribute('occurred_at');
                $audit[] = ['id' => (int) $event->getKey(), 'action' => (string) $event->getAttribute('action'), 'actor' => (string) ($snapshot['name'] ?? 'Unknown actor'), 'reason' => (string) $event->getAttribute('reason'), 'occurred_at' => $time instanceof CarbonInterface ? $time->toIso8601String() : null];
            }
        }

        return Inertia::render('ERP/Accounting/Mappings/Show', ['company' => $company->only(['id', 'name', 'code', 'currency_code']), 'version' => $this->version($version), 'entries' => $entries, 'audit' => $audit, 'canViewAudit' => $actor->can('erp.accounting-audit.view'), 'canViewAccounts' => $actor->can('erp.accounts.view')]);
    }

    public function publish(Request $request): RedirectResponse
    {
        $version = $this->service->publish($this->actor($request), $request->all());

        return redirect()->route('erp.accounting.mappings.show', $version->getKey())->with('success', 'Posting profile published. Financial posting has not been activated.');
    }

    /** @return array<string, mixed> */
    private function version(AccountingMappingVersion $row): array
    {
        $mapping = [];
        foreach (AccountingAccountMapping::query()->where('company_id', $row->getAttribute('company_id'))->where('mapping_version_id', $row->getKey())->orderBy('role')->get() as $entry) {
            $mapping[(string) $entry->getAttribute('role')] = (int) $entry->getAttribute('account_id');
        }

        return ['id' => (int) $row->getKey(), 'version' => (int) $row->getAttribute('version'), 'state' => (string) $row->getAttribute('state'), 'effective_from' => $row->getAttribute('effective_from')->format('Y-m-d'), 'payload_hash' => $row->getAttribute('payload_hash'), 'mappings' => $mapping];
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
