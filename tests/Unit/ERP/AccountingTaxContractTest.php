<?php

declare(strict_types=1);

namespace Tests\Unit\ERP;

use App\Services\ERP\Accounting\AccountingDecimal;
use App\Services\ERP\Accounting\Reports\AccountingTaxContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AccountingTaxContractTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function accounts(): array
    {
        return [['id' => 1, 'code' => 'INPUT', 'name' => 'Input tax', 'category' => 'asset', 'statement_role' => 'current_asset', 'normal_side' => 'debit', 'is_postable' => true, 'status' => 'active', 'cash_flow_role' => null, 'control_role' => null], ['id' => 2, 'code' => 'OUTPUT', 'name' => 'Output tax', 'category' => 'liability', 'statement_role' => 'current_liability', 'normal_side' => 'credit', 'is_postable' => true, 'status' => 'active', 'cash_flow_role' => null, 'control_role' => null]];
    }

    /** @param array<string, mixed> $change @return array<string, mixed> */
    private function line(array $change = []): array
    {
        return ['date' => '2026-10-26', 'journal_id' => 1, 'account_id' => 1, 'number' => 'J1', 'entry_kind' => 'automatic', 'role' => 'input_tax', 'debit' => '5.00', 'credit' => '0.00', 'source_kind' => 'supplier_bill_item', 'source_id' => 10, ...$change];
    }

    /** @param list<array<string, mixed>> $actual @param list<array<string, mixed>> $expected @return array<string, mixed> */
    private function report(array $actual, array $expected): array
    {
        return (new AccountingTaxContract(new AccountingDecimal))->project($this->accounts(), $actual, $expected, [1 => ['input_tax' => true], 2 => ['output_tax' => true]], '2026-10-01', '2026-10-26');
    }

    public function test_input_output_are_separate_with_correct_normal_signs(): void
    {
        $rows = [$this->line(), $this->line(['journal_id' => 2, 'account_id' => 2, 'role' => 'output_tax', 'debit' => '0.00', 'credit' => '3.00'])];
        $r = $this->report($rows, $rows);
        $this->assertTrue($r['complete']);
        $this->assertSame('5.00', $r['gl']['input_tax']['closing']);
        $this->assertSame('3.00', $r['gl']['output_tax']['closing']);
        $this->assertSame('-2.00', $r['arithmetic_output_less_input']);
    }

    public function test_preperiod_source_establishes_opening_without_current_period_tax(): void
    {
        $l = $this->line(['date' => '2026-09-30']);
        $r = $this->report([$l], [$l]);
        $this->assertSame('5.00', $r['gl']['input_tax']['opening']);
        $this->assertSame('0.00', $r['gl']['input_tax']['period_debit']);
        $this->assertCount(0, $r['rows']);
    }

    public function test_source_compensation_preserves_debit_and_credit_activity(): void
    {
        $rows = [$this->line(), $this->line(['journal_id' => 2, 'debit' => '0.00', 'credit' => '5.00'])];
        $r = $this->report($rows, $rows);
        $this->assertSame('0.00', $r['gl']['input_tax']['closing']);
        $this->assertSame('5.00', $r['gl']['input_tax']['period_credit']);
        $this->assertTrue($r['complete']);
    }

    public function test_manual_adjustment_remains_known_gl_but_unverified_native_tax(): void
    {
        $r = $this->report([$this->line(['entry_kind' => 'manual', 'source_kind' => null, 'source_id' => null])], []);
        $this->assertSame('5.00', $r['gl']['input_tax']['closing']);
        $this->assertNull($r['confirmed_balances']);
    }

    public function test_opening_journal_requires_dedicated_tax_coverage(): void
    {
        $r = $this->report([$this->line(['entry_kind' => 'opening'])], []);
        $this->assertContains('opening_tax_coverage_not_integrated', array_column($r['issues'], 'issue'));
    }

    public function test_same_totals_under_wrong_native_item_do_not_reconcile(): void
    {
        $r = $this->report([$this->line(['source_id' => 11])], [$this->line()]);
        $this->assertSame('0.00', $r['difference']['input_tax']['closing']);
        $this->assertFalse($r['complete']);
        $this->assertFalse($r['rows'][0]['native_recipe_matched']);
    }

    public function test_offsetting_extra_lines_cannot_claim_native_match(): void
    {
        $r = $this->report([$this->line(), $this->line(['debit' => '1.00']), $this->line(['debit' => '0.00', 'credit' => '1.00'])], [$this->line()]);
        $this->assertSame('0.00', $r['difference']['input_tax']['closing']);
        $this->assertFalse($r['rows'][0]['native_recipe_matched']);
        $this->assertFalse($r['complete']);
    }

    public function test_ambiguous_role_retains_unclassified_amount_instead_of_silent_zero(): void
    {
        $r = (new AccountingTaxContract(new AccountingDecimal))->project($this->accounts(), [$this->line()], [], [1 => ['input_tax' => true, 'output_tax' => true]], '2026-10-01', '2026-10-26');
        $this->assertSame(1, $r['unclassified_lines']['count']);
        $this->assertSame('5.00', $r['unclassified_lines']['debit']);
        $this->assertNull($r['confirmed_balances']);
    }

    public function test_invalid_current_classification_withholds_confirmation(): void
    {
        $a = $this->accounts();
        $a[0]['category'] = 'expense';
        $r = (new AccountingTaxContract(new AccountingDecimal))->project($a, [$this->line()], [$this->line()], [1 => ['input_tax' => true], 2 => ['output_tax' => true]], '2026-10-01', '2026-10-26');
        $this->assertFalse($r['complete']);
        $this->assertSame('5.00', $r['gl']['input_tax']['closing']);
    }

    public function test_inactive_historical_account_balance_is_preserved(): void
    {
        $a = $this->accounts();
        $a[0]['status'] = 'inactive';
        $r = (new AccountingTaxContract(new AccountingDecimal))->project($a, [$this->line()], [$this->line()], [1 => ['input_tax' => true], 2 => ['output_tax' => true]], '2026-10-01', '2026-10-26');
        $this->assertTrue($r['complete']);
        $this->assertSame('inactive', $r['accounts'][0]['status']);
    }

    public function test_large_aggregate_keeps_exact_cents(): void
    {
        $rows = [$this->line(['debit' => '999999999999999999.99']), $this->line(['journal_id' => 2, 'debit' => '0.02'])];
        $this->assertSame('1000000000000000000.01', $this->report($rows, $rows)['gl']['input_tax']['closing']);
    }

    public function test_float_line_is_rejected_without_coercion(): void
    {
        $this->expectException(ValidationException::class);
        $this->report([$this->line(['debit' => 5.1])], []);
    }
}
