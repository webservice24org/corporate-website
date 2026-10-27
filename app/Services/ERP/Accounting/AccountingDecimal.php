<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use Illuminate\Validation\ValidationException;

final class AccountingDecimal
{
    public function money(mixed $value): string
    {
        return $this->normalize($value, 18, 2);
    }

    public function source(mixed $value): string
    {
        return $this->normalize($value, 14, 4);
    }

    /** @param list<mixed> $values */
    public function total(array $values): string
    {
        $total = '0.00';
        foreach ($values as $value) {
            $total = bcadd($total, $this->money($value), 2);
            $total = $this->money($total);
        }

        return $total;
    }

    public function roundedCost(mixed $value): string
    {
        return $this->money(bcadd(bcadd($this->source($value), '0.0050', 4), '0', 2));
    }

    /** Cost share between previous and current cumulative source quantities. */
    public function costShare(mixed $cost, mixed $quantity, mixed $previous, mixed $current): string
    {
        $sourceCost = $this->source($cost);
        $sourceQuantity = $this->source($quantity);
        $before = $this->source($previous);
        $after = $this->source($current);
        if (bccomp($sourceQuantity, '0', 4) <= 0 || bccomp($before, $after, 4) > 0 || bccomp($after, $sourceQuantity, 4) > 0) {
            $this->fail('Invalid cumulative source quantity range.');
        }

        return $this->money(bcsub($this->cumulative($sourceCost, $sourceQuantity, $after), $this->cumulative($sourceCost, $sourceQuantity, $before), 2));
    }

    private function normalize(mixed $value, int $integerDigits, int $scale): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (! is_string($value) || ! preg_match('/\A[0-9]{1,'.$integerDigits.'}(?:\.[0-9]{1,'.$scale.'})?\z/', $value)) {
            $this->fail('Use an unsigned exact decimal within the accounting precision; floats and excess decimals are not accepted.');
        }

        return bcadd($value, '0', $scale);
    }

    private function cumulative(string $cost, string $quantity, string $accepted): string
    {
        // Work in integer 1/10000 units; never truncate a repeating ratio before rounding.
        $numerator = bcmul(str_replace('.', '', $cost), str_replace('.', '', $accepted), 0);
        $denominator = bcmul(str_replace('.', '', $quantity), '100', 0);
        $cents = bcdiv($numerator, $denominator, 0);
        $remainder = bcsub($numerator, bcmul($cents, $denominator, 0), 0);
        if (bccomp(bcmul($remainder, '2', 0), $denominator, 0) >= 0) {
            $cents = bcadd($cents, '1', 0);
        }

        return $this->money(bcdiv($cents, '100', 2));
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['amount' => $message]);
    }
}
