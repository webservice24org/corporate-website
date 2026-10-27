<?php

declare(strict_types=1);

namespace Tests\Unit\ERP;

use App\Services\ERP\Accounting\Reports\AccountingAgingGlBridge;
use Tests\TestCase;

final class AccountingAgingGlBridgeTest extends TestCase
{
    /** @param array<string, mixed> $change @return array<string, mixed> */
    private function line(array $change = []): array
    {
        return ['date' => '2026-10-26', 'journal_id' => 1, 'account_id' => 10, 'party_id' => 7, 'party_type' => 'customer', 'source_kind' => 'sales_invoice', 'source_id' => 5, 'role' => 'trade_ar', 'entry_kind' => 'automatic', 'debit' => '100.27', 'credit' => '0.00', ...$change];
    }

    public function test_exact_source_membership_reconciles(): void
    {
        $l = $this->line();
        $r = (new AccountingAgingGlBridge)->reconcile('ar', [$l], [$l], [10 => ['trade_ar' => true], 20 => ['unapplied_customer_funds' => true]], '2026-10-26', '100.27');
        $this->assertTrue($r['reconciled']);
    }

    public function test_same_total_under_wrong_source_does_not_reconcile(): void
    {
        $r = (new AccountingAgingGlBridge)->reconcile('ar', [$this->line()], [$this->line(['source_id' => 6])], [10 => ['trade_ar' => true], 20 => ['unapplied_customer_funds' => true]], '2026-10-26', '100.27');
        $this->assertSame('0.00', $r['difference']['trade_ar']);
        $this->assertFalse($r['reconciled']);
    }

    public function test_supplier_advance_is_separate_from_trade_ap(): void
    {
        $a = $this->line(['account_id' => 30, 'party_type' => 'supplier', 'role' => 'supplier_advances', 'source_kind' => 'supplier_payment']);
        $r = (new AccountingAgingGlBridge)->reconcile('ap', [$a], [$a], [30 => ['supplier_advances' => true], 40 => ['trade_ap' => true]], '2026-10-26', '0.00');
        $this->assertSame('100.27', $r['separate_unapplied_or_advance_balance']);
        $this->assertSame('0.00', $r['source']['trade_ap']);
        $this->assertTrue($r['reconciled']);
    }

    public function test_unassigned_control_line_requires_review(): void
    {
        $r = (new AccountingAgingGlBridge)->reconcile('ar', [], [$this->line(['party_id' => null, 'party_type' => null])], [10 => ['trade_ar' => true], 20 => ['unapplied_customer_funds' => true]], '2026-10-26', '0.00');
        $this->assertContains('unassigned_control_line', array_column($r['issues'], 'issue'));
    }

    public function test_opening_control_cannot_be_silently_matched_to_live_documents(): void
    {
        $r = (new AccountingAgingGlBridge)->reconcile('ar', [], [$this->line(['entry_kind' => 'opening'])], [10 => ['trade_ar' => true], 20 => ['unapplied_customer_funds' => true]], '2026-10-26', '0.00');
        $this->assertContains('opening_control_coverage_not_integrated', array_column($r['issues'], 'issue'));
    }
}
