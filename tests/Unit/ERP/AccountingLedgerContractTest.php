<?php

declare(strict_types=1);

namespace Tests\Unit\ERP;

use App\Services\ERP\Accounting\AccountingDecimal;
use App\Services\ERP\Accounting\Reports\AccountingLedgerContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AccountingLedgerContractTest extends TestCase
{
    private function contract(): AccountingLedgerContract
    {
        return new AccountingLedgerContract(new AccountingDecimal);
    }

    /** @return array<string, mixed> */
    private function line(string $date, string $debit, string $credit, int $account = 1): array
    {
        return ['date' => $date, 'debit' => $debit, 'credit' => $credit, 'account_id' => $account];
    }

    public function test_opening_period_and_closing_use_inclusive_date_boundaries(): void
    {
        $r = $this->contract()->ledger([$this->line('2026-09-30', '100.27', '0.00'), $this->line('2026-10-01', '0.00', '20.13'), $this->line('2026-10-31', '0.00', '10.14'), $this->line('2026-11-01', '999.00', '0.00')], '2026-10-01', '2026-10-31');
        $this->assertSame('100.27', $r['opening']);
        $this->assertSame('30.27', $r['period_credit']);
        $this->assertSame('70.00', $r['closing']);
        $this->assertCount(2, $r['data']);
    }

    public function test_second_page_keeps_full_running_balance_and_totals(): void
    {
        $r = $this->contract()->ledger([$this->line('2026-10-01', '10.27', '0.00'), $this->line('2026-10-02', '0.00', '3.13')], '2026-10-01', '2026-10-31', 2, 1);
        $this->assertSame('7.14', $r['data'][0]['balance']);
        $this->assertSame('10.27', $r['period_debit']);
        $this->assertSame(2, $r['page']['total']);
    }

    public function test_credit_balance_is_not_changed_by_display_normal_side(): void
    {
        $r = $this->contract()->ledger([$this->line('2026-10-01', '0.00', '42.27')], '2026-10-01', '2026-10-31');
        $this->assertSame('-42.27', $r['closing']);
        $this->assertSame(['debit' => '0.00', 'credit' => '42.27'], $r['closing_sides']);
    }

    public function test_accumulation_preserves_large_exact_cents_beyond_one_line_limit(): void
    {
        $r = $this->contract()->ledger([$this->line('2026-10-01', '999999999999999999.99', '0.00'), $this->line('2026-10-02', '0.02', '0.00')], '2026-10-01', '2026-10-31');
        $this->assertSame('1000000000000000000.01', $r['closing']);
    }

    public function test_trial_balance_includes_inactive_and_zero_accounts_without_parent_rollup(): void
    {
        $r = $this->contract()->trial([['id' => 1, 'status' => 'inactive'], ['id' => 2], ['id' => 3]], [$this->line('2026-10-01', '100.27', '0.00'), $this->line('2026-10-01', '0.00', '100.27', 2)], '2026-10-01', '2026-10-31');
        $this->assertTrue($r['balanced']);
        $this->assertCount(3, $r['accounts']);
        $this->assertSame('100.27', $r['totals']['closing_debit']);
        $this->assertSame('0.00', $r['accounts'][2]['closing_credit']);
    }

    public function test_trial_balance_reports_opening_and_period_differences_separately(): void
    {
        $r = $this->contract()->trial([['id' => 1], ['id' => 2]], [$this->line('2026-09-30', '20.27', '0.00'), $this->line('2026-10-01', '0.00', '20.27', 2)], '2026-10-01', '2026-10-31');
        $this->assertFalse($r['balanced']);
        $this->assertSame('20.27', $r['differences']['opening']);
        $this->assertSame('-20.27', $r['differences']['period']);
        $this->assertSame('0.00', $r['differences']['closing']);
    }

    public function test_unknown_account_cannot_disappear_from_totals(): void
    {
        $this->expectException(ValidationException::class);
        $this->contract()->trial([['id' => 1]], [$this->line('2026-10-01', '0.00', '1.00', 99)], '2026-10-01', '2026-10-31');
    }

    public function test_two_positive_sides_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->contract()->ledger([$this->line('2026-10-01', '1.00', '1.00')], '2026-10-01', '2026-10-31');
    }

    public function test_float_input_is_not_coerced_to_money(): void
    {
        $this->expectException(ValidationException::class);
        $this->contract()->ledger([['date' => '2026-10-01', 'debit' => 1.27, 'credit' => '0.00', 'account_id' => 1]], '2026-10-01', '2026-10-31');
    }

    public function test_empty_history_has_known_zero_gl_balances(): void
    {
        $r = $this->contract()->ledger([], '2026-10-01', '2026-10-31');
        $this->assertSame('0.00', $r['opening']);
        $this->assertSame('0.00', $r['closing']);
        $this->assertSame(0, $r['page']['total']);
    }
}
