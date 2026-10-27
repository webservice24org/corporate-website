<?php

declare(strict_types=1);

namespace Tests\Unit\ERP;

use App\Services\ERP\Accounting\AccountingDecimal;
use App\Services\ERP\Accounting\Reports\AccountingAgingContract;
use DateTimeImmutable;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AccountingAgingContractTest extends TestCase
{
    private function contract(): AccountingAgingContract
    {
        return new AccountingAgingContract(new AccountingDecimal);
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    private function doc(int $id = 1, array $changes = []): array
    {
        return ['id' => $id, 'company_id' => 1, 'party_id' => 1, 'currency' => 'BDT', 'number' => 'DOC-'.$id, 'recognized_on' => '2026-01-01', 'due_date' => '2026-10-01', 'due_basis' => 'admitted', 'amount' => '100.27', 'verified' => true, ...$changes];
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    private function event(int $id, array $changes = []): array
    {
        return ['id' => 'E'.$id, 'company_id' => 1, 'currency' => 'BDT', 'document_id' => 1, 'party_id' => 1, 'date' => '2026-10-01', 'sequence' => $id, 'direction' => 'decrease', 'amount' => '40.13', 'reverses_id' => null, ...$changes];
    }

    public function test_all_bucket_boundaries_use_due_date_and_calendar_days(): void
    {
        foreach ([0 => 'current', 1 => 'days_1_30', 30 => 'days_1_30', 31 => 'days_31_60', 60 => 'days_31_60', 61 => 'days_61_90', 90 => 'days_61_90', 91 => 'days_91_120', 120 => 'days_91_120', 121 => 'over_120'] as $days => $bucket) {
            $due = (new DateTimeImmutable('2026-10-26'))->modify('-'.$days.' days')->format('Y-m-d');
            $v = $this->contract()->project(1, 'BDT', [$this->doc(1, ['due_date' => $due])], [], '2026-10-26', '2026-10-26');
            $this->assertSame($bucket, $v['rows'][0]['bucket']);
            $this->assertSame('100.27', $v['totals']['buckets'][$bucket]);
        }
    }

    public function test_future_due_is_current_not_overdue(): void
    {
        $v = $this->contract()->project(1, 'BDT', [$this->doc(1, ['due_date' => '2026-10-27'])], [], '2026-10-26', '2026-10-26');
        $this->assertSame('current', $v['rows'][0]['bucket']);
        $this->assertSame(-1, $v['rows'][0]['days_overdue']);
    }

    public function test_future_allocation_does_not_reduce_past_outstanding(): void
    {
        $v = $this->contract()->project(1, 'BDT', [$this->doc()], [$this->event(1, ['date' => '2026-10-03'])], '2026-10-02', '2026-10-04');
        $this->assertSame('100.27', $v['rows'][0]['outstanding']);
    }

    public function test_future_compensation_does_not_reopen_past_settled_amount(): void
    {
        $events = [$this->event(1), $this->event(2, ['date' => '2026-10-03', 'direction' => 'increase', 'reverses_id' => 'E1'])];
        $past = $this->contract()->project(1, 'BDT', [$this->doc()], $events, '2026-10-02', '2026-10-04');
        $now = $this->contract()->project(1, 'BDT', [$this->doc()], $events, '2026-10-04', '2026-10-04');
        $this->assertSame('60.14', $past['rows'][0]['outstanding']);
        $this->assertSame('100.27', $now['rows'][0]['outstanding']);
    }

    public function test_double_compensation_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->contract()->project(1, 'BDT', [$this->doc()], [$this->event(1), $this->event(2, ['direction' => 'increase', 'reverses_id' => 'E1']), $this->event(3, ['direction' => 'increase', 'reverses_id' => 'E1'])], '2026-10-26', '2026-10-26');
    }

    public function test_foreign_document_cannot_be_merged(): void
    {
        $this->expectException(ValidationException::class);
        $this->contract()->project(1, 'BDT', [$this->doc(1, ['company_id' => 2])], [], '2026-10-26', '2026-10-26');
    }

    public function test_duplicate_event_identity_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->contract()->project(1, 'BDT', [$this->doc()], [$this->event(1), $this->event(1)], '2026-10-26', '2026-10-26');
    }

    public function test_document_credit_is_separate_from_positive_aging(): void
    {
        $v = $this->contract()->project(1, 'BDT', [$this->doc(), $this->doc(2)], [$this->event(1, ['amount' => '120.27'])], '2026-10-26', '2026-10-26');
        $this->assertSame('100.27', $v['totals']['open_documents']);
        $this->assertSame('20.00', $v['totals']['document_credits']);
        $this->assertSame('80.27', $v['totals']['signed_documents']);
    }

    public function test_unrecognized_legacy_document_is_unknown_not_zero(): void
    {
        $v = $this->contract()->project(1, 'BDT', [$this->doc(1, ['verified' => false, 'amount' => null])], [], '2026-10-26', '2026-10-26');
        $this->assertNull($v['rows'][0]['outstanding']);
        $this->assertNull($v['confirmed_totals']);
        $this->assertSame(1, $v['totals']['unknown_documents']);
    }

    public function test_missing_due_date_goes_to_unknown_due_without_document_date_fallback(): void
    {
        $v = $this->contract()->project(1, 'BDT', [$this->doc(1, ['due_date' => null])], [], '2026-10-26', '2026-10-26');
        $this->assertSame('unknown_due', $v['rows'][0]['bucket']);
        $this->assertFalse($v['complete']);
    }

    public function test_historical_current_terms_are_provisional(): void
    {
        $v = $this->contract()->project(1, 'BDT', [$this->doc(1, ['due_basis' => 'current_native_terms'])], [], '2026-10-25', '2026-10-26');
        $this->assertNull($v['confirmed_totals']);
        $this->assertContains('historical_due_terms_unverified', array_column($v['issues'], 'issue'));
    }

    public function test_current_day_native_terms_are_explicitly_usable(): void
    {
        $v = $this->contract()->project(1, 'BDT', [$this->doc(1, ['due_basis' => 'current_native_terms'])], [], '2026-10-26', '2026-10-26');
        $this->assertTrue($v['complete']);
    }

    public function test_zero_document_does_not_invent_a_receivable(): void
    {
        $v = $this->contract()->project(1, 'BDT', [$this->doc(1, ['amount' => '0.00'])], [], '2026-10-26', '2026-10-26');
        $this->assertSame('settled', $v['rows'][0]['balance_kind']);
        $this->assertSame('0.00', $v['totals']['open_documents']);
    }

    public function test_same_party_credit_transfer_moves_document_balances_without_changing_company_total(): void
    {
        $docs = [$this->doc(1, ['amount' => '60.00']), $this->doc(2, ['amount' => '100.00'])];
        $events = [$this->event(1, ['amount' => '60.00']), $this->event(2, ['amount' => '50.00']), $this->event(3, ['direction' => 'increase', 'amount' => '20.00']), $this->event(4, ['document_id' => 2, 'amount' => '20.00'])];
        $v = $this->contract()->project(1, 'BDT', $docs, $events, '2026-10-26', '2026-10-26');
        $this->assertSame('80.00', $v['totals']['open_documents']);
        $this->assertSame('30.00', $v['totals']['document_credits']);
        $this->assertSame('50.00', $v['totals']['signed_documents']);
    }

    public function test_future_document_is_not_in_historical_aging(): void
    {
        $v = $this->contract()->project(1, 'BDT', [$this->doc(1, ['recognized_on' => '2026-10-27'])], [], '2026-10-26', '2026-10-28');
        $this->assertCount(0, $v['rows']);
    }

    public function test_large_aggregates_keep_exact_cents(): void
    {
        $v = $this->contract()->project(1, 'BDT', [$this->doc(1, ['amount' => '999999999999999999.99']), $this->doc(2, ['amount' => '0.02'])], [], '2026-10-26', '2026-10-26');
        $this->assertSame('1000000000000000000.01', $v['totals']['open_documents']);
    }
}
