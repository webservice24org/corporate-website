<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Employee;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\Dashboard\AccountingFinanceDashboard;
use App\Services\ERP\Accounting\Reconciliation\AccountingBankReconciliationService;
use App\Services\ERP\Accounting\Statements\AccountingBankStatementContract;
use App\Services\ERP\Accounting\Statements\AccountingBankStatementService;
use Database\Seeders\AccountingBankReconciliationPermissionSeeder;
use Database\Seeders\AccountingBankStatementPermissionSeeder;
use Database\Seeders\AccountingCashBookPermissionSeeder;
use Database\Seeders\AccountingFinanceDashboardPermissionSeeder;
use Illuminate\Support\Str;
use Tests\Support\ERP\AccountingExpenseWorkspaceTestCase;

final class AccountingFinanceDashboardReviewTest extends AccountingExpenseWorkspaceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach ([AccountingFinanceDashboardPermissionSeeder::class, AccountingBankStatementPermissionSeeder::class, AccountingBankReconciliationPermissionSeeder::class, AccountingCashBookPermissionSeeder::class] as $seeder) {
            $this->seed($seeder);
        }
    }

    /** @param list<string> $permissions */
    private function staff(array $permissions): User
    {
        $u = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $u->getKey(), 'employee_number' => 'REV-'.$u->getKey(), 'first_name' => 'Review', 'last_name' => 'Staff', 'employment_status' => 'active']);
        $u->givePermissionTo($permissions);

        return $u;
    }

    /** @return array<string, mixed> */
    private function page(User $actor, string $to = '2026-10-26'): array
    {
        return app(AccountingFinanceDashboard::class)->page($actor, ['company_id' => $this->company->getKey(), 'from' => '2026-10-01', 'to' => $to])['dashboard'];
    }

    private function reconciliation(): int
    {
        $meta = ['company_id' => (int) $this->company->getKey(), 'cash_account_id' => (int) $this->bank->getKey(), 'statement_reference' => 'DASH-'.Str::uuid(), 'currency_code' => 'BDT', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-26', 'opening_balance' => '0.00', 'closing_balance' => '100.00'];
        $rows = [['date' => '2026-10-26', 'value_date' => '', 'transaction_id' => (string) Str::uuid(), 'reference' => 'PRIVATE-BANK-REF', 'description' => 'PRIVATE-BANK-TEXT', 'money_in' => '100.00', 'money_out' => '', 'balance' => '']];
        $body = app(AccountingBankStatementContract::class)->normalize($meta, $rows);
        $statement = app(AccountingBankStatementService::class)->record($this->maker, 'manual', $meta, $rows, null, (string) Str::uuid(), app(AccountingCanonicalPayload::class)->hash($body));

        return (int) app(AccountingBankReconciliationService::class)->open($this->maker, (int) $statement->getKey(), 'Dashboard fixture', (string) Str::uuid())->getKey();
    }

    public function test_bank_difference_reproduces_matching_report_without_export_permission(): void
    {
        $id = $this->reconciliation();
        $u = $this->staff(['erp.finance-dashboard.view', 'erp.bank-reconciliations.view', 'erp.bank-statements.view', 'erp.cash-books.view']);
        $r = app(AccountingBankReconciliationService::class)->review($u, $id);
        $d = $this->page($u);
        $this->assertSame($r['bridge']['statement_minus_book'], $d['bank_rows'][0]['difference']);
        $this->assertSame('2026-10-26', $d['bank_rows'][0]['cutoff']);
        $this->assertSame('working_projection', $d['bank_rows'][0]['status']);
        $this->assertFalse($u->can('erp.bank-reconciliations.export'));
    }

    public function test_bank_statement_after_cutoff_is_not_included(): void
    {
        $this->reconciliation();
        $u = $this->staff(['erp.finance-dashboard.view', 'erp.bank-reconciliations.view', 'erp.bank-statements.view', 'erp.cash-books.view']);
        $this->assertSame([], $this->page($u, '2026-10-25')['bank_rows']);
    }

    public function test_partial_bank_permission_hides_bank_summary(): void
    {
        $this->reconciliation();
        $d = $this->page($this->staff(['erp.finance-dashboard.view', 'erp.bank-reconciliations.view']));
        $this->assertFalse($d['bank_visible']);
        $this->assertSame([], $d['bank_rows']);
    }

    public function test_source_scan_does_not_return_private_native_rows_or_claim_total_exceptions(): void
    {
        $this->recognized($this->expense());
        $d = $this->page($this->staff(['erp.finance-dashboard.view', 'erp.accounting-reports.view']));
        $scan = $d['source_scan'];
        $this->assertSame('current_scan', $scan['status']);
        $this->assertLessThanOrEqual(50, $scan['inspected']);
        $this->assertArrayNotHasKey('rows', $scan);
        $this->assertArrayNotHasKey('total_exceptions', $scan);
        $this->assertStringNotContainsString('PRIVATE-DESCRIPTION', json_encode($d, JSON_THROW_ON_ERROR));
    }
}
