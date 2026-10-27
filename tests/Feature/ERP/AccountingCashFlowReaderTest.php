<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\ERP\Accounting\Reports\AccountingCashFlowReader;
use Database\Seeders\AccountingCashFlowPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\ERP\AccountingExpenseWorkspaceTestCase;

final class AccountingCashFlowReaderTest extends AccountingExpenseWorkspaceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingCashFlowPermissionSeeder::class);
    }

    /** @param list<string> $permissions */
    private function staff(array $permissions): User
    {
        $staff = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $staff->getKey(), 'employee_number' => 'CASHFLOW-'.$staff->getKey(), 'first_name' => 'Cash', 'last_name' => 'Flow', 'employment_status' => 'active']);
        if ($permissions !== []) {
            $staff->givePermissionTo($permissions);
        }

        return $staff;
    }

    /** @return array<string, mixed> */
    private function report(?User $actor = null): array
    {
        return app(AccountingCashFlowReader::class)->report($actor ?? $this->maker, (int) $this->company->getKey(), ['from' => '2026-10-26', 'to' => '2026-10-26']);
    }

    public function test_cashflow_only_staff_can_read_accrual_bridge_without_other_report_permission(): void
    {
        $this->recognized($this->expense());
        $r = $this->report($this->staff(['erp.cash-flow.view']));
        $this->assertTrue($r['complete']);
        $this->assertSame('0.00', $r['confirmed_flows']['operating']);
        $this->assertSame('-100.27', $r['indirect_bridge']['period_result']);
        $this->assertFalse($r['native_source_coverage_certified']);
    }

    public function test_native_expense_permission_alone_cannot_read_cashflow(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->report($this->staff(['erp.expenses.view']));
    }

    public function test_foreign_company_is_forbidden_for_staff(): void
    {
        $foreign = Company::query()->create(['code' => 'CF-OTHER', 'name' => 'Foreign', 'status' => 'active', 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka']);
        $this->expectException(AuthorizationException::class);
        app(AccountingCashFlowReader::class)->report($this->staff(['erp.cash-flow.view']), (int) $foreign->getKey(), ['from' => '2026-10-26', 'to' => '2026-10-26']);
    }

    public function test_partial_settlement_is_cash_outflow_without_second_expense(): void
    {
        $expense = $this->expense();
        $this->recognized($expense);
        $this->postPlan($this->approved($this->settle($expense)));
        $r = $this->report();
        $this->assertSame('-40.13', $r['confirmed_flows']['operating']);
        $this->assertSame('-100.27', $r['indirect_bridge']['period_result']);
        $this->assertSame('0.00', $r['reconciliation_difference']);
    }

    public function test_paid_now_expense_is_operating_cash_outflow(): void
    {
        $this->recognized($this->expense(['settlement_mode' => 'paid_now', 'cash_account_id' => $this->cash->getKey()]));
        $this->assertSame('-100.27', $this->report()['confirmed_flows']['operating']);
    }

    public function test_unmapped_tax_account_produces_readiness_issue_not_a_fabricated_classification(): void
    {
        $this->recognized($this->expense(['tax_treatment' => 'recoverable', 'tax_amount' => '5.00', 'tax_account_id' => $this->mapping['input_tax']]));
        $r = $this->report();
        $this->assertNull($r['confirmed_flows']);
        $this->assertContains('active_account_cash_flow_role_missing_or_invalid', array_column($r['issues'], 'issue'));
    }

    public function test_inactive_original_binding_does_not_erase_historical_cash(): void
    {
        $this->recognized($this->expense(['settlement_mode' => 'paid_now', 'cash_account_id' => $this->cash->getKey()]));
        DB::table('accounting_cash_accounts')->where('id', $this->cash->getKey())->update(['status' => 'inactive']);
        $this->assertSame('-100.27', $this->report()['confirmed_flows']['operating']);
    }

    public function test_damaged_journal_is_rejected_before_projection(): void
    {
        $this->recognized($this->expense());
        DB::table('journal_entry_lines')->where('company_id', $this->company->getKey())->where('credit', '>', 0)->update(['credit' => '100.28']);
        $this->expectException(ValidationException::class);
        $this->report();
    }

    public function test_read_does_not_write_or_expose_private_source_text(): void
    {
        $this->recognized($this->expense());
        $before = DB::table('journal_entries')->count();
        $r = $this->report();
        $this->assertSame($before, DB::table('journal_entries')->count());
        $this->assertStringNotContainsString('PRIVATE-DESCRIPTION', json_encode($r, JSON_THROW_ON_ERROR));
        $this->assertSame('indirect', $r['method']);
    }
}
