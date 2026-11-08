<?php

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Http\Middleware\SecurityHeaders;
use App\Models\Branch;
use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use App\Services\Store\Cart;
use App\Services\Store\OrderService;
use App\Services\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesShop;
use Tests\TestCase;

/**
 * Regression tests for the findings of the November 2026 security and
 * calculation review (see the review report).
 */
class SecurityFixesTest extends TestCase
{
    use CreatesShop, RefreshDatabase;

    protected Product $shirt;

    protected ProductVariant $variant;

    protected Branch $mall;

    protected PaymentAccount $mallDrawer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createShop('Review Garments');

        $this->mall = app(TenantProvisioner::class)->createBranch(['name' => 'Mall', 'code' => 'MALL']);
        $this->mallDrawer = $this->mall->cashDrawer()->firstOrFail();

        $this->shirt = $this->makeProduct('Review Shirt', 2299);
        app(PurchaseService::class)->create([
            'branch_id' => $this->main->id,
            'vendor_id' => Vendor::create(['name' => 'Mills'])->id,
            'purchase_date' => today()->toDateString(),
            'total_lot_cost' => 9500,
            'items' => [['product_id' => $this->shirt->id, 'color_id' => $this->colorId('Black'), 'size_id' => $this->sizeId('M'), 'quantity' => 10, 'cost_per_item' => 950, 'retail_price' => 2299]],
        ]);
        $this->variant = ProductVariant::query()->where('product_id', $this->shirt->id)->firstOrFail();
    }

    protected function cashierAtMain(): User
    {
        $cashier = User::create(['name' => 'Till', 'email' => 'till@review.test', 'password' => 'password', 'role' => User::ROLE_CASHIER, 'branch_id' => $this->main->id]);
        $this->actingAs($cashier, 'web');
        tenancy()->setUser($cashier);
        tenancy()->setBranchId($this->main->id);

        return $cashier;
    }

    protected function sell(int $qty, float $discount = 0, float $taxRate = 0): Sale
    {
        return app(SaleService::class)->create([
            'branch_id' => $this->main->id,
            'items' => [['variant_id' => $this->variant->id, 'quantity' => $qty, 'unit_price' => 2299]],
            'discount' => $discount,
            'tax_rate' => $taxRate,
            'payments' => [['payment_account_id' => $this->drawer->id, 'amount' => round(($qty * 2299 - $discount) * (1 + $taxRate / 100), 2)]],
        ]);
    }

    public function test_refunds_and_payments_cannot_use_another_branchs_drawer(): void
    {
        $sale = $this->sell(2);
        $this->cashierAtMain();
        $item = $sale->items()->firstOrFail();

        try {
            app(SaleReturnService::class)->create($sale, [$item->id => 1], 'cash', $this->mallDrawer->id);
            $this->fail('A Main Store refund was paid from the Mall drawer.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('belongs to another branch', $e->getMessage());
        }

        $this->assertEquals(0, $sale->fresh()->returned_amount);

        // The branch's own drawer and shop-wide accounts still work.
        app(SaleReturnService::class)->create($sale, [$item->id => 1], 'cash', $this->drawer->id);
        $this->assertEquals(2299, $sale->fresh()->returned_amount);

        $this->expectException(BusinessException::class);
        app(PurchaseService::class)->payVendor(Vendor::query()->firstOrFail(), $this->mallDrawer->id, 100);
    }

    public function test_the_last_return_refunds_exactly_what_is_left_of_the_invoice(): void
    {
        // 10% discount + 5% tax makes per-unit refunds round: 3 x 2,172.56 = 6,517.68 vs 6,517.67 paid.
        $sale = $this->sell(3, 689.70, 5);
        $this->assertEquals(6517.67, (float) $sale->total_amount);
        $item = $sale->items()->firstOrFail();

        $returns = app(SaleReturnService::class);
        $first = $returns->create($sale, [$item->id => 1], 'cash', $this->drawer->id);
        $second = $returns->create($sale->fresh(), [$item->id => 2], 'cash', $this->drawer->id);

        $this->assertEquals(2172.56, (float) $first->total_amount);
        $this->assertEquals(4345.11, (float) $second->total_amount);
        $this->assertEquals((float) $sale->total_amount, (float) $sale->fresh()->returned_amount);
        $this->assertEquals((float) $second->total_amount, (float) $second->items->sum('total_price'));
    }

    public function test_lot_cost_must_match_the_item_costs(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('Item costs add up to');

        app(PurchaseService::class)->create([
            'branch_id' => $this->main->id,
            'vendor_id' => Vendor::query()->firstOrFail()->id,
            'total_lot_cost' => 12000,
            'items' => [['product_id' => $this->shirt->id, 'color_id' => $this->colorId('Black'), 'size_id' => $this->sizeId('L'), 'quantity' => 10, 'cost_per_item' => 950, 'retail_price' => 2299]],
        ]);
    }

    public function test_averaging_residue_within_a_paisa_per_piece_is_accepted(): void
    {
        $purchase = app(PurchaseService::class)->create([
            'branch_id' => $this->main->id,
            'vendor_id' => Vendor::query()->firstOrFail()->id,
            'total_lot_cost' => 10000,
            'items' => [['product_id' => $this->shirt->id, 'color_id' => $this->colorId('Black'), 'size_id' => $this->sizeId('L'), 'quantity' => 3, 'cost_per_item' => 3333.33, 'retail_price' => 2299]],
        ]);

        $this->assertEquals(10000, (float) $purchase->total_lot_cost);
    }

    public function test_payment_receipts_are_private_and_only_staff_can_open_them(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $settings = $this->shop->settings ?? [];
        $settings['store'] = ['cod' => true, 'bank_accounts' => [$this->bank->id], 'delivery_fee' => 0];
        $this->shop->update(['settings' => $settings, 'store_enabled' => true]);
        $this->enterShop($this->shop->fresh());

        app(Cart::class)->add($this->variant->id, 1);
        $order = app(OrderService::class)->place([
            'name' => 'Zara', 'phone' => '0321 9998877', 'address' => 'House 1', 'city' => 'Lahore',
            'payment_method' => 'bank', 'payment_account_id' => $this->bank->id,
        ], null, UploadedFile::fake()->image('receipt.png'));

        Storage::disk(Order::PROOF_DISK)->assertExists($order->payment_proof);
        Storage::disk('public')->assertMissing($order->payment_proof);
        $this->assertStringContainsString('/app/orders/'.$order->order_number.'/receipt', $order->payment_proof_url);

        $this->get($order->payment_proof_url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        Auth::guard('web')->logout();
        $this->get($order->payment_proof_url)->assertRedirect(route('app.login'));
    }

    public function test_unknown_report_grouping_falls_back_instead_of_crashing(): void
    {
        $this->get('/app/reports/sales?group=zzz&sort=zzz')->assertOk()->assertSee('Net sales by category');
    }

    public function test_pages_send_security_headers(): void
    {
        $response = $this->get('/app')->assertOk();

        foreach (SecurityHeaders::HEADERS as $name => $value) {
            $response->assertHeader($name, $value);
        }
    }

    public function test_store_rate_limits_are_counted_per_action(): void
    {
        $settings = $this->shop->settings ?? [];
        $settings['store'] = ['cod' => true, 'delivery_fee' => 0];
        $this->shop->update(['settings' => $settings, 'store_enabled' => true]);
        Auth::guard('web')->logout();
        tenancy()->forget();
        $slug = $this->shop->slug;

        for ($i = 0; $i < 12; $i++) {
            $this->postJson("/{$slug}/bag", ['variant_id' => $this->variant->id, 'quantity' => 1]);
        }

        // Bag activity must not use up the order-tracking allowance.
        $this->post("/{$slug}/track", ['order_number' => 'ORD-999999', 'phone' => '03000000000'])->assertRedirect();
    }

    public function test_online_payments_are_not_labelled_as_pos_payments(): void
    {
        $sale = app(SaleService::class)->create([
            'branch_id' => $this->main->id,
            'channel' => 'online',
            'customer_id' => \App\Models\Customer::create(['name' => 'Web Buyer', 'phone' => '03111111111'])->id,
            'items' => [['variant_id' => $this->variant->id, 'quantity' => 1, 'unit_price' => 2299]],
            'payments' => [['payment_account_id' => $this->bank->id, 'amount' => 2299]],
        ]);

        $note = \App\Models\CustomerLedger::query()->where('sale_id', $sale->id)->where('type', 'payment')->value('note');
        $this->assertStringStartsWith('Paid online via', $note);
    }
}
