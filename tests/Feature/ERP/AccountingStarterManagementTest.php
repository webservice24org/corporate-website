<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingMappingVersion;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingAccountService;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\AccountingStarterService;
use App\Services\ERP\Accounting\AccountingStarterTemplate;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AccountingStarterManagementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private AccountingStarterService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingPermissionSeeder::class);
        $this->company = $this->newCompany('START-A');
        $this->actor = User::factory()->create();
        $this->actor->assignRole('super-admin');
        app(AccountingSetupService::class)->initialize($this->actor, (int) $this->company->getKey());
        $this->service = app(AccountingStarterService::class);
    }

    private function newCompany(string $code): Company
    {
        return Company::query()->create(['name' => $code, 'code' => $code, 'status' => 'active', 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka']);
    }

    /** @param list<string> $permissions */
    private function staff(array $permissions): User
    {
        $user = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $user->getKey(), 'employee_number' => 'START-'.$user->getKey(), 'first_name' => 'Chart', 'last_name' => 'User']);
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function data(array $overrides = []): array
    {
        return ['company_id' => (int) $this->company->getKey(), 'template_id' => AccountingStarterTemplate::ID, 'reason' => 'Create reviewed starter company chart', ...$overrides];
    }

    /** @return array{created: int, reused: int} */
    private function applyStarter(): array
    {
        return $this->service->apply($this->actor, $this->data());
    }

    private function account(string $code): Account
    {
        return Account::query()->where('company_id', $this->company->getKey())->where('code', $code)->firstOrFail();
    }

    private function invalid(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Expected starter validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function publishProfile(string $date = '2026-10-01'): AccountingMappingVersion
    {
        $this->applyStarter();
        $preview = $this->service->preview($this->actor, (int) $this->company->getKey());
        $mapping = [];
        foreach ($preview['rows'] as $row) {
            if ($row['role'] !== null) {
                $mapping[$row['role']] = $row['existing_id'];
            }
        }

        return app(AccountingMappingService::class)->publish($this->actor, ['company_id' => $this->company->getKey(), 'base_version_id' => null, 'effective_from' => $date, 'mappings' => $mapping, 'reason' => 'Review starter posting profile']);
    }

    public function test_routes_are_mounted_with_account_permissions_and_correct_paths(): void
    {
        foreach ([['GET', '/erp/accounting/setup-readiness', 'index'], ['POST', '/erp/accounting/setup-readiness/apply', 'apply']] as [$method, $url, $action]) {
            $route = Route::getRoutes()->getByName('erp.accounting.starter.'.$action);
            $this->assertNotNull($route, 'Mount accounting-starter.php at the top level of routes/web.php.');
            $matched = Route::getRoutes()->match(Request::create($url, $method));
            $this->assertSame('erp.accounting.starter.'.$action, $matched->getName());
            $this->assertContains('auth', $matched->gatherMiddleware());
            $this->assertContains('can:erp.accounts.view', $matched->gatherMiddleware());
            $this->assertNotContains('can:erp.accounting-settings.view', $matched->gatherMiddleware());
            if ($action === 'apply') {
                $this->assertContains('can:erp.accounts.create', $matched->gatherMiddleware());
            }
        }
    }

    public function test_guests_require_login(): void
    {
        $this->get('/erp/accounting/setup-readiness')->assertRedirect('/login');
    }

    public function test_account_view_permission_is_required(): void
    {
        $this->actingAs($this->staff([]))->get('/erp/accounting/setup-readiness')->assertForbidden();
    }

    public function test_preview_is_read_only_and_creates_no_groups_or_accounts(): void
    {
        $before = DB::table('accounting_audit_events')->count();
        $plan = $this->service->preview($this->actor, (int) $this->company->getKey());
        $this->assertSame(['missing' => 28, 'reusable' => 0, 'conflict' => 0], $plan['counts']);
        $this->assertDatabaseCount('account_groups', 0);
        $this->assertDatabaseCount('accounts', 0);
        $this->assertSame($before, DB::table('accounting_audit_events')->count());
    }

    public function test_starter_creates_twenty_eight_valid_accounts_and_five_groups(): void
    {
        $this->assertSame(['created' => 28, 'reused' => 0], $this->applyStarter());
        $this->assertDatabaseCount('accounts', 28);
        $this->assertDatabaseCount('account_groups', 5);
        $this->assertFalse($this->account('1000')->getAttribute('is_postable'));
        $this->assertSame($this->account('1000')->getKey(), (int) $this->account('1200')->getAttribute('parent_id'));
        $this->assertSame('inventory', $this->account('1200')->getAttribute('control_role'));
        $this->assertSame('debit', $this->account('4200')->getAttribute('normal_side'));
        $this->assertSame('cash', $this->account('1010')->getAttribute('cash_flow_role'));
        $this->assertSame('equity', $this->account('3200')->getAttribute('statement_role'));
    }

    public function test_repeat_apply_preserves_ids_revisions_and_audit_count(): void
    {
        $this->applyStarter();
        $before = Account::query()->orderBy('id')->get()->toArray();
        $audit = DB::table('accounting_audit_events')->count();
        $this->assertSame(['created' => 0, 'reused' => 28], $this->applyStarter());
        $this->assertSame($before, Account::query()->orderBy('id')->get()->toArray());
        $this->assertSame($audit, DB::table('accounting_audit_events')->count());
    }

    public function test_compatible_custom_names_are_preserved(): void
    {
        $this->applyStarter();
        $account = $this->account('1100');
        $account->update(['name' => 'Our Customer Ledger']);
        $this->applyStarter();
        $this->assertSame('Our Customer Ledger', $account->fresh()->getAttribute('name'));
    }

    public function test_partial_compatible_chart_is_completed_without_duplicates(): void
    {
        $this->applyStarter();
        $root = $this->account('1000');
        $leaf = $this->account('1010');
        DB::table('accounts')->whereNotIn('id', [$root->getKey(), $leaf->getKey()])->whereNotIn('code', ['2000', '3000', '4000', '5000'])->delete();
        // Keep the five roots plus one cash account: all remaining parent references are valid.
        $result = $this->applyStarter();
        $this->assertSame(['created' => 22, 'reused' => 6], $result);
        $this->assertDatabaseCount('accounts', 28);
        $this->assertSame($leaf->getKey(), $this->account('1010')->getKey());
    }

    public function test_conflicting_code_blocks_all_new_accounts(): void
    {
        app(AccountingAccountService::class)->initializeGroups($this->actor, (int) $this->company->getKey());
        $group = AccountGroup::query()->where('category', 'asset')->firstOrFail();
        Account::query()->create(['company_id' => $this->company->getKey(), 'account_group_id' => $group->getKey(), 'code' => '1000', 'name' => 'Existing cash', 'normal_side' => 'debit', 'is_postable' => true, 'statement_role' => 'current_asset', 'status' => 'active', 'revision' => 1]);
        $before = DB::table('accounting_audit_events')->count();
        $plan = $this->service->preview($this->actor, (int) $this->company->getKey());
        $this->assertSame(1, $plan['counts']['conflict']);
        $this->invalid(fn () => $this->applyStarter(), 'template_id');
        $this->assertDatabaseCount('accounts', 1);
        $this->assertSame($before, DB::table('accounting_audit_events')->count());
    }

    public function test_inactive_existing_account_is_not_silently_reactivated(): void
    {
        $this->applyStarter();
        $this->account('1100')->update(['status' => 'inactive']);
        $this->invalid(fn () => $this->applyStarter(), 'template_id');
        $this->assertSame('inactive', $this->account('1100')->getAttribute('status'));
    }

    public function test_changed_classification_is_not_overwritten(): void
    {
        $this->applyStarter();
        $this->account('1100')->update(['control_role' => null]);
        $this->invalid(fn () => $this->applyStarter(), 'template_id');
        $this->assertNull($this->account('1100')->getAttribute('control_role'));
    }

    public function test_moved_account_is_not_reparented(): void
    {
        $this->applyStarter();
        $this->account('1100')->update(['parent_id' => null]);
        $this->invalid(fn () => $this->applyStarter(), 'template_id');
        $this->assertNull($this->account('1100')->getAttribute('parent_id'));
    }

    public function test_invalid_standard_group_blocks_apply_without_fixing_it(): void
    {
        app(AccountingAccountService::class)->initializeGroups($this->actor, (int) $this->company->getKey());
        AccountGroup::query()->where('category', 'asset')->update(['normal_side' => 'credit']);
        $this->invalid(fn () => $this->applyStarter(), 'template_id');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_missing_setup_blocks_apply(): void
    {
        $other = $this->newCompany('START-B');
        $this->invalid(fn () => $this->service->apply($this->actor, $this->data(['company_id' => $other->getKey()])), 'company_id');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_inactive_company_blocks_apply(): void
    {
        $this->company->update(['status' => 'inactive']);
        $this->invalid(fn () => $this->applyStarter(), 'company_id');
    }

    public function test_cutover_lock_blocks_apply(): void
    {
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['cutover_locked_at' => now()]);
        $this->invalid(fn () => $this->applyStarter(), 'company_id');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_unknown_template_and_blank_reason_are_rejected(): void
    {
        $this->invalid(fn () => $this->service->apply($this->actor, $this->data(['template_id' => 'unknown'])), 'template_id');
        $this->invalid(fn () => $this->service->apply($this->actor, $this->data(['reason' => '  '])), 'reason');
    }

    public function test_injected_fields_are_rejected(): void
    {
        $this->invalid(fn () => $this->service->apply($this->actor, $this->data(['state' => 'enabled'])), 'template_id');
    }

    public function test_foreign_company_access_is_forbidden(): void
    {
        $other = $this->newCompany('START-B');
        $actor = $this->staff(['erp.accounts.view', 'erp.accounts.create']);
        $this->actingAs($actor)->get('/erp/accounting/setup-readiness?company_id='.$other->getKey())->assertForbidden();
        $this->expectException(AuthorizationException::class);
        $this->service->apply($actor, $this->data(['company_id' => $other->getKey()]));
    }

    public function test_reader_cannot_apply(): void
    {
        $this->actingAs($this->staff(['erp.accounts.view']))->post('/erp/accounting/setup-readiness/apply', $this->data())->assertForbidden();
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_account_creator_does_not_need_setup_or_mapping_permissions(): void
    {
        $this->actingAs($this->staff(['erp.accounts.view', 'erp.accounts.create']))->post('/erp/accounting/setup-readiness/apply', $this->data())->assertRedirect();
        $this->assertDatabaseCount('accounts', 28);
    }

    public function test_readiness_component_and_restricted_profile_section(): void
    {
        $this->actingAs($this->staff(['erp.accounts.view']))->get('/erp/accounting/setup-readiness')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Starter/Index')->where('canApply', false)->where('canViewMappings', false)->where('mapping', null)->has('preview.rows', 28));
    }

    public function test_apply_does_not_publish_profile_create_journal_or_enable_setup(): void
    {
        $this->applyStarter();
        $this->assertDatabaseCount('accounting_mapping_versions', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseHas('accounting_company_settings', ['company_id' => $this->company->getKey(), 'state' => 'disabled']);
    }

    public function test_initial_mapping_form_suggests_all_twenty_two_links(): void
    {
        $this->applyStarter();
        $this->actingAs($this->actor)->get('/erp/accounting/mappings')->assertOk()->assertInertia(fn (Assert $page) => $page->has('suggestedMappings', 22)->where('suggestedMappings.inventory', $this->account('1200')->getKey())->where('suggestedMappings.cash:MAIN', $this->account('1010')->getKey())->where('suggestedMappings.bank:CURRENT', $this->account('1020')->getKey())->has('versions', 0));
    }

    public function test_template_profile_can_be_published_and_pass_readiness(): void
    {
        $version = $this->publishProfile();
        $result = app(AccountingMappingService::class)->readiness($this->actor, (int) $this->company->getKey(), '2026-10-15');
        $this->assertSame('ready', $result['status']);
        $this->assertSame($version->getKey(), $result['version_id']);
        $this->actingAs($this->actor)->get('/erp/accounting/setup-readiness?date=2026-10-15')->assertOk()->assertInertia(fn (Assert $page) => $page->where('mapping.status', 'ready')->where('preview.counts.reusable', 28)->where('setupState', 'disabled'));
    }

    public function test_reapplying_after_publication_does_not_mutate_published_evidence(): void
    {
        $version = $this->publishProfile();
        $before = $version->getAttribute('payload_hash');
        $audits = DB::table('accounting_audit_events')->count();
        $this->assertSame(['created' => 0, 'reused' => 28], $this->applyStarter());
        $this->assertSame($before, $version->fresh()->getAttribute('payload_hash'));
        $this->assertSame($audits, DB::table('accounting_audit_events')->count());
    }

    public function test_profile_readiness_detects_missing_coverage(): void
    {
        $this->publishProfile('2026-11-01');
        $result = app(AccountingMappingService::class)->readiness($this->actor, (int) $this->company->getKey(), '2026-10-15');
        $this->assertSame('review', $result['status']);
        $this->assertNull($result['version_id']);
    }

    public function test_profile_readiness_detects_hash_tampering(): void
    {
        $version = $this->publishProfile();
        DB::table('accounting_mapping_versions')->where('id', $version->getKey())->update(['payload_hash' => str_repeat('0', 64)]);
        $result = app(AccountingMappingService::class)->readiness($this->actor, (int) $this->company->getKey(), '2026-10-15');
        $this->assertSame('review', $result['status']);
        $this->assertNotEmpty($result['issues']);
    }

    public function test_profile_readiness_detects_inactive_mapped_account(): void
    {
        $this->publishProfile();
        $this->account('1200')->update(['status' => 'inactive']);
        $result = app(AccountingMappingService::class)->readiness($this->actor, (int) $this->company->getKey(), '2026-10-15');
        $this->assertSame('review', $result['status']);
    }

    public function test_profile_readiness_requires_mapping_view_permission(): void
    {
        $this->expectException(AuthorizationException::class);
        app(AccountingMappingService::class)->readiness($this->staff(['erp.accounts.view']), (int) $this->company->getKey(), '2026-10-15');
    }

    public function test_invalid_coverage_date_is_rejected(): void
    {
        $this->actingAs($this->actor)->get('/erp/accounting/setup-readiness?date=2026-02-30')->assertSessionHasErrors('date');
    }
}
