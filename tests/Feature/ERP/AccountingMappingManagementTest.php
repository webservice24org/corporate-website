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
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingAccountService;
use App\Services\ERP\Accounting\AccountingMappingCatalog;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\AccountingSetupService;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AccountingMappingManagementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private AccountingMappingService $service;

    /** @var array<string, int> */
    private array $mapping;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingPermissionSeeder::class);
        $this->company = $this->newCompany('MAP-A');
        $this->actor = User::factory()->create();
        $this->actor->assignRole('super-admin');
        app(AccountingSetupService::class)->initialize($this->actor, (int) $this->company->getKey());
        $this->service = app(AccountingMappingService::class);
        $this->mapping = $this->chart($this->company);
    }

    private function newCompany(string $code): Company
    {
        return Company::query()->create(['name' => $code, 'code' => $code, 'status' => 'active', 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka']);
    }

    /** @return array<string, int> */
    private function chart(Company $company): array
    {
        app(AccountingAccountService::class)->initializeGroups($this->actor, (int) $company->getKey());
        $catalog = app(AccountingMappingCatalog::class);
        $map = [];
        $number = 1000;
        foreach ([...$catalog->roles(), 'cash:MAIN' => 'asset'] as $role => $category) {
            $group = AccountGroup::query()->where('company_id', $company->getKey())->where('category', $category)->firstOrFail();
            $account = Account::query()->create(['company_id' => $company->getKey(), 'account_group_id' => $group->getKey(), 'code' => (string) $number++, 'name' => $role, 'normal_side' => $role === 'sales_allowances' ? 'debit' : $group->getAttribute('normal_side'), 'is_postable' => true, 'control_role' => $catalog->control($role), 'statement_role' => $catalog->statements($role)[0], 'cash_flow_role' => $catalog->cashRole($role) ? 'cash' : null, 'status' => 'active', 'revision' => 1]);
            $map[$role] = (int) $account->getKey();
        }

        return $map;
    }

    /** @param list<string> $permissions */
    private function staff(array $permissions): User
    {
        $user = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $user->getKey(), 'employee_number' => 'MAP-'.$user->getKey(), 'first_name' => 'Finance', 'last_name' => 'User']);
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function data(array $overrides = []): array
    {
        return ['company_id' => (int) $this->company->getKey(), 'base_version_id' => null, 'effective_from' => '2026-10-01', 'mappings' => $this->mapping, 'reason' => 'Approve initial company posting profile', ...$overrides];
    }

    /** @param array<string, mixed> $overrides */
    private function publish(array $overrides = []): AccountingMappingVersion
    {
        return $this->service->publish($this->actor, $this->data($overrides));
    }

    private function invalid(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Expected mapping validation failure.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    private function postedJournal(string $date): void
    {
        $year = FiscalYear::query()->create(['company_id' => $this->company->getKey(), 'code' => 'FY26', 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $period = AccountingPeriod::query()->create(['company_id' => $this->company->getKey(), 'fiscal_year_id' => $year->getKey(), 'code' => 'OCT26', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
        $journal = JournalEntry::query()->create(['company_id' => $this->company->getKey(), 'accounting_period_id' => $period->getKey(), 'number' => 'PROFILE-FIXTURE', 'accounting_date' => $date, 'currency_code' => 'BDT', 'description' => 'Posted date fixture', 'created_by' => $this->actor->getKey(), 'updated_by' => $this->actor->getKey()]);
        $journal->update(['status' => 'posted']);
    }

    public function test_routes_are_registered_at_real_urls_with_permissions(): void
    {
        foreach ([['GET', '/erp/accounting/mappings', 'index'], ['POST', '/erp/accounting/mappings', 'publish'], ['GET', '/erp/accounting/mappings/123', 'show']] as [$method, $url, $action]) {
            $route = Route::getRoutes()->getByName('erp.accounting.mappings.'.$action);
            $this->assertNotNull($route, 'Mount routes/accounting-mappings.php at the top level of routes/web.php.');
            $matched = Route::getRoutes()->match(Request::create($url, $method));
            $this->assertSame('erp.accounting.mappings.'.$action, $matched->getName());
            $this->assertContains('auth', $matched->gatherMiddleware());
            $this->assertContains('can:erp.accounting-mappings.view', $matched->gatherMiddleware());
            $this->assertNotContains('can:erp.accounting-settings.view', $matched->gatherMiddleware());
            if ($action === 'publish') {
                $this->assertContains('can:erp.accounting-mappings.publish', $matched->gatherMiddleware());
            }
        }
    }

    public function test_guests_redirect_to_login(): void
    {
        $this->get('/erp/accounting/mappings')->assertRedirect('/login');
    }

    public function test_view_permission_is_required(): void
    {
        $this->actingAs($this->staff([]))->get('/erp/accounting/mappings')->assertForbidden();
    }

    public function test_mapping_view_does_not_require_setup_or_account_permission(): void
    {
        $this->actingAs($this->staff(['erp.accounting-mappings.view']))->get('/erp/accounting/mappings')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Mappings/Index')->where('canPublish', false)->where('canViewSetup', false)->where('canViewAccounts', false)->has('roles', 20)->has('versions', 0));
    }

    public function test_reader_cannot_publish_over_http(): void
    {
        $this->actingAs($this->staff(['erp.accounting-mappings.view']))->post('/erp/accounting/mappings', $this->data())->assertForbidden();
    }

    public function test_service_requires_publication_permission(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->service->publish($this->staff(['erp.accounting-mappings.view']), $this->data());
    }

    public function test_foreign_company_filter_is_forbidden(): void
    {
        $other = $this->newCompany('MAP-B');
        $this->actingAs($this->staff(['erp.accounting-mappings.view']))->get('/erp/accounting/mappings?company_id='.$other->getKey())->assertForbidden();
    }

    public function test_foreign_version_detail_is_not_found(): void
    {
        $other = $this->newCompany('MAP-B');
        $version = AccountingMappingVersion::query()->create(['company_id' => $other->getKey(), 'version' => 1, 'effective_from' => '2026-10-01']);
        $this->actingAs($this->staff(['erp.accounting-mappings.view']))->get('/erp/accounting/mappings/'.$version->getKey())->assertNotFound();
    }

    public function test_http_publication_and_show_component(): void
    {
        $this->actingAs($this->actor)->post('/erp/accounting/mappings', $this->data())->assertRedirect();
        $version = AccountingMappingVersion::query()->firstOrFail();
        $this->get('/erp/accounting/mappings/'.$version->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Mappings/Show')->where('version.state', 'published')->has('entries', 21)->has('audit', 1));
    }

    public function test_publication_persists_complete_hashed_immutable_profile_and_audit(): void
    {
        $version = $this->publish();
        $this->assertSame('published', $version->getAttribute('state'));
        $this->assertSame(1, $version->getAttribute('version'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $version->getAttribute('payload_hash'));
        $this->assertDatabaseCount('accounting_account_mappings', 21);
        $this->assertSame(1, DB::table('accounting_audit_events')->where('entity_kind', 'mapping')->where('action', 'publish')->count());
    }

    public function test_second_version_preserves_previous_assignments_and_history(): void
    {
        $first = $this->publish();
        $second = $this->publish(['base_version_id' => $first->getKey(), 'effective_from' => '2026-11-01']);
        $this->assertSame(2, $second->getAttribute('version'));
        $this->assertSame('published', $first->fresh()->getAttribute('state'));
        $this->assertDatabaseCount('accounting_account_mappings', 42);
    }

    public function test_stale_base_version_is_rejected_without_partial_rows(): void
    {
        $this->publish();
        $this->invalid(fn () => $this->publish(['effective_from' => '2026-11-01']), 'base_version_id');
        $this->assertDatabaseCount('accounting_mapping_versions', 1);
        $this->assertDatabaseCount('accounting_account_mappings', 21);
    }

    public function test_missing_standard_role_is_rejected(): void
    {
        $map = $this->mapping;
        unset($map['trade_ar']);
        $this->invalid(fn () => $this->publish(['mappings' => $map]), 'mappings');
        $this->assertDatabaseCount('accounting_mapping_versions', 0);
    }

    public function test_unknown_role_is_rejected(): void
    {
        $this->invalid(fn () => $this->publish(['mappings' => [...$this->mapping, 'unknown' => $this->mapping['trade_ar']]]), 'mappings');
    }

    public function test_missing_cash_link_is_rejected(): void
    {
        $map = $this->mapping;
        unset($map['cash:MAIN']);
        $this->invalid(fn () => $this->publish(['mappings' => $map]), 'mappings');
    }

    public function test_duplicate_cash_gl_is_rejected(): void
    {
        $this->invalid(fn () => $this->publish(['mappings' => [...$this->mapping, 'bank:CURRENT' => $this->mapping['cash:MAIN']]]), 'mappings');
    }

    public function test_malformed_cash_code_is_rejected(): void
    {
        $map = $this->mapping;
        unset($map['cash:MAIN']);
        $map['cash:bad code'] = $this->mapping['cash:MAIN'];
        $this->invalid(fn () => $this->publish(['mappings' => $map]), 'mappings');
    }

    public function test_foreign_account_is_rejected_atomically(): void
    {
        $other = $this->newCompany('MAP-B');
        app(AccountingSetupService::class)->initialize($this->actor, (int) $other->getKey());
        $otherMap = $this->chart($other);
        $this->invalid(fn () => $this->publish(['mappings' => [...$this->mapping, 'inventory' => $otherMap['inventory']]]), 'mappings');
        $this->assertDatabaseCount('accounting_mapping_versions', 0);
        $this->assertDatabaseCount('accounting_account_mappings', 0);
    }

    public function test_inactive_account_is_rejected(): void
    {
        Account::query()->findOrFail($this->mapping['inventory'])->update(['status' => 'inactive']);
        $this->invalid(fn () => $this->publish(), 'mappings');
    }

    public function test_nonpostable_account_is_rejected(): void
    {
        Account::query()->findOrFail($this->mapping['inventory'])->update(['is_postable' => false]);
        $this->invalid(fn () => $this->publish(), 'mappings');
    }

    public function test_wrong_account_category_is_rejected(): void
    {
        $this->invalid(fn () => $this->publish(['mappings' => [...$this->mapping, 'inventory' => $this->mapping['trade_ap']]]), 'mappings');
    }

    public function test_wrong_control_role_is_rejected(): void
    {
        Account::query()->findOrFail($this->mapping['trade_ar'])->update(['control_role' => null]);
        $this->invalid(fn () => $this->publish(), 'mappings');
    }

    public function test_wrong_statement_role_is_rejected(): void
    {
        Account::query()->findOrFail($this->mapping['sales_allowances'])->update(['statement_role' => 'operating_revenue']);
        $this->invalid(fn () => $this->publish(), 'mappings');
    }

    public function test_cash_account_requires_cash_classification(): void
    {
        Account::query()->findOrFail($this->mapping['cash:MAIN'])->update(['cash_flow_role' => null]);
        $this->invalid(fn () => $this->publish(), 'mappings');
    }

    public function test_cash_account_requires_debit_normal_side(): void
    {
        Account::query()->findOrFail($this->mapping['cash:MAIN'])->update(['normal_side' => 'credit']);
        $this->invalid(fn () => $this->publish(), 'mappings');
    }

    public function test_whitespace_reason_and_injected_state_are_rejected(): void
    {
        $this->invalid(fn () => $this->publish(['reason' => '  ']), 'reason');
        $this->invalid(fn () => $this->publish(['state' => 'published']), 'mappings');
    }

    public function test_same_or_earlier_effective_date_is_rejected(): void
    {
        $version = $this->publish();
        foreach (['2026-10-01', '2026-09-30'] as $date) {
            $this->invalid(fn () => $this->publish(['base_version_id' => $version->getKey(), 'effective_from' => $date]), 'effective_from');
        }
    }

    public function test_invalid_calendar_date_is_rejected(): void
    {
        $this->invalid(fn () => $this->publish(['effective_from' => '2026-02-30']), 'effective_from');
    }

    public function test_existing_posted_dates_block_retroactive_profile(): void
    {
        $this->postedJournal('2026-10-15');
        $this->invalid(fn () => $this->publish(['effective_from' => '2026-10-15']), 'effective_from');
        $version = $this->publish(['effective_from' => '2026-10-16']);
        $this->assertSame('published', $version->getAttribute('state'));
    }

    public function test_cutover_lock_blocks_publication(): void
    {
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['cutover_locked_at' => now()]);
        $this->invalid(fn () => $this->publish(), 'company_id');
    }

    public function test_inactive_company_blocks_publication(): void
    {
        $this->company->update(['status' => 'inactive']);
        $this->invalid(fn () => $this->publish(), 'company_id');
    }

    public function test_published_version_and_rows_cannot_be_edited(): void
    {
        $version = $this->publish();
        try {
            $version->update(['effective_from' => '2026-11-01']);
            $this->fail('Expected immutable version.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
        $row = AccountingAccountMapping::query()->firstOrFail();
        $this->expectException(\LogicException::class);
        $row->update(['role' => 'renamed']);
    }

    public function test_resolver_selects_profile_for_historical_and_future_dates(): void
    {
        $first = $this->publish();
        $second = $this->publish(['base_version_id' => $first->getKey(), 'effective_from' => '2026-11-01']);
        $october = DB::transaction(fn () => $this->service->resolveForPosting($this->actor, (int) $this->company->getKey(), '2026-10-31', ['inventory', 'cash:MAIN']));
        $november = DB::transaction(fn () => $this->service->resolveForPosting($this->actor, (int) $this->company->getKey(), '2026-11-01', ['inventory']));
        $this->assertSame($first->getKey(), $october['version_id']);
        $this->assertSame($second->getKey(), $november['version_id']);
        $this->assertSame($this->mapping['inventory'], $october['accounts']['inventory']);
    }

    public function test_resolver_does_not_use_future_profile_before_its_date(): void
    {
        $this->publish();
        $this->invalid(fn () => DB::transaction(fn () => $this->service->resolveForPosting($this->actor, (int) $this->company->getKey(), '2026-09-30', ['inventory'])), 'mappings');
    }

    public function test_resolver_detects_tampered_evidence(): void
    {
        $version = $this->publish();
        DB::table('accounting_mapping_versions')->where('id', $version->getKey())->update(['payload_hash' => str_repeat('0', 64)]);
        $this->invalid(fn () => DB::transaction(fn () => $this->service->resolveForPosting($this->actor, (int) $this->company->getKey(), '2026-10-15', ['inventory'])), 'mappings');
    }

    public function test_resolver_revalidates_account_activity(): void
    {
        $this->publish();
        DB::table('accounts')->where('id', $this->mapping['inventory'])->update(['status' => 'inactive']);
        $this->invalid(fn () => DB::transaction(fn () => $this->service->resolveForPosting($this->actor, (int) $this->company->getKey(), '2026-10-15', ['inventory'])), 'mappings');
    }

    public function test_resolver_rejects_unconfigured_cash_code(): void
    {
        $this->publish();
        $this->invalid(fn () => DB::transaction(fn () => $this->service->resolveForPosting($this->actor, (int) $this->company->getKey(), '2026-10-15', ['bank:UNKNOWN'])), 'mappings');
    }

    public function test_audit_requires_separate_view_permission(): void
    {
        $version = $this->publish();
        $this->actingAs($this->staff(['erp.accounting-mappings.view']))->get('/erp/accounting/mappings/'.$version->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->where('canViewAudit', false)->has('audit', 0));
    }

    public function test_publication_freezes_account_semantics_through_existing_service(): void
    {
        $this->publish();
        $account = Account::query()->findOrFail($this->mapping['inventory']);
        $data = [...$account->only(['company_id', 'account_group_id', 'parent_id', 'code', 'name', 'normal_side', 'is_postable', 'control_role', 'statement_role', 'cash_flow_role', 'revision']), 'code' => 'CHANGED', 'reason' => 'Attempt reclassification'];
        $this->invalid(fn () => app(AccountingAccountService::class)->save($this->actor, $data, (int) $account->getKey()), 'code');
    }

    public function test_profile_publication_does_not_enable_posting(): void
    {
        $this->publish();
        $this->assertDatabaseHas('accounting_company_settings', ['company_id' => $this->company->getKey(), 'state' => 'disabled']);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_resolver_requires_journal_posting_permission(): void
    {
        $user = $this->staff(['erp.accounting-mappings.view']);
        $this->publish();
        $this->expectException(AuthorizationException::class);
        $this->service->resolveForPosting($user, (int) $this->company->getKey(), '2026-10-15', ['inventory']);
    }

    public function test_service_cannot_publish_for_a_foreign_company(): void
    {
        $other = $this->newCompany('MAP-B');
        $this->expectException(AuthorizationException::class);
        $this->service->publish($this->staff(['erp.accounting-mappings.publish']), $this->data(['company_id' => $other->getKey()]));
    }

    public function test_inactive_ancestor_blocks_publication(): void
    {
        $account = Account::query()->findOrFail($this->mapping['inventory']);
        $parent = Account::query()->create(['company_id' => $this->company->getKey(), 'account_group_id' => $account->getAttribute('account_group_id'), 'code' => 'PARENT', 'name' => 'Inactive parent', 'normal_side' => 'debit', 'is_postable' => false, 'statement_role' => 'current_asset', 'status' => 'inactive', 'revision' => 1]);
        $account->update(['parent_id' => $parent->getKey()]);
        $this->invalid(fn () => $this->publish(), 'mappings');
    }

    public function test_published_mapped_account_cannot_be_deactivated(): void
    {
        $this->publish();
        $this->invalid(fn () => app(AccountingAccountService::class)->setStatus($this->actor, (int) $this->company->getKey(), $this->mapping['inventory'], 'inactive', 1, 'Attempt deactivation'), 'status');
    }
}
