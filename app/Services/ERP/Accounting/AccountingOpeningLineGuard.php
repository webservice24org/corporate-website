<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Branch;
use App\Models\Department;
use Illuminate\Validation\ValidationException;

/** Structural opening guard. Source reconciliation is a separate dry-run result, never inferred here. */
final class AccountingOpeningLineGuard
{
    public function __construct(private readonly AccountingAccountCatalog $catalog, private readonly AccountingCompanyAccess $access) {}

    /** @param list<array<string, mixed>> $lines */
    public function validate(int $companyId, array $lines): void
    {
        $this->access->requireTransaction();
        $accounts = Account::query()->where('company_id', $companyId)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $groups = AccountGroup::query()->where('company_id', $companyId)->get()->keyBy('id');
        $parents = [];
        foreach ($accounts as $account) {
            if ($account->getAttribute('parent_id') !== null) {
                $parents[(int) $account->getAttribute('parent_id')] = true;
            }
        }
        $branchIds = [];
        $departmentIds = [];
        foreach ($lines as $line) {
            if ($line['branch_id'] !== null) {
                $branchIds[] = (int) $line['branch_id'];
            } if ($line['department_id'] !== null) {
                $departmentIds[] = (int) $line['department_id'];
            }
        }
        $branches = Branch::query()->where('company_id', $companyId)->whereIn('id', array_unique($branchIds))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $departments = Department::query()->where('company_id', $companyId)->whereIn('id', array_unique($departmentIds))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($lines as $index => $line) {
            $account = $accounts->get((int) $line['account_id']);
            if ($account === null || $account->getAttribute('status') !== 'active' || ! $account->getAttribute('is_postable') || isset($parents[(int) $account->getKey()])) {
                $this->fail('lines.'.$index.'.account_id', 'Choose an active postable leaf account owned by this company.');
            }
            $group = $groups->get($account->getAttribute('account_group_id'));
            $category = $group === null ? '' : (string) $group->getAttribute('category');
            $definition = $this->catalog->groups()[$category] ?? null;
            if ($group === null || $definition === null || $group->getAttribute('code') !== $definition['code'] || $group->getAttribute('normal_side') !== $definition['normal_side'] || ! in_array($account->getAttribute('normal_side'), ['debit', 'credit'], true) || ! in_array($account->getAttribute('statement_role'), $this->catalog->statementRoles()[$category], true) || ! in_array($account->getAttribute('cash_flow_role'), [null, 'operating', 'investing', 'financing', 'cash', 'noncash'], true)) {
                $this->fail('lines.'.$index.'.account_id', 'Account classification requires review.');
            }
            if (! in_array($category, ['asset', 'liability', 'equity'], true)) {
                $this->fail('lines.'.$index.'.account_id', 'Opening P&L balances require a separately reviewed conversion contract; use balance-sheet accounts here.');
            }
            $seen = [(int) $account->getKey() => true];
            $parent = $account->getAttribute('parent_id');
            while ($parent !== null) {
                $id = (int) $parent;
                $ancestor = $accounts->get($id);
                if ($ancestor === null || isset($seen[$id]) || count($seen) >= 32 || $ancestor->getAttribute('status') !== 'active' || $ancestor->getAttribute('is_postable') || (int) $ancestor->getAttribute('account_group_id') !== (int) $account->getAttribute('account_group_id')) {
                    $this->fail('lines.'.$index.'.account_id', 'Account hierarchy requires active compatible nonpostable parents without cycles.');
                }
                $seen[$id] = true;
                $parent = $ancestor->getAttribute('parent_id');
            }
            $branchId = $line['branch_id'];
            $departmentId = $line['department_id'];
            if ($branchId !== null) {
                $branch = $branches->get($branchId);
                if ($branch === null || $branch->getAttribute('status') !== 'active') {
                    $this->fail('lines.'.$index.'.branch_id', 'Choose an active branch owned by this company.');
                }
            }
            if ($departmentId !== null) {
                $department = $departments->get($departmentId);
                if ($department === null || $department->getAttribute('status') !== 'active' || ($department->getAttribute('branch_id') !== null && (int) $department->getAttribute('branch_id') !== $branchId)) {
                    $this->fail('lines.'.$index.'.department_id', 'Choose an active company department and its matching branch when branch-specific.');
                }
            }
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
