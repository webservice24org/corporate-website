<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Supplier> */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            // Supply company_id via ->state() to ensure tenant ownership.
            'code' => strtoupper(fake()->unique()->bothify('SUP-######')),
            'name' => fake()->company(),
            'currency_code' => 'BDT',
            'status' => 'active',
            'payment_terms_days' => 30,
        ];
    }
}
