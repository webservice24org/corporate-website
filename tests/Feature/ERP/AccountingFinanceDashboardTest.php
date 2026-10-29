<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\ERP\Accounting\Books\AccountingCashBookReader;
use App\Services\ERP\Accounting\Dashboard\AccountingFinanceDashboard;
use App\Services\ERP\Accounting\Reports\AccountingProfitLossWorkspace;
use Database\Seeders\AccountingCashBookPermissionSeeder;
use Database\Seeders\AccountingFinanceDashboardPermissionSeeder;
use Database\Seeders\AccountingProfitLossPermissionSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ERP\AccountingExpenseWorkspaceTestCase;

final class AccountingFinanceDashboardTest extends AccountingExpenseWorkspaceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach ([AccountingFinanceDashboardPermissionSeeder::class, AccountingProfitLossPermissionSeeder::class, AccountingCashBookPermissionSeeder::class] as $seeder) {
            $this->seed($seeder);
        }
    }

    /** @param list<string> $permissions */
    private function staff(array $permissions = ['erp.finance-dashboard.view']): User
    {
        $user = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $user->getKey(), 'employee_number' => 'FD-'.$user->getKey(), 'first_name' => 'Finance', 'last_name' => 'Staff', 'employment_status' => 'active']);
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    private function url(): string
    {
        return '/erp/accounting/dashboard?company_id='.$this->company->getKey().'&from=2026-10-01&to=2026-10-26';
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    private function page(User $user, array $filters = []): array
    {
        return app(AccountingFinanceDashboard::class)->page($user, ['company_id' => $this->company->getKey(), 'from' => '2026-10-01', 'to' => '2026-10-26', ...$filters]);
    }

    public function test_guest_redirects_to_login(): void
    {
        $this->get($this->url())->assertRedirect('/login');
    }

    public function test_source_permission_does_not_grant_dashboard_access(): void
    {
        $this->actingAs($this->staff(['erp.profit-loss.view']))->get($this->url())->assertForbidden();
    }

    public function test_dashboard_only_user_gets_no_amounts_or_report_links(): void
    {
        $this->recognized($this->expense());
        $this->actingAs($this->staff())->get($this->url())->assertOk()->assertInertia(fn (Assert $p) => $p->component('ERP/Accounting/Dashboard/Index')->has('dashboard.cards', 0)->has('dashboard.links', 0)->where('dashboard.source_scan', null)->where('dashboard.bank_visible', false));
    }

    public function test_staff_defaults_to_own_company(): void
    {
        $this->actingAs($this->staff())->get('/erp/accounting/dashboard')->assertOk()->assertInertia(fn (Assert $p) => $p->where('company.id', (int) $this->company->getKey())->has('companies', 1));
    }

    public function test_foreign_company_is_forbidden(): void
    {
        $other = Company::query()->create(['code' => 'FD-B', 'name' => 'Other', 'status' => 'active', 'currency_code' => 'USD', 'timezone' => 'UTC']);
        $this->actingAs($this->staff())->get('/erp/accounting/dashboard?company_id='.$other->getKey())->assertForbidden();
    }

    public function test_admin_without_company_gets_selector_without_metrics(): void
    {
        $this->actingAs($this->maker)->get('/erp/accounting/dashboard')->assertOk()->assertInertia(fn (Assert $p) => $p->where('company', null)->where('dashboard', null));
    }

    public function test_future_dates_rejected_even_with_no_metric_permissions(): void
    {
        $this->actingAs($this->staff())->getJson($this->url().'&to=2026-10-27')->assertUnprocessable();
    }

    public function test_inverted_dates_rejected(): void
    {
        $this->actingAs($this->staff())->getJson($this->url().'&from=2026-10-26&to=2026-10-01')->assertUnprocessable();
    }

    public function test_unsupported_party_subset_is_rejected(): void
    {
        $this->actingAs($this->staff())->getJson($this->url().'&party_id=1')->assertUnprocessable();
    }

    public function test_profit_cards_reproduce_source_report_and_do_not_grant_exports(): void
    {
        $this->recognized($this->expense());
        $user = $this->staff(['erp.finance-dashboard.view', 'erp.profit-loss.view']);
        $page = $this->page($user);
        $cards = array_column($page['dashboard']['cards'], null, 'id');
        $report = app(AccountingProfitLossWorkspace::class)->page($user, ['company_id' => $this->company->getKey(), 'from' => '2026-10-01', 'to' => '2026-10-26'])['report'];
        $this->assertSame($report['provisional_result'], $cards['profit']['value']);
        $this->assertSame('100.27', $cards['expenses']['value']);
        $this->assertSame('period', $cards['profit']['basis']);
        $this->assertCount(1, $page['dashboard']['links']);
        $this->assertStringContainsString('from=2026-10-01&to=2026-10-26', $cards['profit']['url']);
    }

    public function test_settlement_does_not_create_second_expense_in_dashboard(): void
    {
        $e = $this->expense();
        $this->recognized($e);
        $this->postPlan($this->approved($this->settle($e)));
        $p = $this->page($this->staff(['erp.finance-dashboard.view', 'erp.profit-loss.view']));
        $cards = array_column($p['dashboard']['cards'], null, 'id');
        $this->assertSame('100.27', $cards['expenses']['value']);
    }

    public function test_cash_card_uses_same_binding_and_dates_as_cashbook(): void
    {
        $e = $this->expense();
        $this->recognized($e);
        $this->postPlan($this->approved($this->settle($e)));
        $u = $this->staff(['erp.finance-dashboard.view', 'erp.cash-books.view']);
        $p = $this->page($u);
        $cards = array_column($p['dashboard']['cards'], null, 'id');
        $book = app(AccountingCashBookReader::class)->book($u, (int) $this->company->getKey(), (int) $this->cash->getKey(), ['from' => '2026-10-01', 'to' => '2026-10-26']);
        $card = $cards['cash_'.$this->cash->getKey()];
        $this->assertSame($book['posted_gl']['closing'], $card['value']);
        $this->assertStringContainsString('binding_id='.$this->cash->getKey(), $card['url']);
        $this->assertStringNotContainsString('cash_account_id=', $card['url']);
    }

    public function test_earlier_cutoff_excludes_later_expense_activity(): void
    {
        $this->recognized($this->expense());
        $p = $this->page($this->staff(['erp.finance-dashboard.view', 'erp.profit-loss.view']), ['to' => '2026-10-25']);
        $cards = array_column($p['dashboard']['cards'], null, 'id');
        $this->assertSame('0.00', $cards['expenses']['value']);
    }

    public function test_report_evidence_failure_is_unknown_not_zero(): void
    {
        $this->recognized($this->expense());
        DB::table('journal_entries')->where('company_id', $this->company->getKey())->update(['payload_hash' => str_repeat('0', 64)]);
        $p = $this->page($this->staff(['erp.finance-dashboard.view', 'erp.profit-loss.view']));
        $cards = array_column($p['dashboard']['cards'], null, 'id');
        $this->assertSame('review', $cards['profit_review']['status']);
        $this->assertNull($cards['profit_review']['value']);
    }

    public function test_read_is_private_and_does_not_write_financial_rows(): void
    {
        $this->recognized($this->expense());
        $before = DB::table('journal_entries')->count();
        $response = $this->actingAs($this->staff(['erp.finance-dashboard.view', 'erp.profit-loss.view']))->get($this->url())->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($before, DB::table('journal_entries')->count());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertDontSee('PRIVATE-DESCRIPTION');
    }

    public function test_dashboard_seeder_is_idempotent_and_does_not_grant_reports(): void
    {
        $this->seed(AccountingFinanceDashboardPermissionSeeder::class);
        $this->assertSame(1, DB::table('permissions')->where('name', AccountingFinanceDashboard::PERMISSION)->count());
        $u = $this->staff();
        $this->assertFalse($u->can('erp.profit-loss.view'));
        $this->assertFalse($u->can('erp.cash-books.export'));
    }
}
