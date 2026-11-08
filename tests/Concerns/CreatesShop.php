<?php

namespace Tests\Concerns;

use App\Models\Branch;
use App\Models\Color;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\Size;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ProductService;
use App\Services\TenantProvisioner;
use Illuminate\Support\Str;

/**
 * Builds a fresh shop through the real provisioner and enters its context.
 */
trait CreatesShop
{
    protected Tenant $shop;

    protected Branch $main;

    protected User $owner;

    protected PaymentAccount $drawer;

    protected PaymentAccount $bank;

    protected function createShop(string $name = 'Test Garments', float $openingCash = 100000): Tenant
    {
        $provisioner = app(TenantProvisioner::class);

        $this->shop = $provisioner->create([
            'name' => $name,
            'owner_name' => 'Owner '.$name,
            'owner_email' => Str::slug($name).'-'.Str::random(5).'@example.com',
            'owner_password' => 'password',
            'opening_cash' => $openingCash,
        ]);

        $this->enterShop($this->shop);
        $this->main = Branch::query()->where('is_main', true)->firstOrFail();
        $this->owner = User::query()->where('role', User::ROLE_OWNER)->firstOrFail();
        $this->drawer = $this->main->cashDrawer()->firstOrFail();
        $this->bank = $provisioner->createAccount(['account_name' => 'Test Bank', 'account_type' => 'bank', 'opening_balance' => 500000]);
        $this->actingAs($this->owner, 'web');
        tenancy()->setUser($this->owner);
        tenancy()->setBranchId($this->main->id);

        return $this->shop;
    }

    protected function enterShop(Tenant $tenant): void
    {
        tenancy()->forget();
        tenancy()->set($tenant);
    }

    protected function makeProduct(string $name = 'Test Shirt', float $price = 1000): Product
    {
        return app(ProductService::class)->create(['name' => $name, 'base_price' => $price, 'base_cost' => $price / 2]);
    }

    protected function colorId(string $name = 'Black'): int
    {
        return Color::query()->where('name', $name)->value('id');
    }

    protected function sizeId(string $code = 'M'): int
    {
        return Size::query()->where('code', $code)->value('id');
    }
}
