<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Inventory;
use App\Models\ProductVariant;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Inventory>
 */
class InventoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => tenant_id() ?? Tenant::factory(),
            'branch_id' => fn (array $a) => Branch::factory()->state(['tenant_id' => $a['tenant_id']]),
            'product_variant_id' => fn (array $a) => ProductVariant::factory()->state(['tenant_id' => $a['tenant_id']]),
            'quantity' => fake()->numberBetween(0, 100),
        ];
    }
}
