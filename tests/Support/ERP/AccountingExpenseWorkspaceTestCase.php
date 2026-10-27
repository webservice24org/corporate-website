<?php

declare(strict_types=1);

namespace Tests\Support\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingExpense;
use App\Models\Accounting\AccountingExpenseFinancePlan;
use App\Models\Accounting\JournalEntryLine;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingAccountService;
use App\Services\ERP\Accounting\AccountingCalendarService;
use App\Services\ERP\Accounting\AccountingCashAccountService;
use App\Services\ERP\Accounting\AccountingMappingCatalog;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseFinanceService;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseWorkflow;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingExpenseAttachmentPermissionSeeder;
use Database\Seeders\AccountingExpenseFinancePermissionSeeder;
use Database\Seeders\AccountingExpensePermissionSeeder;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

abstract class AccountingExpenseWorkspaceTestCase extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $maker;

    protected User $reviewer;

    protected AccountingCashAccount $cash;

    protected AccountingCashAccount $bank;

    protected Account $liability;

    protected int $category;

    /** @var array<string, int> */
    protected array $mapping;

    protected AccountingExpenseWorkflow $native;

    protected AccountingExpenseFinanceService $finance;

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
    protected function expense(array $overrides = []): AccountingExpense
    {
        $row = $this->native->save($this->maker, ['company_id' => (int) $this->company->getKey(), 'category_id' => $this->category, 'document_date' => '2026-10-26', 'accounting_date' => '2026-10-26', 'document_reference' => 'EXP-'.Str::uuid(), 'payee_type' => 'other', 'payee_label' => 'Vendor', 'settlement_mode' => 'unpaid', 'net_amount' => '100.27', 'tax_amount' => '0.00', 'tax_treatment' => 'none', 'description' => 'PRIVATE-DESCRIPTION', 'key' => (string) Str::uuid(), ...$overrides]);

        $row = $this->native->change($this->maker, (int) $row->getKey(), 1, 'submit', 'Submit expense');

        return $this->native->change($this->reviewer, (int) $row->getKey(), 2, 'approve', 'Independent expense approval');
    }

    /** @param array<string, mixed> $overrides */
    protected function instruction(AccountingExpense $expense, array $overrides = []): AccountingExpenseFinancePlan
    {
        return $this->finance->create($this->maker, ['expense_id' => (int) $expense->getKey(), 'native_revision' => (int) $expense->getAttribute('revision'), 'kind' => 'recognition', 'liability_account_id' => $expense->getAttribute('settlement_mode') === 'paid_now' ? null : (int) $this->liability->getKey(), 'key' => (string) Str::uuid(), 'reason' => 'Prepare finance instruction', ...$overrides]);
    }

    protected function approved(AccountingExpenseFinancePlan $plan): AccountingExpenseFinancePlan
    {
        return $this->finance->change($this->reviewer, (int) $plan->getKey(), (int) $plan->getAttribute('revision'), 'approve', 'Independent finance approval');
    }

    protected function postPlan(AccountingExpenseFinancePlan $plan, ?string $key = null, ?User $actor = null): AccountingExpenseFinancePlan
    {
        return $this->finance->change($actor ?? $this->maker, (int) $plan->getKey(), (int) $plan->getAttribute('revision'), 'post', 'Post finance', $key ?? (string) Str::uuid());
    }

    protected function recognized(AccountingExpense $expense): AccountingExpenseFinancePlan
    {
        return $this->postPlan($this->approved($this->instruction($expense)));
    }

    protected function settle(AccountingExpense $expense, string $amount = '40.13', ?AccountingCashAccount $cash = null): AccountingExpenseFinancePlan
    {
        return $this->finance->create($this->maker, ['expense_id' => (int) $expense->getKey(), 'native_revision' => (int) $expense->refresh()->getAttribute('revision'), 'kind' => 'settlement', 'date' => '2026-10-26', 'amount' => $amount, 'cash_account_id' => (int) ($cash ?? $this->cash)->getKey(), 'payment_reference' => 'PAY-'.Str::uuid(), 'key' => (string) Str::uuid(), 'reason' => 'Pay recognized expense']);
    }

    protected function invalid(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected validation rejection.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    protected function side(int $plan, int $account, string $field): string
    {
        $total = '0.00';
        foreach (JournalEntryLine::query()->where('source_line_type', 'expense_finance')->where('source_line_id', $plan)->where('account_id', $account)->get() as $line) {
            $total = bcadd($total, (string) $line->getAttribute($field), 2);
        }

        return $total;
    }
}
