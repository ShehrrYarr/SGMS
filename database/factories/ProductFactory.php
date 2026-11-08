<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $cost = fake()->numberBetween(400, 2000);

        return [
            'tenant_id' => fn () => tenant_id() ?? Tenant::factory(),
            'category_id' => null,
            'brand_id' => null,
            'name' => fake()->randomElement(['Oxford', 'Slim Fit', 'Classic', 'Relaxed', 'Premium']).' '.fake()->randomElement(['Shirt', 'Jeans', 'Hoodie', 'Polo', 'Chino']),
            'sku' => strtoupper(fake()->unique()->bothify('PRD-#####')),
            'description' => fake()->sentence(),
            'base_cost' => $cost,
            'base_price' => $cost * 2,
            'status' => 'active',
        ];
    }
}
