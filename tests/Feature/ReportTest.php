<?php

namespace Tests\Feature;

use App\Models\ExpenseCategory;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\StockSnapshot;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ExpenseService;
use App\Services\InventoryService;
use App\Services\PurchaseService;
use App\Services\ReportService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use App\Services\StockSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesShop;
use Tests\TestCase;

/**
 * Scenario (all amounts hand-checked):
 *  - 10 pcs bought at 1,000 (5 days ago)
 *  - 2 pcs sold at 3,000 with a 500 invoice discount (2 days ago)  -> net sales 5,500, COGS 2,000
 *  - 1 pc returned for cash (restocked)                          -> returns 2,750, COGS back 1,000
 *  - Rent expense 1,000; 1 damaged pc written off at cost 1,000
 *  => net revenue 2,750, gross profit 1,750, net profit -250
 */
class ReportTest extends TestCase
{
    use CreatesShop, RefreshDatabase;

    protected ProductVariant $variant;

    protected Sale $sale;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createShop();

        $shirt = $this->makeProduct('Report Shirt', 3000);
        app(PurchaseService::class)->create([
            'branch_id' => $this->main->id,
            'vendor_id' => Vendor::create(['name' => 'Mills'])->id,
            'total_lot_cost' => 10000,
            'purchase_date' => today()->subDays(5)->toDateString(),
            'items' => [['product_id' => $shirt->id, 'color_id' => $this->colorId(), 'size_id' => $this->sizeId('M'), 'quantity' => 10, 'cost_per_item' => 1000, 'retail_price' => 3000]],
        ]);
        $this->variant = ProductVariant::query()->firstOrFail();

        $this->sale = app(SaleService::class)->create([
            'branch_id' => $this->main->id,
            'sale_date' => now()->subDays(2),
            'discount' => 500,
            'items' => [['variant_id' => $this->variant->id, 'quantity' => 2, 'unit_price' => 3000]],
            'payments' => [['payment_account_id' => $this->drawer->id, 'amount' => 5500]],
        ]);

        app(SaleReturnService::class)->create($this->sale, [$this->sale->items->first()->id => 1], 'cash', $this->drawer->id);

        app(ExpenseService::class)->create([
            'branch_id' => $this->main->id,
            'expense_category_id' => ExpenseCategory::query()->where('name', 'Rent')->value('id'),
            'payment_account_id' => $this->bank->id,
            'amount' => 1000,
            'expense_date' => today()->subDay()->toDateString(),
        ]);

        app(InventoryService::class)->adjust($this->main->id, $this->variant->id, -1, 'adjustment', note: 'Damaged', allowNegative: true);
    }

    public function test_profit_and_loss_statement(): void
    {
        $pnl = app(ReportService::class)->profitAndLoss(today()->subDays(7), today(), [$this->main->id]);

        $this->assertEquals(6000, $pnl['gross_sales']);
        $this->assertEquals(500, $pnl['discounts']);
        $this->assertEquals(5500, $pnl['net_sales']);
        $this->assertEquals(2750, $pnl['returns']);
        $this->assertEquals(2750, $pnl['net_revenue']);
        $this->assertEquals(1000, $pnl['cogs']);
        $this->assertEquals(1750, $pnl['gross_profit']);
        $this->assertEquals(1000, $pnl['expenses_total']);
        $this->assertEquals(-1000, $pnl['adjustments']);
        $this->assertEquals(-250, $pnl['net_profit']);
        $this->assertSame(1, $pnl['invoices']);
        $this->assertSame(2, $pnl['items_sold']);

        // A period before any trading is empty.
        $empty = app(ReportService::class)->profitAndLoss(today()->subDays(30), today()->subDays(20), [$this->main->id]);
        $this->assertEquals(0, $empty['net_profit']);
    }

    public function test_monthly_trend_matches_the_statement(): void
    {
        $trend = app(ReportService::class)->monthlyTrend(3, [$this->main->id]);
        $this->assertCount(3, $trend);

        $total = collect($trend)->sum('net_profit');
        $this->assertEquals(-250, round($total, 2));
    }

    public function test_sales_breakdown_by_category_staff_and_payment(): void
    {
        $reports = app(ReportService::class);
        [$from, $to] = [today()->subDays(7), today()];

        $byCategory = $reports->salesBreakdown($from, $to, [$this->main->id], 'category');
        $this->assertCount(1, $byCategory);
        $this->assertSame('Uncategorised', $byCategory[0]['label']);
        $this->assertEquals(5500, $byCategory[0]['revenue']);
        $this->assertEquals(3500, $byCategory[0]['profit']);
        $this->assertEquals(100, $byCategory[0]['share']);

        $byStaff = $reports->salesBreakdown($from, $to, [$this->main->id], 'staff');
        $this->assertSame($this->owner->name, $byStaff[0]['label']);

        $byPayment = $reports->salesBreakdown($from, $to, [$this->main->id], 'payment');
        $this->assertEquals(5500, $byPayment->sum('revenue'));

        foreach (array_keys(ReportService::SALES_GROUPS) as $group) {
            $this->assertNotEmpty($reports->salesBreakdown($from, $to, [$this->main->id], $group), "Group {$group} returned nothing");
        }
    }

    public function test_stock_history_is_rebuilt_from_movements(): void
    {
        app(StockSnapshotService::class)->backfill(today()->subDays(7), today());

        $units = fn ($daysAgo) => (int) StockSnapshot::query()->where('snapshot_date', today()->subDays($daysAgo)->toDateString())->sum('units');

        $this->assertSame(0, $units(6));   // before the lot
        $this->assertSame(10, $units(4));  // lot received
        $this->assertSame(8, $units(1));   // 2 sold
        $this->assertSame(8, $units(0));   // +1 returned, -1 written off

        $flow = app(ReportService::class)->stockFlow(today()->subDays(3), today(), [$this->main->id]);
        $this->assertSame(10, $flow['opening']);
        $this->assertSame(-2, $flow['types']['sale']['qty']);
        $this->assertSame(8, $flow['closing']);

        $valuation = app(ReportService::class)->stockValuation([$this->main->id]);
        $this->assertEquals(8000, $valuation->sum('cost_value'));
        $this->assertEquals(24000, $valuation->sum('retail_value'));
    }

    public function test_report_pages_render_and_export(): void
    {
        Volt::test('app.reports.profit-loss')->assertSee('Net profit')->call('export')->assertFileDownloaded();
        Volt::test('app.reports.sales')->call('setGroup', 'product')->assertSee('Report Shirt')->call('export')->assertFileDownloaded();
        Volt::test('app.reports.stock')->call('rebuild')->assertSee('Stock flow')->call('export')->assertFileDownloaded();
        Volt::test('app.reports.profit-loss')->set('range', 'custom')->set('from', today()->subDays(10)->toDateString())->assertSee('Net revenue');
    }

    public function test_cashiers_cannot_open_reports(): void
    {
        $cashier = User::create(['name' => 'Till', 'email' => 'till@example.com', 'password' => 'password', 'role' => 'cashier', 'branch_id' => $this->main->id]);
        tenancy()->forget();

        $this->actingAs($cashier, 'web')->get(route('app.reports.profit-loss'))->assertForbidden();
        $this->actingAs($this->owner, 'web')->get(route('app.reports'))->assertRedirect('/app/reports/profit-loss');
    }
}
