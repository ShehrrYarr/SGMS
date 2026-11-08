<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Purchase;
use App\Models\Tenant;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Purchase>
 */
class PurchaseFactory extends Factory
{
    public function definition(): array
    {
        $total = fake()->numberBetween(20000, 300000);

        return [
            'tenant_id' => fn () => tenant_id() ?? Tenant::factory(),
            'branch_id' => fn (array $a) => Branch::factory()->state(['tenant_id' => $a['tenant_id']]),
            'vendor_id' => fn (array $a) => Vendor::factory()->state(['tenant_id' => $a['tenant_id']]),
            'lot_number' => strtoupper(fake()->unique()->bothify('LOT-#####')),
            'lot_name' => fake()->randomElement(['Summer', 'Winter', 'Eid', 'Clearance']).' Lot',
            'total_lot_cost' => $total,
            'total_garment_qty' => fake()->numberBetween(50, 500),
            'payment_status' => 'unpaid',
            'payment_method' => 'credit',
            'paid_amount' => 0,
            'due_amount' => $total,
            'purchase_date' => fake()->dateTimeBetween('-60 days'),
            'notes' => null,
        ];
    }
}
