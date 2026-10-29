<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\AccountingTaxPermissionSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ERP\AccountingExpenseWorkspaceTestCase;

final class AccountingTaxScreenTest extends AccountingExpenseWorkspaceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingTaxPermissionSeeder::class);
    }

    /** @param list<string> $permissions */
    private function staff(array $permissions): User
    {
        $user = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $user->getKey(), 'employee_number' => 'TAX-'.$user->getKey(), 'first_name' => 'Tax', 'last_name' => 'Staff', 'employment_status' => 'active']);
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    private function url(?string $format = null): string
    {
        return '/erp/accounting/tax-reconciliation'.($format === null ? '' : '/export').'?company_id='.$this->company->getKey().'&from=2026-10-01&to=2026-10-26'.($format === null ? '' : '&format='.$format);
    }

    private function recognition(): void
    {
        $this->recognized($this->expense(['tax_treatment' => 'recoverable', 'tax_amount' => '5.00', 'tax_account_id' => $this->mapping['input_tax']]));
    }

    public function test_guest_redirects_from_screen_and_export(): void
    {
        $this->get($this->url())->assertRedirect('/login');
        $this->get($this->url('csv'))->assertRedirect('/login');
    }

    public function test_tax_only_view_resolves_without_gl_or_export_rights(): void
    {
        $this->recognition();
        $this->actingAs($this->staff(['erp.tax-reconciliation.view']))->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Tax/Index')->where('canLedger', false)->where('canExport', false)->where('report.gl.input_tax.closing', '5.00'));
    }

    public function test_view_only_and_export_only_are_independently_blocked(): void
    {
        $this->actingAs($this->staff(['erp.tax-reconciliation.view']))->get($this->url('csv'))->assertForbidden();
        $this->get($this->url('print'))->assertForbidden();
        $this->actingAs($this->staff(['erp.tax-reconciliation.export']))->get($this->url())->assertForbidden();
        $this->get($this->url('csv'))->assertForbidden();
    }

    public function test_foreign_company_scope_is_forbidden(): void
    {
        $other = Company::query()->create(['code' => 'TAX-B', 'name' => 'Foreign', 'status' => 'active', 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka']);
        $this->actingAs($this->staff(['erp.tax-reconciliation.view', 'erp.tax-reconciliation.export']))->get('/erp/accounting/tax-reconciliation?company_id='.$other->getKey())->assertForbidden();
    }

    public function test_csv_is_complete_formula_safe_private_and_excludes_source_notes(): void
    {
        $this->recognition();
        DB::table('companies')->where('id', $this->company->getKey())->update(['name' => '=1+1']);
        $response = $this->actingAs($this->maker)->get($this->url('csv').'&page=99&per_page=1')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringContainsString('5.00', $csv);
        $this->assertStringContainsString('Verified native source', $csv);
        $this->assertStringNotContainsString('PRIVATE-DESCRIPTION', $csv);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_print_is_escaped_and_disclaims_return_or_payment_amount(): void
    {
        DB::table('companies')->where('id', $this->company->getKey())->update(['name' => '<script>alert(1)</script>']);
        $this->actingAs($this->maker)->get($this->url('print'))->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>', false)->assertSee('no tax return');
    }

    public function test_missing_native_proof_keeps_exports_explicitly_provisional(): void
    {
        $this->recognition();
        DB::table('accounting_expense_finance_operations')->where('company_id', $this->company->getKey())->update(['payload_hash' => str_repeat('0', 64)]);
        $csv = $this->actingAs($this->maker)->get($this->url('csv'))->assertOk()->streamedContent();
        $this->assertStringContainsString('PROVISIONAL', $csv);
        $this->assertStringContainsString('native_tax_source_or_legacy_coverage_review', $csv);
    }

    public function test_future_dates_and_unsupported_subsets_are_rejected(): void
    {
        $this->actingAs($this->maker)->getJson('/erp/accounting/tax-reconciliation?company_id='.$this->company->getKey().'&from=2026-10-01&to=2026-10-27')->assertUnprocessable();
        $this->getJson($this->url().'&account_id=1')->assertUnprocessable();
    }
}
