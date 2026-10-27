<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\AccountingExpense;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ERP\AccountingExpenseWorkspaceTestCase;

final class AccountingExpenseWorkspaceScreenTest extends AccountingExpenseWorkspaceTestCase
{
    /** @param list<string> $permissions */
    private function staff(array $permissions): User
    {
        $user = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $user->getKey(), 'employee_number' => 'SCREEN-'.$user->getKey(), 'first_name' => 'Expense', 'last_name' => 'Staff', 'employment_status' => 'active']);
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

return $user;
    }

    private function draft(): AccountingExpense
    {
        return $this->native->save($this->maker, ['company_id' => (int) $this->company->getKey(), 'category_id' => $this->category, 'document_date' => '2026-10-26', 'accounting_date' => '2026-10-26', 'document_reference' => 'SCREEN-DRAFT', 'payee_type' => 'other', 'payee_label' => 'Screen vendor', 'settlement_mode' => 'unpaid', 'net_amount' => '50.27', 'tax_amount' => '0.00', 'tax_treatment' => 'none', 'description' => 'Screen document', 'key' => (string) Str::uuid()]);
    }

    public function test_guests_redirect_from_all_registers(): void
    {
        foreach (['expense-workspace', 'expense-categories-workspace', 'expense-finance-workspace'] as $path) {
            $this->get('/erp/accounting/'.$path)->assertRedirect('/login');
        }
    }

    public function test_expense_register_resolves_with_company_filter_and_scoped_data(): void
    {
        $row = $this->expense();
        $this->actingAs($this->maker)->get('/erp/accounting/expense-workspace?company_id='.$this->company->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Expenses/Index')->where('module', 'expenses')->has('result.data', 1)->where('result.data.0.id', (int) $row->getKey()));
    }

    public function test_register_without_selected_company_is_read_only_and_does_not_seed(): void
    {
        $before = DB::table('accounting_expense_events')->count();
        $this->actingAs($this->maker)->get('/erp/accounting/expense-workspace')->assertOk()->assertInertia(fn (Assert $page) => $page->where('context.company', null)->where('result', null));
        $this->assertSame($before, DB::table('accounting_expense_events')->count());
    }

    public function test_create_and_edit_pages_exist_with_selected_ids(): void
    {
        $this->actingAs($this->maker)->get('/erp/accounting/expense-workspace/create?company_id='.$this->company->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Expenses/Form')->where('record', null)->has('options.categories', 1));
        $row = $this->draft();
        $this->get('/erp/accounting/expense-workspace/'.$row->getKey().'/edit')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Expenses/Form')->where('record.id', (int) $row->getKey())->where('record.net_amount', '50.27'));
    }

    public function test_reviewed_document_cannot_open_edit_page(): void
    {
        $row = $this->expense();
        $this->actingAs($this->maker)->get('/erp/accounting/expense-workspace/'.$row->getKey().'/edit')->assertUnprocessable();
    }

    public function test_detail_shows_payable_and_attachment_sections_for_authorized_user(): void
    {
        $row = $this->expense();
        $this->recognized($row);
        $this->actingAs($this->maker)->get('/erp/accounting/expense-workspace/'.$row->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Expenses/Show')->where('finance.outstanding', '100.27')->where('finance.financial_status', 'unpaid')->has('attachments.data', 0)->missing('expense.data.creation_request_hash'));
    }

    public function test_native_viewer_does_not_receive_private_attachment_or_finance_payloads(): void
    {
        $row = $this->expense();
        $staff = $this->staff(['erp.expenses.view']);
        $this->actingAs($staff)->get('/erp/accounting/expense-workspace/'.$row->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->where('attachments', null)->where('finance', null)->where('context.can', static fn ($can): bool => $can['erp.expenses.create'] === false));
    }

    public function test_category_register_and_form_resolve(): void
    {
        $this->actingAs($this->maker)->get('/erp/accounting/expense-categories-workspace?company_id='.$this->company->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Expenses/Index')->where('module', 'categories'));
        $this->get('/erp/accounting/expense-categories-workspace/'.$this->category.'/edit')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Expenses/Form')->where('categoryMode', true)->where('record.id', $this->category));
    }

    public function test_category_viewer_without_native_expense_view_can_read_category_register(): void
    {
        $staff = $this->staff(['erp.expense-categories.view']);
        $this->actingAs($staff)->get('/erp/accounting/expense-categories-workspace')->assertOk()->assertInertia(fn (Assert $page) => $page->where('module', 'categories')->where('context.company.id', (int) $this->company->getKey()));
        $this->get('/erp/accounting/expense-workspace')->assertForbidden();
    }

    public function test_finance_register_and_detail_resolve(): void
    {
        $row = $this->expense();
        $plan = $this->instruction($row);
        $this->actingAs($this->maker)->get('/erp/accounting/expense-finance-workspace?company_id='.$this->company->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Expenses/Index')->where('module', 'finance')->where('result.data.0.id', (int) $plan->getKey()));
        $this->get('/erp/accounting/expense-finance-workspace/'.$plan->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Expenses/FinanceShow')->where('canApprove', false)->has('journalLines', 2)->where('journalLines.0.debit', '100.27')->missing('finance.data.snapshot'));
    }

    public function test_recognition_and_settlement_prepare_screens_resolve(): void
    {
        $row = $this->expense();
        $this->actingAs($this->maker)->get('/erp/accounting/expense-finance-workspace/create?expense_id='.$row->getKey().'&kind=recognition')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Expenses/FinanceForm')->where('kind', 'recognition')->has('options.liabilities', 1));
        $this->recognized($row);
        $this->get('/erp/accounting/expense-finance-workspace/create?expense_id='.$row->getKey().'&kind=settlement')->assertOk()->assertInertia(fn (Assert $page) => $page->where('kind', 'settlement')->where('position.outstanding', '100.27'));
    }

    public function test_reversal_prepare_screen_is_bound_to_original_and_amount(): void
    {
        $row = $this->expense();
        $plan = $this->recognized($row);
        $this->actingAs($this->maker)->get('/erp/accounting/expense-finance-workspace/create?expense_id='.$row->getKey().'&kind=reversal&original_plan_id='.$plan->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Expenses/FinanceForm')->where('original.id', (int) $plan->getKey())->where('original.amount', '100.27')->where('kind', 'reversal'));
    }

    public function test_staff_cannot_select_foreign_company(): void
    {
        $foreign = Company::query()->create(['code' => 'SCREEN-B', 'name' => 'Foreign', 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka', 'status' => 'active']);
        $staff = $this->staff(['erp.expenses.view']);
        $this->actingAs($staff)->get('/erp/accounting/expense-workspace?company_id='.$foreign->getKey())->assertForbidden();
    }

    public function test_options_hide_private_employee_and_supplier_fields(): void
    {
        Employee::query()->create(['company_id' => $this->company->getKey(), 'employee_number' => 'SAFE-E', 'first_name' => 'Public', 'last_name' => 'Name', 'employment_status' => 'active', 'national_id' => 'PRIVATE-NID', 'notes' => 'PRIVATE-HR']);
        $staff = $this->staff(['erp.expenses.view', 'erp.expenses.create']);
        $response = $this->actingAs($staff)->getJson('/erp/accounting/expense-options?company_id='.$this->company->getKey().'&mode=native&q=SAFE-E')->assertOk();
        $this->assertStringNotContainsString('PRIVATE-NID', $response->getContent());
        $this->assertStringNotContainsString('PRIVATE-HR', $response->getContent());
        $response->assertJsonPath('employees.0.label', 'SAFE-E — Public Name')->assertJsonMissingPath('employees.0.national_id');
    }

    public function test_finance_choices_do_not_disclose_employee_directory(): void
    {
        $this->actingAs($this->maker)->getJson('/erp/accounting/expense-options?company_id='.$this->company->getKey().'&mode=finance')->assertOk()->assertJsonCount(0, 'employees')->assertJsonCount(0, 'suppliers')->assertJsonCount(1, 'liabilities');
    }

    public function test_view_only_staff_cannot_search_mutation_choices(): void
    {
        $this->actingAs($this->staff(['erp.expenses.view']))->getJson('/erp/accounting/expense-options?company_id='.$this->company->getKey().'&mode=native')->assertForbidden();
    }

    public function test_foreign_option_company_is_rejected(): void
    {
        $foreign = Company::query()->create(['code' => 'OPT-B', 'name' => 'Foreign', 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka', 'status' => 'active']);
        $staff = $this->staff(['erp.expenses.view', 'erp.expenses.create']);
        $this->actingAs($staff)->getJson('/erp/accounting/expense-options?company_id='.$foreign->getKey().'&mode=native')->assertForbidden();
    }

    public function test_edit_only_staff_can_refresh_current_draft_options(): void
    {
        $row = $this->draft();
        $staff = $this->staff(['erp.expenses.view', 'erp.expenses.edit']);
        $this->actingAs($staff)->getJson('/erp/accounting/expense-options?company_id='.$this->company->getKey().'&mode=native&expense_id='.$row->getKey())->assertOk()->assertJsonCount(1, 'categories');
    }

    public function test_independent_reviewer_receives_approval_action(): void
    {
        $plan = $this->instruction($this->expense());
        $this->actingAs($this->reviewer)->get('/erp/accounting/expense-finance-workspace/'.$plan->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->where('canApprove', true));
    }

    public function test_reversal_workspace_requires_explicit_reverse_permission(): void
    {
        $row = $this->expense();
        $plan = $this->recognized($row);
        $staff = $this->staff(['erp.expenses.view', 'erp.expense-finance.view', 'erp.expense-finance.create']);
        $this->actingAs($staff)->get('/erp/accounting/expense-finance-workspace/create?expense_id='.$row->getKey().'&kind=reversal&original_plan_id='.$plan->getKey())->assertForbidden();
    }
}
