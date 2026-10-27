<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingProfitLossPermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ERP\AccountingExpenseWorkspaceTestCase;

final class AccountingProfitLossScreenTest extends AccountingExpenseWorkspaceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingProfitLossPermissionSeeder::class);
    }

    private function url(): string
    {
        return '/erp/accounting/profit-loss?company_id='.$this->company->getKey().'&from=2026-10-01&to=2026-10-26';
    }

    /** @param list<string> $permissions */
    private function staff(array $permissions): User
    {
        $staff = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $staff->getKey(), 'employee_number' => 'PROFIT-'.$staff->getKey(), 'first_name' => 'Profit', 'last_name' => 'Staff', 'employment_status' => 'active']);
        if ($permissions !== []) {
            $staff->givePermissionTo($permissions);
        }

        return $staff;
    }

    public function test_guest_redirects_to_login(): void
    {
        $this->get($this->url())->assertRedirect('/login');
    }

    public function test_expense_permission_does_not_grant_profit_report(): void
    {
        $this->actingAs($this->staff(['erp.expenses.view']))->get($this->url())->assertForbidden();
    }

    public function test_profit_only_staff_can_read_without_trial_or_ledger_permission(): void
    {
        $this->recognized($this->expense());
        $this->actingAs($this->staff(['erp.profit-loss.view']))->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/ProfitLoss/Index')->where('canLedger', false)->where('canTrial', false)->where('report.period_result', '-100.27')->missing('report.accounts')->missing('report.totals'));
    }

    public function test_foreign_company_query_is_forbidden_for_staff(): void
    {
        $foreign = Company::query()->create(['code' => 'PROFIT-B', 'name' => 'Foreign', 'status' => 'active', 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka']);
        $this->actingAs($this->staff(['erp.profit-loss.view']))->get('/erp/accounting/profit-loss?company_id='.$foreign->getKey())->assertForbidden();
    }

    public function test_admin_without_company_does_not_receive_a_report(): void
    {
        $this->actingAs($this->maker)->get('/erp/accounting/profit-loss')->assertOk()->assertInertia(fn (Assert $page) => $page->where('report', null)->where('company', null));
    }

    public function test_unposted_expense_does_not_reduce_profit(): void
    {
        $this->expense();
        $this->actingAs($this->maker)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.period_result', '0.00'));
    }

    public function test_payment_does_not_recognize_expense_twice(): void
    {
        $expense = $this->expense();
        $this->recognized($expense);
        $this->postPlan($this->approved($this->settle($expense)));
        $this->actingAs($this->maker)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.period_result', '-100.27'));
    }

    public function test_recognition_compensation_restores_period_result(): void
    {
        $expense = $this->expense();
        $original = $this->recognized($expense);
        $reverse = $this->finance->create($this->maker, ['expense_id' => (int) $expense->getKey(), 'native_revision' => (int) $expense->refresh()->getAttribute('revision'), 'kind' => 'reversal', 'original_plan_id' => (int) $original->getKey(), 'date' => '2026-10-26', 'key' => (string) Str::uuid(), 'reason' => 'Reverse expense for report test']);
        $this->postPlan($this->approved($reverse));
        $this->actingAs($this->maker)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.period_result', '0.00'));
    }

    public function test_inconsistent_role_withholds_result_and_exposes_review_reason(): void
    {
        $this->recognized($this->expense());
        DB::table('accounts')->where('id', $this->mapping['general_expense'])->update(['statement_role' => 'current_asset']);
        $this->actingAs($this->maker)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.period_result', null)->where('report.classification_complete', false)->where('report.issues.0.issue', 'classification_missing_or_inconsistent'));
    }

    public function test_inactive_historical_account_is_still_reported(): void
    {
        $this->recognized($this->expense());
        DB::table('accounts')->where('id', $this->mapping['general_expense'])->update(['status' => 'inactive']);
        $this->actingAs($this->maker)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.period_result', '-100.27'));
    }

    public function test_preperiod_expense_is_not_current_period_income_or_cost(): void
    {
        $this->recognized($this->expense());
        $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00:00', 'Asia/Dhaka'));
        $this->actingAs($this->maker)->get('/erp/accounting/profit-loss?company_id='.$this->company->getKey().'&from=2026-11-01&to=2026-11-01')->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.period_result', '0.00'));
    }

    public function test_future_dates_and_unsupported_dimension_filters_fail(): void
    {
        $this->actingAs($this->maker)->getJson('/erp/accounting/profit-loss?company_id='.$this->company->getKey().'&from=2026-10-01&to=2026-10-27')->assertUnprocessable();
        $this->getJson($this->url().'&branch_id=1')->assertUnprocessable();
    }

    public function test_damaged_journal_does_not_produce_a_partial_profit_result(): void
    {
        $this->recognized($this->expense());
        DB::table('journal_entry_lines')->where('company_id', $this->company->getKey())->where('credit', '>', 0)->update(['credit' => '100.28']);
        $this->actingAs($this->maker)->getJson($this->url())->assertUnprocessable();
    }

    public function test_report_read_does_not_write_or_return_private_description(): void
    {
        $this->recognized($this->expense());
        $before = DB::table('journal_entries')->count();
        $response = $this->actingAs($this->maker)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.native_source_coverage_certified', false));
        $this->assertStringNotContainsString('PRIVATE-DESCRIPTION', $response->getContent());
        $this->assertSame($before, DB::table('journal_entries')->count());
    }
}
