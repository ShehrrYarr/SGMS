<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Sale;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    public function definition(): array
    {
        $total = fake()->numberBetween(1000, 20000);

        return [
            'tenant_id' => fn () => tenant_id() ?? Tenant::factory(),
            'branch_id' => fn (array $a) => Branch::factory()->state(['tenant_id' => $a['tenant_id']]),
            'customer_id' => null,
            'invoice_number' => strtoupper(fake()->unique()->bothify('INV-######')),
            'subtotal' => $total,
            'discount' => 0,
            'tax' => 0,
            'total_amount' => $total,
            'paid_amount' => $total,
            'due_amount' => 0,
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'sale_date' => fake()->dateTimeBetween('-30 days'),
        ];
    }
}
