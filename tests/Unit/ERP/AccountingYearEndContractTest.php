<?php

declare(strict_types=1);

namespace Tests\Unit\ERP;

use App\Services\ERP\Accounting\YearEnd\AccountingYearEndContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AccountingYearEndContractTest extends TestCase
{
    /** @return array<string, mixed> */
    private function nominal(int $id, string $debit, string $credit, string $category = 'revenue'): array
    {
        return ['id' => $id, 'category' => $category, 'opening_debit' => '0.00', 'opening_credit' => '0.00', 'closing_debit' => $debit, 'closing_credit' => $credit, 'is_postable' => true, 'cash_flow_role' => 'operating', 'status' => 'active'];
    }

    /** @return array<string, mixed> */
    private function retained(): array
    {
        return ['id' => 9, 'category' => 'equity', 'statement_role' => 'retained_earnings', 'control_role' => 'retained_earnings', 'normal_side' => 'credit', 'status' => 'active', 'is_postable' => true, 'cash_flow_role' => null];
    }

    public function test_profit_credits_retained_earnings_and_clears_both_nominal_sides(): void
    {
        $p = app(AccountingYearEndContract::class)->plan([$this->nominal(1, '0.00', '200.01'), $this->nominal(2, '100.27', '0.00', 'expense'), $this->retained()], [], 9);
        $this->assertSame('99.74', $p['result']);
        $this->assertSame('99.74', $p['lines'][2]['credit']);
        $this->assertSame('200.01', $p['lines'][0]['debit']);
    }

    public function test_loss_debits_retained_earnings(): void
    {
        $p = app(AccountingYearEndContract::class)->plan([$this->nominal(1, '100.27', '0.00', 'expense'), $this->retained()], [], 9);
        $this->assertSame('-100.27', $p['result']);
        $this->assertSame('100.27', $p['lines'][1]['debit']);
    }

    public function test_zero_profit_still_clears_nonzero_offsetting_nominal_balances(): void
    {
        $p = app(AccountingYearEndContract::class)->plan([$this->nominal(1, '0.00', '100.27'), $this->nominal(2, '100.27', '0.00', 'expense'), $this->retained()], [], 9);
        $this->assertSame('0.00', $p['result']);
        $this->assertCount(2, $p['lines']);
        $this->assertNull($p['no_journal_reason']);
    }

    public function test_empty_nominal_balances_have_explicit_no_journal_outcome(): void
    {
        $p = app(AccountingYearEndContract::class)->plan([$this->retained()], [], 9);
        $this->assertSame([], $p['lines']);
        $this->assertSame('zero_nominal_balances', $p['no_journal_reason']);
    }

    public function test_contra_revenue_is_not_assumed_credit_positive(): void
    {
        $p = app(AccountingYearEndContract::class)->plan([$this->nominal(1, '10.01', '0.00'), $this->retained()], [], 9);
        $this->assertSame('-10.01', $p['result']);
        $this->assertSame('10.01', $p['lines'][0]['credit']);
    }

    public function test_reverse_exactly_swaps_original_sides_and_keeps_retained_binding(): void
    {
        $c = app(AccountingYearEndContract::class);
        $p = $c->plan([$this->nominal(1, '0.00', '100.27'), $this->retained()], [], 9);
        $reverse = $c->reverse($p);
        $this->assertSame(9, $reverse['retained_account_id']);
        $this->assertSame('-100.27', $reverse['result']);
        foreach ($p['lines'] as $i => $line) {
            $this->assertSame($line['debit'], $reverse['lines'][$i]['credit']);
            $this->assertSame($line['credit'], $reverse['lines'][$i]['debit']);
        }
    }

    public function test_prior_nominal_opening_balance_is_not_swept_into_current_result(): void
    {
        $a = $this->nominal(1, '0.00', '100.27');
        $a['opening_credit'] = '1.00';
        $this->expectException(ValidationException::class);
        app(AccountingYearEndContract::class)->plan([$a, $this->retained()], [], 9);
    }

    public function test_explicit_nominal_opening_journal_requires_cutover_review(): void
    {
        $this->expectException(ValidationException::class);
        app(AccountingYearEndContract::class)->plan([$this->nominal(1, '0.00', '100.27'), $this->retained()], [1 => ['debit' => '0.00', 'credit' => '1.00']], 9);
    }

    public function test_inactive_retained_target_cannot_receive_a_new_close(): void
    {
        $a = $this->retained();
        $a['status'] = 'inactive';
        $this->expectException(ValidationException::class);
        app(AccountingYearEndContract::class)->plan([$a], [], 9);
    }

    public function test_exact_large_cents_do_not_use_floats(): void
    {
        $p = app(AccountingYearEndContract::class)->plan([$this->nominal(1, '0.00', '999999999999.99'), $this->retained()], [], 9);
        $this->assertSame('999999999999.99', $p['result']);
    }
}
