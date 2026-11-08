<?php

namespace Database\Factories;

use App\Models\Size;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Size>
 */
class SizeFactory extends Factory
{
    public function definition(): array
    {
        $code = fake()->unique()->randomElement(['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL', '28', '30', '32', '34', '36']);

        return [
            'tenant_id' => fn () => tenant_id() ?? Tenant::factory(),
            'name' => $code,
            'code' => $code,
            'sort_order' => fake()->numberBetween(1, 20),
        ];
    }
}
