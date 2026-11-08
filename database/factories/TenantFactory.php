<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(10, 99999),
            'status' => 'active',
            'owner_email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('03#########'),
            'address' => fake()->address(),
            'plan' => fake()->randomElement(['basic', 'standard', 'premium']),
            'trial_ends_at' => now()->addDays(14),
            'currency_symbol' => 'Rs.',
            'tax_rate' => 0,
            'settings' => ['receipt_width' => '80'],
        ];
    }

    public function suspended(): static
    {
        return $this->state(['status' => 'suspended']);
    }
}
