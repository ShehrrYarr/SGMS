<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseItem>
 */
class PurchaseItemFactory extends Factory
{
    public function definition(): array
    {
        $qty = fake()->numberBetween(5, 50);
        $cost = fake()->numberBetween(400, 1500);

        return [
            'tenant_id' => fn () => tenant_id() ?? Tenant::factory(),
            'purchase_id' => fn (array $a) => Purchase::factory()->state(['tenant_id' => $a['tenant_id']]),
            'product_variant_id' => fn (array $a) => ProductVariant::factory()->state(['tenant_id' => $a['tenant_id']]),
            'quantity' => $qty,
            'cost_per_item' => $cost,
            'retail_price_set' => $cost * 2,
            'total_cost' => $qty * $cost,
        ];
    }
}
