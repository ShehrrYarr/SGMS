<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => tenant_id() ?? Tenant::factory(),
            'name' => fake()->name(),
            'phone' => fake()->unique()->numerify('03#########'),
            'email' => fake()->unique()->safeEmail(),
            'password' => null,
            'address' => fake()->address(),
            'opening_balance' => 0,
            'current_balance' => 0,
            'status' => 'active',
        ];
    }

    public function withPortalAccess(string $password = 'password'): static
    {
        return $this->state(['password' => $password]);
    }
}
