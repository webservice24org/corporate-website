<?php

declare(strict_types=1);

namespace Tests\Unit\ERP;

use App\Services\ERP\Accounting\AccountingAccountCatalog;
use App\Services\ERP\Accounting\Reports\AccountingBalanceSheetContract;
use Tests\TestCase;

final class AccountingBalanceSheetContractTest extends TestCase
{
    private function contract(): AccountingBalanceSheetContract
    {
        return new AccountingBalanceSheetContract(new AccountingAccountCatalog);
    }

    /** @return array<string, mixed> */
    private function year(): array
    {
        return ['id' => 1, 'code' => 'FY26', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'state' => 'open'];
    }

    /** @return array<string, mixed> */
    private function account(int $id, string $category, string $role, string $debit = '0.00', string $credit = '0.00', string $oldDebit = '0.00', string $oldCredit = '0.00'): array
    {
        return ['id' => $id, 'code' => 'B'.$id, 'name' => 'Account '.$id, 'category' => $category, 'statement_role' => $role, 'closing_debit' => $debit, 'closing_credit' => $credit, 'opening_debit' => $oldDebit, 'opening_credit' => $oldCredit, 'period_debit' => bcsub($debit, $oldDebit, 2), 'period_credit' => bcsub($credit, $oldCredit, 2), 'is_postable' => true, 'status' => 'active'];
    }

    public function test_posted_retained_earnings_and_current_profit_are_added_once(): void
    {
        $r = $this->contract()->project([$this->account(1, 'asset', 'current_asset', '150.27'), $this->account(2, 'equity', 'retained_earnings', '0.00', '100.00'), $this->account(3, 'revenue', 'operating_revenue', '0.00', '50.27')], [], $this->year());
        $this->assertTrue($r['complete']);
        $this->assertSame('150.27', $r['totals']['equity_with_earnings']);
        $this->assertSame('100.00', $r['totals']['posted_equity']);
        $this->assertSame('50.27', $r['earnings']['residual_total']);
    }

    public function test_transferred_earnings_already_in_equity_are_not_added_again(): void
    {
        $r = $this->contract()->project([$this->account(1, 'asset', 'current_asset', '150.27'), $this->account(2, 'equity', 'retained_earnings', '0.00', '150.27'), $this->account(3, 'revenue', 'operating_revenue')], [], [...$this->year(), 'state' => 'closed']);
        $this->assertTrue($r['complete']);
        $this->assertSame('0.00', $r['earnings']['residual_total']);
        $this->assertSame('150.27', $r['totals']['equity_with_earnings']);
    }

    public function test_prior_year_nominal_residual_is_separate_and_requires_review(): void
    {
        $r = $this->contract()->project([$this->account(1, 'asset', 'current_asset', '50.27'), $this->account(2, 'revenue', 'operating_revenue', '0.00', '50.27', '0.00', '40.00')], [], $this->year());
        $this->assertSame('40.00', $r['earnings']['prior_year_unclosed']);
        $this->assertSame('10.27', $r['earnings']['current_year_unclosed_result']);
        $this->assertTrue($r['equation_balanced']);
        $this->assertNull($r['confirmed_totals']);
    }

    public function test_nominal_opening_is_not_current_year_profit(): void
    {
        $r = $this->contract()->project([$this->account(1, 'asset', 'current_asset', '50.27'), $this->account(2, 'revenue', 'operating_revenue', '0.00', '50.27')], [2 => ['debit' => '0.00', 'credit' => '40.00']], $this->year());
        $this->assertSame('40.00', $r['earnings']['current_year_nominal_opening']);
        $this->assertSame('10.27', $r['earnings']['current_year_unclosed_result']);
        $this->assertFalse($r['complete']);
        $this->assertSame('0.00', $r['earnings']['bridge_difference']);
    }

    public function test_loss_is_negative_equity_and_keeps_equation_balanced(): void
    {
        $r = $this->contract()->project([$this->account(1, 'liability', 'current_liability', '0.00', '100.27'), $this->account(2, 'expense', 'operating_expense', '100.27')], [], $this->year());
        $this->assertTrue($r['complete']);
        $this->assertSame('-100.27', $r['totals']['equity_with_earnings']);
        $this->assertSame('0.00', $r['difference']);
    }

    public function test_contra_asset_reduces_assets_without_absolute_value_flip(): void
    {
        $r = $this->contract()->project([$this->account(1, 'asset', 'non_current_asset', '120.27'), $this->account(2, 'asset', 'non_current_asset', '0.00', '20.00'), $this->account(3, 'equity', 'equity', '0.00', '100.27')], [], $this->year());
        $this->assertSame('100.27', $r['totals']['assets']);
        $this->assertCount(1, $r['warnings']);
        $this->assertTrue($r['complete']);
    }

    public function test_classification_mismatch_withholds_confirmed_totals(): void
    {
        $r = $this->contract()->project([$this->account(1, 'asset', 'current_liability', '100.27')], [], $this->year());
        $this->assertFalse($r['complete']);
        $this->assertNull($r['confirmed_totals']);
        $this->assertSame('classification_missing_or_inconsistent', $r['issues'][0]['issue']);
    }

    public function test_missing_fiscal_year_does_not_guess_calendar_split(): void
    {
        $r = $this->contract()->project([$this->account(1, 'asset', 'current_asset', '100.27'), $this->account(2, 'equity', 'equity', '0.00', '100.27')], [], null);
        $this->assertTrue($r['equation_balanced']);
        $this->assertNull($r['earnings']['current_year_unclosed_result']);
        $this->assertNull($r['confirmed_totals']);
    }

    public function test_closed_year_with_nominal_residual_requires_review(): void
    {
        $r = $this->contract()->project([$this->account(1, 'asset', 'current_asset', '100.27'), $this->account(2, 'revenue', 'operating_revenue', '0.00', '100.27')], [], [...$this->year(), 'state' => 'closed']);
        $this->assertFalse($r['complete']);
        $this->assertContains('closed_year_has_nominal_residual', array_column($r['issues'], 'issue'));
    }

    public function test_inactive_accounts_keep_historical_balances(): void
    {
        $r = $this->contract()->project([[...$this->account(1, 'asset', 'current_asset', '100.27'), 'status' => 'inactive'], $this->account(2, 'equity', 'equity', '0.00', '100.27')], [], $this->year());
        $this->assertTrue($r['complete']);
        $this->assertSame('100.27', $r['totals']['assets']);
    }

    public function test_nonpostable_parent_balance_requires_review_without_rolling_up_children(): void
    {
        $r = $this->contract()->project([[...$this->account(1, 'asset', 'current_asset', '100.27'), 'is_postable' => false], $this->account(2, 'equity', 'equity', '0.00', '100.27')], [], $this->year());
        $this->assertFalse($r['complete']);
        $this->assertSame('100.27', $r['totals']['assets']);
    }

    public function test_equation_difference_is_not_hidden_by_classification_success(): void
    {
        $r = $this->contract()->project([$this->account(1, 'asset', 'current_asset', '100.27'), $this->account(2, 'equity', 'equity', '0.00', '100.26')], [], $this->year());
        $this->assertFalse($r['complete']);
        $this->assertSame('0.01', $r['difference']);
    }

    public function test_large_aggregates_preserve_cents(): void
    {
        $r = $this->contract()->project([$this->account(1, 'asset', 'current_asset', '999999999999999999.99'), $this->account(2, 'asset', 'current_asset', '0.02'), $this->account(3, 'equity', 'equity', '0.00', '999999999999999999.99'), $this->account(4, 'equity', 'equity', '0.00', '0.02')], [], $this->year());
        $this->assertSame('1000000000000000000.01', $r['totals']['assets']);
        $this->assertTrue($r['complete']);
    }

    public function test_inconsistent_nominal_split_is_detected(): void
    {
        $row = $this->account(2, 'revenue', 'operating_revenue', '0.00', '100.27');
        $row['period_credit'] = '99.27';
        $r = $this->contract()->project([$this->account(1, 'asset', 'current_asset', '100.27'), $row], [], $this->year());
        $this->assertFalse($r['complete']);
        $this->assertSame('1.00', $r['earnings']['bridge_difference']);
    }
}
