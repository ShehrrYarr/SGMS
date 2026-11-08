<?php

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;
use App\Models\Vendor;
use App\Services\InventoryService;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use App\Services\StockTransferService;
use App\Services\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesShop;
use Tests\TestCase;

class SaleFlowTest extends TestCase
{
    use CreatesShop, RefreshDatabase;

    protected ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createShop();

        $hoodie = $this->makeProduct('Hoodie', 3000);
        app(PurchaseService::class)->create([
            'branch_id' => $this->main->id,
            'vendor_id' => Vendor::create(['name' => 'Mills'])->id,
            'total_lot_cost' => 10000,
            'items' => [['product_id' => $hoodie->id, 'color_id' => $this->colorId(), 'size_id' => $this->sizeId('L'), 'quantity' => 10, 'cost_per_item' => 1000, 'retail_price' => 3000]],
        ]);

        $this->variant = ProductVariant::query()->firstOrFail();
    }

    protected function sell(array $overrides = []): Sale
    {
        return app(SaleService::class)->create(array_merge([
            'branch_id' => $this->main->id,
            'items' => [['variant_id' => $this->variant->id, 'quantity' => 2, 'unit_price' => 3000]],
            'payments' => [['payment_account_id' => $this->drawer->id, 'amount' => 6000]],
        ], $overrides));
    }

    public function test_cash_sale_updates_stock_and_drawer(): void
    {
        $sale = $this->sell();

        $this->assertSame('INV-000001', $sale->invoice_number);
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame(8, (int) Inventory::query()->sum('quantity'));
        $this->assertEquals(106000, (float) $this->drawer->fresh()->current_balance);
        $this->assertEquals(1000, (float) $sale->items->first()->cost_price); // cost snapshot for profit
    }

    public function test_walk_in_must_pay_in_full(): void
    {
        $this->expectException(BusinessException::class);
        $this->sell(['payments' => [['payment_account_id' => $this->drawer->id, 'amount' => 1000]]]);
    }

    public function test_credit_sale_for_registered_customer_posts_ledger(): void
    {
        $customer = Customer::create(['name' => 'Bilal', 'phone' => '03001234567']);
        $sale = $this->sell(['customer_id' => $customer->id, 'discount' => 500, 'payments' => [['payment_account_id' => $this->drawer->id, 'amount' => 2000]]]);

        $this->assertEquals(5500, (float) $sale->total_amount);
        $this->assertEquals(3500, (float) $sale->due_amount);
        $this->assertEquals(3500, (float) $customer->fresh()->current_balance);

        app(SaleService::class)->receivePayment($customer, $this->bank->id, 3500);
        $this->assertSame('paid', $sale->fresh()->payment_status);
        $this->assertEquals(0, (float) $customer->fresh()->current_balance);
    }

    public function test_overselling_is_blocked(): void
    {
        $this->expectException(BusinessException::class);
        $this->sell(['items' => [['variant_id' => $this->variant->id, 'quantity' => 11, 'unit_price' => 3000]], 'payments' => [['payment_account_id' => $this->drawer->id, 'amount' => 33000]]]);
    }

    public function test_cash_return_restocks_and_refunds(): void
    {
        $sale = $this->sell();
        $item = $sale->items->first();

        $return = app(SaleReturnService::class)->create($sale, [$item->id => 1], 'cash', $this->drawer->id, 'Too small');

        $this->assertEquals(3000, (float) $return->refunded_amount);
        $this->assertSame(9, (int) Inventory::query()->sum('quantity'));
        $this->assertEquals(103000, (float) $this->drawer->fresh()->current_balance);
        $this->assertSame(1, $item->fresh()->returned_quantity);
    }

    public function test_return_on_credit_invoice_clears_the_due_first(): void
    {
        $customer = Customer::create(['name' => 'Sana', 'phone' => '03211234567']);
        $sale = $this->sell(['customer_id' => $customer->id, 'payments' => []]); // fully on credit: 6,000 due

        $return = app(SaleReturnService::class)->create($sale, [$sale->items->first()->id => 1], 'cash', $this->drawer->id);

        $this->assertEquals(3000, (float) $return->adjusted_amount);
        $this->assertEquals(0, (float) $return->refunded_amount);      // no cash out: it reduced the debt
        $this->assertEquals(3000, (float) $customer->fresh()->current_balance);
        $this->assertEquals(100000, (float) $this->drawer->fresh()->current_balance);
    }

    public function test_exchange_credit_is_used_in_the_pos(): void
    {
        $sale = $this->sell();
        $return = app(SaleReturnService::class)->create($sale, [$sale->items->first()->id => 1], 'exchange');
        $this->assertTrue($return->isPendingExchange());

        $exchangeSale = $this->sell([
            'exchange_return_id' => $return->id,
            'items' => [['variant_id' => $this->variant->id, 'quantity' => 2, 'unit_price' => 3000]],
            'payments' => [['payment_account_id' => $this->drawer->id, 'amount' => 3000]],
        ]);

        $this->assertSame('paid', $exchangeSale->payment_status);
        $this->assertSame('completed', $return->fresh()->status);
        $this->assertSame($exchangeSale->id, $return->fresh()->exchange_sale_id);
        $this->assertEquals(109000, (float) $this->drawer->fresh()->current_balance); // 6k + 3k
    }

    public function test_stock_transfer_between_branches(): void
    {
        $mall = app(TenantProvisioner::class)->createBranch(['name' => 'Mall', 'code' => 'MALL']);

        app(StockTransferService::class)->create($this->main->id, $mall->id, [['variant_id' => $this->variant->id, 'quantity' => 4]]);

        $this->assertSame(6, app(InventoryService::class)->available($this->main->id, $this->variant->id));
        $this->assertSame(4, app(InventoryService::class)->available($mall->id, $this->variant->id));
        $this->assertNotNull(Branch::query()->find($mall->id)->cashDrawer);
    }

    public function test_pos_checkout_component(): void
    {
        Volt::test('app.pos')
            ->call('checkout', [
                'items' => [['variant_id' => $this->variant->id, 'quantity' => 1, 'unit_price' => 3000, 'discount' => 0]],
                'customer_id' => null,
                'discount' => 0,
                'apply_tax' => false,
                'payments' => [['payment_account_id' => $this->drawer->id, 'amount' => 3000]],
                'tendered' => 5000,
                'change' => 2000,
            ]);

        $this->assertSame(1, Sale::query()->count());
        $this->assertEquals(103000, (float) $this->drawer->fresh()->current_balance);
    }

    public function test_cashier_cannot_override_prices_at_the_pos(): void
    {
        $cashier = User::create(['name' => 'Till', 'email' => 'till@example.com', 'password' => 'password', 'role' => 'cashier', 'branch_id' => $this->main->id]);
        $this->actingAs($cashier, 'web');
        tenancy()->setUser($cashier);

        Volt::test('app.pos')->call('checkout', [
            'items' => [['variant_id' => $this->variant->id, 'quantity' => 1, 'unit_price' => 100, 'discount' => 0]],
            'payments' => [['payment_account_id' => $this->drawer->id, 'amount' => 100]],
        ]);

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, SaleReturn::query()->count());
    }
}
