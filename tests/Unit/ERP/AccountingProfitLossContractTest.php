<?php

declare(strict_types=1);

namespace Tests\Unit\ERP;

use App\Services\ERP\Accounting\AccountingAccountCatalog;
use App\Services\ERP\Accounting\Reports\AccountingProfitLossContract;
use Tests\TestCase;

final class AccountingProfitLossContractTest extends TestCase
{
    private function contract(): AccountingProfitLossContract
    {
        return new AccountingProfitLossContract(new AccountingAccountCatalog);
    }

    /** @return array<string, mixed> */
    private function account(int $id, string $category, string $role, string $debit = '0.00', string $credit = '0.00'): array
    {
        return ['id' => $id, 'code' => 'A'.$id, 'name' => 'Account '.$id, 'category' => $category, 'statement_role' => $role, 'period_debit' => $debit, 'period_credit' => $credit, 'is_postable' => true, 'status' => 'active'];
    }

    public function test_revenue_allowances_cogs_and_expenses_produce_signed_results(): void
    {
        $r = $this->contract()->project([
            $this->account(1, 'revenue', 'operating_revenue', '0.00', '1000.27'),
            $this->account(2, 'revenue', 'sales_allowances', '20.13'),
            $this->account(3, 'expense', 'cogs', '400.14'),
            $this->account(4, 'expense', 'operating_expense', '200.00'),
            $this->account(5, 'revenue', 'other_revenue', '0.00', '10.00'),
            $this->account(6, 'expense', 'other_expense', '5.00'),
        ]);
        $this->assertSame('980.14', $r['net_revenue']);
        $this->assertSame('580.00', $r['gross_profit']);
        $this->assertSame('380.00', $r['operating_result']);
        $this->assertSame('385.00', $r['period_result']);
    }

    public function test_balance_sheet_accounts_do_not_become_income(): void
    {
        $r = $this->contract()->project([$this->account(1, 'asset', 'current_asset', '100.27'), $this->account(2, 'liability', 'current_liability', '0.00', '100.27')]);
        $this->assertSame('0.00', $r['period_result']);
        $this->assertTrue($r['classification_complete']);
    }

    public function test_unknown_role_withholds_result_instead_of_silently_omitting_activity(): void
    {
        $r = $this->contract()->project([$this->account(1, 'expense', 'unknown', '100.27')]);
        $this->assertNull($r['period_result']);
        $this->assertFalse($r['classification_complete']);
        $this->assertSame('100.27', $r['issues'][0]['period_debit']);
    }

    public function test_role_in_wrong_group_requires_classification_review(): void
    {
        $r = $this->contract()->project([$this->account(1, 'asset', 'operating_revenue', '0.00', '100.27')]);
        $this->assertNull($r['period_result']);
        $this->assertCount(1, $r['issues']);
    }

    public function test_missing_group_requires_review_even_for_zero_account(): void
    {
        $r = $this->contract()->project([$this->account(1, '', 'operating_expense')]);
        $this->assertFalse($r['classification_complete']);
    }

    public function test_nominal_opening_is_excluded_and_needs_cutover_review(): void
    {
        $r = $this->contract()->project([$this->account(1, 'expense', 'operating_expense', '125.27')], [1 => ['debit' => '100.00', 'credit' => '0.00']]);
        $this->assertSame('-25.27', $r['provisional_result']);
        $this->assertNull($r['period_result']);
        $this->assertSame('100.00', $r['excluded_nominal_opening_debit']);
    }

    public function test_inactive_account_activity_remains_in_result(): void
    {
        $r = $this->contract()->project([[...$this->account(1, 'expense', 'operating_expense', '100.27'), 'status' => 'inactive']]);
        $this->assertSame('-100.27', $r['period_result']);
        $this->assertSame('inactive', $r['sections'][3]['accounts'][0]['status']);
    }

    public function test_nonpostable_account_activity_withholds_confirmed_result(): void
    {
        $r = $this->contract()->project([[...$this->account(1, 'expense', 'operating_expense', '100.27'), 'is_postable' => false]]);
        $this->assertNull($r['period_result']);
        $this->assertSame('-100.27', $r['provisional_result']);
    }

    public function test_reversed_expense_retains_negative_section_amount(): void
    {
        $r = $this->contract()->project([$this->account(1, 'expense', 'operating_expense', '0.00', '100.27')]);
        $this->assertSame('100.27', $r['period_result']);
        $this->assertCount(1, $r['warnings']);
    }

    public function test_allowance_compensation_increases_net_revenue(): void
    {
        $r = $this->contract()->project([$this->account(1, 'revenue', 'sales_allowances', '0.00', '10.27')]);
        $this->assertSame('10.27', $r['net_revenue']);
    }

    public function test_large_aggregate_keeps_exact_cents(): void
    {
        $r = $this->contract()->project([$this->account(1, 'revenue', 'operating_revenue', '0.00', '999999999999999999.99'), $this->account(2, 'revenue', 'operating_revenue', '0.00', '0.02')]);
        $this->assertSame('1000000000000000000.01', $r['period_result']);
    }

    public function test_empty_classified_history_has_zero_result(): void
    {
        $r = $this->contract()->project([]);
        $this->assertSame('0.00', $r['period_result']);
        $this->assertCount(6, $r['sections']);
    }
}
