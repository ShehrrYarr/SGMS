<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->randomElement(['Shirts', 'Denim', 'Hoodies', 'Polos', 'Trousers', 'Jackets', 'Kurtas', 'T-Shirts', 'Shorts', 'Sweaters']);

        return [
            'tenant_id' => fn () => tenant_id() ?? Tenant::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
        ];
    }
}
