<?php

declare(strict_types=1);

namespace Tests\Unit\ERP;

use App\Services\ERP\Accounting\Dashboard\AccountingDashboardContract;
use PHPUnit\Framework\TestCase;

final class AccountingDashboardContractTest extends TestCase
{
    /** @return array<string, mixed> */
    private function profit(): array
    {
        return ['sections' => [['role' => 'cogs', 'amount' => '1.01'], ['role' => 'operating_expense', 'amount' => '2.02'], ['role' => 'other_expense', 'amount' => '3.03'], ['role' => 'other_revenue', 'amount' => '7.00']], 'classification_complete' => true, 'net_revenue' => '10.00', 'provisional_result' => '10.94'];
    }

    /** @return array<string, mixed> */
    private function aging(): array
    {
        return ['complete' => true, 'totals' => ['open_documents' => '100.27', 'buckets' => ['current' => '10.00', 'days_1_30' => '20.01', 'days_31_60' => '20.02', 'days_61_90' => '20.03', 'days_91_120' => '20.04', 'over_120' => '5.05', 'unknown_due' => '5.12']]];
    }

    public function test_cost_and_expenses_include_cogs_and_other_expenses_exactly(): void
    {
        $cards = (new AccountingDashboardContract)->profit($this->profit(), '/profit');
        $this->assertSame('6.06', $cards[1]['value']);
    }

    public function test_result_is_copied_from_profit_report_without_recomputing_it(): void
    {
        $cards = (new AccountingDashboardContract)->profit($this->profit(), '/profit');
        $this->assertSame('10.94', $cards[2]['value']);
    }

    public function test_incomplete_classification_marks_all_profit_metrics_provisional(): void
    {
        $p = $this->profit();
        $p['classification_complete'] = false;
        foreach ((new AccountingDashboardContract)->profit($p, '/profit') as $card) {
            $this->assertSame('review', $card['status']);
        }
    }

    public function test_overdue_excludes_current_and_unknown_due(): void
    {
        $cards = (new AccountingDashboardContract)->aging($this->aging(), 'ar', '/ar');
        $this->assertSame('85.15', $cards[1]['value']);
        $this->assertSame('5.12', $cards[2]['value']);
    }

    public function test_open_documents_are_not_netted_with_credits(): void
    {
        $a = $this->aging();
        $a['totals']['document_credits'] = '40.00';
        $cards = (new AccountingDashboardContract)->aging($a, 'ap', '/ap');
        $this->assertSame('100.27', $cards[0]['value']);
    }

    public function test_aging_review_retains_known_amounts_with_provisional_status(): void
    {
        $a = $this->aging();
        $a['complete'] = false;
        $cards = (new AccountingDashboardContract)->aging($a, 'ap', '/ap');
        $this->assertSame('review', $cards[0]['status']);
        $this->assertSame('100.27', $cards[0]['value']);
    }

    public function test_large_exact_expense_sum_does_not_use_float_arithmetic(): void
    {
        $p = $this->profit();
        $p['sections'][0]['amount'] = '999999999999.99';
        $cards = (new AccountingDashboardContract)->profit($p, '/profit');
        $this->assertSame('1000000000005.04', $cards[1]['value']);
    }

    public function test_unknown_card_remains_null_and_count_is_not_currency(): void
    {
        $c = (new AccountingDashboardContract)->card('x', 'Unknown', null, 'current', 'review', '/report', 'Review', 'count');
        $this->assertNull($c['value']);
        $this->assertSame('count', $c['unit']);
    }
}
