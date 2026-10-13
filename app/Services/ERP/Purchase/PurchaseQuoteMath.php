<?php

declare(strict_types=1);

namespace App\Services\ERP\Purchase;

use Illuminate\Validation\ValidationException;

final class PurchaseQuoteMath
{
    public function scaled(string $value, int $scale, int $max = 1000000): int
    {
        if (! preg_match('/^[0-9]+(?:\.[0-9]{1,'.$scale.'})?$/D', $value)) {
            throw ValidationException::withMessages(['items' => 'Use nonnegative decimals with the supported precision.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0');
        if (strlen($whole) > strlen((string) $max) || (int) $whole > $max || ((int) $whole === $max && (int) $fraction > 0)) {
            throw ValidationException::withMessages(['items' => 'Quotation value exceeds the supported amount.']);
        }

        return (int) $whole * (10 ** $scale) + (int) str_pad($fraction, $scale, '0');
    }

    public function line(string $quantity, string $price, string $discount, string $tax): int
    {
        $qty = $this->scaled($quantity, 4);
        $base = intdiv($qty * $this->scaled($price, 2) + 5000, 10000);
        $deduction = $this->scaled($discount, 2, 1000000000000);
        $taxCents = $this->scaled($tax, 2, 1000000000000);
        if ($qty <= 0 || $deduction > $base || $base - $deduction + $taxCents > 100000000000000) {
            throw ValidationException::withMessages(['items' => 'Invalid quantity, discount or line amount.']);
        }

        return $base - $deduction + $taxCents;
    }

    public function format(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
