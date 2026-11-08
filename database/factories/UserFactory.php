<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => tenant_id() ?? Tenant::factory(),
            'branch_id' => fn (array $a) => Branch::factory()->state(['tenant_id' => $a['tenant_id']]),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('03#########'),
            'password' => 'password',
            'role' => User::ROLE_CASHIER,
            'status' => 'active',
            'remember_token' => Str::random(10),
        ];
    }

    public function owner(): static
    {
        return $this->state(['role' => User::ROLE_OWNER]);
    }

    public function manager(): static
    {
        return $this->state(['role' => User::ROLE_MANAGER]);
    }
}
