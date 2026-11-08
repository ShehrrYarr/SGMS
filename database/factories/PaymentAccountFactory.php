<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\PaymentAccount;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentAccount>
 */
class PaymentAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => tenant_id() ?? Tenant::factory(),
            'branch_id' => null,
            'account_name' => fake()->randomElement(['Meezan Bank', 'HBL', 'UBL', 'MCB']).' Current',
            'account_type' => PaymentAccount::TYPE_BANK,
            'bank_name' => fake()->randomElement(['Meezan Bank', 'HBL', 'UBL']),
            'account_number' => fake()->numerify('##############'),
            'opening_balance' => 0,
            'current_balance' => 0,
            'is_default' => false,
            'status' => 'active',
        ];
    }

    public function cashDrawer(): static
    {
        return $this->state([
            'account_type' => PaymentAccount::TYPE_CASH,
            'account_name' => 'Cash Drawer',
            'bank_name' => null,
            'account_number' => null,
            'is_default' => true,
            'branch_id' => fn (array $a) => Branch::factory()->state(['tenant_id' => $a['tenant_id']]),
        ]);
    }
}
