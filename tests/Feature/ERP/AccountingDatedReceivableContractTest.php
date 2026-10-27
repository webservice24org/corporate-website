<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Services\ERP\Accounting\Source\Sales\AccountingDatedReceivableContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Pure domain tests: no synthetic activation, database writes or seeders. */
final class AccountingDatedReceivableContractTest extends TestCase
{
    /** @return array<string, mixed> */
    private function invoice(): array
    {
        return ['company_id' => 1, 'invoice_id' => 10, 'customer_id' => 20, 'currency' => 'BDT', 'document_date' => '2026-10-15', 'issued_at' => '2026-10-15T12:00:00+06:00', 'gross' => '100.00', 'payload_hash' => str_repeat('a', 64)];
    }

    /** @return array<string, mixed> */
    private function event(int $id = 1, string $amount = '30.00', string $time = '2026-10-16T12:00:00+06:00', ?int $reverse = null, int $receipt = 50): array
    {
        return ['id' => $id, 'invoice_id' => 10, 'company_id' => 1, 'customer_id' => 20, 'currency' => 'BDT', 'amount' => $amount, 'posted_at' => $time, 'reverses_id' => $reverse, 'payload_hash' => str_repeat('b', 64), 'receipt' => ['id' => $receipt, 'company_id' => 1, 'customer_id' => 20, 'currency' => 'BDT', 'document_date' => '2026-10-15', 'posted_at' => '2026-10-15T13:00:00+06:00', 'amount' => '100.00', 'payload_hash' => str_repeat('c', 64)]];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @param  array<string, mixed>|null  $invoice
     * @return array<string, mixed>
     */
    private function project(array $events = [], ?array $invoice = null, string $cutover = '2026-10-17', string $zone = 'Asia/Dhaka'): array
    {
        return app(AccountingDatedReceivableContract::class)->project($invoice ?? $this->invoice(), $events, $cutover, $zone);
    }

    private function invalid(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected rejected dated-history evidence.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    public function test_unpaid_projection_is_preparation_only(): void
    {
        $p = $this->project();
        $this->assertSame('100.00', $p['snapshot']['opening_ar']);
        $this->assertSame('unverified_preparation', $p['snapshot']['recognition_state']);
        $this->assertSame('legacy-ar-dated-preparation.v1', $p['snapshot']['contract']);
    }

    public function test_partial_allocation_reduces_opening_ar(): void
    {
        $p = $this->project([$this->event()]);
        $this->assertSame('70.00', $p['snapshot']['opening_ar']);
        $this->assertSame('30.00', $p['snapshot']['settled_at_cutover']);
    }

    public function test_exact_compensation_before_cutover_restores_ar(): void
    {
        $p = $this->project([$this->event(), $this->event(2, '30.00', '2026-10-16T13:00:00+06:00', 1)]);
        $this->assertSame('100.00', $p['snapshot']['opening_ar']);
        $this->assertCount(2, $p['snapshot']['history']);
    }

    public function test_reallocation_preserves_all_history(): void
    {
        $p = $this->project([$this->event(), $this->event(2, '30.00', '2026-10-16T13:00:00+06:00', 1), $this->event(3, '45.00', '2026-10-16T14:00:00+06:00')]);
        $this->assertSame('55.00', $p['snapshot']['opening_ar']);
        $this->assertCount(3, $p['snapshot']['history']);
    }

    public function test_fully_settled_invoice_has_zero_opening_ar(): void
    {
        $this->assertSame('0.00', $this->project([$this->event(1, '100.00')])['snapshot']['opening_ar']);
    }

    public function test_cutover_midnight_is_exclusive(): void
    {
        $p = $this->project([$this->event(1, '30.00', '2026-10-17T00:00:00+06:00')]);
        $this->assertSame('100.00', $p['snapshot']['opening_ar']);
        $this->assertSame([], $p['snapshot']['history']);
        $this->assertSame('2026-10-16T18:00:00+00:00', $p['snapshot']['boundary_utc']);
    }

    public function test_one_second_before_boundary_is_included(): void
    {
        $this->assertSame('70.00', $this->project([$this->event(1, '30.00', '2026-10-16T17:59:59Z')])['snapshot']['opening_ar']);
    }

    public function test_later_compensation_does_not_rewrite_opening_history(): void
    {
        $before = $this->project([$this->event()]);
        $after = $this->project([$this->event(), $this->event(2, '30.00', '2026-10-18T12:00:00+06:00', 1)]);
        $this->assertSame($before, $after);
    }

    public function test_later_new_allocation_does_not_rewrite_opening_hash(): void
    {
        $this->assertSame($this->project([$this->event()]), $this->project([$this->event(), $this->event(2, '20.00', '2026-10-18T12:00:00+06:00')]));
    }

    public function test_input_order_does_not_change_canonical_chronology(): void
    {
        $a = $this->event();
        $b = $this->event(2, '20.00', '2026-10-16T13:00:00+06:00');
        $this->assertSame($this->project([$a, $b]), $this->project([$b, $a]));
    }

    public function test_json_object_key_order_and_equivalent_offsets_are_stable(): void
    {
        $a = $this->event();
        $b = array_reverse($a, true);
        $b['posted_at'] = '2026-10-16T06:00:00Z';
        $b['receipt'] = array_reverse($a['receipt'], true);
        $this->assertSame($this->project([$a]), $this->project([$b]));
    }

    public function test_same_second_native_identity_orders_original_before_compensation(): void
    {
        $this->assertSame('100.00', $this->project([$this->event(2, '30.00', '2026-10-16T12:00:00+06:00', 1), $this->event()])['snapshot']['opening_ar']);
    }

    public function test_compensation_cannot_precede_original(): void
    {
        $this->invalid(fn () => $this->project([$this->event(), $this->event(2, '30.00', '2026-10-16T11:00:00+06:00', 1)]));
    }

    public function test_missing_original_is_rejected(): void
    {
        $this->invalid(fn () => $this->project([$this->event(2, '30.00', '2026-10-16T13:00:00+06:00', 1)]));
    }

    public function test_double_compensation_is_rejected(): void
    {
        $this->invalid(fn () => $this->project([$this->event(), $this->event(2, '30.00', '2026-10-16T13:00:00+06:00', 1), $this->event(3, '30.00', '2026-10-16T14:00:00+06:00', 1)]));
    }

    public function test_compensation_cannot_itself_be_reversed(): void
    {
        $this->invalid(fn () => $this->project([$this->event(), $this->event(2, '30.00', '2026-10-16T13:00:00+06:00', 1), $this->event(3, '30.00', '2026-10-16T14:00:00+06:00', 2)]));
    }

    public function test_compensation_amount_must_match(): void
    {
        $this->invalid(fn () => $this->project([$this->event(), $this->event(2, '20.00', '2026-10-16T13:00:00+06:00', 1)]));
    }

    public function test_compensation_receipt_must_match(): void
    {
        $this->invalid(fn () => $this->project([$this->event(), $this->event(2, '30.00', '2026-10-16T13:00:00+06:00', 1, 51)]));
    }

    public function test_duplicate_event_identity_is_rejected(): void
    {
        $this->invalid(fn () => $this->project([$this->event(), $this->event()]));
    }

    public function test_cross_company_customer_currency_and_invoice_are_rejected(): void
    {
        foreach (['company_id' => 2, 'customer_id' => 21, 'currency' => 'USD', 'invoice_id' => 11] as $field => $value) {
            $e = $this->event();
            $e[$field] = $value;
            $this->invalid(fn () => $this->project([$e]));
        }
    }

    public function test_receipt_ownership_and_currency_are_checked(): void
    {
        foreach (['company_id' => 2, 'customer_id' => 21, 'currency' => 'USD'] as $field => $value) {
            $e = $this->event();
            $e['receipt'][$field] = $value;
            $this->invalid(fn () => $this->project([$e]));
        }
    }

    public function test_receipt_identity_cannot_have_conflicting_evidence(): void
    {
        $e = $this->event(2, '20.00');
        $e['receipt']['payload_hash'] = str_repeat('d', 64);
        $this->invalid(fn () => $this->project([$this->event(), $e]));
    }

    public function test_overpayment_is_rejected_at_each_historical_point(): void
    {
        $this->invalid(fn () => $this->project([$this->event(1, '80.00'), $this->event(2, '30.00', '2026-10-16T13:00:00+06:00', null, 51), $this->event(3, '80.00', '2026-10-16T14:00:00+06:00', 1)]));
    }

    public function test_single_receipt_cannot_be_overallocated_even_on_large_invoice(): void
    {
        $i = $this->invoice();
        $i['gross'] = '200.00';
        $this->invalid(fn () => $this->project([$this->event(1, '80.00'), $this->event(2, '30.00')], $i));
    }

    public function test_compensated_receipt_funds_can_be_reused_in_dated_model(): void
    {
        $this->assertSame('0.00', $this->project([$this->event(1, '100.00'), $this->event(2, '100.00', '2026-10-16T13:00:00+06:00', 1), $this->event(3, '100.00', '2026-10-16T14:00:00+06:00')])['snapshot']['opening_ar']);
    }

    public function test_zero_negative_float_and_excess_precision_are_rejected(): void
    {
        foreach (['0.00', '-1.00', 1.1, '1.001', '1e2'] as $value) {
            $e = $this->event();
            $e['amount'] = $value;
            $this->invalid(fn () => $this->project([$e]));
        }
    }

    public function test_large_exact_amount_does_not_use_floats(): void
    {
        $i = $this->invoice();
        $i['gross'] = '999999999999999999.99';
        $e = $this->event(1, '999999999999999999.98');
        $e['receipt']['amount'] = $i['gross'];
        $this->assertSame('0.01', $this->project([$e], $i)['snapshot']['opening_ar']);
    }

    public function test_invalid_dates_and_unzoned_instants_are_rejected(): void
    {
        foreach (['2026-02-30T12:00:00+06:00', '2026-10-16 12:00:00', '2026-10-16T25:00:00Z', '2026-10-16T12:00:60Z', '2026-10-16T12:00:00+14:30'] as $value) {
            $e = $this->event();
            $e['posted_at'] = $value;
            $this->invalid(fn () => $this->project([$e]));
        }
        $this->invalid(fn () => $this->project([], null, '2026-02-30'));
    }

    public function test_invalid_timezone_is_rejected(): void
    {
        $this->invalid(fn () => $this->project([], null, '2026-10-17', 'Unknown/Zone'));
    }

    public function test_named_dst_timezone_uses_local_midnight(): void
    {
        $i = $this->invoice();
        $i['document_date'] = '2026-03-01';
        $i['issued_at'] = '2026-03-01T12:00:00-05:00';
        $p = $this->project([], $i, '2026-03-09', 'America/New_York');
        $this->assertSame('2026-03-09T04:00:00+00:00', $p['snapshot']['boundary_utc']);
    }

    public function test_skipped_local_cutover_day_is_rejected(): void
    {
        $i = $this->invoice();
        $i['document_date'] = '2011-12-01';
        $i['issued_at'] = '2011-12-01T12:00:00-10:00';
        $this->invalid(fn () => $this->project([], $i, '2011-12-30', 'Pacific/Apia'));
    }

    public function test_invoice_issued_after_cutover_cannot_use_backdated_document_date(): void
    {
        $i = $this->invoice();
        $i['issued_at'] = '2026-10-17T00:00:00+06:00';
        $this->invalid(fn () => $this->project([], $i));
    }

    public function test_allocation_cannot_precede_invoice_or_cash_posting(): void
    {
        $this->invalid(fn () => $this->project([$this->event(1, '30.00', '2026-10-15T11:00:00+06:00')]));
        $e = $this->event();
        $e['receipt']['posted_at'] = '2026-10-16T13:00:00+06:00';
        $this->invalid(fn () => $this->project([$e]));
    }

    public function test_receipt_cannot_be_posted_before_its_document_date(): void
    {
        $e = $this->event();
        $e['receipt']['document_date'] = '2026-10-16';
        $this->invalid(fn () => $this->project([$e]));
    }

    public function test_missing_digests_and_non_integer_ids_are_rejected(): void
    {
        $e = $this->event();
        $e['payload_hash'] = '';
        $this->invalid(fn () => $this->project([$e]));
        $e = $this->event();
        $e['id'] = '1';
        $this->invalid(fn () => $this->project([$e]));
    }

    public function test_private_and_unsupported_fields_cannot_enter_contract(): void
    {
        foreach (['notes' => 'PRIVATE', 'kind' => 'credit_note', 'recognized' => true] as $key => $value) {
            $e = $this->event();
            $e[$key] = $value;
            $this->invalid(fn () => $this->project([$e]));
        }
        $json = json_encode($this->project([$this->event()]), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('idempotency_key', $json);
        $this->assertStringNotContainsString('snapshot', json_encode($this->project()['snapshot']['invoice'], JSON_THROW_ON_ERROR));
    }

    public function test_history_limit_and_non_list_inputs_are_rejected(): void
    {
        $this->invalid(fn () => $this->project(array_fill(0, 1001, $this->event())));
        $this->invalid(fn () => app(AccountingDatedReceivableContract::class)->project($this->invoice(), [2 => $this->event()], '2026-10-17', 'Asia/Dhaka'));
    }

    public function test_project_is_repeatable_and_does_not_mutate_inputs(): void
    {
        $i = $this->invoice();
        $events = [$this->event()];
        $copy = $events;
        $this->assertSame($this->project($events, $i), $this->project($events, $i));
        $this->assertSame($copy, $events);
        $this->assertSame($this->invoice(), $i);
    }
}
