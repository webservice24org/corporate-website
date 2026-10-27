<?php

declare(strict_types=1);

namespace Tests\Unit\ERP;

use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingDecimal;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AccountingDecimalContractTest extends TestCase
{
    public function test_money_normalizes_without_float_conversion(): void
    {
        $m = new AccountingDecimal;
        $this->assertSame('0.00', $m->money('0'));
        $this->assertSame('123.40', $m->money('000123.4'));
        $this->assertSame('42.00', $m->money(42));
        $this->assertSame('999999999999999999.99', $m->money('999999999999999999.99'));
    }

    public function test_money_rejects_ambiguous_or_overflow_input(): void
    {
        foreach ([1.25, -1, '-1', '+1', '1e3', '1,000', ' 1', '1 ', '.5', '1.', '0.001', '1000000000000000000', true, null, [], 'NaN'] as $value) {
            try {
                (new AccountingDecimal)->money($value);
                $this->fail('Expected invalid exact money rejection.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('amount', $e->errors());
            }
        }
    }

    public function test_source_precision_and_half_up_rounding(): void
    {
        $m = new AccountingDecimal;
        $this->assertSame('1.0050', $m->source('1.005'));
        $this->assertSame('1.01', $m->roundedCost('1.0050'));
        $this->assertSame('1.00', $m->roundedCost('1.0049'));
        $this->assertSame('0.00', $m->roundedCost('0.0000'));
    }

    public function test_source_rejects_excess_precision(): void
    {
        $this->expectException(ValidationException::class);
        (new AccountingDecimal)->source('1.00001');
    }

    public function test_total_rejects_aggregate_overflow(): void
    {
        $this->expectException(ValidationException::class);
        (new AccountingDecimal)->total(['999999999999999999.99', '0.01']);
    }

    public function test_total_preserves_cents_at_large_values(): void
    {
        $this->assertSame('900000000000000000.03', (new AccountingDecimal)->total(['900000000000000000.01', '0.02']));
    }

    public function test_complement_cost_split_consumes_exact_original(): void
    {
        $m = new AccountingDecimal;
        $shares = [$m->costShare('0.0500', '3', '0', '1'), $m->costShare('0.0500', '3', '1', '2'), $m->costShare('0.0500', '3', '2', '3')];
        $this->assertSame(['0.02', '0.01', '0.02'], $shares);
        $this->assertSame('0.05', $m->total($shares));
    }

    public function test_exact_ratio_handles_large_source_quantities(): void
    {
        $m = new AccountingDecimal;
        $this->assertSame('99999999999999.99', $m->costShare('99999999999999.9900', '99999999999999.9999', '0', '99999999999999.9999'));
        $this->assertSame('0.00', $m->costShare('10', '3', '1', '1'));
    }

    public function test_invalid_cost_capacity_is_rejected(): void
    {
        foreach ([['1', '0', '0', '0'], ['1', '3', '2', '1'], ['1', '3', '0', '3.0001']] as $arguments) {
            try {
                (new AccountingDecimal)->costShare(...$arguments);
                $this->fail('Expected quantity capacity rejection.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    public function test_cost_shares_reconcile_across_many_partitions(): void
    {
        $m = new AccountingDecimal;
        foreach (['0.0000', '0.0050', '1.0050', '19.9999', '123456.7890'] as $cost) {
            $parts = [];
            for ($i = 1; $i <= 37; $i++) {
                $parts[] = $m->costShare($cost, '37', (string) ($i - 1), (string) $i);
            }
            $this->assertSame($m->roundedCost($cost), $m->total($parts));
        }
    }

    public function test_hash_is_key_order_stable_but_line_order_sensitive(): void
    {
        $h = new AccountingCanonicalPayload;
        $this->assertSame($h->hash(['b' => '2.00', 'a' => ['y' => 2, 'x' => 1]]), $h->hash(['a' => ['x' => 1, 'y' => 2], 'b' => '2.00']));
        $this->assertNotSame($h->hash(['lines' => ['a', 'b']]), $h->hash(['lines' => ['b', 'a']]));
        $this->assertNotSame($h->hash(['amount' => '1.00']), $h->hash(['amount' => '1.01']));
    }

    public function test_hash_rejects_floats_and_objects(): void
    {
        foreach ([1.1, new \stdClass] as $value) {
            try {
                (new AccountingCanonicalPayload)->hash(['value' => $value]);
                $this->fail('Expected unsafe evidence rejection.');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }
}
