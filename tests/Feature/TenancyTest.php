<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Size;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShop;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use CreatesShop, RefreshDatabase;

    public function test_queries_are_scoped_to_the_active_shop(): void
    {
        $a = $this->createShop('Shop A');
        Vendor::create(['name' => 'Vendor of A']);
        Customer::create(['name' => 'Customer of A', 'phone' => '03000000001']);

        $b = $this->createShop('Shop B');
        Vendor::create(['name' => 'Vendor of B']);

        $this->assertSame(['Vendor of B'], Vendor::query()->pluck('name')->all());
        $this->assertSame(0, Customer::query()->count());

        $this->enterShop($a);
        $this->assertSame(['Vendor of A'], Vendor::query()->pluck('name')->all());

        // Same phone number may exist in two shops.
        $this->enterShop($b);
        Customer::create(['name' => 'Customer of B', 'phone' => '03000000001']);
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_staff_cannot_open_another_shops_records(): void
    {
        $this->createShop('Shop A');
        $vendorA = Vendor::create(['name' => 'Private vendor']);

        $this->createShop('Shop B');
        $ownerB = $this->owner;
        tenancy()->forget();

        $this->actingAs($ownerB, 'web')->get(route('app.vendors.show', $vendorA))->assertNotFound();
    }

    public function test_suspending_a_shop_blocks_staff_and_portal(): void
    {
        $shop = $this->createShop('Shop A');
        $owner = $this->owner;
        tenancy()->forget();

        $this->actingAs($owner, 'web')->get(route('app.dashboard'))->assertOk();

        $shop->update(['status' => Tenant::STATUS_SUSPENDED]);

        $this->actingAs($owner, 'web')->get(route('app.dashboard'))->assertRedirect(route('app.login'));
        $this->get(route('portal.login', $shop->slug))->assertForbidden();
    }

    public function test_provisioning_creates_owner_branch_and_cash_drawer(): void
    {
        $this->createShop('Fresh Shop', 25000);

        $this->assertSame(1, User::query()->where('role', 'owner')->count());
        $this->assertTrue($this->main->is_main);
        $this->assertEquals(25000, (float) $this->drawer->fresh()->current_balance);
        $this->assertSame(5, Size::query()->count());
    }
}
