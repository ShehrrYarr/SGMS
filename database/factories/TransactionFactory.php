<?php

namespace Database\Factories;

use App\Models\PaymentAccount;
use App\Models\Tenant;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 100, 5000);

        return [
            'tenant_id' => fn () => tenant_id() ?? Tenant::factory(),
            'branch_id' => null,
            'payment_account_id' => fn (array $a) => PaymentAccount::factory()->state(['tenant_id' => $a['tenant_id']]),
            'reference_type' => 'adjustment',
            'reference_id' => null,
            'type' => 'credit',
            'amount' => $amount,
            'balance_after' => $amount,
            'note' => fake()->sentence(3),
        ];
    }
}
