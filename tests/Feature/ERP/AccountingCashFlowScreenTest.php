<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\ERP\Accounting\Reports\AccountingCashFlowReader;
use Database\Seeders\AccountingCashFlowWorkspacePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ERP\AccountingExpenseWorkspaceTestCase;

final class AccountingCashFlowScreenTest extends AccountingExpenseWorkspaceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingCashFlowWorkspacePermissionSeeder::class);
    }

    /** @param list<string> $permissions */
    private function staff(array $permissions): User
    {
        $staff = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $staff->getKey(), 'employee_number' => 'CFS-'.$staff->getKey(), 'first_name' => 'Cashflow', 'last_name' => 'Staff', 'employment_status' => 'active']);
        if ($permissions !== []) {
            $staff->givePermissionTo($permissions);
        }

        return $staff;
    }

    private function url(?string $format = null, string $extra = ''): string
    {
        return '/erp/accounting/cash-flow'.($format === null ? '' : '/export').'?company_id='.$this->company->getKey().'&from=2026-10-26&to=2026-10-26'.($format === null ? '' : '&format='.$format).$extra;
    }

    public function test_guest_redirects_from_screen_and_download(): void
    {
        $this->get($this->url())->assertRedirect('/login');
        $this->get($this->url('csv'))->assertRedirect('/login');
    }

    public function test_native_expense_permission_does_not_grant_cashflow_screen(): void
    {
        $this->actingAs($this->staff(['erp.expenses.view']))->get($this->url())->assertForbidden();
    }

    public function test_cashflow_only_staff_can_view_without_other_finance_links(): void
    {
        $this->recognized($this->expense());
        $this->actingAs($this->staff(['erp.cash-flow.view']))->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/CashFlow/Index')->where('canLedger', false)->where('canTrial', false)->where('canProfit', false)->where('canBalance', false)->where('canExport', false)->where('report.confirmed_flows.operating', '0.00'));
    }

    public function test_admin_without_company_receives_selector_only_and_no_writes(): void
    {
        $before = DB::table('journal_entries')->count();
        $this->actingAs($this->maker)->get('/erp/accounting/cash-flow')->assertOk()->assertInertia(fn (Assert $page) => $page->where('company', null)->where('report', null));
        $this->assertSame($before, DB::table('journal_entries')->count());
    }

    public function test_staff_defaults_to_own_company(): void
    {
        $this->actingAs($this->staff(['erp.cash-flow.view']))->get('/erp/accounting/cash-flow')->assertOk()->assertInertia(fn (Assert $page) => $page->where('company.id', (int) $this->company->getKey())->has('companies', 1));
    }

    public function test_foreign_company_is_forbidden_on_screen_and_export(): void
    {
        $other = Company::query()->create(['code' => 'CFS-B', 'name' => 'Foreign', 'status' => 'active', 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka']);
        $staff = $this->staff(['erp.cash-flow.view', 'erp.cash-flow.export']);
        $this->actingAs($staff)->get('/erp/accounting/cash-flow?company_id='.$other->getKey())->assertForbidden();
        $this->get('/erp/accounting/cash-flow/export?company_id='.$other->getKey().'&format=csv&from=2026-10-26&to=2026-10-26')->assertForbidden();
    }

    public function test_view_only_cannot_download_csv_or_print(): void
    {
        $staff = $this->staff(['erp.cash-flow.view']);
        foreach (['csv', 'print'] as $format) {
            $this->actingAs($staff)->get($this->url($format))->assertForbidden();
        }
    }

    public function test_export_only_does_not_grant_view_or_download(): void
    {
        $staff = $this->staff(['erp.cash-flow.export']);
        $this->actingAs($staff)->get($this->url())->assertForbidden();
        $this->get($this->url('csv'))->assertForbidden();
    }

    public function test_paid_now_screen_reconciles_indirect_operating_cash(): void
    {
        $this->recognized($this->expense(['settlement_mode' => 'paid_now', 'cash_account_id' => $this->cash->getKey()]));
        $this->actingAs($this->maker)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.complete', true)->where('report.indirect_bridge.operating_cash', '-100.27')->where('report.reconciliation_difference', '0.00')->where('report.native_source_coverage_certified', false));
    }

    public function test_partial_payment_keeps_accrual_noncash_disclosure_and_cash_control(): void
    {
        $expense = $this->expense();
        $this->recognized($expense);
        $this->postPlan($this->approved($this->settle($expense)));
        $this->actingAs($this->maker)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.confirmed_flows.operating', '-40.13')->has('report.noncash_journals', 1)->has('report.cash_journals', 1));
    }

    public function test_unmapped_activity_withholds_confirmation_and_exports_provisional_label(): void
    {
        $this->recognized($this->expense([
            'tax_treatment' => 'recoverable',
            'tax_amount' => '5.00',
            'tax_account_id' => $this->mapping['input_tax'],
        ]));
        $this->actingAs($this->maker)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.complete', false)->where('report.confirmed_flows', null));
        $csv = $this->get($this->url('csv'))->assertOk()->streamedContent();
        $this->assertStringContainsString('PROVISIONAL', $csv);
        $this->assertStringContainsString('active_account_cash_flow_role_missing_or_invalid', $csv);
    }

    public function test_csv_contains_full_scope_and_neutralizes_formula_like_company_name(): void
    {
        DB::table('companies')->where('id', $this->company->getKey())->update(['name' => '=1+1']);
        $this->recognized($this->expense());
        $response = $this->actingAs($this->maker)->get($this->url('csv'))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringContainsString('BDT', $csv);
        $this->assertStringContainsString('Indirect operating bridge', $csv);
        $this->assertStringContainsString('Noncash disclosure', $csv);
        $this->assertStringNotContainsString('PRIVATE-DESCRIPTION', $csv);
    }

    public function test_print_escapes_labels_and_identifies_complete_scope(): void
    {
        DB::table('companies')->where('id', $this->company->getKey())->update(['name' => '<script>alert(1)</script>']);
        $this->actingAs($this->maker)->get($this->url('print'))->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>', false)->assertSee('All filtered rows');
    }

    public function test_exports_include_every_journal_despite_page_controls(): void
    {
        $expense = $this->expense();
        $this->recognized($expense);
        $this->postPlan($this->approved($this->settle($expense, '40.13')));
        $this->postPlan($this->approved($this->settle($expense, '60.14')));
        $report = app(AccountingCashFlowReader::class)->report($this->maker, (int) $this->company->getKey(), ['from' => '2026-10-26', 'to' => '2026-10-26']);
        $this->assertCount(2, $report['cash_journals']);
        $csv = $this->actingAs($this->maker)->get($this->url('csv', '&page=99&per_page=1'))->assertOk()->streamedContent();
        foreach ([...$report['cash_journals'], ...$report['noncash_journals']] as $journal) {
            $this->assertStringContainsString($journal['number'], $csv);
        }
        $this->assertStringContainsString('60.14', $csv);
        $this->assertStringContainsString('40.13', $csv);
    }

    public function test_screen_and_print_are_private_and_uncached(): void
    {
        foreach ([null, 'print'] as $format) {
            $response = $this->actingAs($this->maker)->get($this->url($format))->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        }
    }

    public function test_invalid_dates_and_unsupported_dimensions_are_rejected(): void
    {
        $this->actingAs($this->maker)->getJson('/erp/accounting/cash-flow?company_id='.$this->company->getKey().'&from=2026-10-26&to=2026-10-27')->assertUnprocessable();
        $this->getJson($this->url(null, '&branch_id=1'))->assertUnprocessable();
        $this->getJson($this->url('csv', '&department_id=1'))->assertUnprocessable();
    }

    public function test_damaged_journal_blocks_screen_and_download(): void
    {
        $this->recognized($this->expense());
        DB::table('journal_entry_lines')->where('company_id', $this->company->getKey())->where('credit', '>', 0)->update(['credit' => '100.28']);
        $this->actingAs($this->maker)->getJson($this->url())->assertUnprocessable();
        $this->getJson($this->url('csv'))->assertUnprocessable();
    }

    public function test_screen_and_export_never_append_financial_rows(): void
    {
        $this->recognized($this->expense());
        $before = [];
        foreach (['journal_entries', 'accounting_source_effects', 'accounting_expense_finance_operations'] as $table) {
            $before[$table] = DB::table($table)->count();
        }
        $this->actingAs($this->maker)->get($this->url())->assertOk();
        $this->get($this->url('csv'))->assertOk()->streamedContent();
        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count());
        }
    }
}
