<?php

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\Inventory;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\Vendor;
use App\Services\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesShop;
use Tests\TestCase;

class PurchaseFlowTest extends TestCase
{
    use CreatesShop, RefreshDatabase;

    protected function lot(array $overrides = []): array
    {
        $shirt = $this->makeProduct('Oxford Shirt', 1500);

        return array_merge([
            'branch_id' => $this->main->id,
            'vendor_id' => Vendor::create(['name' => 'Mills'])->id,
            'total_lot_cost' => 30000,
            'purchase_date' => today()->toDateString(),
            'items' => [
                ['product_id' => $shirt->id, 'color_id' => $this->colorId(), 'size_id' => $this->sizeId('M'), 'quantity' => 20, 'cost_per_item' => 600, 'retail_price' => 1500],
                ['product_id' => $shirt->id, 'color_id' => $this->colorId(), 'size_id' => $this->sizeId('L'), 'quantity' => 30, 'cost_per_item' => 600, 'retail_price' => 1500],
            ],
        ], $overrides);
    }

    public function test_split_partial_credit_purchase_posts_everything(): void
    {
        $this->createShop();
        $data = $this->lot(['payments' => [
            ['payment_account_id' => $this->drawer->id, 'amount' => 10000],
            ['payment_account_id' => $this->bank->id, 'amount' => 15000, 'reference_no' => 'TRX-1'],
        ]]);

        $purchase = app(PurchaseService::class)->create($data);

        $this->assertSame('partial', $purchase->payment_status);
        $this->assertSame('split', $purchase->payment_method);
        $this->assertEquals(5000, (float) $purchase->due_amount);
        $this->assertSame(50, (int) Inventory::query()->where('branch_id', $this->main->id)->sum('quantity'));
        $this->assertSame(2, ProductVariant::query()->count());
        $this->assertNotEmpty(ProductVariant::query()->first()->barcode);

        $this->assertEquals(90000, (float) $this->drawer->fresh()->current_balance);   // 100k - 10k
        $this->assertEquals(485000, (float) $this->bank->fresh()->current_balance);    // 500k - 15k
        $this->assertEquals(5000, (float) Vendor::query()->first()->current_balance);   // owed

        $ledger = Vendor::query()->first()->ledgers()->orderBy('id')->get();
        $this->assertSame(['purchase', 'payment', 'payment'], $ledger->pluck('type')->all());
        $this->assertEquals(5000, (float) $ledger->last()->running_balance);
    }

    public function test_full_credit_purchase_moves_no_money(): void
    {
        $this->createShop();
        $purchase = app(PurchaseService::class)->create($this->lot());

        $this->assertSame('unpaid', $purchase->payment_status);
        $this->assertSame('credit', $purchase->payment_method);
        $this->assertEquals(100000, (float) $this->drawer->fresh()->current_balance);
        $this->assertEquals(30000, (float) $purchase->vendor->fresh()->current_balance);
    }

    public function test_paying_from_an_empty_drawer_is_rejected_and_rolled_back(): void
    {
        $this->createShop(openingCash: 1000);

        try {
            app(PurchaseService::class)->create($this->lot(['payments' => [['payment_account_id' => $this->drawer->id, 'amount' => 30000]]]));
            $this->fail('Expected insufficient balance.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('Insufficient balance', $e->getMessage());
        }

        $this->assertSame(0, Purchase::query()->count());
        $this->assertSame(0, (int) Inventory::query()->sum('quantity'));
    }

    public function test_vendor_payment_is_allocated_to_oldest_lots_first(): void
    {
        $this->createShop();
        $service = app(PurchaseService::class);
        $first = $service->create($this->lot(['purchase_date' => today()->subDays(5)->toDateString()]));
        $vendorId = $first->vendor_id;
        $second = $service->create(array_merge($this->lot(['vendor_id' => $vendorId]), ['items' => [
            ['product_id' => $first->items->first()->variant->product_id, 'color_id' => $this->colorId('White'), 'size_id' => $this->sizeId('S'), 'quantity' => 10, 'cost_per_item' => 3000, 'retail_price' => 5000],
        ]]));

        $service->payVendor(Vendor::query()->find($vendorId), $this->bank->id, 40000);

        $this->assertSame('paid', $first->fresh()->payment_status);
        $this->assertEquals(20000, (float) $second->fresh()->due_amount);
        $this->assertEquals(20000, (float) Vendor::query()->find($vendorId)->current_balance);
    }

    public function test_weighted_average_cost_on_repeat_lots(): void
    {
        $this->createShop();
        $service = app(PurchaseService::class);
        $first = $service->create($this->lot());
        $variant = $first->items->first()->variant;

        $service->create($this->lot(['vendor_id' => $first->vendor_id, 'total_lot_cost' => 20000, 'items' => [
            ['product_id' => $variant->product_id, 'color_id' => $variant->color_id, 'size_id' => $variant->size_id, 'quantity' => 20, 'cost_per_item' => 1000, 'retail_price' => 1600],
        ]]));

        // (20 x 600 + 20 x 1000) / 40 = 800
        $this->assertEquals(800, (float) $variant->fresh()->cost_price);
        $this->assertEquals(1600, (float) $variant->fresh()->retail_price);
    }

    public function test_lot_intake_wizard_auto_averages_and_saves(): void
    {
        $this->createShop();
        $vendor = Vendor::create(['name' => 'Wizard Mills']);
        $shirt = $this->makeProduct('Wizard Shirt', 1200);

        Volt::test('app.purchases.create')
            ->set('vendor_id', $vendor->id)
            ->set('total_lot_cost', 10000)
            ->set('items.0.product_id', $shirt->id)
            ->set('items.0.color_id', $this->colorId())
            ->set('items.0.size_id', $this->sizeId('M'))
            ->set('items.0.quantity', 20)
            ->set('items.0.retail_price', 1200)
            ->assertSet('items.0.cost_per_item', 500.0)  // 10,000 / 20 pieces
            ->set('payment_mode', 'partial')
            ->set('cash_amount', 4000)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $purchase = Purchase::query()->firstOrFail();
        $this->assertSame('partial', $purchase->payment_status);
        $this->assertEquals(6000, (float) $purchase->due_amount);
        $this->assertEquals(6000, (float) $vendor->fresh()->current_balance);
    }
}
