<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PaymentAccount;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AdminShopsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(SuperAdmin::factory()->create(), 'superadmin');
    }

    public function test_super_admin_creates_a_ready_to_use_shop(): void
    {
        Volt::test('admin.shops.index')
            ->set('form.name', 'Style Hub')
            ->assertSet('form.slug', 'style-hub')
            ->set('form.owner_name', 'Ali Khan')
            ->set('form.owner_email', 'ali@stylehub.pk')
            ->set('form.owner_password', 'secret-123')
            ->set('form.opening_cash', 15000)
            ->call('createShop')
            ->assertHasNoErrors()
            ->assertDispatched('shop-created');

        $tenant = Tenant::query()->where('slug', 'style-hub')->firstOrFail();
        $owner = User::query()->forTenant($tenant->id)->firstOrFail();

        $this->assertSame('owner', $owner->role);
        $this->assertTrue(Hash::check('secret-123', $owner->password));
        $this->assertSame(1, Branch::query()->forTenant($tenant->id)->count());
        $this->assertEquals(15000, (float) PaymentAccount::query()->forTenant($tenant->id)->where('account_type', 'cash_drawer')->value('current_balance'));
    }

    public function test_duplicate_owner_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        Volt::test('admin.shops.index')
            ->set('form.name', 'Another Shop')
            ->set('form.owner_name', 'X')
            ->set('form.owner_email', 'taken@example.com')
            ->call('createShop')
            ->assertHasErrors(['form.owner_email']);
    }

    public function test_toggle_and_reset_password(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->owner()->create(['tenant_id' => $tenant->id]);

        Volt::test('admin.shops.index')->call('toggleStatus', $tenant->id);
        $this->assertSame('suspended', $tenant->fresh()->status);

        Volt::test('admin.shops.index')->call('toggleStatus', $tenant->id);
        $this->assertSame('active', $tenant->fresh()->status);

        Volt::test('admin.shops.index')
            ->call('startReset', $tenant->id)
            ->assertSet('resetUserId', $user->id)
            ->set('newPassword', 'brand-new-pass')
            ->call('resetPassword')
            ->assertDispatched('password-reset');

        $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()->password));
    }
}
