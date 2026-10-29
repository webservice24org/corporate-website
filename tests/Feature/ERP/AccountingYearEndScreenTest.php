<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCalendarService;
use App\Services\ERP\Accounting\YearEnd\AccountingYearEndWorkflow;
use Database\Seeders\AccountingYearEndPermissionSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ERP\AccountingYearEndTestCase;

final class AccountingYearEndScreenTest extends AccountingYearEndTestCase
{
    /** @param list<string> $permissions */
    private function staff(array $permissions = ['erp.year-end.view']): User
    {
        $u = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $u->getKey(), 'employee_number' => 'YE-'.$u->getKey(), 'first_name' => 'Year', 'last_name' => 'Staff', 'employment_status' => 'active']);
        if ($permissions !== []) {
            $u->givePermissionTo($permissions);
        }

        return $u;
    }

    private function url(): string
    {
        return '/erp/accounting/year-end?company_id='.$this->company->getKey().'&year_id='.$this->endYear->getKey();
    }

    private function action(string $mode, string $action): string
    {
        return '/erp/accounting/year-end/'.$this->company->getKey().'/'.$this->endYear->getKey().'/'.$mode.'/'.$action;
    }

    public function test_guest_redirects_from_screen_and_commands(): void
    {
        $this->get($this->url())->assertRedirect('/login');
        $this->post($this->action('close', 'prepare'))->assertRedirect('/login');
    }

    public function test_report_authority_does_not_grant_year_end_view(): void
    {
        $this->actingAs($this->staff(['erp.trial-balance.view']))->get($this->url())->assertForbidden();
    }

    public function test_view_only_user_has_no_financial_amounts_or_historical_plans(): void
    {
        $this->closeExpenseYear();
        $this->actingAs($this->staff())->get($this->url())->assertOk()->assertInertia(fn (Assert $p) => $p->component('ERP/Accounting/YearEnd/Index')->where('review.plan', null)->where('review.approvalReference', null)->has('review.operations', 0)->where('review.canPrepare', false)->where('review.canApprove', false)->where('review.canPost', false));
    }

    public function test_staff_defaults_to_own_company_and_admin_sees_selector(): void
    {
        $this->actingAs($this->staff())->get('/erp/accounting/year-end')->assertOk()->assertInertia(fn (Assert $p) => $p->where('company.id', (int) $this->company->getKey())->has('companies', 1));
        $this->actingAs($this->maker)->get('/erp/accounting/year-end')->assertOk()->assertInertia(fn (Assert $p) => $p->where('company', null)->where('review', null));
    }

    public function test_foreign_company_scope_is_forbidden(): void
    {
        $other = Company::query()->create(['code' => 'YE-B', 'name' => 'Other', 'status' => 'active', 'currency_code' => 'USD', 'timezone' => 'UTC']);
        $this->actingAs($this->staff())->get('/erp/accounting/year-end?company_id='.$other->getKey().'&year_id='.$this->endYear->getKey())->assertForbidden();
    }

    public function test_view_permission_cannot_prepare_approve_or_execute(): void
    {
        $this->actingAs($this->staff());
        foreach (['prepare', 'approve', 'post'] as $action) {
            $this->post($this->action('close', $action), $this->yearCommand())->assertForbidden();
        }
    }

    public function test_close_permission_does_not_grant_reopening(): void
    {
        $this->closeExpenseYear();
        $this->actingAs($this->staff(['erp.year-end.view', 'erp.year-end.close']))->post($this->action('reopen', 'post'), $this->yearCommand())->assertForbidden();
    }

    public function test_client_cannot_supply_journal_lines_or_retained_target(): void
    {
        $this->closeMonth();
        $command = [...$this->yearCommand(), 'lines' => [], 'retained_account_id' => $this->mapping['general_expense']];
        $this->actingAs($this->maker)->post($this->action('close', 'prepare'), $command)->assertSessionHasErrors('year_end');
        $this->assertDatabaseCount('accounting_year_end_events', 0);
    }

    public function test_read_is_private_and_does_not_prepare_or_post(): void
    {
        $this->closeMonth();
        $response = $this->actingAs($this->maker)->get($this->url())->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertInertia(fn (Assert $p) => $p->missing('review.snapshot_hash')->missing('review.fingerprint'));
        $this->assertDatabaseCount('accounting_year_end_events', 0);
    }

    public function test_independent_approver_capability_is_not_given_to_preparer(): void
    {
        $this->closeMonth();
        $this->prepareYear();
        $this->actingAs($this->maker)->get($this->url())->assertOk()->assertInertia(fn (Assert $p) => $p->where('review.canApprove', false));
        $this->actingAs($this->reviewer)->get($this->url())->assertOk()->assertInertia(fn (Assert $p) => $p->where('review.canApprove', true));
    }

    public function test_later_hard_closed_period_blocks_year_reopen(): void
    {
        $this->closeExpenseYear();
        $calendar = app(AccountingCalendarService::class);
        $year = $calendar->saveYear($this->maker, ['company_id' => $this->company->getKey(), 'code' => 'NEXT', 'name' => 'Next', 'starts_on' => '2026-11-01', 'ends_on' => '2027-10-31', 'reason' => 'Later dependency fixture']);
        $period = $calendar->savePeriod($this->maker, (int) $year->getKey(), ['company_id' => $this->company->getKey(), 'code' => 'NOV', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'reason' => 'Later dependency fixture']);
        DB::table('accounting_periods')->where('id', $period->getKey())->update(['state' => 'hard_closed']);
        $r = $this->yearReview();
        $checks = array_column($r['checks'], null, 'code');
        $this->assertFalse($checks['later_closures']['passed']);
        $this->invalid(fn () => $this->prepareYear('reopen'));
    }

    public function test_retained_evidence_is_append_only(): void
    {
        $this->closeMonth();
        $e = $this->prepareYear();
        $this->expectException(\LogicException::class);
        $e->delete();
    }

    public function test_permissions_are_idempotent_and_do_not_grant_manual_posting(): void
    {
        $this->seed(AccountingYearEndPermissionSeeder::class);
        $u = $this->staff();
        $this->assertFalse($u->can('erp.journal-entries.post'));
        $this->assertFalse($u->can('erp.year-end.close'));
        $this->assertSame(1, DB::table('permissions')->where('name', AccountingYearEndWorkflow::VIEW)->count());
    }
}
