<?php

declare(strict_types=1);

namespace App\Services\ERP\Sales;

use App\Models\SalesInvoiceItem;
use Illuminate\Validation\ValidationException;

final class SalesCreditMath
{
    public function money(string $value, bool $positive = false): string
    {
        if (preg_match('/\A[0-9]{1,16}(?:\.[0-9]{1,2})?\z/', $value) !== 1 || ($positive && bccomp($value, '0', 2) <= 0)) {
            $this->fail('Use positive money with at most two decimal places.');
        }

        return bcadd($value, '0', 2);
    }

    public function ratio(string $amount, string $numerator, string $denominator): string
    {
        if (bccomp($denominator, '0', 4) <= 0) {
            $this->fail('Credit ratio denominator must be positive.');
        }

        return bcdiv(bcadd(bcdiv(bcmul($amount, $numerator, 8), $denominator, 8), '0.005', 8), '1', 2);
    }

    public function quantityValue(SalesInvoiceItem $line, string $before, string $quantity): string
    {
        $whole = (string) $line->getAttribute('quantity');
        $after = bcadd($before, $quantity, 4);
        if (bccomp($quantity, '0', 4) <= 0 || bccomp($after, $whole, 4) > 0) {
            $this->fail('Quantity credit exceeds uncredited original invoice quantity.');
        }
        $total = (string) $line->getAttribute('line_total');

        // Complement rounding preserves every cent at full quantity.
        return bcsub($this->ratio($total, bcsub($whole, $before, 4), $whole), $this->ratio($total, bcsub($whole, $after, 4), $whole), 2);
    }

    /** @return array<string, string> */
    public function slice(SalesInvoiceItem $line, string $before, string $amount): array
    {
        $whole = $this->money((string) $line->getAttribute('line_total'));
        $after = bcadd($before, $amount, 2);
        if (bccomp($whole, '0', 2) <= 0 || bccomp($amount, '0', 2) <= 0 || bccomp($after, $whole, 2) > 0) {
            $this->fail('Credit exceeds the original invoice line value or has no financial value.');
        }
        $old = $this->components($line, $before);
        $new = $this->components($line, $after);
        $result = [];
        foreach ($new as $key => $value) {
            $result[$key] = bcsub($value, $old[$key], 2);
        }

        return $result;
    }

    /** @return array<string, string> */
    private function components(SalesInvoiceItem $line, string $amount): array
    {
        $whole = (string) $line->getAttribute('line_total');
        $tax = $this->ratio((string) $line->getAttribute('tax_amount'), $amount, $whole);
        $net = bcsub($amount, $tax, 2);
        $base = bcsub((string) $line->getAttribute('subtotal'), (string) $line->getAttribute('discount_amount'), 2);
        if (bccomp($base, '0', 2) <= 0) {
            $this->fail('Original invoice net components require review.');
        }
        $discount = $this->ratio((string) $line->getAttribute('discount_amount'), $net, $base);

        return ['subtotal' => bcadd($net, $discount, 2), 'discount_amount' => $discount, 'tax_amount' => $tax, 'line_total' => $amount];
    }

    public function fail(string $message): never
    {
        throw ValidationException::withMessages(['credit' => $message]);
    }
}
