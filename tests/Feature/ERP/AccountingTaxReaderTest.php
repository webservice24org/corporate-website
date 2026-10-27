<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\AccountingExpense;
use App\Models\User;
use App\Services\ERP\Accounting\Reports\AccountingTaxReader;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingTaxPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ERP\AccountingExpenseWorkspaceTestCase;

final class AccountingTaxReaderTest extends AccountingExpenseWorkspaceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingTaxPermissionSeeder::class);
    }

    private function taxExpense(string $treatment = 'recoverable'): AccountingExpense
    {
        return $this->expense(['tax_treatment' => $treatment, 'tax_amount' => '5.00', 'tax_account_id' => $treatment === 'recoverable' ? $this->mapping['input_tax'] : null]);
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        return app(AccountingTaxReader::class)->report($this->maker, (int) $this->company->getKey(), ['from' => '2026-10-01', 'to' => '2026-10-26']);
    }

    public function test_recoverable_recognition_matches_input_tax_native_recipe(): void
    {
        $this->recognized($this->taxExpense());
        $r = $this->report();
        $this->assertTrue($r['complete']);
        $this->assertSame('5.00', $r['gl']['input_tax']['closing']);
        $this->assertSame('0.00', $r['difference']['input_tax']['closing']);
    }

    public function test_settlement_does_not_recognize_input_tax_again(): void
    {
        $expense = $this->taxExpense();
        $this->recognized($expense);
        $this->postPlan($this->approved($this->settle($expense)));
        $this->assertSame('5.00', $this->report()['source']['input_tax']['period_debit']);
    }

    public function test_recognition_reversal_compensates_original_tax_exactly(): void
    {
        $expense = $this->taxExpense();
        $original = $this->recognized($expense);
        $reverse = $this->finance->create($this->maker, ['expense_id' => (int) $expense->getKey(), 'native_revision' => (int) $expense->refresh()->getAttribute('revision'), 'kind' => 'reversal', 'original_plan_id' => (int) $original->getKey(), 'date' => '2026-10-26', 'key' => (string) Str::uuid(), 'reason' => 'Compensate recognized tax']);
        $this->postPlan($this->approved($reverse));
        $r = $this->report();
        $this->assertTrue($r['complete']);
        $this->assertSame('5.00', $r['gl']['input_tax']['period_credit']);
        $this->assertSame('0.00', $r['gl']['input_tax']['closing']);
    }

    public function test_tax_included_in_cost_is_not_recoverable_input_tax_gl(): void
    {
        $this->recognized($this->taxExpense('included_in_cost'));
        $r = $this->report();
        $this->assertSame('0.00', $r['gl']['input_tax']['closing']);
        $this->assertTrue($r['complete']);
    }

    public function test_missing_native_manifest_withholds_confirmation_without_hiding_gl_tax(): void
    {
        $this->recognized($this->taxExpense());
        DB::table('accounting_expense_finance_operations')->where('company_id', $this->company->getKey())->update(['payload_hash' => str_repeat('0', 64)]);
        $r = $this->report();
        $this->assertSame('5.00', $r['gl']['input_tax']['closing']);
        $this->assertNull($r['confirmed_balances']);
    }

    public function test_later_report_range_preserves_tax_opening(): void
    {
        $this->recognized($this->taxExpense());
        $this->travelTo(CarbonImmutable::parse('2026-10-27 12:00:00', 'Asia/Dhaka'));
        $r = app(AccountingTaxReader::class)->report($this->maker, (int) $this->company->getKey(), ['from' => '2026-10-27', 'to' => '2026-10-27']);
        $this->assertSame('5.00', $r['gl']['input_tax']['opening']);
        $this->assertSame('0.00', $r['gl']['input_tax']['period_debit']);
        $this->assertSame('opening_evidence', $r['sources'][0]['date_scope']);
    }

    public function test_tax_requires_its_own_permission(): void
    {
        $staff = User::factory()->create();
        $this->expectException(AuthorizationException::class);
        app(AccountingTaxReader::class)->report($staff, (int) $this->company->getKey(), ['from' => '2026-10-01', 'to' => '2026-10-26']);
    }

    public function test_read_has_no_financial_writes_or_private_description(): void
    {
        $this->recognized($this->taxExpense());
        $count = DB::table('journal_entries')->count();
        $r = $this->report();
        $this->assertSame($count, DB::table('journal_entries')->count());
        $this->assertStringNotContainsString('PRIVATE-DESCRIPTION', json_encode($r, JSON_THROW_ON_ERROR));
        $this->assertFalse($r['statutory_filing_supported']);
    }
}
