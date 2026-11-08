<?php

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\ProductColorImage;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\Vendor;
use App\Services\ProductService;
use App\Services\PurchaseService;
use App\Services\ReportService;
use App\Services\SaleService;
use App\Services\Store\Cart;
use App\Services\Store\OrderService;
use App\Services\TenantProvisioner;
use App\Support\Store\Themes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesShop;
use Tests\TestCase;

/**
 * Online store: themes, visibility, bag & checkout, and how online orders
 * post to sales, stock, the customer ledger and payment accounts.
 */
class StorefrontTest extends TestCase
{
    use CreatesShop, RefreshDatabase;

    protected Product $chino;

    protected ProductVariant $blackM;

    protected ProductVariant $navyL;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createShop('Store Garments');

        $this->chino = app(ProductService::class)->create(['name' => 'Chino Trousers', 'base_price' => 3000, 'base_cost' => 1000, 'compare_at_price' => 3500]);
        app(PurchaseService::class)->create([
            'branch_id' => $this->main->id,
            'vendor_id' => Vendor::create(['name' => 'Mills'])->id,
            'purchase_date' => today()->toDateString(),
            'total_lot_cost' => 6000,
            'items' => [
                ['product_id' => $this->chino->id, 'color_id' => $this->colorId('Black'), 'size_id' => $this->sizeId('M'), 'quantity' => 3, 'cost_per_item' => 1000, 'retail_price' => 3000],
                ['product_id' => $this->chino->id, 'color_id' => $this->colorId('Navy Blue'), 'size_id' => $this->sizeId('L'), 'quantity' => 3, 'cost_per_item' => 1000, 'retail_price' => 3000],
            ],
        ]);

        $variants = ProductVariant::query()->get();
        $this->blackM = $variants->firstWhere('color_id', $this->colorId('Black'));
        $this->navyL = $variants->firstWhere('color_id', $this->colorId('Navy Blue'));

        $this->configureStore(['delivery_fee' => 250, 'free_delivery_over' => 5000, 'cod' => true, 'bank_accounts' => [$this->bank->id]]);
    }

    protected function configureStore(array $store, bool $enabled = true, bool $blocked = false): void
    {
        $shop = $this->shop->fresh();
        $settings = $shop->settings ?? [];
        $settings['store'] = array_merge($settings['store'] ?? [], $store);
        $shop->update(['settings' => $settings, 'store_enabled' => $enabled, 'store_blocked' => $blocked]);
        $this->shop = $shop;
        $this->enterShop($shop);
    }

    protected function asShopper(): void
    {
        Auth::guard('web')->forgetUser();
        Auth::guard('customer')->forgetUser();
        tenancy()->forget();
    }

    /** Fill the bag and place an order through the real service. */
    protected function order(array $lines, string $method = 'cod', ?int $accountId = null, array $details = []): Order
    {
        $this->enterShop($this->shop);
        $cart = app(Cart::class);

        foreach ($lines as [$variant, $qty]) {
            $cart->add($variant->id, $qty);
        }

        return app(OrderService::class)->place($details + [
            'name' => 'Ali Hassan', 'phone' => '0300 5556677', 'address' => 'House 1, Street 2', 'city' => 'Lahore',
            'payment_method' => $method, 'payment_account_id' => $accountId,
        ]);
    }

    public function test_every_theme_renders_the_store_pages(): void
    {
        $slug = $this->shop->slug;

        foreach (array_keys(Themes::ALL) as $theme) {
            $this->configureStore(['theme' => $theme]);
            $this->asShopper();

            $this->get("/{$slug}")->assertOk()->assertSee('sf--'.$theme, false)->assertSee('Chino Trousers');
            $this->get("/{$slug}/shop?sizes[]=".$this->sizeId('M').'&sort=price_asc')->assertOk()->assertSee('Chino Trousers');
            $this->get("/{$slug}/product/{$this->chino->slug}")->assertOk()
                ->assertSee('application/ld+json', false)
                ->assertSee('Rs. 3,500') // compare-at price
                ->assertSee('Add to bag');
            $this->get("/{$slug}/bag")->assertOk()->assertSee('Your bag is empty');
            $this->get("/{$slug}/track")->assertOk();
            $this->get("/{$slug}/wishlist")->assertOk();
        }

        $this->get("/{$slug}/product/does-not-exist")->assertNotFound();
        $this->get("/{$slug}/shop?q=nothing-matches-this")->assertOk()->assertSee('No products match');
    }

    public function test_store_visibility_rules(): void
    {
        $slug = $this->shop->slug;

        // Switched off: shoppers see "coming soon", signed-in staff can preview.
        $this->configureStore([], enabled: false);
        $this->asShopper();
        $this->get("/{$slug}")->assertOk()->assertSee('opens soon')->assertDontSee('Chino Trousers');
        $this->actingAs($this->owner, 'web')->get("/{$slug}?preview_theme=atelier")->assertOk()->assertSee('Chino Trousers')->assertSee('sf--atelier', false);

        // Blocked by the platform.
        $this->configureStore([], enabled: true, blocked: true);
        $this->asShopper();
        $this->get("/{$slug}")->assertNotFound();

        // Hidden products disappear from listings and their page.
        $this->configureStore([], enabled: true);
        $this->chino->update(['show_online' => false]);
        $this->asShopper();
        $this->get("/{$slug}/shop")->assertOk()->assertDontSee('Chino Trousers');
        $this->get("/{$slug}/product/{$this->chino->slug}")->assertNotFound();

        // Suspended shops are closed everywhere.
        $this->shop->update(['status' => 'suspended']);
        $this->get("/{$slug}")->assertForbidden();
    }

    public function test_bag_respects_online_stock_and_pending_orders_reserve_it(): void
    {
        $cart = app(Cart::class);
        $this->assertSame(3, $cart->add($this->blackM->id, 3));

        try {
            $cart->add($this->blackM->id, 1);
            $this->fail('Adding beyond stock should be refused.');
        } catch (BusinessException) {
            // expected
        }

        $cart->clear();
        $this->order([[$this->blackM, 2]]);

        // 3 in stock, 2 reserved by the pending order.
        $available = ProductVariant::query()->withOnlineStock()->whereKey($this->blackM->id)->value('available');
        $this->assertSame(1, (int) $available);
        $this->assertSame(1, app(Cart::class)->add($this->blackM->id, 5));
    }

    public function test_checkout_places_an_order_for_a_new_or_existing_customer(): void
    {
        $this->asShopper();
        $slug = $this->shop->slug;

        $this->postJson("/{$slug}/bag", ['variant_id' => $this->navyL->id, 'quantity' => 1])
            ->assertOk()->assertJson(['count' => 1])->assertJsonStructure(['html']);

        $this->enterShop($this->shop);
        Volt::test('store.checkout')
            ->set('name', 'Zara Ahmed')
            ->set('phone', '+92 333-1112223')
            ->set('address', 'Flat 4, Gulberg')
            ->set('city', 'Lahore')
            ->set('payment_method', 'cod')
            ->call('place')
            ->assertHasNoErrors()
            ->assertRedirect();

        $order = Order::query()->with('items', 'customer')->sole();
        $this->assertSame('pending', $order->status);
        $this->assertSame('03331112223', $order->customer->phone);
        $this->assertEquals(3000, $order->subtotal);
        $this->assertEquals(250, $order->shipping_fee); // under the Rs. 5,000 free delivery threshold
        $this->assertEquals(3250, $order->total);
        $this->assertSame(0, app(Cart::class)->count(), 'Bag is emptied after checkout');

        // The order page is visible in this session, not to strangers.
        $this->get("/{$slug}/orders/{$order->order_number}")->assertOk()->assertSee('Thank you');
        $this->flushSession();
        $this->get("/{$slug}/orders/{$order->order_number}")->assertRedirect("/{$slug}/track");
        $this->post("/{$slug}/track", ['order_number' => $order->order_number, 'phone' => '0333 1112223'])->assertRedirect("/{$slug}/orders/{$order->order_number}");

        // Same phone again: linked to the same customer, free delivery over Rs. 5,000.
        $second = $this->order([[$this->blackM, 2]], details: ['name' => 'Someone Else', 'phone' => '03331112223']);
        $this->assertSame($order->customer_id, $second->customer_id);
        $this->assertEquals(0, $second->shipping_fee);
        $this->assertSame('Zara Ahmed', Customer::query()->find($order->customer_id)->name, 'Guest details never overwrite a customer');
    }

    public function test_confirming_and_delivering_a_cod_order_posts_sale_stock_and_cash(): void
    {
        $order = $this->order([[$this->blackM, 1]]);
        $orders = app(OrderService::class);
        $drawerBefore = (float) $this->drawer->fresh()->current_balance;

        $orders->confirm($order, $this->main->id);
        $sale = Sale::query()->sole();

        $this->assertSame('online', $sale->channel);
        $this->assertEquals(250, $sale->shipping_fee);
        $this->assertEquals(3250, $sale->total_amount);
        $this->assertEquals(3250, $sale->due_amount);
        $this->assertSame(2, (int) Inventory::query()->where('branch_id', $this->main->id)->where('product_variant_id', $this->blackM->id)->value('quantity'));
        $this->assertEquals(3250, $order->customer->fresh()->current_balance);

        $orders->ship($order->fresh(), 'TCS', 'T123');
        $orders->deliver($order->fresh(), $this->drawer->id);

        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertEquals(0, $sale->fresh()->due_amount);
        $this->assertEquals($drawerBefore + 3250, (float) $this->drawer->fresh()->current_balance);
        $this->assertEquals(0, $order->customer->fresh()->current_balance);

        $pnl = app(ReportService::class)->profitAndLoss(today(), today(), [$this->main->id]);
        $this->assertEquals(3000, $pnl['net_sales']);
        $this->assertEquals(250, $pnl['delivery']);
        $this->assertEquals(3250, $pnl['net_revenue']);
        $this->assertEquals(1000, $pnl['cogs']);
    }

    public function test_cancelling_a_confirmed_order_restocks_and_clears_the_invoice(): void
    {
        $order = $this->order([[$this->blackM, 1]]);
        $orders = app(OrderService::class);
        $orders->confirm($order, $this->main->id);
        $orders->cancel($order->fresh(), 'Customer changed mind');

        $sale = Sale::query()->sole();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(3, (int) Inventory::query()->where('branch_id', $this->main->id)->where('product_variant_id', $this->blackM->id)->value('quantity'));
        $this->assertEquals(0, $sale->due_amount);
        $this->assertEquals(0, $sale->shipping_fee);
        $this->assertEquals(3000, $sale->returned_amount);
        $this->assertEquals(0, $order->customer->fresh()->current_balance);

        // Cancelled orders reserve nothing.
        $this->assertSame(3, (int) ProductVariant::query()->withOnlineStock()->whereKey($this->blackM->id)->value('available'));
    }

    public function test_prepaid_order_is_posted_to_the_bank_and_refunded_on_cancel(): void
    {
        $order = $this->order([[$this->navyL, 1]], 'bank', $this->bank->id);
        $bankBefore = (float) $this->bank->fresh()->current_balance;
        $orders = app(OrderService::class);

        $orders->markPaid($order);
        $orders->confirm($order->fresh(), $this->main->id);

        $this->assertSame('paid', Sale::query()->sole()->payment_status);
        $this->assertEquals($bankBefore + 3250, (float) $this->bank->fresh()->current_balance);

        $orders->cancel($order->fresh(), 'Out of stock at branch');

        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertEquals($bankBefore, (float) $this->bank->fresh()->current_balance);
        $this->assertEquals(0, $order->customer->fresh()->current_balance);
    }

    public function test_coupons_apply_limits_and_are_released_on_cancel(): void
    {
        Coupon::create(['code' => 'save10', 'type' => 'percent', 'value' => 10, 'max_discount' => 400, 'usage_limit' => 1]);
        Coupon::create(['code' => 'BIG', 'type' => 'fixed', 'value' => 500, 'min_order' => 10000]);
        $cart = app(Cart::class);

        $cart->add($this->blackM->id, 2);

        try {
            $cart->applyCoupon('BIG');
            $this->fail('A code below its minimum order must be refused.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('or more to use this code', $e->getMessage());
        }

        $coupon = $cart->applyCoupon('save10');
        $this->assertSame('SAVE10', $coupon->code);
        $this->assertEquals(400, $cart->summary()['discount']); // 10% of 6,000 capped at 400

        $order = app(OrderService::class)->place(['name' => 'Ali', 'phone' => '03005556677', 'address' => 'A', 'city' => 'Lahore', 'payment_method' => 'cod']);
        $this->assertEquals(400, $order->discount);
        $this->assertEquals(5600, $order->total); // 6,000 - 400, free delivery over 5,000
        $this->assertSame(1, Coupon::query()->where('code', 'SAVE10')->value('used_count'));

        // Usage limit reached.
        $cart->add($this->navyL->id, 1);
        $this->expectException(BusinessException::class);

        try {
            $cart->applyCoupon('SAVE10');
        } finally {
            app(OrderService::class)->cancel($order, 'Test');
            $this->assertSame(0, Coupon::query()->where('code', 'SAVE10')->value('used_count'), 'Cancelling releases the use');
        }
    }

    public function test_only_buyers_can_review_and_reviews_need_approval(): void
    {
        $slug = $this->shop->slug;
        $buyer = Customer::create(['name' => 'Rabia Noor', 'phone' => '03009998887', 'password' => 'password']);
        $stranger = Customer::create(['name' => 'Stranger', 'phone' => '03001110000', 'password' => 'password']);

        app(SaleService::class)->create([
            'branch_id' => $this->main->id, 'customer_id' => $buyer->id,
            'items' => [['variant_id' => $this->blackM->id, 'quantity' => 1, 'unit_price' => 3000]],
            'payments' => [['payment_account_id' => $this->drawer->id, 'amount' => 3000]],
        ]);

        $this->asShopper();
        $this->actingAs($stranger, 'customer')->post("/{$slug}/product/{$this->chino->id}/reviews", ['rating' => 1])->assertForbidden();
        $this->actingAs($buyer, 'customer')->post("/{$slug}/product/{$this->chino->id}/reviews", ['rating' => 5, 'body' => 'Great fit'])->assertRedirect();

        $this->enterShop($this->shop);
        $review = ProductReview::query()->sole();
        $this->assertSame('pending', $review->status);

        $this->asShopper();
        $this->get("/{$slug}/product/{$this->chino->slug}")->assertDontSee('Great fit');
        $review->update(['status' => 'approved']);
        $this->get("/{$slug}/product/{$this->chino->slug}")->assertSee('Great fit')->assertSee('Rabia N.');
    }

    public function test_shops_cannot_see_each_others_store_data(): void
    {
        $order = $this->order([[$this->blackM, 1]]);
        $other = app(TenantProvisioner::class)->create(['name' => 'Other Shop', 'owner_name' => 'O', 'owner_email' => 'other@example.com', 'owner_password' => 'password']);
        $other->update(['store_enabled' => true]);

        $this->asShopper();
        $this->get("/{$other->slug}")->assertOk()->assertDontSee('Chino Trousers');
        $this->get("/{$other->slug}/product/{$this->chino->slug}")->assertNotFound();
        $this->get("/{$other->slug}/orders/{$order->order_number}")->assertNotFound();

        // URL words used by the app can never become a shop slug.
        $this->assertSame('admin-2', app(TenantProvisioner::class)->uniqueSlug('Admin'));
        $this->assertSame('livewire-2', app(TenantProvisioner::class)->uniqueSlug('Livewire'));
    }

    public function test_back_office_store_screens(): void
    {
        $order = $this->order([[$this->blackM, 1]]);
        $this->enterShop($this->shop);
        $this->actingAs($this->owner, 'web');

        foreach (['app.orders.index', 'app.coupons', 'app.reviews', 'app.settings.store', 'app.settings.appearance'] as $route) {
            tenancy()->forget();
            $this->get(route($route))->assertOk();
        }

        tenancy()->forget();
        $this->get(route('app.orders.show', $order))->assertOk()->assertSee($order->order_number);
        $this->assertNotNull($order->fresh()->seen_at, 'Opening an order clears the new-order alert');

        $this->enterShop($this->shop);
        Volt::test('app.settings.appearance')->call('pickTheme', 'noor');
        $this->assertSame('noor', $this->shop->fresh()->store()->theme());

        Volt::test('app.settings.store')
            ->set('delivery_fee', 300)->set('free_delivery', true)->set('free_delivery_over', 4000)
            ->set('cod', false)->set('bank_accounts', [(string) $this->bank->id])
            ->call('save')->assertHasNoErrors();
        $store = $this->shop->fresh()->store();
        $this->assertEquals(300, $store->deliveryFeeFor(3999));
        $this->assertEquals(0, $store->deliveryFeeFor(4000));
        $this->assertSame(['bank'], array_keys($store->paymentMethods()));

        $this->enterShop($this->shop->fresh());
        Volt::test('app.orders.show', ['order' => $order])->set('branchId', $this->main->id)->call('confirm');
        $this->assertSame('confirmed', $order->fresh()->status);

        Volt::test('store.order-bell')->assertSee('No orders waiting');
    }

    public function test_colour_photos_and_size_chart_are_managed_from_the_product_form(): void
    {
        Storage::fake('public');
        $navy = $this->colorId('Navy Blue');

        $form = Volt::test('app.products.form', ['product' => $this->chino])
            ->set("colorPhotos.{$navy}", UploadedFile::fake()->image('navy.jpg', 600, 750))
            ->assertHasNoErrors();

        $photo = ProductColorImage::query()->sole();
        $this->assertSame($navy, $photo->color_id);
        Storage::disk('public')->assertExists($photo->image);

        $form->set('sizeChart', UploadedFile::fake()->image('chart.png'))
            ->set('compare_at_price', 3600)
            ->set('show_online', true)
            ->call('save')
            ->assertHasNoErrors();

        $chino = $this->chino->fresh();
        $this->assertNotNull($chino->size_chart);
        $this->assertEquals(3600, $chino->compare_at_price);

        // The store shows the colour photo and the size chart link.
        $this->asShopper();
        $this->get("/{$this->shop->slug}/product/{$chino->slug}")->assertOk()
            ->assertSee($photo->url)
            ->assertSee('Size chart');

        $this->enterShop($this->shop);
        Volt::test('app.products.form', ['product' => $chino])->call('removeColorPhoto', $navy);
        $this->assertSame(0, ProductColorImage::query()->count());
        Storage::disk('public')->assertMissing($photo->image);
    }

    public function test_payment_methods_follow_the_settings(): void
    {
        $wallet = app(TenantProvisioner::class)->createAccount(['account_name' => 'Easypaisa', 'account_type' => PaymentAccount::TYPE_WALLET, 'account_number' => '03451234567']);
        $this->configureStore(['cod' => false, 'bank_accounts' => [], 'wallet_accounts' => [$wallet->id]]);

        $this->assertSame(['wallet'], array_keys($this->shop->store()->paymentMethods()));

        app(Cart::class)->add($this->blackM->id, 1);
        $this->expectException(BusinessException::class);
        app(OrderService::class)->place(['name' => 'A', 'phone' => '03005556677', 'address' => 'A', 'city' => 'B', 'payment_method' => 'cod']);
    }
}
