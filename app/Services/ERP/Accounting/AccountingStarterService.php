<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingSetting;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class AccountingStarterService
{
    public function __construct(private readonly AccountingCompanyAccess $access, private readonly AccountingConfigurationGuard $configuration, private readonly AccountingStarterTemplate $template, private readonly AccountingAccountCatalog $catalog, private readonly AccountingAccountService $accounts) {}

    /** @return array<string, mixed> */
    public function preview(User $actor, int $companyId): array
    {
        $company = $this->access->authorize($actor, $companyId, 'erp.accounts.view');

        return $this->plan($company);
    }

    /** @param array<string, mixed> $input @return array{created: int, reused: int} */
    public function apply(User $actor, array $input): array
    {
        $data = Validator::make($input, ['company_id' => ['required', 'integer', 'min:1'], 'template_id' => ['required', 'in:'.AccountingStarterTemplate::ID], 'reason' => ['required', 'string', 'max:1800', 'regex:/\S/u']])->validate();
        if (array_diff(array_keys($input), ['company_id', 'template_id', 'reason']) !== []) {
            $this->fail('template_id', 'Starter input contains unsupported fields.');
        }

        return DB::transaction(function () use ($actor, $data): array {
            $companyId = (int) $data['company_id'];
            $company = $this->access->authorize($actor, $companyId, 'erp.accounts.create', true);
            $this->access->authorize($actor, $companyId, 'erp.accounts.view');
            $this->configuration->lockForSetup($company);
            $this->accounts->read($actor, $companyId);
            $plan = $this->plan($company);
            if ($plan['issues'] !== [] || $plan['counts']['conflict'] > 0) {
                $this->fail('template_id', 'Resolve the preview conflicts or setup issues before applying the starter. Existing records were not changed.');
            }
            $this->accounts->initializeGroups($actor, $companyId);
            $groups = AccountGroup::query()->where('company_id', $companyId)->get()->keyBy('category');
            $byCode = [];
            foreach (Account::query()->where('company_id', $companyId)->get() as $row) {
                $byCode[(string) $row->getAttribute('code')] = (int) $row->getKey();
            }
            $created = 0;
            $reused = 0;
            foreach ($this->template->definitions() as $definition) {
                if (isset($byCode[$definition['code']])) {
                    $reused++;

                    continue;
                }
                $group = $groups->get($definition['category']);
                if ($group === null) {
                    $this->fail('template_id', 'Standard group initialization requires review.');
                }
                $row = $this->accounts->save($actor, ['company_id' => $companyId, 'account_group_id' => $group->getKey(), 'parent_id' => $definition['parent_code'] === null ? null : $byCode[$definition['parent_code']], 'code' => $definition['code'], 'name' => $definition['name'], 'normal_side' => $definition['normal_side'], 'is_postable' => $definition['is_postable'], 'control_role' => $definition['control_role'], 'statement_role' => $definition['statement_role'], 'cash_flow_role' => $definition['cash_flow_role'], 'reason' => 'Starter '.AccountingStarterTemplate::ID.': '.trim((string) $data['reason'])]);
                $byCode[$definition['code']] = (int) $row->getKey();
                $created++;
            }

            return ['created' => $created, 'reused' => $reused];
        });
    }

    /** @return array<string, mixed> */
    private function plan(Company $company): array
    {
        $companyId = (int) $company->getKey();
        $issues = $this->configuration->issues($company, AccountingSetting::query()->where('company_id', $companyId)->first());
        $groups = AccountGroup::query()->where('company_id', $companyId)->get()->keyBy('id');
        foreach ($groups as $group) {
            $category = (string) $group->getAttribute('category');
            $expected = $this->catalog->groups()[$category] ?? null;
            if ($expected === null || $group->getAttribute('code') !== $expected['code'] || $group->getAttribute('normal_side') !== $expected['normal_side']) {
                $issues[] = 'Existing account group classification requires review.';
            }
        }
        $byCode = [];
        $children = [];
        foreach (Account::query()->where('company_id', $companyId)->get() as $row) {
            $byCode[(string) $row->getAttribute('code')] = $row;
            if ($row->getAttribute('parent_id') !== null) {
                $children[(int) $row->getAttribute('parent_id')] = true;
            }
        }
        $rows = [];
        $counts = ['missing' => 0, 'reusable' => 0, 'conflict' => 0];
        foreach ($this->template->definitions() as $definition) {
            $row = $byCode[$definition['code']] ?? null;
            $problems = [];
            if ($row !== null) {
                $group = $groups->get($row->getAttribute('account_group_id'));
                if ($group === null || $group->getAttribute('category') !== $definition['category']) {
                    $problems[] = 'Account group differs.';
                }
                foreach (['normal_side', 'is_postable', 'control_role', 'statement_role', 'cash_flow_role'] as $field) {
                    if ($row->getAttribute($field) !== $definition[$field]) {
                        $problems[] = str_replace('_', ' ', $field).' differs.';
                    }
                }
                $parent = $definition['parent_code'] === null ? null : ($byCode[$definition['parent_code']] ?? null);
                $expectedParent = $parent === null ? null : (int) $parent->getKey();
                $actualParent = $row->getAttribute('parent_id') === null ? null : (int) $row->getAttribute('parent_id');
                if (($definition['parent_code'] !== null && $parent === null) || $expectedParent !== $actualParent) {
                    $problems[] = 'Parent differs.';
                }
                if ($row->getAttribute('status') !== 'active') {
                    $problems[] = 'Account is inactive.';
                }
                if ($definition['is_postable'] && isset($children[(int) $row->getKey()])) {
                    $problems[] = 'Postable account has children.';
                }
            }
            $status = $row === null ? 'missing' : ($problems === [] ? 'reusable' : 'conflict');
            $counts[$status]++;
            $rows[] = [...$definition, 'status' => $status, 'problems' => $problems, 'existing_id' => $row === null ? null : (int) $row->getKey(), 'existing_name' => $row === null ? null : (string) $row->getAttribute('name')];
        }

        return ['template_id' => AccountingStarterTemplate::ID, 'title' => AccountingStarterTemplate::TITLE, 'rows' => $rows, 'counts' => $counts, 'issues' => array_values(array_unique($issues))];
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
