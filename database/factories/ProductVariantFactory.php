<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    public function definition(): array
    {
        $cost = fake()->numberBetween(400, 2000);

        return [
            'tenant_id' => fn () => tenant_id() ?? Tenant::factory(),
            'product_id' => fn (array $a) => Product::factory()->state(['tenant_id' => $a['tenant_id']]),
            'color_id' => null,
            'size_id' => null,
            'sku' => strtoupper(fake()->unique()->bothify('SKU-#######')),
            'barcode' => fake()->unique()->ean13(),
            'cost_price' => $cost,
            'retail_price' => $cost * 2,
            'wholesale_price' => round($cost * 1.5),
            'alert_quantity' => 5,
        ];
    }
}
