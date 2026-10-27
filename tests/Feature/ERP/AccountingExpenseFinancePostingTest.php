<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingExpense;
use App\Models\Accounting\AccountingExpenseFinanceOperation;
use App\Models\Accounting\AccountingExpenseFinancePlan;
use App\Models\Accounting\JournalEntryLine;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingAccountService;
use App\Services\ERP\Accounting\AccountingCalendarService;
use App\Services\ERP\Accounting\AccountingCashAccountService;
use App\Services\ERP\Accounting\AccountingMappingCatalog;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\Books\AccountingCashBookSourceEvidence;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseFinanceEvidence;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseFinanceService;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseWorkflow;
use App\Services\ERP\Accounting\Source\AccountingSourceReconciliation;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingExpenseAttachmentPermissionSeeder;
use Database\Seeders\AccountingExpenseFinancePermissionSeeder;
use Database\Seeders\AccountingExpensePermissionSeeder;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AccountingExpenseFinancePostingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $maker;

    private User $reviewer;

    private AccountingCashAccount $cash;

    private AccountingCashAccount $bank;

    private Account $liability;

    private int $category;

    /** @var array<string, int> */
    private array $mapping;

    private AccountingExpenseWorkflow $native;

    private AccountingExpenseFinanceService $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        foreach ([AccountingPermissionSeeder::class, AccountingExpensePermissionSeeder::class, AccountingExpenseAttachmentPermissionSeeder::class, AccountingExpenseFinancePermissionSeeder::class] as $seeder) {
            $this->seed($seeder);
        }

        $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00:00', 'Asia/Dhaka'));

        $this->company = Company::query()->create(['code' => 'EXP-F', 'name' => 'Expense finance', 'status' => 'active', 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka']);

        $this->maker = User::factory()->create();
        $this->maker->assignRole('super-admin');
        $this->reviewer = User::factory()->create();
        $this->reviewer->assignRole('super-admin');

        app(AccountingSetupService::class)->initialize($this->maker, (int) $this->company->getKey());
        app(AccountingAccountService::class)->initializeGroups($this->maker, (int) $this->company->getKey());

        $catalog = app(AccountingMappingCatalog::class);
        $this->mapping = [];
        $code = 1000;

        foreach ([...$catalog->roles(), 'cash:MAIN' => 'asset', 'bank:CURRENT' => 'asset'] as $role => $category) {
            $group = AccountGroup::query()->where('company_id', $this->company->getKey())->where('category', $category)->firstOrFail();

            $gl = Account::query()->create(['company_id' => $this->company->getKey(), 'account_group_id' => $group->getKey(), 'code' => (string) $code++, 'name' => $role, 'normal_side' => $role === 'sales_allowances' ? 'debit' : $group->getAttribute('normal_side'), 'is_postable' => true, 'control_role' => $catalog->control($role), 'statement_role' => $catalog->statements($role)[0], 'cash_flow_role' => $catalog->cashRole($role) ? 'cash' : ($role === 'general_expense' ? 'operating' : null), 'status' => 'active', 'revision' => 1]);
            $this->mapping[$role] = (int) $gl->getKey();
        }

        $group = AccountGroup::query()->where('company_id', $this->company->getKey())->where('category', 'liability')->firstOrFail();

        $this->liability = Account::query()->create(['company_id' => $this->company->getKey(), 'account_group_id' => $group->getKey(), 'code' => '9000', 'name' => 'Standalone expense payables', 'normal_side' => 'credit', 'is_postable' => true, 'control_role' => null, 'statement_role' => 'current_liability', 'cash_flow_role' => 'operating', 'status' => 'active', 'revision' => 1]);

        app(AccountingMappingService::class)->publish($this->maker, ['company_id' => (int) $this->company->getKey(), 'base_version_id' => null, 'effective_from' => '2026-10-01', 'mappings' => $this->mapping, 'reason' => 'Expense finance test profile']);

        app(AccountingCashAccountService::class)->initialize($this->maker, (int) $this->company->getKey(), 'Expense cash fixture');

        $this->cash = AccountingCashAccount::query()->where('company_id', $this->company->getKey())->where('role', 'cash:MAIN')->firstOrFail();
        $this->bank = AccountingCashAccount::query()->where('company_id', $this->company->getKey())->where('role', 'bank:CURRENT')->firstOrFail();

        $calendar = app(AccountingCalendarService::class);
        $year = $calendar->saveYear($this->maker, ['company_id' => (int) $this->company->getKey(), 'code' => 'FY26', 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'reason' => 'Expense test calendar']);

        $calendar->savePeriod($this->maker, (int) $year->getKey(), ['company_id' => (int) $this->company->getKey(), 'code' => 'OCT', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'reason' => 'Expense test period']);

        // Synthetic activation fixture only; production activation remains its own reviewed workflow.

        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['state' => 'enabled', 'activation_date' => '2026-10-01', 'activated_by' => $this->maker->getKey(), 'activated_at' => now()]);

        AccountingCutover::query()->create(['company_id' => $this->company->getKey(), 'cutover_date' => '2026-10-01', 'strategy' => 'snapshot', 'status' => 'activated', 'created_by' => $this->maker->getKey(), 'approved_by' => $this->reviewer->getKey(), 'approved_at' => now(), 'activated_by' => $this->maker->getKey(), 'activated_at' => now(), 'manifest_hash' => str_repeat('a', 64), 'reconciliation_hash' => str_repeat('b', 64), 'revision' => 1]);

        $this->native = app(AccountingExpenseWorkflow::class);
        $this->finance = app(AccountingExpenseFinanceService::class);

        $category = $this->native->saveCategory($this->maker, ['company_id' => (int) $this->company->getKey(), 'code' => 'OFFICE', 'name' => 'Office', 'account_id' => $this->mapping['general_expense'], 'status' => 'active', 'reason' => 'Expense test category', 'key' => (string) Str::uuid()]);
        $this->category = (int) $category->getKey();
    }

    /** @param array<string, mixed> $overrides */
    private function expense(array $overrides = []): AccountingExpense
    {
        $row = $this->native->save($this->maker, ['company_id' => (int) $this->company->getKey(), 'category_id' => $this->category, 'document_date' => '2026-10-26', 'accounting_date' => '2026-10-26', 'document_reference' => 'EXP-'.Str::uuid(), 'payee_type' => 'other', 'payee_label' => 'Vendor', 'settlement_mode' => 'unpaid', 'net_amount' => '100.27', 'tax_amount' => '0.00', 'tax_treatment' => 'none', 'description' => 'PRIVATE-DESCRIPTION', 'key' => (string) Str::uuid(), ...$overrides]);

        $row = $this->native->change($this->maker, (int) $row->getKey(), 1, 'submit', 'Submit expense');

        return $this->native->change($this->reviewer, (int) $row->getKey(), 2, 'approve', 'Independent expense approval');
    }

    /** @param array<string, mixed> $overrides */
    private function instruction(AccountingExpense $expense, array $overrides = []): AccountingExpenseFinancePlan
    {
        return $this->finance->create($this->maker, ['expense_id' => (int) $expense->getKey(), 'native_revision' => (int) $expense->getAttribute('revision'), 'kind' => 'recognition', 'liability_account_id' => $expense->getAttribute('settlement_mode') === 'paid_now' ? null : (int) $this->liability->getKey(), 'key' => (string) Str::uuid(), 'reason' => 'Prepare finance instruction', ...$overrides]);
    }

    private function approved(AccountingExpenseFinancePlan $plan): AccountingExpenseFinancePlan
    {
        return $this->finance->change($this->reviewer, (int) $plan->getKey(), (int) $plan->getAttribute('revision'), 'approve', 'Independent finance approval');
    }

    private function postPlan(AccountingExpenseFinancePlan $plan, ?string $key = null, ?User $actor = null): AccountingExpenseFinancePlan
    {
        return $this->finance->change($actor ?? $this->maker, (int) $plan->getKey(), (int) $plan->getAttribute('revision'), 'post', 'Post finance', $key ?? (string) Str::uuid());
    }

    private function recognized(AccountingExpense $expense): AccountingExpenseFinancePlan
    {
        return $this->postPlan($this->approved($this->instruction($expense)));
    }

    private function settle(AccountingExpense $expense, string $amount = '40.13', ?AccountingCashAccount $cash = null): AccountingExpenseFinancePlan
    {
        return $this->finance->create($this->maker, ['expense_id' => (int) $expense->getKey(), 'native_revision' => (int) $expense->refresh()->getAttribute('revision'), 'kind' => 'settlement', 'date' => '2026-10-26', 'amount' => $amount, 'cash_account_id' => (int) ($cash ?? $this->cash)->getKey(), 'payment_reference' => 'PAY-'.Str::uuid(), 'key' => (string) Str::uuid(), 'reason' => 'Pay recognized expense']);
    }

    private function invalid(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected validation rejection.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    private function side(int $plan, int $account, string $field): string
    {
        $total = '0.00';
        foreach (JournalEntryLine::query()->where('source_line_type', 'expense_finance')->where('source_line_id', $plan)->where('account_id', $account)->get() as $line) {
            $total = bcadd($total, (string) $line->getAttribute($field), 2);
        }

        return $total;
    }

    public function test_unpaid_recognition_debits_expense_and_credits_dedicated_payable(): void
    {
        $expense = $this->expense();
        $plan = $this->recognized($expense);
        $this->assertSame('100.27', $this->side((int) $plan->getKey(), $this->mapping['general_expense'], 'debit'));
        $this->assertSame('100.27', $this->side((int) $plan->getKey(), (int) $this->liability->getKey(), 'credit'));
        $this->assertSame('posted', $expense->refresh()->getAttribute('status'));
        $this->assertDatabaseCount('supplier_bills', 0);
        $this->assertDatabaseCount('supplier_payables', 0);
    }

    public function test_paid_now_recognition_credits_reviewed_concrete_cash_binding(): void
    {
        $expense = $this->expense(['settlement_mode' => 'paid_now', 'cash_account_id' => $this->cash->getKey()]);
        $plan = $this->recognized($expense);
        $this->assertSame('100.27', $this->side((int) $plan->getKey(), (int) $this->cash->getAttribute('account_id'), 'credit'));
        $this->assertSame('0.00', $this->finance->payable($this->maker, (int) $expense->getKey())['outstanding']);
    }

    public function test_partial_and_final_settlement_never_recognize_second_expense(): void
    {
        $expense = $this->expense();
        $this->recognized($expense);
        $a = $this->postPlan($this->approved($this->settle($expense)));
        $b = $this->postPlan($this->approved($this->settle($expense, '60.14')));

        $this->assertSame('40.13', $this->side((int) $a->getKey(), (int) $this->liability->getKey(), 'debit'));
        $this->assertSame('0.00', $this->side((int) $a->getKey(), $this->mapping['general_expense'], 'debit'));
        $this->assertSame('0.00', $this->side((int) $b->getKey(), $this->mapping['general_expense'], 'debit'));
        $position = $this->finance->payable($this->maker, (int) $expense->getKey());
        $this->assertSame('100.27', $position['settled']);
        $this->assertSame('0.00', $position['outstanding']);
    }

    public function test_recoverable_tax_is_separate_and_matches_published_input_tax(): void
    {
        $expense = $this->expense(['tax_amount' => '15.03', 'tax_treatment' => 'recoverable', 'tax_account_id' => $this->mapping['input_tax']]);
        $plan = $this->recognized($expense);
        $this->assertSame('15.03', $this->side((int) $plan->getKey(), $this->mapping['input_tax'], 'debit'));
        $this->assertSame('115.30', $this->side((int) $plan->getKey(), (int) $this->liability->getKey(), 'credit'));
    }

    public function test_included_tax_debits_gross_expense_without_input_tax(): void
    {
        $plan = $this->recognized($this->expense(['tax_amount' => '5.10', 'tax_treatment' => 'included_in_cost']));
        $this->assertSame('105.37', $this->side((int) $plan->getKey(), $this->mapping['general_expense'], 'debit'));
        $this->assertSame('0.00', $this->side((int) $plan->getKey(), $this->mapping['input_tax'], 'debit'));
    }

    public function test_employee_reimbursement_uses_real_employee_and_separate_payable(): void
    {
        $employee = Employee::query()->create(['company_id' => $this->company->getKey(), 'employee_number' => 'EXP-E', 'first_name' => 'Employee', 'last_name' => 'Claimant', 'employment_status' => 'active', 'national_id' => 'PRIVATE-NID']);
        $expense = $this->expense(['payee_type' => 'employee', 'employee_id' => $employee->getKey(), 'payee_label' => '', 'settlement_mode' => 'employee_reimbursement']);
        $this->recognized($expense);
        $plan = $this->postPlan($this->approved($this->settle($expense, '100.27')));
        $this->assertSame((int) $employee->getKey(), $plan->getAttribute('snapshot')['payee_id']);
        $this->assertSame('0.00', $this->side((int) $plan->getKey(), $this->mapping['payroll_payable'], 'debit'));
        $this->assertDatabaseCount('suppliers', 0);
    }

    public function test_supplier_expense_is_explicit_cohort_without_fake_purchase_bill(): void
    {
        $supplier = Supplier::query()->create(['company_id' => $this->company->getKey(), 'code' => 'S-EXP', 'name' => 'Supplier', 'status' => 'active', 'currency_code' => 'BDT']);
        $expense = $this->expense(['payee_type' => 'supplier', 'supplier_id' => $supplier->getKey(), 'payee_label' => '']);
        $plan = $this->recognized($expense);
        $this->assertSame((int) $supplier->getKey(), $plan->getAttribute('snapshot')['payee_id']);
        $this->assertDatabaseCount('supplier_bills', 0);
        $this->assertSame('0.00', $this->side((int) $plan->getKey(), $this->mapping['trade_ap'], 'credit'));
    }

    public function test_payroll_or_trade_ap_account_cannot_be_repurposed_as_expense_payable(): void
    {
        $expense = $this->expense();
        foreach (['trade_ap', 'payroll_payable'] as $role) {
            $this->invalid(fn () => $this->instruction($expense, ['liability_account_id' => $this->mapping[$role]]));
        }
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_instruction_maker_cannot_approve_even_as_super_admin(): void
    {
        $plan = $this->instruction($this->expense());
        $this->expectException(AuthorizationException::class);
        $this->finance->change($this->maker, (int) $plan->getKey(), 1, 'approve', 'Own instruction');
    }

    public function test_post_requires_approved_finance_instruction(): void
    {
        $plan = $this->instruction($this->expense());
        $this->invalid(fn () => $this->postPlan($plan));
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_duplicate_recognition_instruction_is_rejected(): void
    {
        $expense = $this->expense();
        $this->instruction($expense);
        $this->invalid(fn () => $this->instruction($expense));
        $this->assertDatabaseCount('accounting_expense_finance_plans', 1);
    }

    public function test_post_replay_reuses_complete_manifest_even_after_closed_period_and_disabled_settings(): void
    {
        $expense = $this->expense();
        $plan = $this->approved($this->instruction($expense));
        $key = (string) Str::uuid();
        $this->postPlan($plan, $key);
        DB::table('accounting_periods')->where('company_id', $this->company->getKey())->update(['state' => 'closed']);
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['state' => 'disabled']);
        $this->cash->update(['status' => 'inactive']);

        $again = $this->finance->change($this->maker, (int) $plan->getKey(), 2, 'post', 'Post finance', $key);
        $this->assertSame('posted', $again->getAttribute('status'));
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('accounting_expense_finance_operations', 1);
    }

    public function test_missing_operation_manifest_is_not_recreated_on_retry(): void
    {
        $plan = $this->approved($this->instruction($this->expense()));
        $key = (string) Str::uuid();
        $this->postPlan($plan, $key);
        DB::table('accounting_expense_finance_operations')->where('plan_id', $plan->getKey())->delete();

        try {
            $this->finance->change($this->maker, (int) $plan->getKey(), 2, 'post', 'Retry', $key);
            $this->fail('Expected missing manifest rejection.');
        } catch (ModelNotFoundException|ValidationException) {
            $this->assertDatabaseCount('accounting_expense_finance_operations', 0);
        }
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_closed_period_rolls_back_native_finance_post_and_journal(): void
    {
        $expense = $this->expense();
        $plan = $this->approved($this->instruction($expense));
        DB::table('accounting_periods')->where('company_id', $this->company->getKey())->update(['state' => 'closed']);
        $this->invalid(fn () => $this->postPlan($plan));
        $this->assertSame('approved', $plan->refresh()->getAttribute('status'));
        $this->assertSame('approved', $expense->refresh()->getAttribute('status'));
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_disabled_accounting_does_not_mark_native_expense_posted(): void
    {
        $expense = $this->expense();
        $plan = $this->approved($this->instruction($expense));
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['state' => 'disabled']);
        $this->invalid(fn () => $this->postPlan($plan));
        $this->assertSame('approved', $expense->refresh()->getAttribute('status'));
        $this->assertSame('approved', $plan->refresh()->getAttribute('status'));
        $this->assertDatabaseCount('accounting_expense_finance_operations', 0);
    }

    public function test_overpayment_blocked_at_prepare_and_again_when_competing_approval_posts(): void
    {
        $expense = $this->expense();
        $this->recognized($expense);
        $this->invalid(fn () => $this->settle($expense, '100.28'));
        $a = $this->approved($this->settle($expense, '70.00'));
        $b = $this->approved($this->settle($expense, '70.00'));
        $this->postPlan($a);
        $this->invalid(fn () => $this->postPlan($b));
        $this->assertSame('30.27', $this->finance->payable($this->maker, (int) $expense->getKey())['outstanding']);
    }

    public function test_cash_binding_inactivation_blocks_new_settlement_post(): void
    {
        $expense = $this->expense();
        $this->recognized($expense);
        $plan = $this->approved($this->settle($expense));
        $this->cash->update(['status' => 'inactive']);
        $this->invalid(fn () => $this->postPlan($plan));
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_paid_now_has_no_second_payable_to_settle(): void
    {
        $expense = $this->expense(['settlement_mode' => 'paid_now', 'cash_account_id' => $this->cash->getKey()]);
        $this->recognized($expense);
        $this->invalid(fn () => $this->settle($expense));
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_posted_native_expense_cannot_be_casually_cancelled(): void
    {
        $expense = $this->expense();
        $this->recognized($expense);
        $this->invalid(fn () => $this->native->change($this->maker, (int) $expense->getKey(), (int) $expense->refresh()->getAttribute('revision'), 'cancel', 'Not a reversal'));
        $this->assertSame('posted', $expense->refresh()->getAttribute('status'));
    }

    public function test_financial_manifest_failure_rolls_back_journal_claim_and_native_status(): void
    {
        $expense = $this->expense();
        $plan = $this->approved($this->instruction($expense));
        $active = true;
        AccountingExpenseFinanceOperation::creating(static function () use (&$active): void {
            if ($active) {
                throw new \RuntimeException('Injected manifest failure');
            }
        });

        try {
            $this->postPlan($plan);
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected manifest failure', $e->getMessage());
        } finally {
            $active = false;
        }
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('accounting_expense_finance_operations', 0);
        $this->assertSame('approved', $expense->refresh()->getAttribute('status'));
        $this->assertSame('approved', $plan->refresh()->getAttribute('status'));
    }

    public function test_finance_evidence_does_not_copy_private_description_or_document_reference(): void
    {
        $expense = $this->expense(['document_reference' => 'PRIVATE-RECEIPT']);
        $plan = $this->recognized($expense);
        $effect = app(AccountingExpenseFinanceEvidence::class)->verify((int) $this->company->getKey(), (int) $plan->getKey())['effect'];
        $json = json_encode($effect->getAttribute('source_snapshot'), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('PRIVATE-DESCRIPTION', $json);
        $this->assertStringNotContainsString('PRIVATE-RECEIPT', $json);
    }

    public function test_float_and_subcent_settlement_are_rejected(): void
    {
        $expense = $this->expense();
        $this->recognized($expense);
        $this->invalid(fn () => $this->settle($expense, '1.001'));
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_native_supplier_bill_conflict_blocks_finance_preparation(): void
    {
        $supplier = Supplier::query()->create(['company_id' => $this->company->getKey(), 'code' => 'BILL-S', 'name' => 'Bill supplier', 'currency_code' => 'BDT', 'status' => 'active']);
        $expense = $this->expense(['payee_type' => 'supplier', 'supplier_id' => $supplier->getKey(), 'payee_label' => '', 'document_reference' => 'INV-1']);

        SupplierBill::query()->create(['company_id' => $this->company->getKey(), 'supplier_id' => $supplier->getKey(), 'number' => 'BILL-1', 'supplier_invoice_number' => 'inv-1', 'status' => 'draft', 'invoice_date' => '2026-10-26', 'currency_code' => 'BDT', 'created_by' => $this->maker->getKey()]);
        $this->invalid(fn () => $this->instruction($expense));
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_guest_and_staff_without_finance_permission_are_blocked(): void
    {
        $this->get('/erp/accounting/expense-finance/1')->assertRedirect('/login');
        $staff = User::factory()->create();
        $staff->givePermissionTo('erp.expenses.view');
        $this->actingAs($staff)->getJson('/erp/accounting/expense-finance/1')->assertForbidden();
    }

    public function test_http_prepare_approve_post_and_payable_detail(): void
    {
        $expense = $this->expense();
        $response = $this->actingAs($this->maker)->postJson('/erp/accounting/expense-finance', ['expense_id' => $expense->getKey(), 'native_revision' => 3, 'kind' => 'recognition', 'liability_account_id' => $this->liability->getKey(), 'key' => (string) Str::uuid(), 'reason' => 'Recognize expense'])->assertOk();
        $id = $response->json('data.id');

        $this->actingAs($this->reviewer)->postJson('/erp/accounting/expense-finance/'.$id.'/approve', ['revision' => 1, 'reason' => 'Independent approval'])->assertOk();
        $this->actingAs($this->maker)->postJson('/erp/accounting/expense-finance/'.$id.'/post', ['revision' => 2, 'reason' => 'Post approved expense', 'key' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.status', 'posted');
        $this->getJson('/erp/accounting/expenses/'.$expense->getKey().'/payable')->assertOk()->assertJsonPath('outstanding', '100.27');
    }

    public function test_bank_settlement_has_one_concrete_bank_leg(): void
    {
        $expense = $this->expense();
        $this->recognized($expense);
        $plan = $this->postPlan($this->approved($this->settle($expense, '40.13', $this->bank)));
        $this->assertSame(1, JournalEntryLine::query()->where('source_line_type', 'expense_finance')->where('source_line_id', $plan->getKey())->where('cash_account_id', $this->bank->getKey())->count());
    }

    public function test_cancelled_instruction_can_be_replaced_without_changing_expense(): void
    {
        $expense = $this->expense();
        $plan = $this->instruction($expense);
        $this->finance->change($this->maker, (int) $plan->getKey(), 1, 'cancel', 'Prepare a corrected instruction');
        $next = $this->instruction($expense);
        $this->assertNotSame($plan->getKey(), $next->getKey());
        $this->assertSame('approved', $expense->refresh()->getAttribute('status'));
    }

    public function test_paid_now_source_is_verified_by_existing_cashbook_adapter(): void
    {
        $expense = $this->expense(['settlement_mode' => 'paid_now', 'cash_account_id' => $this->cash->getKey()]);
        $plan = $this->recognized($expense);
        $p = app(AccountingExpenseFinanceEvidence::class)->verify((int) $this->company->getKey(), (int) $plan->getKey());

        $proof = app(AccountingCashBookSourceEvidence::class)->verify($this->maker, $this->cash, $p['journal']);
        $this->assertSame('100.27', $proof['credit']);
        $this->assertSame('expense_finance', $proof['source_kind']);
    }

    public function test_expense_finance_appears_as_verified_in_source_reconciliation(): void
    {
        $this->recognized($this->expense());
        $page = app(AccountingSourceReconciliation::class)->page($this->maker, (int) $this->company->getKey(), ['kind' => 'expense_finance']);
        $this->assertSame('verified_live', $page['rows'][0]['status']);
    }
}
