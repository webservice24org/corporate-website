<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PurchaseOrder> */
class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        // Supply company_id, supplier_id and created_by explicitly using ->state().
        // This avoids silently creating cross-company purchasing records.
        return [
            'number' => strtoupper(fake()->unique()->bothify('PO-########')),
            'status' => 'draft',
            'currency_code' => 'BDT',
            'subtotal' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 0,
        ];
    }
}
