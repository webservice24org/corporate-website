<?php

declare(strict_types=1);

namespace App\Services\ERP\Sales;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class SalesQuotationMath
{
    public function scaled(string $value, int $max): int
    {
        if (preg_match('/\A[0-9]+(?:\.[0-9]{1,4})?\z/', $value) !== 1) {
            throw ValidationException::withMessages(['items' => 'Use nonnegative decimals with at most four decimal places.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0');
        if (strlen($whole) > strlen((string) $max) || (int) $whole > $max || ((int) $whole === $max && (int) $fraction > 0)) {
            throw ValidationException::withMessages(['items' => 'Quotation input exceeds the supported range.']);
        }

        return (int) $whole * 10000 + (int) str_pad($fraction, 4, '0');
    }

    /** @return array<string, string> */
    public function line(string $quantity, string $price, string $discount, string $tax): array
    {
        $qty = $this->scaled($quantity, 10000);
        $unitPrice = $this->scaled($price, 1000000);
        $discountRate = $this->scaled($discount, 100);
        $taxRate = $this->scaled($tax, 100);
        if ($qty === 0) {
            throw ValidationException::withMessages(['items' => 'Line quantity must be greater than zero.']);
        }
        // Scale-8 base, bounded at 10^18. Split multiplication keeps intermediates in int64.
        $base = $qty * $unitPrice;
        $subtotal = intdiv($base + 500000, 1000000);
        $factor = 1000000 - $discountRate;
        $denominator = 1000000000000;
        $net = intdiv($base, $denominator) * $factor
            + intdiv(($base % $denominator) * $factor + intdiv($denominator, 2), $denominator);
        $taxCents = intdiv($net * $taxRate + 500000, 1000000);

        return [
            'quantity' => $this->format($qty, 4), 'unit_price' => $this->format($unitPrice, 4),
            'discount_percent' => $this->format($discountRate, 4), 'tax_percent' => $this->format($taxRate, 4),
            'subtotal' => $this->format($subtotal), 'discount_amount' => $this->format($subtotal - $net),
            'tax_amount' => $this->format($taxCents), 'line_total' => $this->format($net + $taxCents),
        ];
    }

    public function cents(string $value): int
    {
        if (preg_match('/\A[0-9]+\.[0-9]{2}\z/', $value) !== 1 || strlen(str_replace('.', '', $value)) > 16) {
            throw ValidationException::withMessages(['items' => 'Invalid stored monetary amount.']);
        }

        return (int) str_replace('.', '', $value);
    }

    public function format(int $value, int $scale = 2): string
    {
        $factor = match ($scale) {
            2 => 100, 4 => 10000,
            default => throw new InvalidArgumentException('Unsupported decimal format scale.'),
        };

        return intdiv($value, $factor).'.'.str_pad((string) ($value % $factor), $scale, '0', STR_PAD_LEFT);
    }
}
