<?php

namespace Database\Factories;

use App\Models\Color;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Color>
 */
class ColorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => tenant_id() ?? Tenant::factory(),
            'name' => ucfirst(fake()->unique()->safeColorName()),
            'hex_code' => fake()->hexColor(),
        ];
    }
}
