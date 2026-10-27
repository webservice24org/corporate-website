<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCalendarService;
use App\Services\ERP\Accounting\AccountingSetupService;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AccountingCalendarManagementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private AccountingCalendarService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingPermissionSeeder::class);
        $this->company = $this->newCompany('CAL-A');
        $this->actor = User::factory()->create();
        $this->actor->assignRole('super-admin');
        app(AccountingSetupService::class)->initialize($this->actor, (int) $this->company->getKey());
        $this->service = app(AccountingCalendarService::class);
    }

    private function newCompany(string $code): Company
    {
        return Company::query()->create(['name' => $code, 'code' => $code, 'status' => 'active', 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka']);
    }

    /** @param list<string> $permissions */
    private function staff(array $permissions): User
    {
        $user = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $user->getKey(), 'employee_number' => 'CAL-'.$user->getKey(), 'first_name' => 'Fiscal', 'last_name' => 'User']);
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function yearData(array $overrides = []): array
    {
        return ['company_id' => (int) $this->company->getKey(), 'code' => 'FY2026', 'name' => 'Financial Year 2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'reason' => 'Configure fiscal calendar', ...$overrides];
    }

    /** @param array<string, mixed> $overrides */
    private function year(array $overrides = []): FiscalYear
    {
        return $this->service->saveYear($this->actor, $this->yearData($overrides));
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function periodData(array $overrides = []): array
    {
        return ['company_id' => (int) $this->company->getKey(), 'code' => 'JAN26', 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31', 'reason' => 'Configure accounting period', ...$overrides];
    }

    /** @param array<string, mixed> $overrides */
    private function period(FiscalYear $year, array $overrides = []): AccountingPeriod
    {
        return $this->service->savePeriod($this->actor, (int) $year->getKey(), $this->periodData($overrides));
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function editYear(FiscalYear $year, array $overrides = []): array
    {
        return [...$year->only(['company_id', 'code', 'name', 'revision']), 'starts_on' => $year->getAttribute('starts_on')->format('Y-m-d'), 'ends_on' => $year->getAttribute('ends_on')->format('Y-m-d'), 'reason' => 'Review fiscal year', ...$overrides];
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function editPeriod(AccountingPeriod $period, array $overrides = []): array
    {
        return [...$period->only(['company_id', 'code', 'revision']), 'starts_on' => $period->getAttribute('starts_on')->format('Y-m-d'), 'ends_on' => $period->getAttribute('ends_on')->format('Y-m-d'), 'reason' => 'Review period', ...$overrides];
    }

    /** @return array{created: int, reused: int} */
    private function generate(FiscalYear $year): array
    {
        return $this->service->generate($this->actor, (int) $year->getKey(), ['company_id' => $this->company->getKey(), 'revision' => $year->getAttribute('revision'), 'reason' => 'Review monthly boundaries']);
    }

    private function invalid(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Expected calendar validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function journal(AccountingPeriod $period): void
    {
        JournalEntry::query()->create(['company_id' => $this->company->getKey(), 'accounting_period_id' => $period->getKey(), 'number' => 'CAL-FIXTURE', 'accounting_date' => $period->getAttribute('starts_on')->format('Y-m-d'), 'currency_code' => 'BDT', 'description' => 'Draft calendar reference', 'created_by' => $this->actor->getKey(), 'updated_by' => $this->actor->getKey()]);
    }

    public function test_all_nine_calendar_routes_are_mounted_with_expected_url_and_permissions(): void
    {
        $routes = [
            ['GET', '/erp/accounting/calendar', 'index', null],
            ['GET', '/erp/accounting/calendar/create', 'create', 'erp.fiscal-years.manage'],
            ['POST', '/erp/accounting/calendar', 'store', 'erp.fiscal-years.manage'],
            ['GET', '/erp/accounting/calendar/123/edit', 'edit', 'erp.fiscal-years.manage'],
            ['PUT', '/erp/accounting/calendar/123', 'update', 'erp.fiscal-years.manage'],
            ['GET', '/erp/accounting/calendar/123', 'show', 'erp.accounting-periods.view'],
            ['POST', '/erp/accounting/calendar/123/periods', 'periods.store', 'erp.accounting-periods.manage'],
            ['PUT', '/erp/accounting/calendar/123/periods/456', 'periods.update', 'erp.accounting-periods.manage'],
            ['POST', '/erp/accounting/calendar/123/generate-periods', 'generate', 'erp.accounting-periods.manage'],
        ];
        foreach ($routes as [$method, $url, $action, $permission]) {
            $this->assertNotNull(Route::getRoutes()->getByName('erp.accounting.calendar.'.$action), 'Mount accounting-calendar.php at top level in routes/web.php.');
            $route = Route::getRoutes()->match(Request::create($url, $method));
            $this->assertSame('erp.accounting.calendar.'.$action, $route->getName());
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('can:erp.fiscal-years.view', $route->gatherMiddleware());
            if ($permission !== null) {
                $this->assertContains('can:'.$permission, $route->gatherMiddleware());
            }
        }
    }

    public function test_guests_redirect_to_login(): void
    {
        $this->get('/erp/accounting/calendar')->assertRedirect('/login');
    }

    public function test_year_view_does_not_require_setup_or_account_permission(): void
    {
        $this->actingAs($this->staff(['erp.fiscal-years.view']))->get('/erp/accounting/calendar')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Calendar/Index')->has('years', 0)->where('canViewSetup', false)->where('canManageYears', false));
        $this->assertDatabaseCount('fiscal_years', 0);
    }

    public function test_permission_is_required(): void
    {
        $this->actingAs($this->staff([]))->get('/erp/accounting/calendar')->assertForbidden();
    }

    public function test_create_edit_and_period_pages_exist(): void
    {
        $year = $this->year();
        $this->actingAs($this->actor);
        $this->get('/erp/accounting/calendar/create')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Calendar/YearForm')->where('year', null));
        $this->get('/erp/accounting/calendar/'.$year->getKey().'/edit')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Calendar/YearForm')->where('year.id', $year->getKey()));
        $this->get('/erp/accounting/calendar/'.$year->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Calendar/Show')->has('monthlyPlan', 12));
    }

    public function test_http_year_and_period_creation_and_edit(): void
    {
        $this->actingAs($this->actor)->post('/erp/accounting/calendar', $this->yearData())->assertRedirect();
        $year = FiscalYear::query()->firstOrFail();
        $this->put('/erp/accounting/calendar/'.$year->getKey(), $this->editYear($year, ['name' => 'Renamed year']))->assertRedirect();
        $this->post('/erp/accounting/calendar/'.$year->getKey().'/periods', $this->periodData())->assertRedirect();
        $period = AccountingPeriod::query()->firstOrFail();
        $this->put('/erp/accounting/calendar/'.$year->getKey().'/periods/'.$period->getKey(), $this->editPeriod($period, ['code' => 'JANUARY26']))->assertRedirect();
        $this->assertSame('JANUARY26', $period->fresh()->getAttribute('code'));
    }

    public function test_year_codes_are_normalized_and_company_unique(): void
    {
        $year = $this->year(['code' => ' fy26 ', 'name' => ' Year 2026 ']);
        $this->assertSame('FY26', $year->getAttribute('code'));
        $this->assertSame('Year 2026', $year->getAttribute('name'));
        $this->invalid(fn () => $this->year(['code' => 'FY26', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31']), 'code');
    }

    public function test_year_overlap_is_inclusive_but_adjacent_years_are_allowed(): void
    {
        $this->year();
        $this->invalid(fn () => $this->year(['code' => 'OTHER', 'starts_on' => '2026-12-31', 'ends_on' => '2027-12-30']), 'starts_on');
        $next = $this->year(['code' => 'FY2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31']);
        $this->assertSame('open', $next->getAttribute('state'));
    }

    public function test_closed_years_still_block_overlap(): void
    {
        $year = $this->year();
        $year->update(['state' => 'closed']);
        $this->invalid(fn () => $this->year(['code' => 'OTHER']), 'starts_on');
    }

    public function test_invalid_dates_order_and_excessive_year_span_are_rejected(): void
    {
        $this->invalid(fn () => $this->year(['starts_on' => '2026-02-30']), 'starts_on');
        $this->invalid(fn () => $this->year(['ends_on' => '2025-12-31']), 'ends_on');
        $this->invalid(fn () => $this->year(['ends_on' => '2028-01-01']), 'ends_on');
    }

    public function test_period_must_fit_inside_its_year(): void
    {
        $year = $this->year();
        $this->invalid(fn () => $this->period($year, ['starts_on' => '2025-12-31']), 'starts_on');
        $this->invalid(fn () => $this->period($year, ['ends_on' => '2027-01-01']), 'starts_on');
    }

    public function test_period_overlap_and_shared_endpoints_are_rejected(): void
    {
        $year = $this->year();
        $this->period($year);
        $this->invalid(fn () => $this->period($year, ['code' => 'OTHER', 'starts_on' => '2026-01-31', 'ends_on' => '2026-02-28']), 'starts_on');
        $this->period($year, ['code' => 'FEB26', 'starts_on' => '2026-02-01', 'ends_on' => '2026-02-28']);
        $this->assertDatabaseCount('accounting_periods', 2);
    }

    public function test_closed_periods_still_block_overlap(): void
    {
        $year = $this->year();
        $this->period($year)->update(['state' => 'hard_closed']);
        $this->invalid(fn () => $this->period($year, ['code' => 'OTHER']), 'starts_on');
    }

    public function test_closed_year_or_period_cannot_be_edited(): void
    {
        $year = $this->year();
        $period = $this->period($year);
        $period->update(['state' => 'soft_closed']);
        $this->invalid(fn () => $this->service->savePeriod($this->actor, (int) $year->getKey(), $this->editPeriod($period), (int) $period->getKey()), 'calendar');
        $year->update(['state' => 'closed']);
        $this->invalid(fn () => $this->service->saveYear($this->actor, $this->editYear($year), (int) $year->getKey()), 'calendar');
        $this->invalid(fn () => $this->generate($year), 'calendar');
    }

    public function test_year_shrink_cannot_exclude_existing_period(): void
    {
        $year = $this->year();
        $this->period($year);
        $this->invalid(fn () => $this->service->saveYear($this->actor, $this->editYear($year, ['starts_on' => '2026-02-01']), (int) $year->getKey()), 'starts_on');
    }

    public function test_draft_journal_reference_freezes_period_dates_and_code(): void
    {
        $year = $this->year();
        $period = $this->period($year);
        $this->journal($period);
        $this->invalid(fn () => $this->service->savePeriod($this->actor, (int) $year->getKey(), $this->editPeriod($period, ['code' => 'NEW']), (int) $period->getKey()), 'code');
        $this->invalid(fn () => $this->service->savePeriod($this->actor, (int) $year->getKey(), $this->editPeriod($period, ['ends_on' => '2026-01-30']), (int) $period->getKey()), 'ends_on');
    }

    public function test_draft_journal_reference_freezes_year_dates_but_allows_name_edit(): void
    {
        $year = $this->year();
        $period = $this->period($year);
        $this->journal($period);
        $this->invalid(fn () => $this->service->saveYear($this->actor, $this->editYear($year, ['ends_on' => '2026-11-30']), (int) $year->getKey()), 'ends_on');
        $saved = $this->service->saveYear($this->actor, $this->editYear($year, ['name' => 'Updated display name']), (int) $year->getKey());
        $this->assertSame('Updated display name', $saved->getAttribute('name'));
    }

    public function test_stale_year_and_period_revisions_are_rejected(): void
    {
        $year = $this->year();
        $period = $this->period($year);
        $this->invalid(fn () => $this->service->saveYear($this->actor, $this->editYear($year, ['revision' => 99]), (int) $year->getKey()), 'revision');
        $this->invalid(fn () => $this->service->savePeriod($this->actor, (int) $year->getKey(), $this->editPeriod($period, ['revision' => 99]), (int) $period->getKey()), 'revision');
    }

    public function test_state_metadata_and_parent_injection_are_rejected(): void
    {
        $this->invalid(fn () => $this->year(['state' => 'closed']), 'calendar');
        $year = $this->year();
        $this->invalid(fn () => $this->period($year, ['closed_by' => $this->actor->getKey()]), 'calendar');
        $this->invalid(fn () => $this->period($year, ['fiscal_year_id' => $year->getKey()]), 'calendar');
    }

    public function test_monthly_generation_is_repeatable_without_duplicate_audit(): void
    {
        $year = $this->year();
        $this->assertSame(['created' => 12, 'reused' => 0], $this->generate($year));
        $audit = DB::table('accounting_audit_events')->count();
        $rows = AccountingPeriod::query()->orderBy('id')->get()->toArray();
        $this->assertSame(['created' => 0, 'reused' => 12], $this->generate($year));
        $this->assertSame($audit, DB::table('accounting_audit_events')->count());
        $this->assertSame($rows, AccountingPeriod::query()->orderBy('id')->get()->toArray());
    }

    public function test_generation_handles_leap_day_and_clipped_month_boundaries(): void
    {
        $year = $this->year(['starts_on' => '2024-02-15', 'ends_on' => '2024-04-10']);
        $this->generate($year);
        $this->assertDatabaseHas('accounting_periods', ['code' => 'P-20240215', 'starts_on' => '2024-02-15', 'ends_on' => '2024-02-29']);
        $this->assertDatabaseHas('accounting_periods', ['code' => 'P-20240401', 'starts_on' => '2024-04-01', 'ends_on' => '2024-04-10']);
        $this->assertDatabaseCount('accounting_periods', 3);
    }

    public function test_monthly_generation_preserves_closed_exact_matches(): void
    {
        $year = $this->year();
        $this->generate($year);
        $period = AccountingPeriod::query()->firstOrFail();
        $period->update(['state' => 'hard_closed']);
        $this->assertSame(['created' => 0, 'reused' => 12], $this->generate($year));
        $this->assertSame('hard_closed', $period->fresh()->getAttribute('state'));
    }

    public function test_conflicting_manual_period_blocks_entire_generation(): void
    {
        $year = $this->year();
        $this->period($year);
        $audit = DB::table('accounting_audit_events')->count();
        $this->invalid(fn () => $this->generate($year), 'calendar');
        $this->assertDatabaseCount('accounting_periods', 1);
        $this->assertSame($audit, DB::table('accounting_audit_events')->count());
    }

    public function test_partial_generated_calendar_is_completed(): void
    {
        $year = $this->year();
        $this->period($year, ['code' => 'P-20260101']);
        $this->assertSame(['created' => 11, 'reused' => 1], $this->generate($year));
        $this->assertDatabaseCount('accounting_periods', 12);
    }

    public function test_blank_reason_is_rejected(): void
    {
        $this->invalid(fn () => $this->year(['reason' => '  ']), 'reason');
    }

    public function test_foreign_company_filter_and_service_write_are_forbidden(): void
    {
        $other = $this->newCompany('CAL-B');
        $user = $this->staff(['erp.fiscal-years.view', 'erp.fiscal-years.manage']);
        $this->actingAs($user)->get('/erp/accounting/calendar?company_id='.$other->getKey())->assertForbidden();
        $this->expectException(AuthorizationException::class);
        $this->service->saveYear($user, $this->yearData(['company_id' => $other->getKey()]));
    }

    public function test_foreign_year_url_is_not_found(): void
    {
        $other = $this->newCompany('CAL-B');
        $foreign = FiscalYear::query()->create(['company_id' => $other->getKey(), 'code' => 'FY', 'name' => 'Foreign', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $this->actingAs($this->staff(['erp.fiscal-years.view', 'erp.accounting-periods.view']))->get('/erp/accounting/calendar/'.$foreign->getKey())->assertNotFound();
    }

    public function test_period_cannot_be_moved_between_years(): void
    {
        $year = $this->year();
        $period = $this->period($year);
        $other = $this->year(['code' => 'FY27', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31']);
        $this->expectException(ModelNotFoundException::class);
        $this->service->savePeriod($this->actor, (int) $other->getKey(), $this->editPeriod($period), (int) $period->getKey());
    }

    public function test_viewer_cannot_manage_years_or_periods(): void
    {
        $year = $this->year();
        $this->actingAs($this->staff(['erp.fiscal-years.view', 'erp.accounting-periods.view']));
        $this->post('/erp/accounting/calendar', $this->yearData())->assertForbidden();
        $this->post('/erp/accounting/calendar/'.$year->getKey().'/periods', $this->periodData())->assertForbidden();
        $this->post('/erp/accounting/calendar/'.$year->getKey().'/generate-periods', ['company_id' => $this->company->getKey(), 'revision' => 1, 'reason' => 'Test'])->assertForbidden();
    }

    public function test_year_view_does_not_grant_period_view(): void
    {
        $year = $this->year();
        $this->actingAs($this->staff(['erp.fiscal-years.view']))->get('/erp/accounting/calendar/'.$year->getKey())->assertForbidden();
    }

    public function test_missing_setup_and_cutover_lock_block_configuration(): void
    {
        $other = $this->newCompany('CAL-B');
        $this->invalid(fn () => $this->service->saveYear($this->actor, $this->yearData(['company_id' => $other->getKey()])), 'company_id');
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['cutover_locked_at' => now()]);
        $this->invalid(fn () => $this->year(), 'company_id');
    }

    public function test_inactive_company_is_historically_readable_but_not_writable(): void
    {
        $year = $this->year();
        $this->company->update(['status' => 'inactive']);
        $this->actingAs($this->actor)->get('/erp/accounting/calendar/'.$year->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->where('canManagePeriods', false));
        $this->invalid(fn () => $this->period($year), 'company_id');
    }

    public function test_audit_visibility_requires_separate_permission(): void
    {
        $year = $this->year();
        $this->period($year);
        $this->actingAs($this->staff(['erp.fiscal-years.view', 'erp.accounting-periods.view']))->get('/erp/accounting/calendar/'.$year->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->where('canViewAudit', false)->has('audit', 0));
        $this->actingAs($this->actor)->get('/erp/accounting/calendar/'.$year->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->where('canViewAudit', true)->has('audit', 2));
    }

    public function test_calendar_setup_does_not_create_journals_or_activate_posting(): void
    {
        $year = $this->year();
        $this->generate($year);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseHas('accounting_company_settings', ['company_id' => $this->company->getKey(), 'state' => 'disabled']);
    }

    public function test_period_code_is_unique_even_for_nonoverlapping_dates(): void
    {
        $year = $this->year();
        $this->period($year);
        $this->invalid(fn () => $this->period($year, ['starts_on' => '2026-02-01', 'ends_on' => '2026-02-28']), 'code');
    }

    public function test_same_codes_and_dates_are_allowed_in_another_company(): void
    {
        $this->year();
        $other = $this->newCompany('CAL-B');
        app(AccountingSetupService::class)->initialize($this->actor, (int) $other->getKey());
        $year = $this->service->saveYear($this->actor, $this->yearData(['company_id' => $other->getKey()]));
        $this->assertSame((int) $other->getKey(), (int) $year->getAttribute('company_id'));
    }

    public function test_service_cannot_be_used_without_management_permission(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->service->saveYear($this->staff(['erp.fiscal-years.view']), $this->yearData());
    }

    public function test_historical_long_year_remains_readable_without_monthly_generation(): void
    {
        $year = FiscalYear::query()->create(['company_id' => $this->company->getKey(), 'code' => 'LEGACY', 'name' => 'Legacy fiscal span', 'starts_on' => '2020-01-01', 'ends_on' => '2023-12-31', 'state' => 'closed', 'revision' => 1]);
        $this->actingAs($this->actor)->get('/erp/accounting/calendar/'.$year->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->where('canManagePeriods', false)->has('monthlyPlan', 0)->has('generationIssues', 1));
    }
}
