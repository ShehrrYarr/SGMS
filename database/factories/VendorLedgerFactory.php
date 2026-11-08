<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\Vendor;
use App\Models\VendorLedger;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VendorLedger>
 */
class VendorLedgerFactory extends Factory
{
    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 1000, 50000);

        return [
            'tenant_id' => fn () => tenant_id() ?? Tenant::factory(),
            'vendor_id' => fn (array $a) => Vendor::factory()->state(['tenant_id' => $a['tenant_id']]),
            'purchase_id' => null,
            'type' => 'adjustment',
            'debit' => 0,
            'credit' => $amount,
            'running_balance' => $amount,
            'note' => fake()->sentence(3),
            'transaction_date' => today(),
        ];
    }
}
