<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleItem>
 */
class SaleItemFactory extends Factory
{
    public function definition(): array
    {
        $qty = fake()->numberBetween(1, 4);
        $price = fake()->numberBetween(800, 4000);

        return [
            'tenant_id' => fn () => tenant_id() ?? Tenant::factory(),
            'sale_id' => fn (array $a) => Sale::factory()->state(['tenant_id' => $a['tenant_id']]),
            'product_variant_id' => fn (array $a) => ProductVariant::factory()->state(['tenant_id' => $a['tenant_id']]),
            'quantity' => $qty,
            'unit_price' => $price,
            'cost_price' => round($price / 2),
            'discount' => 0,
            'total_price' => $qty * $price,
        ];
    }
}
