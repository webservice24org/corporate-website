<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Employee;
use App\Models\User;
use App\Services\ERP\Accounting\Reports\AccountingLedgerReader;
use Database\Seeders\AccountingLedgerReadPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\ERP\AccountingExpenseWorkspaceTestCase;

final class AccountingLedgerReaderTest extends AccountingExpenseWorkspaceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingLedgerReadPermissionSeeder::class);
        $this->maker->givePermissionTo([AccountingLedgerReader::LEDGER_PERMISSION, AccountingLedgerReader::TRIAL_PERMISSION]);
    }

    /** @return array<string, string> */
    private function filters(): array
    {
        return ['from' => '2026-10-01', 'to' => '2026-10-26'];
    }

    public function test_posted_expense_trial_balance_and_ledger_reconcile(): void
    {
        $this->recognized($this->expense());
        $reader = app(AccountingLedgerReader::class);
        $r = $reader->trial($this->maker, (int) $this->company->getKey(), $this->filters());
        $this->assertTrue($r['balanced']);
        $this->assertSame('100.27', $r['totals']['period_debit']);
        $book = $reader->ledger($this->maker, (int) $this->company->getKey(), (int) $this->liability->getKey(), $this->filters());
        $this->assertSame('-100.27', $book['closing']);
        $this->assertFalse($book['native_source_coverage_certified']);
        $this->assertArrayNotHasKey('description', $book['data'][0]);
    }

    public function test_approved_unposted_finance_is_not_included(): void
    {
        $this->approved($this->instruction($this->expense()));
        $r = app(AccountingLedgerReader::class)->trial($this->maker, (int) $this->company->getKey(), $this->filters());
        $this->assertSame('0.00', $r['totals']['period_debit']);
    }

    public function test_settlement_moves_payable_and_cash_without_second_expense(): void
    {
        $expense = $this->expense();
        $this->recognized($expense);
        $this->postPlan($this->approved($this->settle($expense)));
        $r = app(AccountingLedgerReader::class)->ledger($this->maker, (int) $this->company->getKey(), (int) $this->liability->getKey(), $this->filters());
        $this->assertSame('-60.14', $r['closing']);
        $this->assertSame('40.13', $r['period_debit']);
    }

    public function test_missing_source_membership_blocks_instead_of_returning_partial_totals(): void
    {
        $this->recognized($this->expense());
        DB::table('accounting_source_effects')->where('company_id', $this->company->getKey())->update(['journal_entry_id' => null]);
        $this->expectException(ModelNotFoundException::class);
        app(AccountingLedgerReader::class)->trial($this->maker, (int) $this->company->getKey(), $this->filters());
    }

    public function test_altered_posted_line_fails_hash_verification(): void
    {
        $this->recognized($this->expense());
        DB::table('journal_entry_lines')->where('company_id', $this->company->getKey())->where('credit', '>', 0)->update(['credit' => '100.28']);
        $this->expectException(ValidationException::class);
        app(AccountingLedgerReader::class)->trial($this->maker, (int) $this->company->getKey(), $this->filters());
    }

    public function test_read_does_not_append_journals_effects_or_operations(): void
    {
        $this->recognized($this->expense());
        $before = [];
        foreach (['journal_entries', 'accounting_source_effects', 'accounting_expense_finance_operations'] as $table) {
            $before[$table] = DB::table($table)->count();
        }
        app(AccountingLedgerReader::class)->trial($this->maker, (int) $this->company->getKey(), $this->filters());
        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count());
        }
    }

    public function test_native_expense_permission_does_not_grant_financial_report_permission(): void
    {
        $staff = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $staff->getKey(), 'employee_number' => 'LEDGER-STAFF', 'first_name' => 'Ledger', 'last_name' => 'Staff', 'employment_status' => 'active']);
        $staff->givePermissionTo('erp.expenses.view');
        $this->expectException(AuthorizationException::class);
        app(AccountingLedgerReader::class)->trial($staff, (int) $this->company->getKey(), $this->filters());
    }

    public function test_foreign_account_is_not_found_in_own_company_scope(): void
    {
        $this->expectException(ModelNotFoundException::class);
        app(AccountingLedgerReader::class)->ledger($this->maker, (int) $this->company->getKey(), 999999, $this->filters());
    }
}
