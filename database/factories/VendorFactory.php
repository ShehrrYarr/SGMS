<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vendor>
 */
class VendorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => tenant_id() ?? Tenant::factory(),
            'name' => fake()->name(),
            'company' => fake()->company().' Textiles',
            'phone' => fake()->numerify('03#########'),
            'email' => fake()->safeEmail(),
            'address' => fake()->address(),
            'opening_balance' => 0,
            'current_balance' => 0,
            'status' => 'active',
        ];
    }
}
