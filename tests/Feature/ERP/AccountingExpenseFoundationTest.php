<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingExpense;
use App\Models\Accounting\AccountingExpenseCategory;
use App\Models\Accounting\AccountingExpenseEvent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingAccountService;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseHistory;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseWorkflow;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingExpensePermissionSeeder;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AccountingExpenseFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $maker;

    private User $reviewer;

    private Account $gl;

    private AccountingExpenseCategory $category;

    private AccountingExpenseWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->seed(AccountingPermissionSeeder::class);
        $this->seed(AccountingExpensePermissionSeeder::class);

        $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00:00', 'Asia/Dhaka'));

        $this->company = $this->newCompany('EXP-A');

        $this->maker = User::factory()->create();
        $this->maker->assignRole('super-admin');

        $this->reviewer = User::factory()->create();
        $this->reviewer->assignRole('super-admin');

        app(AccountingSetupService::class)->initialize($this->maker, (int) $this->company->getKey());

        app(AccountingAccountService::class)->initializeGroups($this->maker, (int) $this->company->getKey());

        $this->gl = $this->account('expense', '6000');
        $this->workflow = app(AccountingExpenseWorkflow::class);

        $this->category = $this->workflow->saveCategory($this->maker, $this->categoryData());
    }

    private function newCompany(string $code): Company
    {
        return Company::query()->create(['code' => $code, 'name' => $code, 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka', 'status' => 'active']);
    }

    private function account(string $category, string $code, ?string $cashFlow = null): Account
    {
        $group = AccountGroup::query()->where('company_id', $this->company->getKey())->where('category', $category)->firstOrFail();

        return Account::query()->create(['company_id' => $this->company->getKey(), 'account_group_id' => $group->getKey(), 'code' => $code, 'name' => $code, 'normal_side' => 'debit', 'is_postable' => true, 'status' => 'active', 'revision' => 1, 'statement_role' => $category === 'expense' ? 'operating_expense' : 'current_asset', 'cash_flow_role' => $cashFlow ?? ($category === 'expense' ? 'operating' : null)]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function categoryData(array $overrides = []): array
    {
        return ['company_id' => (int) $this->company->getKey(), 'code' => 'OFFICE', 'name' => 'Office expenses', 'account_id' => (int) $this->gl->getKey(), 'status' => 'active', 'reason' => 'Reviewed category mapping', 'key' => (string) Str::uuid(), ...$overrides];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function data(array $overrides = []): array
    {
        return ['company_id' => (int) $this->company->getKey(), 'category_id' => (int) $this->category->getKey(), 'document_date' => '2026-10-26', 'accounting_date' => '2026-10-26', 'document_reference' => 'RECEIPT-1', 'payee_type' => 'other', 'payee_label' => 'Office Vendor', 'settlement_mode' => 'unpaid', 'net_amount' => '100.25', 'tax_amount' => '0.00', 'tax_treatment' => 'none', 'description' => 'Office stationery', 'key' => (string) Str::uuid(), ...$overrides];
    }

    private function move(AccountingExpense $row, string $action, ?User $actor = null): AccountingExpense
    {
        return $this->workflow->change($actor ?? $this->maker, (int) $row->getKey(), (int) $row->getAttribute('revision'), $action, 'Reviewed '.$action);
    }

    private function invalid(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected validation failure.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    /** @param list<string> $permissions */
    private function staff(array $permissions): User
    {
        $user = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $user->getKey(), 'employee_number' => 'EXP-'.$user->getKey(), 'first_name' => 'Expense', 'last_name' => 'User', 'employment_status' => 'active']);

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    private function supplier(): Supplier
    {
        return Supplier::query()->create(['company_id' => $this->company->getKey(), 'code' => 'EXP-S', 'name' => 'Supplier', 'currency_code' => 'BDT', 'status' => 'active']);
    }

    private function cash(): AccountingCashAccount
    {
        $gl = $this->account('asset', '1000', 'cash');

        return AccountingCashAccount::query()->create(['company_id' => $this->company->getKey(), 'account_id' => $gl->getKey(), 'role' => 'cash:EXPENSE', 'kind' => 'cash', 'name' => 'Expense cash', 'currency_code' => 'BDT', 'status' => 'active', 'revision' => 1]);
    }

    public function test_guest_redirect_and_view_permission(): void
    {
        $this->get('/erp/accounting/expenses')->assertRedirect('/login');

        $this->actingAs($this->staff([]))->getJson('/erp/accounting/expenses?company_id='.$this->company->getKey())->assertForbidden();
    }

    public function test_category_create_replays_without_extra_history(): void
    {
        $input = $this->categoryData(['code' => 'TRAVEL']);
        $a = $this->workflow->saveCategory($this->maker, $input);
        $b = $this->workflow->saveCategory($this->maker, $input);

        $this->assertSame($a->getKey(), $b->getKey());
        $this->assertDatabaseCount('accounting_expense_categories', 2);
        $this->assertDatabaseCount('accounting_expense_events', 2);
    }

    public function test_category_duplicate_code_rejected_before_write(): void
    {
        $this->invalid(fn () => $this->workflow->saveCategory($this->maker, $this->categoryData()));
        $this->assertDatabaseCount('accounting_expense_categories', 1);
    }

    public function test_category_requires_ordinary_active_expense_leaf(): void
    {
        $asset = $this->account('asset', '1100');
        $this->invalid(fn () => $this->workflow->saveCategory($this->maker, $this->categoryData(['code' => 'WRONG', 'account_id' => $asset->getKey()])));

        $this->gl->update(['status' => 'inactive']);
        $this->invalid(fn () => $this->workflow->saveCategory($this->maker, $this->categoryData(['code' => 'OFF'])));
    }

    public function test_expense_create_exact_money_and_no_financial_side_effects(): void
    {
        $before = DB::table('accounting_company_settings')->first();
        $row = $this->workflow->save($this->maker, $this->data());

        $this->assertSame('EX-2026-000001', $row->getAttribute('number'));
        $this->assertSame('100.25', $row->getAttribute('total_amount'));

        $this->assertSame('draft', $row->getAttribute('status'));
        $this->assertSame((array) $before, (array) DB::table('accounting_company_settings')->first());

        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('accounting_source_effects', 0);
        $this->assertDatabaseCount('supplier_payables', 0);
        $this->assertDatabaseCount('accounting_expense_events', 2);
    }

    public function test_large_cents_survive_reload_submission_and_approval(): void
    {
        $row = $this->workflow->save($this->maker, $this->data(['net_amount' => '123456789012345678.27']));

        $row = $this->move($this->move($row, 'submit'), 'approve', $this->reviewer);

        $this->assertSame('123456789012345678.27', $row->getAttribute('total_amount'));
        $this->assertSame('approved', $row->getAttribute('status'));
        app(AccountingExpenseHistory::class)->verify($row);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_same_create_key_replays_after_cancellation_without_number_or_history(): void
    {
        $input = $this->data();
        $row = $this->workflow->save($this->maker, $input);
        $row = $this->move($row, 'cancel');

        $again = $this->workflow->save($this->maker, $input);
        $this->assertSame($row->getKey(), $again->getKey());
        $this->assertSame('cancelled', $again->getAttribute('status'));
        $this->assertSame(1, (int) DB::table('accounting_expense_sequences')->value('last_number'));
        $this->assertDatabaseCount('accounting_expense_events', 3);
    }

    public function test_create_key_conflicting_body_is_rejected(): void
    {
        $input = $this->data();
        $this->workflow->save($this->maker, $input);
        $this->invalid(fn () => $this->workflow->save($this->maker, [...$input, 'net_amount' => '200.00']));
        $this->assertDatabaseCount('accounting_expenses', 1);
    }

    public function test_duplicate_payee_reference_is_normalized_and_cancel_releases_reservation(): void
    {
        $row = $this->workflow->save($this->maker, $this->data());
        $this->invalid(fn () => $this->workflow->save($this->maker, $this->data(['document_reference' => ' receipt-1 ', 'payee_label' => ' office   vendor '])));

        $this->move($row, 'cancel');
        $next = $this->workflow->save($this->maker, $this->data());
        $this->assertSame('EX-2026-000002', $next->getAttribute('number'));
        $this->assertDatabaseCount('accounting_expenses', 2);
    }

    public function test_edit_uses_current_revision_and_immutable_number(): void
    {
        $row = $this->workflow->save($this->maker, $this->data());
        $data = $this->data(['net_amount' => '75.27', 'revision' => 1]);
        unset($data['key']);

        $edited = $this->workflow->save($this->maker, $data, (int) $row->getKey());
        $this->assertSame($row->getAttribute('number'), $edited->getAttribute('number'));
        $this->assertSame('75.27', $edited->getAttribute('total_amount'));

        $this->invalid(fn () => $this->workflow->save($this->maker, $data, (int) $row->getKey()));
    }

    public function test_maker_cannot_approve_even_as_super_admin(): void
    {
        $row = $this->move($this->workflow->save($this->maker, $this->data()), 'submit');
        $this->expectException(AuthorizationException::class);
        $this->move($row, 'approve');
    }

    public function test_last_editor_and_submitter_cannot_approve(): void
    {
        $row = $this->workflow->save($this->maker, $this->data());
        $data = $this->data(['revision' => 1]);
        unset($data['key']);
        $edited = $this->workflow->save($this->reviewer, $data, (int) $row->getKey());
        $submitted = $this->move($edited, 'submit');

        try {
            $this->move($submitted, 'approve', $this->reviewer);
            $this->fail('Editor approved own intent.');
        } catch (AuthorizationException) {
            $this->assertSame('submitted', $submitted->refresh()->getAttribute('status'));
        }

        $other = User::factory()->create();
        $other->assignRole('super-admin');
        $this->assertSame('approved', $this->move($submitted, 'approve', $other)->getAttribute('status'));
    }

    public function test_rejection_clears_review_stamps_and_allows_resubmission(): void
    {
        $row = $this->move($this->workflow->save($this->maker, $this->data()), 'submit');
        $row = $this->move($row, 'reject', $this->reviewer);
        $this->assertNull($row->getAttribute('submitted_hash'));
        $this->assertSame('draft', $row->getAttribute('status'));
        $this->assertSame('approved', $this->move($this->move($row, 'submit'), 'approve', $this->reviewer)->getAttribute('status'));
    }

    public function test_approved_header_cannot_be_edited_or_posted_by_foundation(): void
    {
        $row = $this->move($this->move($this->workflow->save($this->maker, $this->data()), 'submit'), 'approve', $this->reviewer);
        $data = $this->data(['revision' => 3]);
        unset($data['key']);

        $this->invalid(fn () => $this->workflow->save($this->maker, $data, (int) $row->getKey()));
        $this->invalid(fn () => $this->workflow->change($this->maker, (int) $row->getKey(), 3, 'post', 'Not implemented'));

        $this->actingAs($this->maker)->postJson('/erp/accounting/expenses/'.$row->getKey().'/post', ['revision' => 3, 'reason' => 'No post'])->assertNotFound();
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_category_revision_change_requires_explicit_draft_refresh(): void
    {
        $row = $this->move($this->workflow->save($this->maker, $this->data()), 'submit');
        $input = $this->categoryData(['revision' => 1, 'name' => 'Reviewed office category']);
        unset($input['key']);
        $this->workflow->saveCategory($this->maker, $input, (int) $this->category->getKey());

        $this->invalid(fn () => $this->move($row, 'approve', $this->reviewer));
        $this->assertSame('submitted', $row->refresh()->getAttribute('status'));
    }

    public function test_paid_now_requires_concrete_owned_active_cash_binding(): void
    {
        $this->invalid(fn () => $this->workflow->save($this->maker, $this->data(['settlement_mode' => 'paid_now'])));
        $cash = $this->cash();

        $row = $this->workflow->save($this->maker, $this->data(['settlement_mode' => 'paid_now', 'cash_account_id' => $cash->getKey()]));
        $this->assertSame((int) $cash->getKey(), (int) $row->getAttribute('cash_account_id'));

        $cash->update(['status' => 'inactive']);
        $this->invalid(fn () => $this->move($row, 'submit'));
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_employee_reimbursement_uses_real_employee_without_private_hr_snapshot(): void
    {
        $employee = Employee::query()->create(['company_id' => $this->company->getKey(), 'employee_number' => 'CLAIM-1', 'first_name' => 'Public', 'last_name' => 'Name', 'employment_status' => 'active', 'national_id' => 'PRIVATE-NID', 'notes' => 'PRIVATE-HR']);

        $row = $this->workflow->save($this->maker, $this->data(['payee_type' => 'employee', 'payee_label' => '', 'employee_id' => $employee->getKey(), 'settlement_mode' => 'employee_reimbursement']));

        $this->assertNull($row->getAttribute('supplier_id'));
        $this->actingAs($this->maker)->getJson('/erp/accounting/expenses/'.$row->getKey())->assertOk()->assertJsonPath('data.payee_label', 'CLAIM-1 — Public Name')->assertJsonMissing(['national_id' => 'PRIVATE-NID'])->assertJsonMissing(['notes' => 'PRIVATE-HR'])->assertJsonMissingPath('data.payee_snapshot')->assertJsonMissingPath('data.creation_key_hash');

        $this->assertStringNotContainsString('PRIVATE', json_encode($row->getAttribute('payee_snapshot'), JSON_THROW_ON_ERROR));
    }

    public function test_supplier_expense_identity_and_inactive_historical_read(): void
    {
        $supplier = $this->supplier();
        $row = $this->workflow->save($this->maker, $this->data(['payee_type' => 'supplier', 'payee_label' => '', 'supplier_id' => $supplier->getKey()]));
        $supplier->update(['status' => 'inactive']);

        $this->actingAs($this->maker)->getJson('/erp/accounting/expenses/'.$row->getKey())->assertOk();
        $this->invalid(fn () => $this->move($row, 'submit'));
    }

    public function test_recoverable_tax_requires_separate_input_asset_and_exact_total(): void
    {
        $tax = $this->account('asset', '1200');
        $row = $this->workflow->save($this->maker, $this->data(['tax_treatment' => 'recoverable', 'tax_amount' => '15.03', 'tax_account_id' => $tax->getKey()]));
        $this->assertSame('115.28', $row->getAttribute('total_amount'));

        $this->invalid(fn () => $this->workflow->save($this->maker, $this->data(['document_reference' => 'TAX-2', 'tax_treatment' => 'recoverable', 'tax_amount' => '1.00'])));
    }

    public function test_included_tax_has_no_input_tax_gl_and_none_rejects_tax(): void
    {
        $row = $this->workflow->save($this->maker, $this->data(['tax_treatment' => 'included_in_cost', 'tax_amount' => '5.10']));
        $this->assertNull($row->getAttribute('tax_account_id'));
        $this->assertSame('105.35', $row->getAttribute('total_amount'));
        $this->invalid(fn () => $this->workflow->save($this->maker, $this->data(['document_reference' => 'TAX-3', 'tax_amount' => '1.00'])));
    }

    public function test_department_requires_its_active_owned_branch(): void
    {
        $branch = Branch::query()->create(['company_id' => $this->company->getKey(), 'code' => 'HQ', 'name' => 'HQ', 'status' => 'active']);
        $department = Department::query()->create(['company_id' => $this->company->getKey(), 'branch_id' => $branch->getKey(), 'code' => 'ADMIN', 'name' => 'Admin', 'status' => 'active']);

        $this->invalid(fn () => $this->workflow->save($this->maker, $this->data(['department_id' => $department->getKey()])));

        $row = $this->workflow->save($this->maker, $this->data(['branch_id' => $branch->getKey(), 'department_id' => $department->getKey()]));
        $this->assertSame((int) $department->getKey(), (int) $row->getAttribute('department_id'));
    }

    public function test_foreign_scope_and_foreign_dimension_cannot_be_used(): void
    {
        $foreign = $this->newCompany('EXP-B');
        $branch = Branch::query()->create(['company_id' => $foreign->getKey(), 'code' => 'FOREIGN', 'name' => 'Foreign', 'status' => 'active']);

        $staff = $this->staff(['erp.expenses.view', 'erp.expenses.create']);
        $this->actingAs($staff)->getJson('/erp/accounting/expenses?company_id='.$foreign->getKey())->assertForbidden();

        $this->postJson('/erp/accounting/expenses', $this->data(['branch_id' => $branch->getKey()]))->assertNotFound();
        $this->assertDatabaseCount('accounting_expenses', 0);
    }

    public function test_view_and_create_do_not_require_journal_or_hr_master_permissions(): void
    {
        $staff = $this->staff(['erp.expenses.view', 'erp.expenses.create']);
        $this->actingAs($staff)->postJson('/erp/accounting/expenses', $this->data())->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('posting_available', false);

        $this->assertFalse($staff->can('erp.journal-entries.post'));
        $this->getJson('/erp/accounting/expenses?company_id='.$this->company->getKey())->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_unexpected_source_attachment_or_posting_input_is_rejected(): void
    {
        foreach (['supplier_bill_id' => 1, 'payroll_id' => 1, 'attachment_url' => 'https://example.test/private', 'status' => 'posted', 'total_amount' => '1.00'] as $field => $value) {
            $this->invalid(fn () => $this->workflow->save($this->maker, $this->data([$field => $value])));
        }
        $this->assertDatabaseCount('accounting_expenses', 0);
    }

    public function test_future_date_or_accounting_before_document_rejected(): void
    {
        $this->invalid(fn () => $this->workflow->save($this->maker, $this->data(['document_date' => '2026-10-27', 'accounting_date' => '2026-10-27'])));
        $this->invalid(fn () => $this->workflow->save($this->maker, $this->data(['accounting_date' => '2026-10-25'])));
    }

    public function test_audit_failure_rolls_back_document_claim_and_sequence(): void
    {
        $active = true;

        AccountingExpenseEvent::creating(static function (AccountingExpenseEvent $row) use (&$active): void {
            if ($active && $row->getAttribute('expense_id') !== null) {
                throw new \RuntimeException('Injected native audit failure');
            }
        });

        try {
            $this->workflow->save($this->maker, $this->data());
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected native audit failure', $e->getMessage());
        } finally {
            $active = false;
        }

        $this->assertDatabaseCount('accounting_expenses', 0);
        $this->assertDatabaseCount('accounting_expense_sequences', 0);
        $this->assertDatabaseCount('accounting_expense_events', 1);
    }

    public function test_tampered_header_fails_history_read_without_repair(): void
    {
        $row = $this->workflow->save($this->maker, $this->data());
        DB::table('accounting_expenses')->where('id', $row->getKey())->update(['description' => 'Changed outside reviewed workflow']);

        $this->actingAs($this->maker)->getJson('/erp/accounting/expenses/'.$row->getKey())->assertUnprocessable()->assertJsonValidationErrors('expense_integrity');
        $this->assertDatabaseCount('accounting_expense_events', 2);
    }

    public function test_missing_history_is_not_recreated_on_create_retry(): void
    {
        $input = $this->data();
        $row = $this->workflow->save($this->maker, $input);
        DB::table('accounting_expenses')->where('id', $row->getKey())->update(['history_head_id' => null]);
        DB::table('accounting_expense_events')->where('expense_id', $row->getKey())->delete();

        $this->invalid(fn () => $this->workflow->save($this->maker, $input));
        $this->assertDatabaseCount('accounting_expense_events', 1);
        $this->assertDatabaseCount('accounting_expenses', 1);
    }

    public function test_json_object_key_reordering_preserves_canonical_history(): void
    {
        $row = $this->move($this->workflow->save($this->maker, $this->data()), 'submit');

        foreach (AccountingExpenseEvent::query()->where('expense_id', $row->getKey())->get() as $event) {
            $payload = $event->getAttribute('payload');
            $payload['after'] = array_reverse($payload['after'], true);
            if (is_array($payload['before'])) {
                $payload['before'] = array_reverse($payload['before'], true);
            }

            DB::table('accounting_expense_events')->where('id', $event->getKey())->update(['payload' => json_encode(array_reverse($payload, true), JSON_THROW_ON_ERROR)]);
        }

        $this->actingAs($this->maker)->getJson('/erp/accounting/expenses/'.$row->getKey())->assertOk()->assertJsonPath('data.status', 'submitted');
    }

    public function test_native_review_survives_cutover_configuration_lock_without_changing_it(): void
    {
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['cutover_locked_at' => now()]);

        $row = $this->move($this->move($this->workflow->save($this->maker, $this->data()), 'submit'), 'approve', $this->reviewer);
        $this->assertSame('approved', $row->getAttribute('status'));
        $this->assertNotNull(DB::table('accounting_company_settings')->value('cutover_locked_at'));
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_events_are_append_only_through_models(): void
    {
        $this->workflow->save($this->maker, $this->data());
        $event = AccountingExpenseEvent::query()->whereNotNull('expense_id')->firstOrFail();
        $this->expectException(\LogicException::class);
        $event->update(['reason' => 'Rewritten']);
    }

    public function test_review_command_payload_cannot_overwrite_intent(): void
    {
        $row = $this->workflow->save($this->maker, $this->data());
        $this->actingAs($this->maker)->postJson('/erp/accounting/expenses/'.$row->getKey().'/submit', ['revision' => 1, 'reason' => 'Submit', 'net_amount' => '1.00'])->assertUnprocessable();
        $this->assertSame('draft', $row->refresh()->getAttribute('status'));
    }

    public function test_existing_supplier_bill_reference_cannot_be_reentered_as_expense(): void
    {
        $supplier = $this->supplier();

        SupplierBill::query()->create(['company_id' => $this->company->getKey(), 'supplier_id' => $supplier->getKey(), 'number' => 'BILL-EXISTING', 'supplier_invoice_number' => ' inv   123 ', 'status' => 'draft', 'invoice_date' => '2026-10-26', 'currency_code' => 'BDT', 'created_by' => $this->maker->getKey()]);

        $this->invalid(fn () => $this->workflow->save($this->maker, $this->data(['payee_type' => 'supplier', 'payee_label' => '', 'supplier_id' => $supplier->getKey(), 'document_reference' => 'INV 123'])));
        $this->assertDatabaseCount('accounting_expenses', 0);
        $this->assertDatabaseCount('supplier_bills', 1);
    }

    public function test_foreign_expense_id_is_not_disclosed_to_own_company_staff(): void
    {
        $staff = $this->staff(['erp.expenses.view']);
        $this->company = $this->newCompany('EXP-FOREIGN');
        app(AccountingSetupService::class)->initialize($this->maker, (int) $this->company->getKey());
        app(AccountingAccountService::class)->initializeGroups($this->maker, (int) $this->company->getKey());
        $this->gl = $this->account('expense', '6000');
        $this->category = $this->workflow->saveCategory($this->maker, $this->categoryData());
        $row = $this->workflow->save($this->maker, $this->data());

        $this->actingAs($staff)->getJson('/erp/accounting/expenses/'.$row->getKey())->assertNotFound();
        $this->getJson('/erp/accounting/expense-categories/'.$this->category->getKey())->assertForbidden();
    }

    public function test_category_management_requires_both_native_manage_and_view(): void
    {
        $staff = $this->staff(['erp.expense-categories.manage']);
        $this->actingAs($staff)->postJson('/erp/accounting/expense-categories', $this->categoryData(['code' => 'STAFF']))->assertForbidden();
        $this->assertDatabaseCount('accounting_expense_categories', 1);
    }

    public function test_independent_http_review_round_trip_and_stale_replay(): void
    {
        $created = $this->actingAs($this->maker)->postJson('/erp/accounting/expenses', $this->data())->assertOk();
        $id = $created->json('data.id');

        $this->postJson('/erp/accounting/expenses/'.$id.'/submit', ['revision' => 1, 'reason' => 'Submit document'])->assertOk()->assertJsonPath('data.status', 'submitted');

        $this->actingAs($this->reviewer)->postJson('/erp/accounting/expenses/'.$id.'/approve', ['revision' => 2, 'reason' => 'Independent review'])->assertOk()->assertJsonPath('data.status', 'approved');

        $this->postJson('/erp/accounting/expenses/'.$id.'/approve', ['revision' => 2, 'reason' => 'Retry stale command'])->assertUnprocessable();
        $this->assertDatabaseCount('accounting_expense_events', 4);
    }

    public function test_category_can_be_deactivated_after_its_gl_is_inactive(): void
    {
        $this->gl->update(['status' => 'inactive']);
        $data = $this->categoryData(['status' => 'inactive', 'revision' => 1]);
        unset($data['key']);
        $category = $this->workflow->saveCategory($this->maker, $data, (int) $this->category->getKey());
        $this->assertSame('inactive', $category->getAttribute('status'));
        app(AccountingExpenseHistory::class)->verify($category);
    }
}
