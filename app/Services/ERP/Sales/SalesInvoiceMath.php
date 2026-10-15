<?php

declare(strict_types=1);

namespace App\Services\ERP\Sales;

use App\Models\SalesOrderItem;
use Illuminate\Validation\ValidationException;

final class SalesInvoiceMath
{
    public function __construct(private readonly SalesCreditExposureService $money, private readonly SalesQuotationMath $math) {}

    /** @return array<string, string> */
    public function slice(SalesOrderItem $line, string $already, string $quantity): array
    {
        $totalQuantity = $this->math->scaled((string) $line->getAttribute('quantity'), 10000);
        $beforeQuantity = $this->math->scaled($already, 10000);
        $nextQuantity = $beforeQuantity + $this->math->scaled($quantity, 10000);
        if ($totalQuantity === 0 || $nextQuantity > $totalQuantity || $nextQuantity <= $beforeQuantity) {
            throw ValidationException::withMessages(['quantity' => 'Invoice quantity is outside its source budget.']);
        }
        $subtotal = $this->money->money((string) $line->getAttribute('subtotal'));
        $discount = $this->money->money((string) $line->getAttribute('discount_amount'));
        $tax = $this->money->money((string) $line->getAttribute('tax_amount'));
        $total = $this->money->money((string) $line->getAttribute('line_total'));
        if ($discount > $subtotal || $subtotal - $discount + $tax !== $total) {
            throw ValidationException::withMessages(['source' => 'Original order monetary components do not reconcile.']);
        }
        $before = $this->cumulative($subtotal, $discount, $tax, $total, $beforeQuantity, $totalQuantity);
        $after = $this->cumulative($subtotal, $discount, $tax, $total, $nextQuantity, $totalQuantity);
        $result = [];
        foreach ($after as $field => $value) {
            $result[$field] = $this->math->format($value - $before[$field]);
        }

        return $result;
    }

    /** @return array<string, int> */
    private function cumulative(int $subtotal, int $discount, int $tax, int $total, int $quantity, int $whole): array
    {
        // Complement rounding exactly matches 5.4's cancelled-value budget, including half-cent ties.
        $amount = $total - $this->ratio($total, $whole - $quantity, $whole);
        $taxAmount = $total === 0 ? 0 : $this->ratio($tax, $amount, $total);
        $net = $amount - $taxAmount;
        $discountAmount = $subtotal === $discount ? $this->ratio($discount, $quantity, $whole) : $this->ratio($discount, $net, $subtotal - $discount);

        return ['subtotal' => $net + $discountAmount, 'discount_amount' => $discountAmount, 'tax_amount' => $taxAmount, 'line_total' => $amount];
    }

    private function ratio(int $amount, int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new \LogicException('Invoice allocation denominator must be positive.');
        }
        // BCMath prevents intermediate multiplication overflow at decimal(18,2) scale.
        $product = bcmul((string) $amount, (string) $numerator, 0);

        return (int) bcdiv(bcadd($product, (string) intdiv($denominator, 2), 0), (string) $denominator, 0);
    }
}
