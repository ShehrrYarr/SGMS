<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Renders every page for every role against the seeded demo shop.
 */
class PageSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Demo data is seeded inside the test transaction (rolled back afterwards).
        if (! Tenant::query()->where('slug', 'urban-garments')->exists()) {
            $this->seed();
        }

        $this->tenant = Tenant::query()->where('slug', 'urban-garments')->firstOrFail();
    }

    protected function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    public function test_owner_can_open_every_staff_page(): void
    {
        $id = $this->tenant->id;
        $sale = Sale::query()->forTenant($id)->whereNotNull('customer_id')->firstOrFail();
        $purchase = Purchase::query()->forTenant($id)->firstOrFail();
        $vendor = Vendor::query()->forTenant($id)->firstOrFail();
        $customer = Customer::query()->forTenant($id)->firstOrFail();
        $product = Product::query()->forTenant($id)->firstOrFail();
        $account = PaymentAccount::query()->forTenant($id)->firstOrFail();
        $voucher = VendorLedger::query()->forTenant($id)->where('type', 'payment')->firstOrFail();

        $pages = [
            route('app.dashboard'), route('app.pos'), route('app.profile'),
            route('app.sales.index'), route('app.sales.show', $sale), route('app.sales.receipt', $sale),
            route('app.returns.index'), route('app.returns.create'), route('app.returns.create', ['sale' => $sale->id]),
            route('app.customers.index'), route('app.customers.show', $customer),
            route('app.purchases.index'), route('app.purchases.create'), route('app.purchases.show', $purchase),
            route('app.vendors.index'), route('app.vendors.show', $vendor), route('app.vendors.show', ['vendor' => $vendor, 'tab' => 'lots']),
            route('app.products.index'), route('app.products.create'), route('app.products.edit', $product),
            route('app.inventory.index'), route('app.inventory.index', ['filter' => 'low']),
            route('app.transfers.index'), route('app.transfers.create'),
            route('app.barcodes', ['purchase' => $purchase->id]),
            route('app.attributes'), route('app.attributes', ['tab' => 'colors']), route('app.attributes', ['tab' => 'sizes']),
            route('app.accounts.index'), route('app.accounts.show', $account), route('app.expenses.index'),
            route('app.branches'), route('app.users'), route('app.settings'),
        ];

        $owner = $this->user('owner@urbangarments.com');

        foreach ($pages as $url) {
            $this->actingAs($owner, 'web')->get($url)->assertOk();
        }

        foreach ([route('app.sales.pdf', $sale), route('app.purchases.pdf', $purchase), route('app.vendors.ledger.pdf', $vendor),
            route('app.vendors.receipt.pdf', [$vendor, $voucher]), route('app.customers.statement', $customer)] as $pdf) {
            $this->actingAs($owner, 'web')->get($pdf)->assertOk()->assertHeader('content-type', 'application/pdf');
        }
    }

    public function test_cashier_is_limited_to_pos_and_sales(): void
    {
        $cashier = $this->user('cashier@urbangarments.com');

        foreach ([route('app.dashboard'), route('app.pos'), route('app.sales.index'), route('app.customers.index'), route('app.returns.create')] as $url) {
            $this->actingAs($cashier, 'web')->get($url)->assertOk();
        }

        foreach ([route('app.purchases.index'), route('app.vendors.index'), route('app.inventory.index'), route('app.accounts.index'), route('app.branches'), route('app.settings')] as $url) {
            $this->actingAs($cashier, 'web')->get($url)->assertForbidden();
        }

        // Cashiers only see invoices they rang up themselves.
        $ownersSale = Sale::query()->forTenant($this->tenant->id)->where('created_by', '!=', $cashier->id)->firstOrFail();
        $this->actingAs($cashier, 'web')->get(route('app.sales.show', $ownersSale))->assertForbidden();
    }

    public function test_manager_is_limited_to_their_branch(): void
    {
        $manager = $this->user('manager@urbangarments.com');

        $this->actingAs($manager, 'web')->get(route('app.purchases.index'))->assertOk();
        $this->actingAs($manager, 'web')->get(route('app.branches'))->assertForbidden();

        // All demo sales happened at Main Store; the City Mall manager cannot open them.
        $mainSale = Sale::query()->forTenant($this->tenant->id)->firstOrFail();
        $this->actingAs($manager, 'web')->get(route('app.sales.show', $mainSale))->assertForbidden();
    }

    public function test_super_admin_pages(): void
    {
        $admin = SuperAdmin::query()->firstOrFail();

        foreach ([route('admin.dashboard'), route('admin.shops.index'), route('admin.shops.show', $this->tenant), route('admin.profile')] as $url) {
            $this->actingAs($admin, 'superadmin')->get($url)->assertOk();
        }
    }

    public function test_staff_session_never_unlocks_the_admin_area(): void
    {
        $this->actingAs($this->user('owner@urbangarments.com'), 'web')->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
    }

    public function test_customer_portal_pages(): void
    {
        $customer = Customer::query()->forTenant($this->tenant->id)->where('phone', '03001234567')->firstOrFail();
        $sale = $customer->sales()->firstOrFail();
        $slug = $this->tenant->slug;

        foreach ([route('portal.dashboard', $slug), route('portal.purchases.index', $slug), route('portal.purchases.show', [$slug, $sale]),
            route('portal.statement', $slug), route('portal.profile', $slug), route('portal.purchases.receipt', [$slug, $sale])] as $url) {
            $this->actingAs($customer, 'customer')->get($url)->assertOk();
        }

        $this->actingAs($customer, 'customer')->get(route('portal.statement.pdf', $slug))->assertOk()->assertHeader('content-type', 'application/pdf');

        // Another customer's invoice is not visible.
        $other = Sale::query()->forTenant($this->tenant->id)->where('customer_id', '!=', $customer->id)->whereNotNull('customer_id')->firstOrFail();
        $this->actingAs($customer, 'customer')->get(route('portal.purchases.show', [$slug, $other]))->assertNotFound();
    }

    public function test_guests_are_redirected_to_the_right_login(): void
    {
        $this->get(route('app.dashboard'))->assertRedirect(route('app.login'));
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
        $this->get(route('portal.dashboard', 'urban-garments'))->assertRedirect(route('portal.login', 'urban-garments'));
        $this->get(route('portal.login', 'denim-corner'))->assertForbidden(); // suspended shop
        $this->get('/portal/does-not-exist/login')->assertNotFound();
        $this->assertTrue(CustomerLedger::query()->exists());
    }
}
