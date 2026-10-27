<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\AccountingSetting;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingSetupService;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AccountingWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingPermissionSeeder::class);
    }

    private function company(string $code = 'ACC-A'): Company
    {
        return Company::query()->create(['name' => $code, 'code' => $code, 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka', 'status' => 'active']);
    }

    private function admin(): User
    {
        $actor = User::factory()->create();
        $actor->assignRole('super-admin');

        return $actor;
    }

    /** @param list<string> $permissions */
    private function staff(Company $company, array $permissions = ['erp.accounting-settings.view']): User
    {
        $actor = User::factory()->create();
        Employee::query()->create(['company_id' => $company->getKey(), 'user_id' => $actor->getKey(), 'employee_number' => 'AW-'.$actor->getKey(), 'first_name' => 'Accounting', 'last_name' => 'User']);
        if ($permissions !== []) {
            $actor->givePermissionTo($permissions);
        }

        return $actor;
    }

    public function test_routes_are_registered_once_inside_erp_with_auth_and_permissions(): void
    {
        foreach (['index' => 'erp/accounting', 'settings' => 'erp/accounting/settings', 'initialize' => 'erp/accounting/settings/initialize'] as $name => $uri) {
            $route = Route::getRoutes()->getByName('erp.accounting.'.$name);
            $this->assertNotNull($route, 'Mount routes/accounting.php inside the existing ERP route group.');
            $this->assertSame($uri, rtrim($route->uri(), '/'));
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('can:erp.accounting-settings.view', $route->gatherMiddleware());
            if ($name === 'initialize') {
                $this->assertContains('can:erp.accounting-settings.update', $route->gatherMiddleware());
            }
        }
        $matches = array_filter(Route::getRoutes()->getRoutes(), static fn ($route): bool => in_array($route->getName(), ['erp.accounting.index', 'erp.accounting.settings', 'erp.accounting.initialize'], true));
        $this->assertCount(3, $matches);
    }

    public function test_guests_require_login_for_both_pages_and_initialization(): void
    {
        $this->get('/erp/accounting')->assertRedirect('/login');
        $this->get('/erp/accounting/settings')->assertRedirect('/login');
        $this->post('/erp/accounting/settings/initialize', ['company_id' => 1])->assertRedirect('/login');
    }

    public function test_user_without_view_permission_cannot_read_workspace(): void
    {
        $company = $this->company();
        $this->actingAs($this->staff($company, []));
        $this->get('/erp/accounting')->assertForbidden();
        $this->get('/erp/accounting/settings')->assertForbidden();
    }

    public function test_other_finance_permissions_do_not_implicitly_grant_setup_access(): void
    {
        $company = $this->company();
        $this->actingAs($this->staff($company, ['erp.accounts.view', 'erp.accounting-reports.view']));
        $this->get('/erp/accounting')->assertForbidden();
        $this->get('/erp/accounting/settings')->assertForbidden();
    }

    public function test_staff_single_company_is_selected_and_scoped(): void
    {
        $own = $this->company();
        $this->company('ACC-B');
        $this->actingAs($this->staff($own));
        $this->get('/erp/accounting')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Overview')->has('companies', 1)->where('companies.0.id', $own->getKey())->where('company.id', $own->getKey())->where('filters.company_id', $own->getKey())->where('configuration', null)->where('postingAvailable', false)->where('canInitialize', false));
    }

    public function test_super_admin_multiple_companies_requires_selection(): void
    {
        $this->company();
        $this->company('ACC-B');
        $this->actingAs($this->admin());
        $this->get('/erp/accounting')->assertOk()->assertInertia(fn (Assert $page) => $page->has('companies', 2)->where('company', null)->where('filters.company_id', null)->where('configuration', null)->where('canInitialize', false));
    }

    public function test_super_admin_can_select_company_without_employee_record(): void
    {
        $this->company();
        $second = $this->company('ACC-B');
        $this->actingAs($this->admin());
        $this->get('/erp/accounting/settings?company_id='.$second->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Settings/Index')->where('company.id', $second->getKey())->where('canInitialize', true));
    }

    public function test_non_admin_without_employee_company_is_forbidden(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('erp.accounting-settings.view');
        $this->actingAs($user);
        $this->get('/erp/accounting')->assertForbidden();
    }

    public function test_foreign_company_filter_is_forbidden(): void
    {
        $own = $this->company();
        $foreign = $this->company('ACC-B');
        $this->actingAs($this->staff($own));
        $this->get('/erp/accounting?company_id='.$foreign->getKey())->assertForbidden();
        $this->get('/erp/accounting/settings?company_id='.$foreign->getKey())->assertForbidden();
    }

    public function test_unknown_company_is_not_found_for_admin(): void
    {
        $this->actingAs($this->admin())->get('/erp/accounting?company_id=999999')->assertNotFound();
    }

    public function test_invalid_company_filter_has_validation_error(): void
    {
        $company = $this->company();
        $this->actingAs($this->staff($company));
        $this->from('/erp/accounting')->get('/erp/accounting?company_id=wrong')->assertSessionHasErrors('company_id');
    }

    public function test_reading_workspace_does_not_initialize_or_audit(): void
    {
        $company = $this->company();
        $this->actingAs($this->admin());
        $this->get('/erp/accounting?company_id='.$company->getKey())->assertOk();
        $this->get('/erp/accounting/settings?company_id='.$company->getKey())->assertOk();
        $this->assertDatabaseCount('accounting_company_settings', 0);
        $this->assertDatabaseCount('accounting_audit_events', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_read_only_user_cannot_initialize(): void
    {
        $company = $this->company();
        $this->actingAs($this->staff($company));
        $this->post('/erp/accounting/settings/initialize', ['company_id' => $company->getKey()])->assertForbidden();
        $this->assertDatabaseCount('accounting_company_settings', 0);
    }

    public function test_update_permission_without_view_cannot_use_workspace_action(): void
    {
        $company = $this->company();
        $this->actingAs($this->staff($company, ['erp.accounting-settings.update']));
        $this->post('/erp/accounting/settings/initialize', ['company_id' => $company->getKey()])->assertForbidden();
    }

    public function test_authorized_initialization_stays_disabled_and_records_one_audit(): void
    {
        $company = $this->company();
        $user = $this->staff($company, ['erp.accounting-settings.view', 'erp.accounting-settings.update']);
        $this->actingAs($user);
        $this->post('/erp/accounting/settings/initialize', ['company_id' => $company->getKey()])->assertRedirect('/erp/accounting/settings?company_id='.$company->getKey());
        $this->assertDatabaseHas('accounting_company_settings', ['company_id' => $company->getKey(), 'state' => 'disabled', 'functional_currency' => 'BDT', 'configured_by' => $user->getKey()]);
        $this->assertDatabaseCount('accounting_audit_events', 1);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_retry_initialization_does_not_create_another_setting_or_audit(): void
    {
        $company = $this->company();
        $this->actingAs($this->admin());
        foreach ([1, 2] as $attempt) {
            $this->post('/erp/accounting/settings/initialize', ['company_id' => $company->getKey()])->assertRedirect();
        }
        $this->assertDatabaseCount('accounting_company_settings', 1);
        $this->assertDatabaseCount('accounting_audit_events', 1);
    }

    public function test_foreign_company_initialization_is_rejected_without_effects(): void
    {
        $own = $this->company();
        $foreign = $this->company('ACC-B');
        $this->actingAs($this->staff($own, ['erp.accounting-settings.view', 'erp.accounting-settings.update']));
        $this->post('/erp/accounting/settings/initialize', ['company_id' => $foreign->getKey()])->assertForbidden();
        $this->assertDatabaseCount('accounting_company_settings', 0);
        $this->assertDatabaseCount('accounting_audit_events', 0);
    }

    public function test_inactive_company_is_readable_but_not_initializable(): void
    {
        $company = $this->company();
        $company->update(['status' => 'inactive']);
        $this->actingAs($this->admin());
        $this->get('/erp/accounting/settings')->assertOk()->assertInertia(fn (Assert $page) => $page->where('canInitialize', false)->has('issues', 1));
        $this->post('/erp/accounting/settings/initialize', ['company_id' => $company->getKey()])->assertSessionHasErrors('company_id');
        $this->assertDatabaseCount('accounting_company_settings', 0);
    }

    public function test_unsupported_currency_has_clear_readiness_message_and_write_error(): void
    {
        $company = $this->company();
        $company->update(['currency_code' => 'JPY']);
        $this->actingAs($this->admin());
        $this->get('/erp/accounting/settings')->assertOk()->assertInertia(fn (Assert $page) => $page->where('canInitialize', false)->has('issues', 1));
        $this->post('/erp/accounting/settings/initialize', ['company_id' => $company->getKey()])->assertSessionHasErrors('currency_code');
    }

    public function test_client_posting_flags_and_currency_cannot_enable_accounting(): void
    {
        $company = $this->company();
        $this->actingAs($this->admin());
        $this->post('/erp/accounting/settings/initialize', ['company_id' => $company->getKey(), 'state' => 'enabled', 'activation_date' => '2026-01-01', 'functional_currency' => 'USD', 'currency_precision' => 9, 'configured_by' => 999999])->assertRedirect();
        $this->assertDatabaseHas('accounting_company_settings', ['company_id' => $company->getKey(), 'state' => 'disabled', 'functional_currency' => 'BDT', 'currency_precision' => 2, 'activation_date' => null]);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('accounting_source_effects', 0);
    }

    public function test_initialization_requires_company_id(): void
    {
        $this->actingAs($this->admin())->post('/erp/accounting/settings/initialize', [])->assertSessionHasErrors('company_id');
    }

    public function test_projection_omits_private_company_and_internal_configuration_fields(): void
    {
        $company = $this->company();
        $company->update(['tax_number' => 'PRIVATE-TAX', 'registration_number' => 'PRIVATE-REG']);
        $admin = $this->admin();
        app(AccountingSetupService::class)->initialize($admin, (int) $company->getKey());
        $this->actingAs($admin);
        $this->get('/erp/accounting/settings')->assertOk()->assertInertia(fn (Assert $page) => $page->missing('company.tax_number')->missing('company.registration_number')->missing('companies.0.tax_number')->missing('configuration.configured_by')->missing('configuration.id')->missing('configuration.active_mapping_version_id')->where('configuration.state', 'disabled')->where('canInitialize', false));
    }

    public function test_saved_currency_mismatch_is_visible_without_rewriting_configuration(): void
    {
        $company = $this->company();
        $admin = $this->admin();
        $setting = app(AccountingSetupService::class)->initialize($admin, (int) $company->getKey());
        $company->update(['currency_code' => 'USD']);
        $this->actingAs($admin);
        $this->get('/erp/accounting/settings')->assertOk()->assertInertia(fn (Assert $page) => $page->where('company.currency_code', 'USD')->where('configuration.functional_currency', 'BDT')->has('issues', 1)->where('canInitialize', false));
        $this->assertSame('BDT', $setting->refresh()->getAttribute('functional_currency'));
        $this->assertDatabaseCount('accounting_audit_events', 1);
    }

    public function test_raw_enabled_state_does_not_claim_posting_is_available(): void
    {
        $company = $this->company();
        $admin = $this->admin();
        app(AccountingSetupService::class)->initialize($admin, (int) $company->getKey());
        DB::table('accounting_company_settings')->where('company_id', $company->getKey())->update(['state' => 'enabled']);
        $this->actingAs($admin);
        $this->get('/erp/accounting')->assertOk()->assertInertia(fn (Assert $page) => $page->where('configuration.state', 'enabled')->where('postingAvailable', false)->where('canInitialize', false));
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_unknown_saved_state_is_reviewed(): void
    {
        $company = $this->company();
        $admin = $this->admin();
        app(AccountingSetupService::class)->initialize($admin, (int) $company->getKey());
        DB::table('accounting_company_settings')->where('company_id', $company->getKey())->update(['state' => 'unknown']);
        $this->actingAs($admin);
        $this->get('/erp/accounting/settings')->assertOk()->assertInertia(fn (Assert $page) => $page->has('issues', 1)->where('postingAvailable', false));
    }

    public function test_company_without_configuration_does_not_inherit_another_company_setting(): void
    {
        $a = $this->company();
        $b = $this->company('ACC-B');
        $admin = $this->admin();
        app(AccountingSetupService::class)->initialize($admin, (int) $a->getKey());
        $this->actingAs($admin);
        $this->get('/erp/accounting/settings?company_id='.$b->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->where('company.id', $b->getKey())->where('configuration', null)->where('canInitialize', true));
        $this->assertDatabaseCount('accounting_company_settings', 1);
    }

    public function test_page_refresh_after_initialization_does_not_write_again(): void
    {
        $company = $this->company();
        $admin = $this->admin();
        app(AccountingSetupService::class)->initialize($admin, (int) $company->getKey());
        $this->actingAs($admin);
        $before = AccountingSetting::query()->where('company_id', $company->getKey())->firstOrFail()->getAttributes();
        $this->get('/erp/accounting')->assertOk();
        $this->get('/erp/accounting/settings')->assertOk();
        $this->assertSame($before, AccountingSetting::query()->where('company_id', $company->getKey())->firstOrFail()->getAttributes());
        $this->assertDatabaseCount('accounting_audit_events', 1);
    }
}
