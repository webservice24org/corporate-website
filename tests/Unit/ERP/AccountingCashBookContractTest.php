<?php

declare(strict_types=1);

namespace Tests\Unit\ERP;

use App\Services\ERP\Accounting\AccountingDecimal;
use App\Services\ERP\Accounting\Books\AccountingCashBookContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AccountingCashBookContractTest extends TestCase
{
    private function contract(): AccountingCashBookContract
    {
        return new AccountingCashBookContract(new AccountingDecimal);
    }

    /** @return array<string, mixed> */
    private function row(int $id, string $date, string $debit, string $credit, bool $verified = true): array
    {
        return ['company_id' => 1, 'binding_id' => 1, 'recorded_binding_id' => 1, 'journal_id' => $id, 'journal_line_id' => $id, 'date' => $date, 'currency' => 'BDT', 'debit' => $debit, 'credit' => $credit, 'verified' => $verified, 'number' => 'D-'.$id, 'source_kind' => 'customer_receipt', 'source_id' => $id, 'event' => 'received', 'source_url' => null, 'journal_url' => null];
    }

    public function test_opening_plus_period_debits_less_credits_equals_closing(): void
    {
        $r = $this->contract()->project([$this->row(1, '2026-10-16', '100.00', '0.00'), $this->row(2, '2026-10-17', '0.00', '30.00')], '2026-10-17', '2026-10-17');
        $this->assertSame(['opening' => '100.00', 'debits' => '0.00', 'credits' => '30.00', 'closing' => '70.00'], $r['posted_gl']);
        $this->assertSame($r['posted_gl'], $r['source_verified']);
    }

    public function test_negative_closing_is_preserved_without_clamping(): void
    {
        $r = $this->contract()->project([$this->row(1, '2026-10-16', '0.00', '95.00')], '2026-10-16', '2026-10-16');
        $this->assertSame('-95.00', $r['posted_gl']['closing']);
    }

    public function test_source_review_does_not_erase_the_posted_gl_movement(): void
    {
        $r = $this->contract()->project([$this->row(1, '2026-10-16', '0.00', '95.00', false)], '2026-10-16', '2026-10-16');
        $this->assertSame('-95.00', $r['posted_gl']['closing']);
        $this->assertSame('0.00', $r['source_verified']['closing']);
        $this->assertFalse($r['rows'][0]['verified']);
    }

    public function test_zero_net_does_not_hide_gross_unsupported_debits_and_credits(): void
    {
        $r = $this->contract()->project([$this->row(1, '2026-10-16', '10.00', '0.00', false), $this->row(2, '2026-10-16', '0.00', '10.00', false)], '2026-10-16', '2026-10-16');
        $this->assertSame('0.00', $r['difference']['closing']);
        $this->assertSame('10.00', $r['difference']['debits']);
        $this->assertSame('10.00', $r['difference']['credits']);
    }

    public function test_pagination_preserves_full_totals_and_prior_running_balance(): void
    {
        $a = $this->row(1, '2026-10-16', '100.00', '0.00');
        $b = $this->row(2, '2026-10-16', '0.00', '30.00');
        $r = $this->contract()->project([$b, $a], '2026-10-16', '2026-10-16', 2, 1);
        $this->assertSame('70.00', $r['rows'][0]['posted_balance']);
        $this->assertSame('100.00', $r['posted_gl']['debits']);
        $this->assertSame(2, $r['pagination']['total']);
    }

    public function test_future_activity_is_excluded_from_the_requested_window(): void
    {
        $r = $this->contract()->project([$this->row(1, '2026-10-17', '100.00', '0.00')], '2026-10-16', '2026-10-16');
        $this->assertSame('0.00', $r['posted_gl']['closing']);
        $this->assertCount(0, $r['rows']);
    }

    public function test_duplicate_line_is_rejected(): void
    {
        $a = $this->row(1, '2026-10-16', '1.00', '0.00');
        $this->expectException(ValidationException::class);
        $this->contract()->project([$a, $a], '2026-10-16', '2026-10-16');
    }

    public function test_company_binding_and_currency_cannot_be_mixed(): void
    {
        $a = $this->row(1, '2026-10-16', '1.00', '0.00');
        $b = [...$this->row(2, '2026-10-16', '1.00', '0.00'), 'binding_id' => 2];
        $this->expectException(ValidationException::class);
        $this->contract()->project([$a, $b], '2026-10-16', '2026-10-16');
    }

    public function test_both_positive_sides_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->contract()->project([$this->row(1, '2026-10-16', '1.00', '1.00')], '2026-10-16', '2026-10-16');
    }

    public function test_invalid_calendar_date_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->contract()->project([], '2026-02-30', '2026-03-01');
    }

    public function test_totals_can_exceed_individual_precision_without_floats(): void
    {
        $a = $this->row(1, '2026-10-16', '999999999999999999.99', '0.00');
        $b = $this->row(2, '2026-10-16', '999999999999999999.99', '0.00');
        $r = $this->contract()->project([$a, $b], '2026-10-16', '2026-10-16');
        $this->assertSame('1999999999999999999.98', $r['posted_gl']['closing']);
    }

    public function test_private_fields_are_not_projected(): void
    {
        $a = [...$this->row(1, '2026-10-16', '1.00', '0.00'), 'notes' => 'PRIVATE', 'payload_hash' => 'SECRET'];
        $r = $this->contract()->project([$a], '2026-10-16', '2026-10-16');
        $this->assertArrayNotHasKey('notes', $r['rows'][0]);
        $this->assertArrayNotHasKey('payload_hash', $r['rows'][0]);
    }
}
