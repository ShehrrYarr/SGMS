<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerLedger>
 */
class CustomerLedgerFactory extends Factory
{
    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 500, 10000);

        return [
            'tenant_id' => fn () => tenant_id() ?? Tenant::factory(),
            'customer_id' => fn (array $a) => Customer::factory()->state(['tenant_id' => $a['tenant_id']]),
            'type' => 'adjustment',
            'debit' => $amount,
            'credit' => 0,
            'running_balance' => $amount,
            'note' => fake()->sentence(3),
            'transaction_date' => today(),
        ];
    }
}
