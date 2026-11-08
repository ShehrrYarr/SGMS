<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Landing page, one-click demo sign-in, and the guards that keep the
 * public demo usable for the next visitor.
 */
class DemoTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Tenant::query()->where('slug', 'urban-garments')->exists()) {
            $this->seed();
        }

        $this->tenant = Tenant::query()->where('slug', 'urban-garments')->firstOrFail();
    }

    protected function staff(string $email): User
    {
        return User::query()->withoutGlobalScope(TenantScope::class)->where('email', $email)->firstOrFail();
    }

    protected function customer(): Customer
    {
        return Customer::query()->withoutGlobalScope(TenantScope::class)->where('phone', '03001234567')->firstOrFail();
    }

    /** Signs in like the demo tag does and enters the shop's context for Volt tests. */
    protected function enterAs(User $user): void
    {
        $this->actingAs($user, 'web');
        tenancy()->forget();
        tenancy()->set($this->tenant);
        tenancy()->setUser($user);
        tenancy()->setBranchId($user->branch_id ?? $this->tenant->branches()->where('is_main', true)->value('id'));
    }

    public function test_landing_page_without_the_demo(): void
    {
        config(['sgms.demo.enabled' => false]);

        $this->get('/')->assertOk()
            ->assertSee('From the lot you buy to the last size you sell.')
            ->assertSee('https://wa.me/923120883979?text=', false)
            ->assertSee('Ask for a demo on WhatsApp')
            ->assertDontSee('/demo/owner');

        $this->post('/demo/owner')->assertNotFound();
    }

    public function test_one_click_sign_in_for_each_role(): void
    {
        config(['sgms.demo.enabled' => true]);

        $page = $this->get('/')->assertOk();
        foreach (['owner', 'manager', 'cashier', 'customer'] as $role) {
            $page->assertSee('/demo/'.$role);
        }
        $page->assertSee('browse the demo shop', false)->assertDontSee('admin@platform.com');

        $this->post('/demo/owner')->assertRedirect(route('app.dashboard'));
        $this->assertAuthenticatedAs($this->staff('owner@urbangarments.com'), 'web');

        $this->post('/demo/manager')->assertRedirect(route('app.dashboard'));
        $this->assertAuthenticatedAs($this->staff('manager@urbangarments.com'), 'web');

        $this->post('/demo/cashier')->assertRedirect(route('app.pos'));
        $this->assertAuthenticatedAs($this->staff('cashier@urbangarments.com'), 'web');

        $this->post('/demo/customer')->assertRedirect(route('portal.dashboard', 'urban-garments'));
        $this->assertAuthenticatedAs($this->customer(), 'customer');

        // The super admin is never a demo role.
        $this->post('/demo/admin')->assertNotFound();
    }

    public function test_demo_accounts_cannot_lock_the_next_visitor_out(): void
    {
        config(['sgms.demo.enabled' => true]);
        $owner = $this->staff('owner@urbangarments.com');
        $this->enterAs($owner);

        Volt::test('app.profile')
            ->set('current_password', 'password')->set('password', 'changed-123')->set('password_confirmation', 'changed-123')
            ->call('savePassword')
            ->assertDispatched('toast', type: 'error');

        Volt::test('app.profile')
            ->set('name', 'Ahmed R.')->set('email', 'someone@else.com')
            ->call('saveProfile')
            ->assertDispatched('toast', type: 'error');

        $manager = $this->staff('manager@urbangarments.com');
        Volt::test('app.users')
            ->call('edit', $manager->id)
            ->set('form.password', 'changed-123')
            ->call('save')
            ->assertDispatched('toast', type: 'error');

        Volt::test('app.settings.store')
            ->set('store_enabled', false)
            ->call('save')
            ->assertDispatched('toast', type: 'error');

        $this->assertTrue(Hash::check('password', $owner->fresh()->password));
        $this->assertSame('owner@urbangarments.com', $owner->fresh()->email);
        $this->assertTrue(Hash::check('password', $manager->fresh()->password));
        $this->assertTrue((bool) $this->tenant->fresh()->store_enabled);

        // Harmless profile edits still work.
        Volt::test('app.profile')->set('name', 'Ahmed R.')->call('saveProfile')->assertDispatched('toast', type: 'success');
        $this->assertSame('Ahmed R.', $owner->fresh()->name);
    }

    public function test_demo_customer_cannot_change_the_password(): void
    {
        config(['sgms.demo.enabled' => true]);
        $customer = $this->customer();
        tenancy()->forget();
        tenancy()->set($this->tenant);
        $this->actingAs($customer, 'customer');

        Volt::test('portal.profile')
            ->set('current_password', 'password')->set('password', 'changed-123')->set('password_confirmation', 'changed-123')
            ->call('savePassword')
            ->assertDispatched('toast', type: 'error');

        $this->assertTrue(Hash::check('password', $customer->fresh()->password));
    }

    public function test_guards_are_off_outside_the_demo(): void
    {
        config(['sgms.demo.enabled' => false]);
        $owner = $this->staff('owner@urbangarments.com');
        $this->enterAs($owner);

        Volt::test('app.profile')
            ->set('current_password', 'password')->set('password', 'changed-123')->set('password_confirmation', 'changed-123')
            ->call('savePassword')
            ->assertDispatched('toast', type: 'success');

        $this->assertTrue(Hash::check('changed-123', $owner->fresh()->password));
    }

    public function test_reset_refuses_to_run_outside_the_demo(): void
    {
        config(['sgms.demo.enabled' => false]);

        $this->artisan('sgms:demo-reset', ['--force' => true])->assertFailed();
        $this->assertTrue(Tenant::query()->where('slug', 'urban-garments')->exists());
    }
}
