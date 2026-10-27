<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Services\ERP\Accounting\AccountingJournalAmounts;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AccountingJournalAmountsTest extends TestCase
{
    private function invalid(array $lines, string $field): void
    {
        try {
            app(AccountingJournalAmounts::class)->balanced($lines);
            $this->fail('Expected exact amount validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_fractional_addition_is_exact_without_binary_floats(): void
    {
        $result = app(AccountingJournalAmounts::class)->balanced([['debit' => '0.10', 'credit' => '0'], ['debit' => '0.20', 'credit' => '0'], ['debit' => '0', 'credit' => '0.30']]);
        $this->assertSame('0.30', $result['total_debit']);
        $this->assertSame('0.30', $result['total_credit']);
    }

    public function test_maximum_decimal_20_2_value_is_valid_in_pure_arithmetic(): void
    {
        $max = '999999999999999999.99';
        $result = app(AccountingJournalAmounts::class)->balanced([['debit' => $max, 'credit' => '0'], ['debit' => '0', 'credit' => $max]]);
        $this->assertSame($max, $result['total_debit']);
    }

    public function test_balanced_aggregate_overflow_is_rejected(): void
    {
        $max = '999999999999999999.99';
        $this->invalid([['debit' => $max, 'credit' => '0'], ['debit' => '0.01', 'credit' => '0'], ['debit' => '0', 'credit' => $max], ['debit' => '0', 'credit' => '0.01']], 'lines');
    }

    public function test_per_line_overflow_is_rejected_before_totalling(): void
    {
        $this->invalid([['debit' => '1000000000000000000', 'credit' => '0'], ['debit' => '0', 'credit' => '1']], 'lines.0.debit');
    }

    public function test_one_cent_difference_is_not_absorbed_by_rounding(): void
    {
        $this->invalid([['debit' => '1.00', 'credit' => '0'], ['debit' => '0', 'credit' => '0.99']], 'lines');
    }

    public function test_exact_integer_and_short_scale_inputs_are_canonicalized(): void
    {
        $result = app(AccountingJournalAmounts::class)->balanced([['debit' => 1, 'credit' => 0], ['debit' => '0', 'credit' => '1.0']]);
        $this->assertSame([['debit' => '1.00', 'credit' => '0.00'], ['debit' => '0.00', 'credit' => '1.00']], $result['amounts']);
    }

    public function test_both_sides_and_zero_sides_are_rejected(): void
    {
        foreach ([['debit' => '0', 'credit' => '0'], ['debit' => '1', 'credit' => '1']] as $line) {
            $this->invalid([$line, ['debit' => '1', 'credit' => '0']], 'lines.0');
        }
    }

    public function test_nonarray_line_is_rejected_without_scalar_offset_access(): void
    {
        $this->invalid(['bad', ['debit' => '0', 'credit' => '1']], 'lines.0');
    }

    public function test_two_hundred_lines_are_supported_with_exact_totals(): void
    {
        $result = app(AccountingJournalAmounts::class)->balanced([...array_fill(0, 100, ['debit' => '0.01', 'credit' => '0']), ...array_fill(0, 100, ['debit' => '0', 'credit' => '0.01'])]);
        $this->assertCount(200, $result['amounts']);
        $this->assertSame('1.00', $result['total_debit']);
    }

    public function test_empty_and_excessive_line_counts_are_rejected(): void
    {
        $this->invalid([], 'lines');
        $this->invalid(array_fill(0, 201, ['debit' => '1', 'credit' => '0']), 'lines');
    }
}
