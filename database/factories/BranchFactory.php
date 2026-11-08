<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => tenant_id() ?? Tenant::factory(),
            'name' => fake()->city().' Branch',
            'code' => strtoupper(fake()->unique()->bothify('BR??##')),
            'phone' => fake()->numerify('04#########'),
            'address' => fake()->streetAddress(),
            'default_cash_drawer_balance' => 0,
            'is_main' => false,
            'status' => 'active',
        ];
    }
}
