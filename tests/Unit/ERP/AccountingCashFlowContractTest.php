<?php

declare(strict_types=1);

namespace Tests\Unit\ERP;

use App\Services\ERP\Accounting\AccountingAccountCatalog;
use App\Services\ERP\Accounting\AccountingDecimal;
use App\Services\ERP\Accounting\Reports\AccountingCashFlowContract;
use App\Services\ERP\Accounting\Reports\AccountingLedgerContract;
use App\Services\ERP\Accounting\Reports\AccountingProfitLossContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AccountingCashFlowContractTest extends TestCase
{
    private function contract(): AccountingCashFlowContract
    {
        return new AccountingCashFlowContract(new AccountingDecimal, new AccountingProfitLossContract(new AccountingAccountCatalog));
    }

    /** @return array<string, mixed> */
    private function account(int $id, string $category, string $statement, ?string $flow): array
    {
        return ['id' => $id, 'code' => 'CF'.$id, 'name' => 'Account '.$id, 'category' => $category, 'statement_role' => $statement, 'cash_flow_role' => $flow, 'control_role' => null, 'normal_side' => $category === 'asset' || $category === 'expense' ? 'debit' : 'credit', 'is_postable' => true, 'status' => 'active'];
    }

    /** @param list<array<string, mixed>> $accounts @param list<array<string, mixed>> $journals @return array<string, mixed> */
    private function source(array $accounts, array $journals): array
    {
        $all = [];
        $bindings = [];
        $opening = [];
        $period = [];
        foreach ($accounts as $a) {
            if ($a['cash_flow_role'] === 'cash') {
                $bindings[] = ['id' => $a['id'], 'account_id' => $a['id'], 'role' => 'cash:CF'.$a['id'], 'kind' => 'cash', 'currency' => 'BDT', 'status' => 'active'];
            }
        }
        foreach ($journals as $offset => $j) {
            foreach ($j['lines'] as $lineNumber => $l) {
                $line = ['journal_id' => $offset + 1, 'number' => 'J'.($offset + 1), 'date' => $j['date'] ?? '2026-10-26', 'entry_kind' => $j['kind'] ?? 'automatic', 'line_number' => $lineNumber + 1, 'account_id' => $l[0], 'debit' => $l[1], 'credit' => $l[2]];
                $all[] = $line;
                if ($line['date'] >= '2026-10-26') {
                    $period[] = $line;
                    if ($line['entry_kind'] === 'opening') {
                        $id = $line['account_id'];
                        $previous = $opening[$id] ?? ['debit' => '0.00', 'credit' => '0.00'];
                        $opening[$id] = ['debit' => bcadd($previous['debit'], $line['debit'], 2), 'credit' => bcadd($previous['credit'], $line['credit'], 2)];
                    }
                }
            }
        }
        $trial = (new AccountingLedgerContract(new AccountingDecimal))->trial($accounts, $all, '2026-10-26', '2026-10-26');

        return ['accounts' => $trial['accounts'], 'lines' => $period, 'bindings' => $bindings, 'opening' => $opening];
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    private function project(array $source): array
    {
        return $this->contract()->project($source['accounts'], $source['lines'], $source['bindings'], $source['opening'], 'BDT');
    }

    public function test_credit_sale_and_partial_receipt_reconcile_indirect_operating_cash(): void
    {
        $s = $this->source([$this->account(1, 'asset', 'current_asset', 'cash'), $this->account(2, 'asset', 'current_asset', 'operating'), $this->account(3, 'revenue', 'operating_revenue', 'operating')], [['lines' => [[2, '100.27', '0.00'], [3, '0.00', '100.27']]], ['lines' => [[1, '60.13', '0.00'], [2, '0.00', '60.13']]]]);
        $r = $this->project($s);
        $this->assertTrue($r['complete']);
        $this->assertSame('100.27', $r['indirect_bridge']['period_result']);
        $this->assertSame('-40.14', $r['indirect_bridge']['operating_balance_adjustments']);
        $this->assertSame('60.13', $r['confirmed_flows']['operating']);
        $this->assertSame('0.00', $r['reconciliation_difference']);
    }

    public function test_unpaid_expense_is_offset_by_payable_change(): void
    {
        $r = $this->project($this->source([$this->account(1, 'asset', 'current_asset', 'cash'), $this->account(2, 'liability', 'current_liability', 'operating'), $this->account(3, 'expense', 'operating_expense', 'operating')], [['lines' => [[3, '100.27', '0.00'], [2, '0.00', '100.27']]]]));
        $this->assertSame('-100.27', $r['indirect_bridge']['period_result']);
        $this->assertSame('100.27', $r['indirect_bridge']['operating_balance_adjustments']);
        $this->assertSame('0.00', $r['confirmed_flows']['operating']);
    }

    public function test_cash_expense_is_operating_outflow(): void
    {
        $r = $this->project($this->source([$this->account(1, 'asset', 'current_asset', 'cash'), $this->account(2, 'expense', 'operating_expense', 'operating')], [['lines' => [[2, '40.13', '0.00'], [1, '0.00', '40.13']]]]));
        $this->assertSame('-40.13', $r['confirmed_flows']['operating']);
    }

    public function test_capital_purchase_is_investing_not_operating(): void
    {
        $r = $this->project($this->source([$this->account(1, 'asset', 'current_asset', 'cash'), $this->account(2, 'asset', 'non_current_asset', 'investing')], [['lines' => [[2, '100.27', '0.00'], [1, '0.00', '100.27']]]]));
        $this->assertSame('-100.27', $r['confirmed_flows']['investing']);
        $this->assertSame('0.00', $r['confirmed_flows']['operating']);
    }

    public function test_loan_receipt_is_financing(): void
    {
        $r = $this->project($this->source([$this->account(1, 'asset', 'current_asset', 'cash'), $this->account(2, 'liability', 'non_current_liability', 'financing')], [['lines' => [[1, '100.27', '0.00'], [2, '0.00', '100.27']]]]));
        $this->assertSame('100.27', $r['confirmed_flows']['financing']);
    }

    public function test_noncash_debt_financed_asset_does_not_invent_cash_flows(): void
    {
        $r = $this->project($this->source([$this->account(1, 'asset', 'current_asset', 'cash'), $this->account(2, 'asset', 'non_current_asset', 'investing'), $this->account(3, 'liability', 'non_current_liability', 'financing')], [['lines' => [[2, '100.27', '0.00'], [3, '0.00', '100.27']]]]));
        $this->assertSame('0.00', $r['confirmed_flows']['investing']);
        $this->assertSame('0.00', $r['confirmed_flows']['financing']);
        $this->assertCount(1, $r['noncash_journals']);
    }

    public function test_mixed_debt_and_cash_purchase_requires_split_even_when_cash_reconciles(): void
    {
        $r = $this->project($this->source([$this->account(1, 'asset', 'current_asset', 'cash'), $this->account(2, 'asset', 'non_current_asset', 'investing'), $this->account(3, 'liability', 'non_current_liability', 'financing')], [['lines' => [[2, '100.27', '0.00'], [3, '0.00', '60.14'], [1, '0.00', '40.13']]]]));
        $this->assertSame('0.00', $r['reconciliation_difference']);
        $this->assertNull($r['confirmed_flows']);
        $this->assertContains('multi_activity_cash_journal_requires_native_split', array_column($r['issues'], 'issue'));
    }

    public function test_depreciation_is_added_back_in_indirect_bridge(): void
    {
        $r = $this->project($this->source([$this->account(1, 'asset', 'current_asset', 'cash'), $this->account(2, 'expense', 'operating_expense', 'noncash'), $this->account(3, 'asset', 'non_current_asset', 'noncash')], [['lines' => [[2, '10.27', '0.00'], [3, '0.00', '10.27']]]]));
        $this->assertSame('-10.27', $r['indirect_bridge']['period_result']);
        $this->assertSame('10.27', $r['indirect_bridge']['other_noncash_adjustments']);
        $this->assertSame('0.00', $r['confirmed_flows']['operating']);
    }

    public function test_disposal_loss_classified_investing_is_removed_from_operating_profit(): void
    {
        $r = $this->project($this->source([$this->account(1, 'asset', 'current_asset', 'cash'), $this->account(2, 'asset', 'non_current_asset', 'investing'), $this->account(3, 'expense', 'other_expense', 'investing')], [['lines' => [[1, '80.13', '0.00'], [2, '0.00', '100.27'], [3, '20.14', '0.00']]]]));
        $this->assertSame('20.14', $r['indirect_bridge']['earnings_reclassification']);
        $this->assertSame('0.00', $r['confirmed_flows']['operating']);
        $this->assertSame('80.13', $r['confirmed_flows']['investing']);
    }

    public function test_internal_cash_transfer_is_excluded_from_activity_totals(): void
    {
        $r = $this->project($this->source([$this->account(1, 'asset', 'current_asset', 'cash'), $this->account(2, 'asset', 'current_asset', 'cash')], [['lines' => [[1, '100.27', '0.00'], [2, '0.00', '100.27']]]]));
        $this->assertSame('0.00', $r['confirmed_flows']['net_change']);
        $this->assertSame(1, $r['cash_transfer_count']);
    }

    public function test_opening_cash_is_disclosed_without_becoming_financing_flow(): void
    {
        $r = $this->project($this->source([$this->account(1, 'asset', 'current_asset', 'cash'), $this->account(2, 'equity', 'equity', 'financing')], [['kind' => 'opening', 'lines' => [[1, '100.27', '0.00'], [2, '0.00', '100.27']]]]));
        $this->assertSame('100.27', $r['opening_cash_movements']);
        $this->assertSame('0.00', $r['provisional_flows']['financing']);
        $this->assertNull($r['confirmed_flows']);
    }

    public function test_unmapped_active_account_requires_review_despite_zero_net_cash(): void
    {
        $r = $this->project($this->source([$this->account(1, 'asset', 'current_asset', 'cash'), $this->account(2, 'asset', 'current_asset', null), $this->account(3, 'revenue', 'operating_revenue', 'operating')], [['lines' => [[2, '100.27', '0.00'], [3, '0.00', '100.27']]]]));
        $this->assertNull($r['confirmed_flows']);
        $this->assertContains('active_account_cash_flow_role_missing_or_invalid', array_column($r['issues'], 'issue'));
    }

    public function test_missing_cash_binding_does_not_certify_cash_scope(): void
    {
        $s = $this->source([$this->account(1, 'asset', 'current_asset', 'cash')], []);
        $s['bindings'] = [];
        $this->assertNull($this->project($s)['confirmed_flows']);
    }

    public function test_snapshot_cash_mismatch_requires_review(): void
    {
        $s = $this->source([$this->account(1, 'asset', 'current_asset', 'cash')], []);
        $s['accounts'][0]['closing_debit'] = '1.00';
        $r = $this->project($s);
        $this->assertSame('1.00', $r['snapshot_difference']);
        $this->assertFalse($r['complete']);
    }

    public function test_mixed_cash_noncash_net_is_not_silently_allocated(): void
    {
        $r = $this->project($this->source([$this->account(1, 'asset', 'current_asset', 'cash'), $this->account(2, 'liability', 'non_current_liability', 'financing'), $this->account(3, 'expense', 'other_expense', 'noncash')], [['lines' => [[1, '80.13', '0.00'], [2, '0.00', '100.27'], [3, '20.14', '0.00']]]]));
        $this->assertNull($r['confirmed_flows']);
        $this->assertSame('-20.14', $r['unallocated_cash_counterparts']);
    }

    public function test_large_totals_keep_exact_cents(): void
    {
        $r = $this->project($this->source([$this->account(1, 'asset', 'current_asset', 'cash'), $this->account(2, 'revenue', 'operating_revenue', 'operating')], [['lines' => [[1, '999999999999999999.99', '0.00'], [2, '0.00', '999999999999999999.99']]], ['lines' => [[1, '0.02', '0.00'], [2, '0.00', '0.02']]]]));
        $this->assertSame('1000000000000000000.01', $r['confirmed_flows']['operating']);
    }

    public function test_incomplete_journal_fails_instead_of_producing_a_projection(): void
    {
        $s = $this->source([$this->account(1, 'asset', 'current_asset', 'cash'), $this->account(2, 'revenue', 'operating_revenue', 'operating')], [['lines' => [[1, '100.27', '0.00'], [2, '0.00', '100.27']]]]);
        array_pop($s['lines']);
        $this->expectException(ValidationException::class);
        $this->project($s);
    }

    public function test_beginning_cash_excludes_prior_date_opening_from_current_flows(): void
    {
        $r = $this->project($this->source([$this->account(1, 'asset', 'current_asset', 'cash'), $this->account(2, 'equity', 'equity', 'financing'), $this->account(3, 'expense', 'operating_expense', 'operating')], [['date' => '2026-10-25', 'kind' => 'opening', 'lines' => [[1, '100.27', '0.00'], [2, '0.00', '100.27']]], ['lines' => [[3, '40.13', '0.00'], [1, '0.00', '40.13']]]]));
        $this->assertSame('100.27', $r['beginning_cash']);
        $this->assertSame('60.14', $r['closing_cash']);
        $this->assertSame('0.00', $r['opening_cash_movements']);
        $this->assertSame('-40.13', $r['confirmed_flows']['net_change']);
    }

    public function test_declared_cash_scope_without_activity_has_known_zero_flows(): void
    {
        $r = $this->project($this->source([$this->account(1, 'asset', 'current_asset', 'cash')], []));
        $this->assertTrue($r['complete']);
        $this->assertSame('0.00', $r['confirmed_flows']['net_change']);
    }
}
