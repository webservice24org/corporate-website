<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\AccountingBalanceSheetPermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ERP\AccountingExpenseWorkspaceTestCase;

final class AccountingBalanceSheetScreenTest extends AccountingExpenseWorkspaceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingBalanceSheetPermissionSeeder::class);
    }

    private function url(): string
    {
        return '/erp/accounting/balance-sheet?company_id='.$this->company->getKey().'&as_of=2026-10-26';
    }

    /** @param list<string> $permissions */
    private function staff(array $permissions): User
    {
        $staff = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $staff->getKey(), 'employee_number' => 'BS-'.$staff->getKey(), 'first_name' => 'Balance', 'last_name' => 'Staff', 'employment_status' => 'active']);
        if ($permissions !== []) {
            $staff->givePermissionTo($permissions);
        }

        return $staff;
    }

    public function test_guest_redirects_to_login(): void
    {
        $this->get($this->url())->assertRedirect('/login');
    }

    public function test_expense_permission_does_not_grant_balance_sheet(): void
    {
        $this->actingAs($this->staff(['erp.expenses.view']))->get($this->url())->assertForbidden();
    }

    public function test_balance_only_staff_can_read_without_other_report_permissions(): void
    {
        $this->recognized($this->expense());
        $this->actingAs($this->staff(['erp.balance-sheet.view']))->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/BalanceSheet/Index')->where('canLedger', false)->where('canTrial', false)->where('canProfit', false)->where('report.totals.liabilities', '100.27')->where('report.earnings.current_year_unclosed_result', '-100.27')->where('report.complete', true)->missing('report.accounts'));
    }

    public function test_foreign_company_is_forbidden_for_staff(): void
    {
        $other = Company::query()->create(['code' => 'BS-OTHER', 'name' => 'Foreign', 'status' => 'active', 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka']);
        $this->actingAs($this->staff(['erp.balance-sheet.view']))->get('/erp/accounting/balance-sheet?company_id='.$other->getKey())->assertForbidden();
    }

    public function test_admin_without_company_receives_selector_only(): void
    {
        $this->actingAs($this->maker)->get('/erp/accounting/balance-sheet')->assertOk()->assertInertia(fn (Assert $page) => $page->where('company', null)->where('report', null));
    }

    public function test_unposted_expense_does_not_change_balance_sheet(): void
    {
        $this->expense();
        $this->actingAs($this->maker)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.totals.liabilities', '0.00')->where('report.earnings.residual_total', '0.00'));
    }

    public function test_partial_settlement_changes_cash_and_liability_without_second_loss(): void
    {
        $expense = $this->expense();
        $this->recognized($expense);
        $this->postPlan($this->approved($this->settle($expense)));
        $this->actingAs($this->maker)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.totals.assets', '-40.13')->where('report.totals.liabilities', '60.14')->where('report.totals.equity_with_earnings', '-100.27')->where('report.difference', '0.00'));
    }

    public function test_recognition_compensation_restores_balances(): void
    {
        $expense = $this->expense();
        $original = $this->recognized($expense);
        $reverse = $this->finance->create($this->maker, ['expense_id' => (int) $expense->getKey(), 'native_revision' => (int) $expense->refresh()->getAttribute('revision'), 'kind' => 'reversal', 'original_plan_id' => (int) $original->getKey(), 'date' => '2026-10-26', 'key' => (string) Str::uuid(), 'reason' => 'Reverse expense for Balance Sheet']);
        $this->postPlan($this->approved($reverse));
        $this->actingAs($this->maker)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.totals.liabilities', '0.00')->where('report.earnings.residual_total', '0.00')->where('report.complete', true));
    }

    public function test_recoverable_tax_remains_asset_not_earnings_cost(): void
    {
        $this->recognized($this->expense(['tax_treatment' => 'recoverable', 'tax_amount' => '5.00', 'tax_account_id' => $this->mapping['input_tax']]));
        $this->actingAs($this->maker)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.totals.assets', '5.00')->where('report.totals.liabilities', '105.27')->where('report.earnings.current_year_unclosed_result', '-100.27'));
    }

    public function test_missing_year_withholds_confirmed_totals_without_calendar_guess(): void
    {
        DB::table('fiscal_years')->where('company_id', $this->company->getKey())->update(['starts_on' => '2027-01-01', 'ends_on' => '2027-12-31']);
        $this->actingAs($this->maker)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->where('fiscalYear', null)->where('report.complete', false)->where('report.confirmed_totals', null)->where('report.earnings.current_year_unclosed_result', null));
    }

    public function test_noncalendar_fiscal_year_start_is_used(): void
    {
        $this->recognized($this->expense());
        DB::table('fiscal_years')->where('company_id', $this->company->getKey())->update(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $this->actingAs($this->maker)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->where('fiscalYear.starts_on', '2026-07-01')->where('report.earnings.current_year_unclosed_result', '-100.27'));
    }

    public function test_overlapping_years_are_not_arbitrarily_selected(): void
    {
        DB::table('fiscal_years')->insert(['company_id' => $this->company->getKey(), 'code' => 'OVERLAP', 'name' => 'Damaged overlapping calendar', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'state' => 'open', 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->maker)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->where('fiscalYear', null)->where('report.fiscal_year_matches', 2)->where('report.complete', false));
    }

    public function test_inconsistent_classification_requires_review(): void
    {
        $this->recognized($this->expense());
        DB::table('accounts')->where('id', $this->liability->getKey())->update(['statement_role' => 'current_asset']);
        $this->actingAs($this->maker)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.complete', false)->where('report.confirmed_totals', null));
    }

    public function test_future_date_and_dimension_subset_are_rejected(): void
    {
        $this->actingAs($this->maker)->getJson('/erp/accounting/balance-sheet?company_id='.$this->company->getKey().'&as_of=2026-10-27')->assertUnprocessable();
        $this->getJson($this->url().'&branch_id=1')->assertUnprocessable();
    }

    public function test_damaged_journal_blocks_partial_balance_sheet(): void
    {
        $this->recognized($this->expense());
        DB::table('journal_entry_lines')->where('company_id', $this->company->getKey())->where('credit', '>', 0)->update(['credit' => '100.28']);
        $this->actingAs($this->maker)->getJson($this->url())->assertUnprocessable();
    }

    public function test_report_read_neither_writes_nor_returns_private_source_text(): void
    {
        $this->recognized($this->expense());
        $before = DB::table('journal_entries')->count();
        $response = $this->actingAs($this->maker)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.native_source_coverage_certified', false));
        $this->assertStringNotContainsString('PRIVATE-DESCRIPTION', $response->getContent());
        $this->assertSame($before, DB::table('journal_entries')->count());
    }
}
