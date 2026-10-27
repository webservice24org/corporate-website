<?php

declare(strict_types=1);

namespace Tests\Unit\ERP;

use App\Services\ERP\Accounting\AccountingDecimal;
use App\Services\ERP\Accounting\Reports\AccountingSupplierStatementContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AccountingSupplierStatementContractTest extends TestCase
{
    private function contract(): AccountingSupplierStatementContract
    {
        return new AccountingSupplierStatementContract(new AccountingDecimal);
    }

    /** @return array<string, mixed> */
    private function event(int $id, string $type, string $amount, string $date = '2026-10-16'): array
    {
        $kind = match ($type) {
            'bill_approved' => 'supplier_bill', 'credit_issued' => 'supplier_credit', 'cash_paid', 'cash_reversed' => 'supplier_payment', default => 'supplier_payment_allocation'
        };

        return ['company_id' => 1, 'supplier_id' => 1, 'currency' => 'BDT', 'sequence' => $id, 'source_kind' => $kind, 'source_id' => $id, 'bill_id' => $kind === 'supplier_bill' ? $id : (in_array($kind, ['supplier_credit', 'supplier_payment_allocation'], true) ? 1 : null), 'payment_id' => in_array($kind, ['supplier_payment', 'supplier_payment_allocation'], true) ? 2 : null, 'date' => $date, 'event' => $type, 'number' => 'SAFE-'.$id, 'amount' => $amount, 'source_url' => null, 'journal_url' => null];
    }

    public function test_bill_increases_payable(): void
    {
        $this->assertSame('95.00', $this->contract()->effect('bill_approved', '95.00')['ap']);
    }

    public function test_credit_reduces_payable(): void
    {
        $this->assertSame('-9.50', $this->contract()->effect('credit_issued', '9.50')['ap']);
    }

    public function test_cash_creates_an_advance_and_reduces_net_debt(): void
    {
        $r = $this->contract()->effect('cash_paid', '100.00');
        $this->assertSame('100.00', $r['advance']);
        $this->assertSame('-100.00', $r['net']);
        $this->assertSame('100.00', $r['cash_paid']);
    }

    public function test_allocation_transfers_controls_without_cash_or_net_change(): void
    {
        $r = $this->contract()->effect('allocated', '30.00');
        $this->assertSame('-30.00', $r['ap']);
        $this->assertSame('-30.00', $r['advance']);
        $this->assertSame('0.00', $r['net']);
        $this->assertSame('0.00', $r['cash_paid']);
    }

    public function test_compensation_restores_both_controls_without_cash(): void
    {
        $r = $this->contract()->effect('allocation_compensated', '30.00');
        $this->assertSame('30.00', $r['ap']);
        $this->assertSame('30.00', $r['advance']);
        $this->assertSame('0.00', $r['cash_reversed']);
    }

    public function test_refund_reduces_advance_once(): void
    {
        $r = $this->contract()->effect('cash_reversed', '100.00');
        $this->assertSame('-100.00', $r['advance']);
        $this->assertSame('100.00', $r['cash_reversed']);
    }

    public function test_dated_opening_activity_and_closing_bridge(): void
    {
        $events = [$this->event(1, 'bill_approved', '95.00'), $this->event(2, 'cash_paid', '100.00'), $this->event(3, 'allocated', '30.00', '2026-10-17')];
        $r = $this->contract()->project($events, '2026-10-17', '2026-10-17');
        $this->assertSame('95.00', $r['opening']['ap']);
        $this->assertSame('100.00', $r['opening']['advance']);
        $this->assertSame('65.00', $r['closing']['ap']);
        $this->assertSame('70.00', $r['closing']['advance']);
        $this->assertSame('-5.00', $r['closing']['net']);
    }

    public function test_pagination_does_not_change_totals_or_running_carry(): void
    {
        $events = [$this->event(1, 'bill_approved', '95.00'), $this->event(2, 'cash_paid', '100.00'), $this->event(3, 'allocated', '30.00')];
        $a = $this->contract()->project($events, '2026-10-16', '2026-10-16', 1, 1);
        $b = $this->contract()->project($events, '2026-10-16', '2026-10-16', 3, 1);
        $this->assertSame($a['closing'], $b['closing']);
        $this->assertSame(3, $b['pagination']['total']);
        $this->assertSame('65.00', $b['rows'][0]['ap_balance']);
    }

    public function test_refund_does_not_erase_original_rows(): void
    {
        $e = [$this->event(1, 'bill_approved', '95.00'), $this->event(2, 'cash_paid', '95.00'), $this->event(3, 'allocated', '95.00'), $this->event(4, 'allocation_compensated', '95.00', '2026-10-17'), $this->event(5, 'cash_reversed', '95.00', '2026-10-17')];
        $r = $this->contract()->project($e, '2026-10-16', '2026-10-17');
        $this->assertCount(5, $r['rows']);
        $this->assertSame('95.00', $r['closing']['ap']);
        $this->assertSame('0.00', $r['closing']['advance']);
        $this->assertSame('0.00', $this->contract()->position($e, '2026-10-16')['ap']);
    }

    public function test_order_is_accounting_date_then_immutable_sequence(): void
    {
        $e = [$this->event(2, 'cash_paid', '20.00', '2026-10-17'), $this->event(1, 'bill_approved', '95.00')];
        $this->assertSame([1, 2], array_column($this->contract()->ordered($e), 'sequence'));
    }

    public function test_duplicate_source_is_rejected(): void
    {
        $e = $this->event(1, 'bill_approved', '1.00');
        $copy = [...$e, 'sequence' => 2];
        $this->expectException(ValidationException::class);
        $this->contract()->ordered([$e, $copy]);
    }

    public function test_duplicate_sequence_is_rejected(): void
    {
        $a = $this->event(1, 'bill_approved', '1.00');
        $b = [...$this->event(2, 'cash_paid', '1.00'), 'sequence' => 1];
        $this->expectException(ValidationException::class);
        $this->contract()->ordered([$a, $b]);
    }

    public function test_mixed_currency_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->contract()->ordered([$this->event(1, 'bill_approved', '1.00'), [...$this->event(2, 'cash_paid', '1.00'), 'currency' => 'USD']]);
    }

    public function test_mixed_company_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->contract()->ordered([$this->event(1, 'bill_approved', '1.00'), [...$this->event(2, 'cash_paid', '1.00'), 'company_id' => 2]]);
    }

    public function test_statement_cannot_mix_suppliers(): void
    {
        $this->expectException(ValidationException::class);
        $this->contract()->project([$this->event(1, 'bill_approved', '1.00'), [...$this->event(2, 'cash_paid', '1.00'), 'supplier_id' => 2]], '2026-10-16', '2026-10-16');
    }

    public function test_event_kind_must_match_native_identity(): void
    {
        $this->expectException(ValidationException::class);
        $this->contract()->ordered([[...$this->event(1, 'bill_approved', '1.00'), 'source_kind' => 'supplier_credit']]);
    }

    public function test_float_money_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->contract()->effect('cash_paid', 0.1);
    }

    public function test_invalid_calendar_date_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->contract()->position([], '2026-02-30');
    }

    public function test_empty_result_has_explicit_zero_balances(): void
    {
        $r = $this->contract()->project([], '2026-10-16', '2026-10-16');
        $this->assertSame('0.00', $r['closing']['net']);
        $this->assertSame(0, $r['pagination']['total']);
    }

    public function test_internal_evidence_and_private_fields_are_not_projected(): void
    {
        $e = [...$this->event(1, 'bill_approved', '95.00'), 'notes' => 'PRIVATE', 'control_lines' => [], 'payload_hash' => 'SECRET'];
        $r = $this->contract()->project([$e], '2026-10-16', '2026-10-16');
        $this->assertArrayNotHasKey('notes', $r['rows'][0]);
        $this->assertArrayNotHasKey('control_lines', $r['rows'][0]);
        $this->assertArrayNotHasKey('payload_hash', $r['rows'][0]);
    }

    public function test_aggregates_can_exceed_individual_amount_precision_without_float(): void
    {
        $a = $this->event(1, 'bill_approved', '999999999999999999.99');
        $b = $this->event(2, 'bill_approved', '999999999999999999.99');
        $this->assertSame('1999999999999999999.98', $this->contract()->position([$a, $b], '2026-10-16')['ap']);
    }
}
