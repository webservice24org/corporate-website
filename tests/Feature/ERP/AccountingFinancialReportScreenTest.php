<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\ERP\Accounting\Reports\AccountingFinancialReportExport;
use Database\Seeders\AccountingFinancialReportPermissionSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ERP\AccountingExpenseWorkspaceTestCase;

final class AccountingFinancialReportScreenTest extends AccountingExpenseWorkspaceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingFinancialReportPermissionSeeder::class);
    }

    /** @param list<string> $permissions */
    private function staff(array $permissions): User
    {
        $staff = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $staff->getKey(), 'employee_number' => 'REPORT-'.$staff->getKey(), 'first_name' => 'Report', 'last_name' => 'Staff', 'employment_status' => 'active']);
        if ($permissions !== []) {
            $staff->givePermissionTo($permissions);
        }

        return $staff;
    }

    private function url(string $kind, bool $export = false, string $extra = ''): string
    {
        return '/erp/accounting/'.$kind.($export ? '/export' : '').'?company_id='.$this->company->getKey().'&from=2026-10-01&to=2026-10-26'.$extra;
    }

    public function test_guests_redirect_from_both_screens_and_exports(): void
    {
        foreach (['general-ledger', 'trial-balance'] as $kind) {
            $this->get($this->url($kind))->assertRedirect('/login');
            $this->get($this->url($kind, true, '&format=csv'))->assertRedirect('/login');
        }
    }

    public function test_report_screens_require_their_specific_permission(): void
    {
        $staff = $this->staff(['erp.expenses.view']);
        foreach (['general-ledger', 'trial-balance'] as $kind) {
            $this->actingAs($staff)->get($this->url($kind))->assertForbidden();
        }
    }

    public function test_trial_screen_resolves_with_exact_balanced_totals(): void
    {
        $this->recognized($this->expense());
        $this->actingAs($this->maker)->get($this->url('trial-balance'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/FinancialReports/Index')->where('kind', 'trial')->where('report.balanced', true)->where('report.totals.period_debit', '100.27')->where('report.native_source_coverage_certified', false));
    }

    public function test_ledger_screen_resolves_with_scoped_account_and_running_balance(): void
    {
        $this->recognized($this->expense());
        $this->actingAs($this->maker)->get($this->url('general-ledger', false, '&account_id='.$this->liability->getKey()))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/FinancialReports/Index')->where('report.closing', '-100.27')->has('report.data', 1));
    }

    public function test_ledger_without_account_shows_selector_without_creating_records(): void
    {
        $before = DB::table('journal_entries')->count();
        $this->actingAs($this->maker)->get($this->url('general-ledger'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('report', null));
        $this->assertSame($before, DB::table('journal_entries')->count());
    }

    public function test_staff_company_defaults_to_own_company(): void
    {
        $this->actingAs($this->staff(['erp.trial-balance.view']))->get('/erp/accounting/trial-balance')->assertOk()->assertInertia(fn (Assert $page) => $page->where('company.id', (int) $this->company->getKey())->has('companies', 1));
    }

    public function test_foreign_company_cannot_be_requested_by_staff(): void
    {
        $foreign = Company::query()->create(['code' => 'REPORT-B', 'name' => 'Other', 'status' => 'active', 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka']);
        $this->actingAs($this->staff(['erp.trial-balance.view', 'erp.trial-balance.export']))->get('/erp/accounting/trial-balance?company_id='.$foreign->getKey())->assertForbidden();
    }

    public function test_view_only_cannot_export_csv_or_print(): void
    {
        $staff = $this->staff(['erp.general-ledger.view', 'erp.trial-balance.view']);
        foreach (['general-ledger', 'trial-balance'] as $kind) {
            foreach (['csv', 'print'] as $format) {
                $this->actingAs($staff)->get($this->url($kind, true, '&format='.$format.'&account_id='.$this->liability->getKey()))->assertForbidden();
            }
        }
    }

    public function test_export_only_does_not_grant_view_or_download(): void
    {
        $this->actingAs($this->staff(['erp.trial-balance.export']))->get($this->url('trial-balance', true, '&format=csv'))->assertForbidden();
    }

    public function test_account_search_preserves_current_selection_outside_results(): void
    {
        $this->actingAs($this->maker)->get($this->url('general-ledger', false, '&account_id='.$this->liability->getKey().'&q=NO-MATCH-XYZ'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('accounts', 1)->where('accounts.0.id', (int) $this->liability->getKey()));
    }

    public function test_unknown_account_is_not_silently_replaced(): void
    {
        $this->actingAs($this->maker)->get($this->url('general-ledger', false, '&account_id=999999'))->assertNotFound();
    }

    public function test_trial_csv_contains_all_accounts_totals_and_scope_without_private_fields(): void
    {
        $this->recognized($this->expense());
        $response = $this->actingAs($this->maker)->get($this->url('trial-balance', true, '&format=csv'))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('100.27', $csv);
        $this->assertStringContainsString('Totals', $csv);
        $this->assertStringContainsString('BDT', $csv);
        $this->assertStringNotContainsString('PRIVATE-DESCRIPTION', $csv);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_csv_neutralizes_formula_like_account_labels(): void
    {
        DB::table('accounts')->where('id', $this->liability->getKey())->update(['name' => '=1+1']);
        $csv = $this->actingAs($this->maker)->get($this->url('trial-balance', true, '&format=csv'))->assertOk()->streamedContent();
        $this->assertStringContainsString("'=1+1", $csv);
    }

    public function test_csv_safety_handles_whitespace_formula_prefixes_and_negative_balances(): void
    {
        $export = app(AccountingFinancialReportExport::class);
        foreach (['=1+1', ' +SUM(A1)', "\t@SUM(A1)", '-100.27'] as $cell) {
            $this->assertSame("'".$cell, $export->safeCell($cell));
        }
        $this->assertSame('100.27', $export->safeCell('100.27'));
    }

    public function test_print_escapes_labels_and_contains_complete_scope(): void
    {
        DB::table('companies')->where('id', $this->company->getKey())->update(['name' => '<script>alert(1)</script>']);
        $this->actingAs($this->maker)->get($this->url('trial-balance', true, '&format=print'))->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>', false)->assertSee('All filtered rows');
    }

    public function test_ledger_export_ignores_screen_page_and_contains_every_filtered_line(): void
    {
        $expense = $this->expense();
        $this->recognized($expense);
        $this->postPlan($this->approved($this->settle($expense)));
        $extra = '&account_id='.$this->liability->getKey().'&per_page=1&page=2';
        $this->actingAs($this->maker)->get($this->url('general-ledger', false, $extra))->assertOk()->assertInertia(fn (Assert $page) => $page->has('report.data', 1)->where('report.page.total', 2)->where('report.closing', '-60.14'));
        $csv = $this->get($this->url('general-ledger', true, $extra.'&format=csv'))->assertOk()->streamedContent();
        $this->assertStringContainsString('40.13', $csv);
        $this->assertStringContainsString('100.27', $csv);
        $this->assertStringContainsString('-60.14', $csv);
    }

    public function test_invalid_and_future_dates_are_rejected(): void
    {
        $this->actingAs($this->maker)->getJson('/erp/accounting/trial-balance?company_id='.$this->company->getKey().'&from=2026-10-28&to=2026-10-27')->assertUnprocessable();
        $this->getJson('/erp/accounting/trial-balance?company_id='.$this->company->getKey().'&from=2026-10-01&to=2026-10-27')->assertUnprocessable();
    }

    public function test_export_does_not_create_or_repair_financial_rows(): void
    {
        $this->recognized($this->expense());
        $before = [];
        foreach (['journal_entries', 'accounting_source_effects', 'accounting_expense_finance_operations'] as $table) {
            $before[$table] = DB::table($table)->count();
        }
        $this->actingAs($this->maker)->get($this->url('trial-balance', true, '&format=csv'))->assertOk()->streamedContent();
        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count());
        }
    }
}
