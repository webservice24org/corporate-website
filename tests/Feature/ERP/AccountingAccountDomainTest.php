<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingAccountMapping;
use App\Models\Accounting\AccountingMappingVersion;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalEntryLine;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingAccountService;
use App\Services\ERP\Accounting\AccountingSetupService;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AccountingAccountDomainTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private AccountingAccountService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingPermissionSeeder::class);
        $this->company = $this->newCompany('COA-A');
        $this->actor = User::factory()->create();
        $this->actor->assignRole('super-admin');
        app(AccountingSetupService::class)->initialize($this->actor, (int) $this->company->getKey());
        $this->service = app(AccountingAccountService::class);
    }

    private function newCompany(string $code): Company
    {
        return Company::query()->create(['name' => $code, 'code' => $code, 'status' => 'active', 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka']);
    }

    /** @param list<string> $permissions */
    private function staff(array $permissions): User
    {
        $user = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $user->getKey(), 'employee_number' => 'COA-'.$user->getKey(), 'first_name' => 'Account', 'last_name' => 'Manager']);
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    private function group(string $category = 'asset', ?Company $company = null): AccountGroup
    {
        $company ??= $this->company;
        $this->service->initializeGroups($this->actor, (int) $company->getKey());

        return AccountGroup::query()->where('company_id', $company->getKey())->where('category', $category)->firstOrFail();
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function data(array $overrides = []): array
    {
        return ['company_id' => $this->company->getKey(), 'account_group_id' => $this->group()->getKey(), 'parent_id' => null, 'code' => '1000', 'name' => 'Operating Cash', 'normal_side' => 'debit', 'is_postable' => true, 'control_role' => null, 'statement_role' => 'current_asset', 'cash_flow_role' => null, 'reason' => 'Create company chart entry', ...$overrides];
    }

    /** @param array<string, mixed> $overrides */
    private function create(array $overrides = []): Account
    {
        return $this->service->save($this->actor, $this->data($overrides));
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function editData(Account $account, array $overrides = []): array
    {
        return [...$account->only(['company_id', 'account_group_id', 'parent_id', 'code', 'name', 'normal_side', 'is_postable', 'control_role', 'statement_role', 'cash_flow_role', 'revision']), 'reason' => 'Review account configuration', ...$overrides];
    }

    private function invalid(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Expected Chart of Accounts validation failure.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    private function reference(Account $account): void
    {
        $year = FiscalYear::query()->create(['company_id' => $this->company->getKey(), 'code' => 'FY26', 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $period = AccountingPeriod::query()->create(['company_id' => $this->company->getKey(), 'fiscal_year_id' => $year->getKey(), 'code' => 'OCT26', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
        $journal = JournalEntry::query()->create(['company_id' => $this->company->getKey(), 'accounting_period_id' => $period->getKey(), 'number' => 'JV-COA-TEST', 'accounting_date' => '2026-10-15', 'currency_code' => 'BDT', 'description' => 'Unposted reference fixture', 'created_by' => $this->actor->getKey(), 'updated_by' => $this->actor->getKey()]);
        JournalEntryLine::query()->create(['company_id' => $this->company->getKey(), 'journal_entry_id' => $journal->getKey(), 'account_id' => $account->getKey(), 'line_number' => 1, 'debit' => '1', 'credit' => '0']);
    }

    private function publishedMapping(Account $account): AccountingMappingVersion
    {
        $version = AccountingMappingVersion::query()->create(['company_id' => $this->company->getKey(), 'version' => 1, 'effective_from' => '2026-01-01']);
        AccountingAccountMapping::query()->create(['company_id' => $this->company->getKey(), 'mapping_version_id' => $version->getKey(), 'role' => 'inventory', 'account_id' => $account->getKey()]);
        $version->update(['state' => 'published']);

        return $version;
    }

    public function test_group_initialization_is_explicit_and_repeatable(): void
    {
        $this->assertSame([], $this->service->read($this->actor, (int) $this->company->getKey())['groups']);
        $first = $this->service->initializeGroups($this->actor, (int) $this->company->getKey());
        $again = $this->service->initializeGroups($this->actor, (int) $this->company->getKey());
        $this->assertCount(5, $first);
        $this->assertSame($first[0]->getKey(), $again[0]->getKey());
        $this->assertDatabaseCount('account_groups', 5);
        $this->assertSame(5, DB::table('accounting_audit_events')->where('entity_kind', 'account_group')->count());
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_standard_group_sides_and_classifications(): void
    {
        $groups = $this->service->initializeGroups($this->actor, (int) $this->company->getKey());
        $this->assertSame(['asset', 'liability', 'equity', 'revenue', 'expense'], array_map(static fn (AccountGroup $g): string => (string) $g->getAttribute('category'), $groups));
        $this->assertSame(['debit', 'credit', 'credit', 'credit', 'debit'], array_map(static fn (AccountGroup $g): string => (string) $g->getAttribute('normal_side'), $groups));
    }

    public function test_group_collision_rolls_back_earlier_creations(): void
    {
        AccountGroup::query()->create(['company_id' => $this->company->getKey(), 'code' => 'CUSTOM-EQUITY', 'name' => 'Legacy', 'category' => 'equity', 'normal_side' => 'credit']);
        $this->invalid(fn () => $this->service->initializeGroups($this->actor, (int) $this->company->getKey()), 'account_group_id');
        $this->assertDatabaseCount('account_groups', 1);
        $this->assertSame(0, DB::table('accounting_audit_events')->where('entity_kind', 'account_group')->count());
    }

    public function test_setup_is_required_before_creating_groups(): void
    {
        $other = $this->newCompany('COA-B');
        $this->invalid(fn () => $this->service->initializeGroups($this->actor, (int) $other->getKey()), 'company_id');
    }

    public function test_read_only_permission_cannot_create_groups(): void
    {
        $user = $this->staff(['erp.accounts.view']);
        $this->expectException(AuthorizationException::class);
        $this->service->initializeGroups($user, (int) $this->company->getKey());
    }

    public function test_account_create_normalizes_code_and_records_actor(): void
    {
        $account = $this->create(['code' => ' op-cash ', 'name' => ' Cash desk ']);
        $this->assertSame('OP-CASH', $account->getAttribute('code'));
        $this->assertSame('Cash desk', $account->getAttribute('name'));
        $this->assertSame('active', $account->getAttribute('status'));
        $this->assertDatabaseHas('accounting_audit_events', ['entity_kind' => 'account', 'entity_id' => $account->getKey(), 'actor_id' => $this->actor->getKey(), 'action' => 'create']);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_same_company_duplicate_code_is_rejected(): void
    {
        $this->create();
        $this->invalid(fn () => $this->create(['code' => '1000']), 'code');
        $this->assertDatabaseCount('accounts', 1);
    }

    public function test_same_code_is_allowed_in_different_companies(): void
    {
        $this->create();
        $other = $this->newCompany('COA-B');
        app(AccountingSetupService::class)->initialize($this->actor, (int) $other->getKey());
        $group = $this->group('asset', $other);
        $this->create(['company_id' => $other->getKey(), 'account_group_id' => $group->getKey()]);
        $this->assertDatabaseCount('accounts', 2);
    }

    public function test_blank_name_and_invalid_code_are_rejected(): void
    {
        $this->invalid(fn () => $this->create(['name' => '   ']), 'name');
        $this->invalid(fn () => $this->create(['code' => '<script>']), 'code');
    }

    public function test_statement_role_must_match_category(): void
    {
        $this->invalid(fn () => $this->create(['statement_role' => 'operating_revenue']), 'statement_role');
    }

    public function test_control_role_must_match_category_and_be_postable(): void
    {
        $this->invalid(fn () => $this->create(['control_role' => 'trade_ap']), 'control_role');
        $this->invalid(fn () => $this->create(['is_postable' => false, 'control_role' => 'trade_ar']), 'control_role');
    }

    public function test_revenue_contra_account_can_have_debit_normal_side(): void
    {
        $revenue = $this->group('revenue');
        $account = $this->create(['account_group_id' => $revenue->getKey(), 'normal_side' => 'debit', 'statement_role' => 'sales_allowances']);
        $this->assertSame('debit', $account->getAttribute('normal_side'));
    }

    public function test_cash_classification_rejects_conflicting_control_account(): void
    {
        $this->invalid(fn () => $this->create(['control_role' => 'trade_ar', 'cash_flow_role' => 'cash']), 'cash_flow_role');
    }

    public function test_hierarchy_accepts_nonpostable_parent_and_reads_depth(): void
    {
        $parent = $this->create(['code' => '100', 'name' => 'Current Assets', 'is_postable' => false]);
        $child = $this->create(['code' => '110', 'parent_id' => $parent->getKey()]);
        $rows = $this->service->read($this->actor, (int) $this->company->getKey())['accounts'];
        $this->assertSame([$parent->getKey(), $child->getKey()], array_column($rows, 'id'));
        $this->assertSame([0, 1], array_column($rows, 'depth'));
    }

    public function test_postable_parent_is_rejected(): void
    {
        $parent = $this->create();
        $this->invalid(fn () => $this->create(['code' => '1100', 'parent_id' => $parent->getKey()]), 'parent_id');
    }

    public function test_cross_category_parent_is_rejected(): void
    {
        $group = $this->group('liability');
        $parent = $this->create(['account_group_id' => $group->getKey(), 'code' => '200', 'normal_side' => 'credit', 'statement_role' => 'current_liability', 'is_postable' => false]);
        $this->invalid(fn () => $this->create(['parent_id' => $parent->getKey()]), 'parent_id');
    }

    public function test_self_parent_and_descendant_cycle_are_rejected(): void
    {
        $parent = $this->create(['code' => '100', 'is_postable' => false]);
        $child = $this->create(['code' => '110', 'is_postable' => false, 'parent_id' => $parent->getKey()]);
        $this->invalid(fn () => $this->service->save($this->actor, $this->editData($parent, ['parent_id' => $parent->getKey()]), (int) $parent->getKey()), 'parent_id');
        $this->invalid(fn () => $this->service->save($this->actor, $this->editData($parent, ['parent_id' => $child->getKey()]), (int) $parent->getKey()), 'parent_id');
    }

    public function test_parent_with_children_cannot_become_postable(): void
    {
        $parent = $this->create(['code' => '100', 'is_postable' => false]);
        $this->create(['code' => '110', 'parent_id' => $parent->getKey()]);
        $this->invalid(fn () => $this->service->save($this->actor, $this->editData($parent, ['is_postable' => true]), (int) $parent->getKey()), 'is_postable');
    }

    public function test_parent_reclassification_cannot_invalidate_descendants(): void
    {
        $parent = $this->create(['code' => '100', 'is_postable' => false]);
        $this->create(['code' => '110', 'parent_id' => $parent->getKey()]);
        $liability = $this->group('liability');
        $this->invalid(fn () => $this->service->save($this->actor, $this->editData($parent, ['account_group_id' => $liability->getKey(), 'statement_role' => 'current_liability']), (int) $parent->getKey()), 'account_group_id');
    }

    public function test_foreign_parent_is_not_found(): void
    {
        $other = $this->newCompany('COA-B');
        app(AccountingSetupService::class)->initialize($this->actor, (int) $other->getKey());
        $group = $this->group('asset', $other);
        $foreign = $this->create(['company_id' => $other->getKey(), 'account_group_id' => $group->getKey(), 'is_postable' => false]);
        $this->expectException(ModelNotFoundException::class);
        $this->create(['parent_id' => $foreign->getKey()]);
    }

    public function test_foreign_group_is_not_found(): void
    {
        $other = $this->newCompany('COA-B');
        app(AccountingSetupService::class)->initialize($this->actor, (int) $other->getKey());
        $group = $this->group('asset', $other);
        $this->expectException(ModelNotFoundException::class);
        $this->create(['account_group_id' => $group->getKey()]);
    }

    public function test_scoped_staff_cannot_write_another_company(): void
    {
        $other = $this->newCompany('COA-B');
        $user = $this->staff(['erp.accounts.create']);
        $this->expectException(AuthorizationException::class);
        $this->service->initializeGroups($user, (int) $other->getKey());
    }

    public function test_account_read_is_scoped_and_does_not_write_audit(): void
    {
        $own = $this->create();
        $before = DB::table('accounting_audit_events')->count();
        $data = $this->service->read($this->staff(['erp.accounts.view']), (int) $this->company->getKey());
        $this->assertSame([$own->getKey()], array_column($data['accounts'], 'id'));
        $this->assertSame($before, DB::table('accounting_audit_events')->count());
    }

    public function test_create_permission_does_not_allow_edit(): void
    {
        $account = $this->create();
        $user = $this->staff(['erp.accounts.create']);
        $this->expectException(AuthorizationException::class);
        $this->service->save($user, $this->editData($account), (int) $account->getKey());
    }

    public function test_status_cannot_be_smuggled_through_edit_payload(): void
    {
        $account = $this->create();
        $this->invalid(fn () => $this->service->save($this->actor, $this->editData($account, ['status' => 'inactive']), (int) $account->getKey()), 'status');
    }

    public function test_stale_edit_preserves_account_and_audit(): void
    {
        $account = $this->create();
        $count = DB::table('accounting_audit_events')->count();
        $this->invalid(fn () => $this->service->save($this->actor, $this->editData($account, ['revision' => 999, 'name' => 'Stale']), (int) $account->getKey()), 'revision');
        $this->assertSame('Operating Cash', $account->refresh()->getAttribute('name'));
        $this->assertSame($count, DB::table('accounting_audit_events')->count());
    }

    public function test_unused_semantics_can_change_but_referenced_semantics_are_frozen(): void
    {
        $account = $this->create();
        $account = $this->service->save($this->actor, $this->editData($account, ['code' => '1010']), (int) $account->getKey());
        $this->reference($account);
        $this->invalid(fn () => $this->service->save($this->actor, $this->editData($account, ['normal_side' => 'credit']), (int) $account->getKey()), 'normal_side');
        $this->assertSame('1010', $account->refresh()->getAttribute('code'));
    }

    public function test_referenced_account_can_be_renamed_with_audit(): void
    {
        $account = $this->create();
        $this->reference($account);
        $edited = $this->service->save($this->actor, $this->editData($account, ['name' => 'Renamed Cash']), (int) $account->getKey());
        $this->assertSame('Renamed Cash', $edited->getAttribute('name'));
        $this->assertDatabaseHas('accounting_audit_events', ['entity_kind' => 'account', 'entity_id' => $account->getKey(), 'action' => 'edit']);
    }

    public function test_published_and_retired_mappings_freeze_account_semantics(): void
    {
        $account = $this->create();
        $version = $this->publishedMapping($account);
        $this->invalid(fn () => $this->service->save($this->actor, $this->editData($account, ['code' => '1010']), (int) $account->getKey()), 'code');
        $version->update(['state' => 'retired']);
        $this->invalid(fn () => $this->service->save($this->actor, $this->editData($account, ['statement_role' => 'non_current_asset']), (int) $account->getKey()), 'statement_role');
    }

    public function test_published_mapping_blocks_deactivation_until_retired(): void
    {
        $account = $this->create();
        $version = $this->publishedMapping($account);
        $this->invalid(fn () => $this->service->setStatus($this->actor, (int) $this->company->getKey(), (int) $account->getKey(), 'inactive', 1, 'Retire cash account'), 'status');
        $version->update(['state' => 'retired']);
        $result = $this->service->setStatus($this->actor, (int) $this->company->getKey(), (int) $account->getKey(), 'inactive', 1, 'Retire cash account');
        $this->assertSame('inactive', $result->getAttribute('status'));
    }

    public function test_status_changes_require_separate_permission(): void
    {
        $account = $this->create();
        $user = $this->staff(['erp.accounts.edit']);
        $this->expectException(AuthorizationException::class);
        $this->service->setStatus($user, (int) $this->company->getKey(), (int) $account->getKey(), 'inactive', 1, 'Retire account');
    }

    public function test_active_child_blocks_parent_deactivation_and_inactive_parent_blocks_reactivation(): void
    {
        $parent = $this->create(['code' => '100', 'is_postable' => false]);
        $child = $this->create(['code' => '110', 'parent_id' => $parent->getKey()]);
        $this->invalid(fn () => $this->service->setStatus($this->actor, (int) $this->company->getKey(), (int) $parent->getKey(), 'inactive', 1, 'Retire branch'), 'status');
        $child = $this->service->setStatus($this->actor, (int) $this->company->getKey(), (int) $child->getKey(), 'inactive', 1, 'Retire leaf');
        $this->service->setStatus($this->actor, (int) $this->company->getKey(), (int) $parent->getKey(), 'inactive', 1, 'Retire branch');
        $this->invalid(fn () => $this->service->setStatus($this->actor, (int) $this->company->getKey(), (int) $child->getKey(), 'active', 2, 'Restore leaf'), 'parent_id');
    }

    public function test_reactivation_and_repeated_status_have_clear_audit(): void
    {
        $account = $this->create();
        $account = $this->service->setStatus($this->actor, (int) $this->company->getKey(), (int) $account->getKey(), 'inactive', 1, 'Retire account');
        $count = DB::table('accounting_audit_events')->count();
        $this->service->setStatus($this->actor, (int) $this->company->getKey(), (int) $account->getKey(), 'inactive', 2, 'Retry same state');
        $this->assertSame($count, DB::table('accounting_audit_events')->count());
        $restored = $this->service->setStatus($this->actor, (int) $this->company->getKey(), (int) $account->getKey(), 'active', 2, 'Restore account');
        $this->assertSame(3, $restored->getAttribute('revision'));
        $this->assertDatabaseHas('accounting_audit_events', ['entity_kind' => 'account', 'entity_id' => $account->getKey(), 'action' => 'reactivate']);
    }

    public function test_inactive_company_read_is_allowed_but_writes_fail(): void
    {
        $account = $this->create();
        $this->company->update(['status' => 'inactive']);
        $this->assertCount(1, $this->service->read($this->actor, (int) $this->company->getKey())['accounts']);
        $this->invalid(fn () => $this->service->save($this->actor, $this->editData($account), (int) $account->getKey()), 'company_id');
    }

    public function test_cutover_lock_and_currency_mismatch_block_writes(): void
    {
        $data = $this->data();
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['cutover_locked_at' => now()->toDateTimeString()]);
        $this->invalid(fn () => $this->service->save($this->actor, $data), 'company_id');
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['cutover_locked_at' => null, 'functional_currency' => 'USD']);
        $this->invalid(fn () => $this->service->save($this->actor, $data), 'company_id');
    }

    public function test_caller_rollback_removes_account_and_its_audit(): void
    {
        $data = $this->data();
        $count = DB::table('accounting_audit_events')->count();
        try {
            DB::transaction(function () use ($data): void {
                $this->service->save($this->actor, $data);
                throw new \RuntimeException('Rollback account');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('Rollback account', $e->getMessage());
        }
        $this->assertDatabaseCount('accounts', 0);
        $this->assertSame($count, DB::table('accounting_audit_events')->count());
    }

    public function test_read_detects_corrupted_cycle_instead_of_looping(): void
    {
        $root = $this->create(['code' => '100', 'is_postable' => false]);
        $child = $this->create(['code' => '110', 'is_postable' => false, 'parent_id' => $root->getKey()]);
        DB::table('accounts')->where('id', $root->getKey())->update(['parent_id' => $child->getKey()]);
        $this->invalid(fn () => $this->service->read($this->actor, (int) $this->company->getKey()), 'parent_id');
    }

    public function test_depth_cap_applies_to_creation_and_subtree_reparenting(): void
    {
        $group = $this->group();
        $last = null;
        $root = null;
        for ($i = 1; $i <= 32; $i++) {
            $last = Account::query()->create(['company_id' => $this->company->getKey(), 'account_group_id' => $group->getKey(), 'parent_id' => $last?->getKey(), 'code' => sprintf('N%02d', $i), 'name' => 'Branch '.$i, 'status' => 'active', 'revision' => 1, 'normal_side' => 'debit', 'is_postable' => false, 'statement_role' => 'current_asset']);
            if ($root === null) {
                $root = $last;
            }
        }
        $this->assertNotNull($last);
        $this->assertNotNull($root);
        $this->invalid(fn () => $this->create(['code' => 'TOO-DEEP', 'parent_id' => $last->getKey()]), 'parent_id');
        $this->assertCount(32, $this->service->read($this->actor, (int) $this->company->getKey())['accounts']);
        $newRoot = $this->create(['code' => 'NEW-ROOT', 'is_postable' => false]);
        $this->invalid(fn () => $this->service->save($this->actor, $this->editData($root, ['parent_id' => $newRoot->getKey()]), (int) $root->getKey()), 'parent_id');
    }

    public function test_status_and_edit_actions_require_reasons(): void
    {
        $account = $this->create();
        $this->invalid(fn () => $this->service->save($this->actor, $this->editData($account, ['reason' => '   ']), (int) $account->getKey()), 'reason');
        $this->invalid(fn () => $this->service->setStatus($this->actor, (int) $this->company->getKey(), (int) $account->getKey(), 'inactive', 1, ''), 'reason');
    }

    public function test_foreign_company_read_is_forbidden(): void
    {
        $other = $this->newCompany('COA-B');
        $reader = $this->staff(['erp.accounts.view']);
        $this->expectException(AuthorizationException::class);
        $this->service->read($reader, (int) $other->getKey());
    }

    public function test_inactive_account_can_be_renamed_under_inactive_parent(): void
    {
        $parent = $this->create(['code' => '100', 'is_postable' => false]);
        $child = $this->create(['code' => '110', 'parent_id' => $parent->getKey()]);
        $child = $this->service->setStatus($this->actor, (int) $this->company->getKey(), (int) $child->getKey(), 'inactive', 1, 'Retire leaf');
        $this->service->setStatus($this->actor, (int) $this->company->getKey(), (int) $parent->getKey(), 'inactive', 1, 'Retire branch');
        $edited = $this->service->save($this->actor, $this->editData($child, ['name' => 'Historical leaf']), (int) $child->getKey());
        $this->assertSame('Historical leaf', $edited->getAttribute('name'));
        $this->assertSame('inactive', $edited->getAttribute('status'));
    }

    public function test_reactivation_rejects_corrupted_classification(): void
    {
        $account = $this->create();
        $this->service->setStatus($this->actor, (int) $this->company->getKey(), (int) $account->getKey(), 'inactive', 1, 'Retire account');
        DB::table('accounts')->where('id', $account->getKey())->update(['statement_role' => 'operating_revenue']);
        $this->invalid(fn () => $this->service->setStatus($this->actor, (int) $this->company->getKey(), (int) $account->getKey(), 'active', 2, 'Restore account'), 'status');
    }

    public function test_referenced_descendant_freezes_ancestor_semantics(): void
    {
        $parent = $this->create(['code' => '100', 'is_postable' => false]);
        $child = $this->create(['code' => '110', 'parent_id' => $parent->getKey()]);
        $this->reference($child);
        $this->invalid(fn () => $this->service->save($this->actor, $this->editData($parent, ['code' => '200']), (int) $parent->getKey()), 'code');
        $this->assertSame('100', $parent->refresh()->getAttribute('code'));
    }
}
