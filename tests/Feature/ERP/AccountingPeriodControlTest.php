<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Accounting\AccountingCalendarService;
use App\Services\ERP\Accounting\AccountingPeriodGuard;
use App\Services\ERP\Accounting\AccountingPeriodStateService;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\Close\AccountingPeriodCloseGuard;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

final class AccountingPeriodControlTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private FiscalYear $year;

    private AccountingPeriod $period;

    private User $actor;

    private User $reviewer;

    private AccountingPeriodStateService $states;

    private AccountingPeriodGuard $guard;

    private int $journalSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Isolate the pre-existing calendar state/lock contract. The 6.13A suites exercise the real close gate.
        $this->mock(AccountingPeriodCloseGuard::class, static function (MockInterface $mock): void {
            $mock->shouldReceive('assertReady')->andReturnNull();
        });
        $this->seed(AccountingPermissionSeeder::class);
        $this->company = Company::query()->create(['name' => 'Period controls', 'code' => 'PERIOD-A', 'status' => 'active', 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka']);
        $this->actor = User::factory()->create();
        $this->actor->assignRole('super-admin');
        $this->reviewer = User::factory()->create();
        $this->reviewer->assignRole('super-admin');
        app(AccountingSetupService::class)->initialize($this->actor, (int) $this->company->getKey());
        $calendar = app(AccountingCalendarService::class);
        $this->year = $calendar->saveYear($this->actor, ['company_id' => $this->company->getKey(), 'code' => 'FY26', 'name' => 'Year 2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'reason' => 'Create year']);
        $this->period = $calendar->savePeriod($this->actor, (int) $this->year->getKey(), ['company_id' => $this->company->getKey(), 'code' => 'OCT26', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'reason' => 'Create period']);
        $this->states = app(AccountingPeriodStateService::class);
        $this->guard = app(AccountingPeriodGuard::class);
    }

    /** @param list<string> $permissions */
    private function staff(array $permissions): User
    {
        $user = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $user->getKey(), 'employee_number' => 'PERIOD-'.$user->getKey(), 'first_name' => 'Period', 'last_name' => 'User']);
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function close(string $state = 'soft_closed', array $overrides = []): AccountingPeriod
    {
        $this->period->refresh();

        return $this->states->close($this->actor, (int) $this->company->getKey(), (int) $this->year->getKey(), (int) $this->period->getKey(), ['state' => $state, 'revision' => $this->period->getAttribute('revision'), 'reason' => 'Review period closure', ...$overrides]);
    }

    private function reopen(User $actor): AccountingPeriod
    {
        $this->period->refresh();

        return $this->states->reopen($actor, (int) $this->company->getKey(), (int) $this->year->getKey(), (int) $this->period->getKey(), ['revision' => $this->period->getAttribute('revision'), 'reason' => 'Independently review reopening']);
    }

    /** @param array<string, mixed> $overrides */
    private function journal(array $overrides = []): JournalEntry
    {
        return JournalEntry::query()->create(['company_id' => $this->company->getKey(), 'accounting_period_id' => $this->period->getKey(), 'number' => 'PC-'.++$this->journalSequence, 'entry_kind' => 'manual', 'status' => 'approved', 'accounting_date' => '2026-10-15', 'currency_code' => 'BDT', 'description' => 'Approved fixture, no financial posting', 'created_by' => $this->actor->getKey(), 'updated_by' => $this->actor->getKey(), 'approved_by' => $this->reviewer->getKey(), 'approved_at' => now(), ...$overrides]);
    }

    /** @return array<string, mixed> */
    private function check(JournalEntry $journal, ?User $actor = null): array
    {
        return DB::transaction(fn () => $this->guard->lockForJournal($actor ?? $this->actor, (int) $this->company->getKey(), (int) $journal->getKey()));
    }

    private function invalid(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Expected period validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function url(string $action): string
    {
        return '/erp/accounting/calendar/'.$this->year->getKey().'/periods/'.$this->period->getKey().'/'.$action;
    }

    public function test_state_routes_are_registered_with_separate_permissions(): void
    {
        foreach (['close', 'reopen'] as $action) {
            $this->assertNotNull(Route::getRoutes()->getByName('erp.accounting.calendar.periods.'.$action));
            $route = Route::getRoutes()->match(Request::create($this->url($action), 'POST'));
            $this->assertSame('erp.accounting.calendar.periods.'.$action, $route->getName());
            foreach (['auth', 'can:erp.fiscal-years.view', 'can:erp.accounting-periods.view', 'can:erp.accounting-periods.'.$action] as $permission) {
                $this->assertContains($permission, $route->gatherMiddleware());
            }
        }
    }

    public function test_soft_close_records_actor_revision_timestamp_and_audit(): void
    {
        $period = $this->close();
        $this->assertSame('soft_closed', $period->getAttribute('state'));
        $this->assertSame(2, $period->getAttribute('revision'));
        $this->assertSame((int) $this->actor->getKey(), (int) $period->getAttribute('closed_by'));
        $this->assertNotNull($period->getAttribute('closed_at'));
        $event = DB::table('accounting_audit_events')->where('entity_kind', 'period')->where('action', 'close')->first();
        $this->assertNotNull($event);
        $this->assertSame('open', json_decode($event->before_payload, true)['state']);
        $this->assertSame('soft_closed', json_decode($event->after_payload, true)['state']);
    }

    public function test_open_can_hard_close_and_soft_close_can_escalate(): void
    {
        $this->close();
        $this->close('hard_closed');
        $this->assertSame('hard_closed', $this->period->fresh()->getAttribute('state'));
    }

    public function test_hard_close_cannot_be_downgraded_via_close(): void
    {
        $this->close('hard_closed');
        $this->invalid(fn () => $this->close(), 'state');
    }

    public function test_same_state_with_matching_revision_creates_no_duplicate_audit(): void
    {
        $this->close();
        $count = DB::table('accounting_audit_events')->count();
        $this->close();
        $this->assertSame($count, DB::table('accounting_audit_events')->count());
        $this->assertSame(2, $this->period->fresh()->getAttribute('revision'));
    }

    public function test_stale_revision_and_blank_reason_are_rejected(): void
    {
        $this->close();
        $this->invalid(fn () => $this->close('hard_closed', ['revision' => 1]), 'revision');
        $this->invalid(fn () => $this->close('hard_closed', ['reason' => '  ']), 'reason');
    }

    public function test_ownership_and_closure_metadata_cannot_be_injected(): void
    {
        foreach (['company_id' => $this->company->getKey(), 'closed_by' => $this->reviewer->getKey(), 'closed_at' => now()->toDateTimeString()] as $field => $value) {
            $this->invalid(fn () => $this->close('soft_closed', [$field => $value]), 'state');
        }
    }

    public function test_manage_permission_does_not_grant_close_or_reopen(): void
    {
        $user = $this->staff(['erp.fiscal-years.view', 'erp.accounting-periods.view', 'erp.accounting-periods.manage']);
        $this->actingAs($user);
        $this->post($this->url('close'), ['state' => 'soft_closed', 'revision' => 1, 'reason' => 'Test'])->assertForbidden();
        $this->post($this->url('reopen'), ['revision' => 1, 'reason' => 'Test'])->assertForbidden();
    }

    public function test_http_close_and_independent_reopen_do_not_require_manage(): void
    {
        $closer = $this->staff(['erp.fiscal-years.view', 'erp.accounting-periods.view', 'erp.accounting-periods.close']);
        $opener = $this->staff(['erp.fiscal-years.view', 'erp.accounting-periods.view', 'erp.accounting-periods.reopen']);
        $this->actingAs($closer)->post($this->url('close'), ['state' => 'hard_closed', 'revision' => 1, 'reason' => 'Close reviewed month'])->assertRedirect();
        $this->actingAs($opener)->post($this->url('reopen'), ['revision' => 2, 'reason' => 'Independent review'])->assertRedirect();
        $this->assertSame('open', $this->period->fresh()->getAttribute('state'));
    }

    public function test_same_closer_cannot_reopen_even_as_super_admin(): void
    {
        $this->close();
        $this->expectException(AuthorizationException::class);
        $this->reopen($this->actor);
    }

    public function test_independent_reopen_preserves_last_closure_and_audits_reviewer(): void
    {
        $closed = $this->close('hard_closed');
        $lastTime = $closed->getAttribute('closed_at')->toIso8601String();
        $period = $this->reopen($this->reviewer);
        $this->assertSame('open', $period->getAttribute('state'));
        $this->assertSame(3, $period->getAttribute('revision'));
        $this->assertSame((int) $this->actor->getKey(), (int) $period->getAttribute('closed_by'));
        $this->assertSame($lastTime, $period->getAttribute('closed_at')->toIso8601String());
        $this->assertDatabaseHas('accounting_audit_events', ['entity_kind' => 'period', 'entity_id' => $period->getKey(), 'action' => 'reopen', 'actor_id' => $this->reviewer->getKey()]);
    }

    public function test_reopening_without_closure_actor_is_rejected(): void
    {
        $this->period->update(['state' => 'soft_closed']);
        $this->expectException(AuthorizationException::class);
        $this->reopen($this->reviewer);
    }

    public function test_closed_year_blocks_period_state_changes(): void
    {
        $this->year->update(['state' => 'closed']);
        $this->invalid(fn () => $this->close(), 'state');
    }

    public function test_cutover_lock_blocks_period_state_changes(): void
    {
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['cutover_locked_at' => now()]);
        $this->invalid(fn () => $this->close(), 'company_id');
    }

    public function test_independent_actor_ui_permissions_are_projected(): void
    {
        $this->close();
        $url = '/erp/accounting/calendar/'.$this->year->getKey();
        $this->actingAs($this->actor)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('periods.0.can_reopen', false)->where('periods.0.reopen_requires_independent', true));
        $this->actingAs($this->reviewer)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('periods.0.can_reopen', true));
    }

    public function test_open_approved_journal_passes_guard_without_posting_it(): void
    {
        $journal = $this->journal();
        $result = $this->check($journal);
        $this->assertSame($journal->getKey(), $result['journal_id']);
        $this->assertSame($this->period->getKey(), $result['period_id']);
        $this->assertSame('approved', $journal->fresh()->getAttribute('status'));
    }

    public function test_approval_before_closure_does_not_allow_posting_after_closure(): void
    {
        $journal = $this->journal();
        $this->check($journal);
        $this->close();
        $this->invalid(fn () => $this->check($journal), 'accounting_date');
    }

    public function test_reopening_restores_period_eligibility_for_the_approved_journal(): void
    {
        $journal = $this->journal();
        $this->close('hard_closed');
        $this->reopen($this->reviewer);
        $this->assertSame($this->period->getKey(), $this->check($journal)['period_id']);
    }

    public function test_soft_close_allows_only_persisted_independently_approved_adjustment(): void
    {
        $this->close();
        $journal = $this->journal(['entry_kind' => 'period_adjustment']);
        $this->assertSame($this->period->getKey(), $this->check($journal)['period_id']);
        $ordinary = $this->journal();
        $this->invalid(fn () => $this->check($ordinary), 'accounting_date');
    }

    public function test_adjustment_permission_is_required_even_for_approved_adjustment(): void
    {
        $journal = $this->journal(['entry_kind' => 'period_adjustment']);
        $this->close();
        $this->expectException(AuthorizationException::class);
        $this->check($journal, $this->staff(['erp.journal-entries.post']));
    }

    public function test_adjustment_cannot_be_self_approved(): void
    {
        $this->close();
        $journal = $this->journal(['entry_kind' => 'period_adjustment', 'approved_by' => $this->actor->getKey()]);
        $this->invalid(fn () => $this->check($journal), 'approval');
    }

    public function test_hard_close_blocks_adjustments_and_reversals_even_for_super_admin(): void
    {
        $this->close('hard_closed');
        foreach (['manual', 'opening', 'reversal', 'period_adjustment'] as $kind) {
            $journal = $this->journal(['entry_kind' => $kind]);
            $this->invalid(fn () => $this->check($journal), 'accounting_date');
        }
    }

    public function test_soft_close_blocks_reversals_and_openings(): void
    {
        $this->close();
        foreach (['reversal', 'opening'] as $kind) {
            $journal = $this->journal(['entry_kind' => $kind]);
            $this->invalid(fn () => $this->check($journal), 'accounting_date');
        }
    }

    public function test_closed_fiscal_year_blocks_guard(): void
    {
        $journal = $this->journal();
        $this->year->update(['state' => 'closed']);
        $this->invalid(fn () => $this->check($journal), 'accounting_date');
    }

    public function test_draft_or_revoked_approval_blocks_guard(): void
    {
        foreach ([['status' => 'draft'], ['approved_by' => null], ['approved_at' => null]] as $override) {
            $journal = $this->journal($override);
            $this->invalid(fn () => $this->check($journal), 'approval');
        }
    }

    public function test_guard_requires_journal_post_permission(): void
    {
        $journal = $this->journal();
        $this->expectException(AuthorizationException::class);
        $this->check($journal, $this->staff(['erp.accounting-periods.view']));
    }

    public function test_guard_does_not_support_arbitrary_automatic_or_closing_headers(): void
    {
        foreach (['automatic', 'closing', 'unknown'] as $kind) {
            $journal = $this->journal(['entry_kind' => $kind]);
            $this->invalid(fn () => $this->check($journal), 'entry_kind');
        }
    }

    public function test_missing_period_or_fiscal_year_blocks_without_changing_date(): void
    {
        foreach (['2026-11-01', '2027-01-01'] as $date) {
            $journal = $this->journal(['accounting_date' => $date]);
            $this->invalid(fn () => $this->check($journal), 'accounting_date');
            $this->assertSame($date, $journal->fresh()->getAttribute('accounting_date')->format('Y-m-d'));
        }
    }

    public function test_overlapping_legacy_periods_are_rejected(): void
    {
        AccountingPeriod::query()->create(['company_id' => $this->company->getKey(), 'fiscal_year_id' => $this->year->getKey(), 'code' => 'RAW', 'starts_on' => '2026-10-10', 'ends_on' => '2026-10-20']);
        $journal = $this->journal();
        $this->invalid(fn () => $this->check($journal), 'accounting_date');
    }

    public function test_overlapping_legacy_years_are_rejected(): void
    {
        FiscalYear::query()->create(['company_id' => $this->company->getKey(), 'code' => 'RAW', 'name' => 'Raw overlap', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
        $journal = $this->journal();
        $this->invalid(fn () => $this->check($journal), 'accounting_date');
    }

    public function test_journal_period_must_match_the_resolved_date(): void
    {
        $other = AccountingPeriod::query()->create(['company_id' => $this->company->getKey(), 'fiscal_year_id' => $this->year->getKey(), 'code' => 'NOV', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30']);
        $journal = $this->journal(['accounting_period_id' => $other->getKey()]);
        $this->invalid(fn () => $this->check($journal), 'accounting_date');
    }

    public function test_date_boundaries_are_inclusive(): void
    {
        foreach (['2026-10-01', '2026-10-31'] as $date) {
            $journal = $this->journal(['accounting_date' => $date]);
            $this->assertSame($date, $this->check($journal)['accounting_date']);
        }
    }

    public function test_business_date_converts_an_instant_in_company_timezone(): void
    {
        $dates = app(AccountingBusinessDate::class);
        $this->assertSame('2026-10-16', $dates->fromInstant($this->company, CarbonImmutable::parse('2026-10-15T20:30:00Z')));
        $this->assertSame('2026-10-15', $dates->fromInstant($this->company, CarbonImmutable::parse('2026-10-15T17:59:59Z')));
    }

    public function test_invalid_timezone_is_not_silently_treated_as_utc(): void
    {
        $this->company->setAttribute('timezone', 'Unknown/Zone');
        $this->invalid(fn () => app(AccountingBusinessDate::class)->today($this->company), 'company_id');
    }

    public function test_readonly_lookup_is_scoped_and_creates_no_audit(): void
    {
        $count = DB::table('accounting_audit_events')->count();
        $result = $this->guard->inspect($this->staff(['erp.accounting-periods.view']), (int) $this->company->getKey(), '2026-10-15');
        $this->assertTrue($result['ordinary_open']);
        $this->assertSame($count, DB::table('accounting_audit_events')->count());
    }

    public function test_explicit_lookup_date_is_not_shifted_to_today(): void
    {
        $this->actingAs($this->actor)->get('/erp/accounting/calendar/'.$this->year->getKey().'?accounting_date=2026-10-01')->assertOk()->assertInertia(fn (Assert $page) => $page->where('dateCheck.date', '2026-10-01')->where('dateCheck.ordinary_open', true));
    }

    public function test_invalid_lookup_date_is_rejected(): void
    {
        $this->actingAs($this->actor)->get('/erp/accounting/calendar/'.$this->year->getKey().'?accounting_date=2026-02-30')->assertSessionHasErrors('accounting_date');
    }

    public function test_closure_and_reopening_roll_back_with_the_caller_transaction(): void
    {
        try {
            DB::transaction(function (): void {
                $this->close('hard_closed');
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }
        $this->assertSame('open', $this->period->fresh()->getAttribute('state'));
        $this->assertSame(0, DB::table('accounting_audit_events')->where('action', 'close')->count());
    }

    public function test_period_actions_never_create_journals_or_enable_posting(): void
    {
        $this->close();
        $this->reopen($this->reviewer);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseHas('accounting_company_settings', ['company_id' => $this->company->getKey(), 'state' => 'disabled']);
    }

    public function test_wrong_company_cannot_close_or_inspect(): void
    {
        $other = Company::query()->create(['name' => 'Other', 'code' => 'PERIOD-B', 'status' => 'active', 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka']);
        $this->expectException(AuthorizationException::class);
        $this->guard->inspect($this->staff(['erp.accounting-periods.view']), (int) $other->getKey(), '2026-10-15');
    }

    public function test_backdated_posting_requires_adjust_permission_without_changing_date(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-16T00:00:00Z'));
        try {
            $journal = $this->journal();
            $user = $this->staff(['erp.journal-entries.post']);
            try {
                $this->check($journal, $user);
                $this->fail('Expected backdate authorization failure.');
            } catch (AuthorizationException $exception) {
                $this->assertStringContainsString('Backdated', $exception->getMessage());
            }
            $this->assertSame('2026-10-15', $journal->fresh()->getAttribute('accounting_date')->format('Y-m-d'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_current_company_date_does_not_require_backdate_permission(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15T00:00:00Z'));
        try {
            $this->assertSame($this->period->getKey(), $this->check($this->journal(), $this->staff(['erp.journal-entries.post']))['period_id']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_wrong_company_cannot_change_state_through_public_service(): void
    {
        $other = Company::query()->create(['name' => 'Other', 'code' => 'STATE-B', 'status' => 'active', 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka']);
        $user = $this->staff(['erp.accounting-periods.close']);
        $this->expectException(AuthorizationException::class);
        $this->states->close($user, (int) $other->getKey(), (int) $this->year->getKey(), (int) $this->period->getKey(), ['state' => 'hard_closed', 'revision' => 1, 'reason' => 'Invalid company']);
    }

    public function test_stale_reopen_fails_without_adding_reopen_audit(): void
    {
        $this->close();
        $this->invalid(fn () => $this->states->reopen($this->reviewer, (int) $this->company->getKey(), (int) $this->year->getKey(), (int) $this->period->getKey(), ['revision' => 1, 'reason' => 'Stale request']), 'revision');
        $this->assertSame(0, DB::table('accounting_audit_events')->where('action', 'reopen')->count());
    }
}
