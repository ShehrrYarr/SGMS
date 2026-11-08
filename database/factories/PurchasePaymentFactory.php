<?php

namespace Database\Factories;

use App\Models\PaymentAccount;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchasePayment>
 */
class PurchasePaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => tenant_id() ?? Tenant::factory(),
            'purchase_id' => fn (array $a) => Purchase::factory()->state(['tenant_id' => $a['tenant_id']]),
            'payment_account_id' => fn (array $a) => PaymentAccount::factory()->state(['tenant_id' => $a['tenant_id']]),
            'amount' => fake()->numberBetween(1000, 20000),
            'payment_method' => 'bank',
            'reference_no' => fake()->bothify('CHQ-######'),
            'payment_date' => today(),
        ];
    }
}
