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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AccountingAccountManagementTest extends TestCase
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

    private function url(string $suffix = ''): string
    {
        return '/erp/accounting/accounts'.$suffix;
    }

    public function test_guests_are_redirected(): void
    {
        $this->get($this->url())->assertRedirect('/login');
    }

    public function test_account_view_is_independent_of_setup_view(): void
    {
        $this->actingAs($this->staff(['erp.accounts.view']))->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Accounts/Index')->where('canViewSetup', false)->where('canCreate', false));
    }

    public function test_view_permission_is_required(): void
    {
        $this->actingAs($this->staff([]))->get($this->url())->assertForbidden();
    }

    public function test_read_does_not_initialize_groups(): void
    {
        $this->actingAs($this->actor)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->has('groups', 0)->where('canInitializeGroups', true));
        $this->assertDatabaseCount('account_groups', 0);
    }

    public function test_group_initialization_is_explicit_and_repeatable_over_http(): void
    {
        $this->actingAs($this->actor);
        $this->post($this->url('/initialize-groups'), ['company_id' => $this->company->getKey()])->assertRedirect();
        $this->post($this->url('/initialize-groups'), ['company_id' => $this->company->getKey()])->assertRedirect();
        $this->assertDatabaseCount('account_groups', 5);
        $this->assertSame(5, DB::table('accounting_audit_events')->where('entity_kind', 'account_group')->count());
    }

    public function test_reader_cannot_initialize_or_create(): void
    {
        $this->actingAs($this->staff(['erp.accounts.view']));
        $this->post($this->url('/initialize-groups'), ['company_id' => $this->company->getKey()])->assertForbidden();
        $this->post($this->url(), $this->data())->assertForbidden();
    }

    public function test_create_edit_and_show_pages_exist(): void
    {
        $account = $this->create();
        $this->actingAs($this->actor);
        $this->get($this->url('/create?company_id='.$this->company->getKey()))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Accounts/Form')->where('account', null)->where('canSave', true));
        $this->get($this->url('/'.$account->getKey().'/edit'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Accounts/Form')->where('account.id', $account->getKey()));
        $this->get($this->url('/'.$account->getKey()))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Accounts/Show')->where('account.id', $account->getKey()));
    }

    public function test_create_update_and_status_use_domain_service(): void
    {
        $this->actingAs($this->actor)->post($this->url(), $this->data())->assertRedirect();
        $account = Account::query()->firstOrFail();
        $this->put($this->url('/'.$account->getKey()), $this->editData($account, ['name' => 'Renamed cash']))->assertRedirect();
        $account->refresh();
        $this->assertSame('Renamed cash', $account->getAttribute('name'));
        $this->post($this->url('/'.$account->getKey().'/status'), ['status' => 'inactive', 'revision' => $account->getAttribute('revision'), 'reason' => 'No longer used'])->assertRedirect();
        $this->assertSame('inactive', $account->fresh()->getAttribute('status'));
    }

    public function test_reason_is_required_for_create(): void
    {
        $this->actingAs($this->actor)->post($this->url(), $this->data(['reason' => '']))->assertSessionHasErrors('reason');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_status_rejects_company_payload(): void
    {
        $account = $this->create();
        $this->actingAs($this->actor)->post($this->url('/'.$account->getKey().'/status'), ['company_id' => $this->company->getKey(), 'status' => 'inactive', 'revision' => 1, 'reason' => 'Test'])->assertSessionHasErrors('company_id');
    }

    public function test_stale_update_is_rejected(): void
    {
        $account = $this->create();
        $this->actingAs($this->actor);
        $this->put($this->url('/'.$account->getKey()), $this->editData($account, ['revision' => 999]))->assertSessionHasErrors('revision');
    }

    public function test_foreign_company_filter_is_forbidden(): void
    {
        $other = $this->newCompany('COA-B');
        $this->actingAs($this->staff(['erp.accounts.view']))->get($this->url('?company_id='.$other->getKey()))->assertForbidden();
    }

    public function test_foreign_account_is_not_found(): void
    {
        $account = $this->create();
        $other = $this->newCompany('COA-B');
        $user = User::factory()->create();
        Employee::query()->create(['company_id' => $other->getKey(), 'user_id' => $user->getKey(), 'employee_number' => 'FOREIGN', 'first_name' => 'Other', 'last_name' => 'User']);
        $user->givePermissionTo('erp.accounts.view');
        $this->actingAs($user)->get($this->url('/'.$account->getKey()))->assertNotFound();
    }

    public function test_reader_cannot_edit_or_change_status(): void
    {
        $account = $this->create();
        $this->actingAs($this->staff(['erp.accounts.view']));
        $this->get($this->url('/'.$account->getKey().'/edit'))->assertForbidden();
        $this->put($this->url('/'.$account->getKey()), $this->editData($account))->assertForbidden();
        $this->post($this->url('/'.$account->getKey().'/status'), ['status' => 'inactive', 'revision' => 1, 'reason' => 'Test'])->assertForbidden();
    }

    public function test_editor_cannot_change_status_without_permission(): void
    {
        $account = $this->create();
        $this->actingAs($this->staff(['erp.accounts.view', 'erp.accounts.edit']));
        $this->get($this->url('/'.$account->getKey().'/edit'))->assertOk();
        $this->post($this->url('/'.$account->getKey().'/status'), ['status' => 'inactive', 'revision' => 1, 'reason' => 'Test'])->assertForbidden();
    }

    public function test_audit_requires_its_own_permission(): void
    {
        $account = $this->create();
        $this->actingAs($this->staff(['erp.accounts.view']))->get($this->url('/'.$account->getKey()))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canViewAudit', false)->has('audit', 0));
        $this->actingAs($this->actor)->get($this->url('/'.$account->getKey()))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canViewAudit', true)->has('audit', 1)->missing('audit.0.actor_snapshot'));
    }

    public function test_search_tree_keeps_parent_context(): void
    {
        $parent = $this->create(['code' => '100', 'name' => 'Assets parent', 'is_postable' => false]);
        $child = $this->create(['code' => '101', 'name' => 'Unique leaf', 'parent_id' => $parent->getKey()]);
        $this->actingAs($this->actor)->get($this->url('?search=Unique'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('accounts', 2)->where('accounts.0.id', $parent->getKey())->where('accounts.0.is_context', true)->where('accounts.1.id', $child->getKey())->where('counts.matching', 1));
    }

    public function test_register_search_excludes_parent_context(): void
    {
        $parent = $this->create(['code' => '100', 'is_postable' => false]);
        $this->create(['code' => '101', 'name' => 'Unique leaf', 'parent_id' => $parent->getKey()]);
        $this->actingAs($this->actor)->get($this->url('?mode=register&search=Unique'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('accounts', 1)->where('accounts.0.is_context', false)->where('pagination.total', 1));
    }

    public function test_parent_options_exclude_self_descendants_and_postable_accounts(): void
    {
        $parent = $this->create(['code' => '100', 'is_postable' => false]);
        $this->create(['code' => '101', 'parent_id' => $parent->getKey(), 'is_postable' => false]);
        $this->create(['code' => '200']);
        $this->actingAs($this->actor)->get($this->url('/'.$parent->getKey().'/edit'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('parents', 0));
    }

    public function test_referenced_account_is_projected_as_semantically_locked(): void
    {
        $account = $this->create();
        $this->reference($account);
        $this->actingAs($this->actor)->get($this->url('/'.$account->getKey()))->assertOk()->assertInertia(fn (Assert $page) => $page->where('account.semantic_locked', true)->where('account.can_edit', true));
    }

    public function test_active_children_block_parent_status_action(): void
    {
        $parent = $this->create(['code' => '100', 'is_postable' => false]);
        $this->create(['code' => '101', 'parent_id' => $parent->getKey()]);
        $this->actingAs($this->actor)->get($this->url('/'.$parent->getKey()))->assertOk()->assertInertia(fn (Assert $page) => $page->where('account.can_change_status', false)->has('account.status_blockers', 1));
    }

    public function test_inactive_company_is_readable_but_not_editable(): void
    {
        $account = $this->create();
        $this->company->update(['status' => 'inactive']);
        $this->actingAs($this->actor)->get($this->url('/'.$account->getKey()))->assertOk()->assertInertia(fn (Assert $page) => $page->where('ready', false)->where('account.can_edit', false)->where('account.can_change_status', false));
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->actingAs($this->actor)->get($this->url('?mode=invalid'))->assertSessionHasErrors('mode');
    }

    public function test_register_paginates_fifty_rows_and_keeps_full_counts(): void
    {
        $group = $this->group();
        for ($i = 0; $i < 51; $i++) {
            Account::query()->create(['company_id' => $this->company->getKey(), 'account_group_id' => $group->getKey(), 'code' => 'A'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'name' => 'Register fixture', 'normal_side' => 'debit', 'is_postable' => true, 'statement_role' => 'current_asset', 'status' => 'active', 'revision' => 1]);
        }
        $this->actingAs($this->actor)->get($this->url('?mode=register'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('accounts', 50)->where('pagination.total', 51)->where('pagination.last_page', 2)->where('counts.total', 51));
        $this->get($this->url('?mode=register&page=2'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('accounts', 1)->where('pagination.current_page', 2));
    }

    public function test_missing_setup_blocks_creation_and_group_initialization(): void
    {
        $other = $this->newCompany('UNINITIALIZED');
        $this->actingAs($this->actor)->get($this->url('?company_id='.$other->getKey()))->assertOk()->assertInertia(fn (Assert $page) => $page->where('ready', false)->where('canCreate', false)->where('canInitializeGroups', false)->has('issues', 1));
        $this->post($this->url('/initialize-groups'), ['company_id' => $other->getKey()])->assertSessionHasErrors('company_id');
    }

    public function test_cutover_lock_disables_edit_projection_and_write(): void
    {
        $account = $this->create();
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['cutover_locked_at' => now()]);
        $this->actingAs($this->actor)->get($this->url('/'.$account->getKey().'/edit'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canSave', false));
        $this->put($this->url('/'.$account->getKey()), $this->editData($account))->assertSessionHasErrors('company_id');
    }

    public function test_permission_without_employee_company_is_forbidden(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('erp.accounts.view');
        $this->actingAs($user)->get($this->url())->assertForbidden();
    }

    public function test_published_mapping_blocks_status_and_locks_semantics(): void
    {
        $account = $this->create();
        $this->publishedMapping($account);
        $this->actingAs($this->actor)->get($this->url('/'.$account->getKey()))->assertOk()->assertInertia(fn (Assert $page) => $page->where('account.semantic_locked', true)->where('account.can_change_status', false)->has('account.status_blockers', 1));
    }

    public function test_company_projection_excludes_private_columns(): void
    {
        $this->actingAs($this->actor)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->missing('company.tax_number')->missing('company.address')->missing('configuration'));
    }
}
