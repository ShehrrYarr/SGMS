<?php

namespace Database\Factories;

use App\Models\PaymentAccount;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalePayment>
 */
class SalePaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => tenant_id() ?? Tenant::factory(),
            'sale_id' => fn (array $a) => Sale::factory()->state(['tenant_id' => $a['tenant_id']]),
            'payment_account_id' => fn (array $a) => PaymentAccount::factory()->state(['tenant_id' => $a['tenant_id']]),
            'amount' => fake()->numberBetween(500, 10000),
            'payment_method' => 'cash',
            'payment_date' => now(),
        ];
    }
}
