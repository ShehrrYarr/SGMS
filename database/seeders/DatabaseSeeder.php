<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Color;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\PaymentAccount;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\Size;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ExpenseService;
use App\Services\InventoryService;
use App\Services\LedgerService;
use App\Services\ProductService;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use App\Services\StockSnapshotService;
use App\Services\StockTransferService;
use App\Services\TenantProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Demo data. Every financial record is created through the real services,
 * so ledgers, account balances and stock all reconcile.
 *
 *   Super admin   admin@platform.com        / password   -> /admin
 *   Shop owner    owner@urbangarments.com  / password   -> /app
 *   Manager       manager@urbangarments.com / password  -> /app (City Mall)
 *   Cashier       cashier@urbangarments.com / password  -> /app (Main Store)
 *   Customer      03001234567              / password   -> /portal/urban-garments
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        SuperAdmin::updateOrCreate(
            ['email' => 'admin@platform.com'],
            ['name' => 'Platform Admin', 'password' => 'password'],
        );

        $provisioner = app(TenantProvisioner::class);

        $tenant = $provisioner->create([
            'name' => 'Urban Garments',
            'slug' => 'urban-garments',
            'plan' => 'premium',
            'phone' => '042-35761234',
            'address' => 'Main Boulevard, Gulberg III, Lahore',
            'owner_name' => 'Ahmed Raza',
            'owner_email' => 'owner@urbangarments.com',
            'owner_password' => 'password',
            'branch_name' => 'Main Store',
            'opening_cash' => 75000,
            'tax_rate' => 0,
        ]);

        tenancy()->run($tenant, fn () => $this->seedUrbanGarments($tenant, $provisioner));

        // A second, suspended shop to demonstrate the platform controls.
        $provisioner->create([
            'name' => 'Denim Corner',
            'slug' => 'denim-corner',
            'plan' => 'basic',
            'trial_days' => 14,
            'owner_name' => 'Usman Ali',
            'owner_email' => 'owner@denimcorner.com',
            'owner_password' => 'password',
        ])->update(['status' => Tenant::STATUS_SUSPENDED]);
    }

    protected function seedUrbanGarments(Tenant $tenant, TenantProvisioner $provisioner): void
    {
        $main = Branch::query()->where('is_main', true)->firstOrFail();
        $owner = User::query()->where('role', User::ROLE_OWNER)->firstOrFail();
        Auth::guard('web')->setUser($owner); // attribute created_by to the owner

        $cityMall = $provisioner->createBranch([
            'name' => 'City Mall Branch',
            'code' => 'CMB',
            'phone' => '042-35870011',
            'address' => 'Shop 24, Ground Floor, Emporium Mall, Lahore',
            'default_cash_drawer_balance' => 20000,
        ]);

        User::create(['tenant_id' => $tenant->id, 'branch_id' => $cityMall->id, 'name' => 'Sara Malik', 'email' => 'manager@urbangarments.com', 'password' => 'password', 'role' => User::ROLE_MANAGER]);
        User::create(['tenant_id' => $tenant->id, 'branch_id' => $main->id, 'name' => 'Hamza Tariq', 'email' => 'cashier@urbangarments.com', 'password' => 'password', 'role' => User::ROLE_CASHIER]);

        $meezan = $provisioner->createAccount(['account_name' => 'Meezan Bank', 'account_type' => PaymentAccount::TYPE_BANK, 'bank_name' => 'Meezan Bank Ltd', 'account_number' => '0210-0104567890', 'opening_balance' => 500000]);
        $hbl = $provisioner->createAccount(['account_name' => 'HBL Current', 'account_type' => PaymentAccount::TYPE_BANK, 'bank_name' => 'Habib Bank Ltd', 'account_number' => '1234-79001234567', 'opening_balance' => 300000]);
        $provisioner->createAccount(['account_name' => 'JazzCash', 'account_type' => PaymentAccount::TYPE_WALLET, 'account_number' => '03001112233', 'opening_balance' => 0]);

        // Attributes (sizes S-XXL and the four colours come from the provisioner).
        $categories = collect(['Shirts', 'Denim', 'Hoodies'])->mapWithKeys(fn ($n) => [$n => Category::create(['name' => $n])]);
        $brands = collect(['Urban Basics', 'Denim Republic', 'Northline'])->mapWithKeys(fn ($n) => [$n => Brand::create(['name' => $n])]);
        $size = Size::query()->pluck('id', 'code');
        $color = Color::query()->pluck('id', 'name');

        $products = app(ProductService::class);
        $shirt = $products->create(['name' => 'Oxford Cotton Shirt', 'category_id' => $categories['Shirts']->id, 'brand_id' => $brands['Urban Basics']->id, 'base_cost' => 550, 'base_price' => 1499, 'description' => '100% cotton Oxford weave, regular fit.']);
        $denim = $products->create(['name' => 'Slim Fit Denim Jeans', 'category_id' => $categories['Denim']->id, 'brand_id' => $brands['Denim Republic']->id, 'base_cost' => 900, 'base_price' => 2499, 'description' => 'Stretch denim, mid-rise slim fit.']);
        $hoodie = $products->create(['name' => 'Fleece Pullover Hoodie', 'category_id' => $categories['Hoodies']->id, 'brand_id' => $brands['Northline']->id, 'base_cost' => 1000, 'base_price' => 2999, 'description' => 'Brushed fleece, kangaroo pocket.']);

        $ledger = app(LedgerService::class);
        $mills = Vendor::create(['name' => 'Kashif Mehmood', 'company' => 'Faisalabad Textile Mills', 'phone' => '0300-7654321', 'email' => 'sales@fbdtextile.pk', 'address' => 'Jhang Road, Faisalabad', 'opening_balance' => 0]);
        $denimHouse = Vendor::create(['name' => 'Rizwan Ahmed', 'company' => 'Lahore Denim House', 'phone' => '0321-4455667', 'address' => 'Shah Alam Market, Lahore', 'opening_balance' => 15000]);
        $ledger->openVendor($denimHouse, 15000);

        // Sample lot: 255 pieces, Rs. 193,500. Paid 50k cash + 100k Meezan, 43.5k on vendor credit.
        $row = fn ($product, $colorName, $sizeCode, $qty, $cost, $retail) => [
            'product_id' => $product->id, 'color_id' => $color[$colorName], 'size_id' => $size[$sizeCode],
            'quantity' => $qty, 'cost_per_item' => $cost, 'retail_price' => $retail, 'wholesale_price' => round($retail * 0.8),
        ];

        $items = [];
        foreach (['S', 'M', 'L', 'XL'] as $s) {
            $items[] = $row($shirt, 'Black', $s, 20, 550, 1499);
        }
        foreach (['M', 'L'] as $s) {
            $items[] = $row($shirt, 'White', $s, 20, 550, 1499);
        }
        foreach (['M', 'L', 'XL'] as $s) {
            $items[] = $row($denim, 'Navy Blue', $s, 25, 900, 2499);
        }
        foreach (['L', 'XL'] as $s) {
            $items[] = $row($hoodie, 'Black', $s, 15, 1000, 2999);
            $items[] = $row($hoodie, 'Crimson Red', $s, 15, 1000, 2999);
        }

        app(PurchaseService::class)->create([
            'branch_id' => $main->id,
            'vendor_id' => $mills->id,
            'lot_name' => 'Winter Collection Lot',
            'purchase_date' => now()->subDays(10)->toDateString(),
            'total_lot_cost' => 193500,
            'notes' => 'Mixed winter lot: shirts, denim and hoodies.',
            'items' => $items,
            'payments' => [
                ['payment_account_id' => $main->cashDrawer->id, 'amount' => 50000],
                ['payment_account_id' => $meezan->id, 'amount' => 100000, 'reference_no' => 'MZN-TRX-88231'],
            ],
        ]);

        // Move some stock to the mall branch.
        $variant = fn ($product, $colorName, $sizeCode) => ProductVariant::query()
            ->where('product_id', $product->id)->where('color_id', $color[$colorName])->where('size_id', $size[$sizeCode])->value('id');

        app(StockTransferService::class)->create($main->id, $cityMall->id, [
            ['variant_id' => $variant($shirt, 'Black', 'M'), 'quantity' => 5],
            ['variant_id' => $variant($shirt, 'Black', 'L'), 'quantity' => 5],
            ['variant_id' => $variant($denim, 'Navy Blue', 'L'), 'quantity' => 5],
            ['variant_id' => $variant($hoodie, 'Black', 'L'), 'quantity' => 3],
        ], now()->subDays(8)->toDateString(), 'Opening stock for City Mall');

        // Customers.
        $bilal = Customer::create(['name' => 'Bilal Ahmed', 'phone' => '03001234567', 'email' => 'bilal@example.com', 'password' => 'password', 'address' => 'DHA Phase 5, Lahore']);
        $sana = Customer::create(['name' => 'Sana Khan', 'phone' => '03211234567', 'email' => 'sana@example.com', 'address' => 'Model Town, Lahore']);

        $sales = app(SaleService::class);
        $drawer = $main->cashDrawer->id;

        $sales->create([
            'branch_id' => $main->id, 'sale_date' => now()->subDays(6),
            'items' => [['variant_id' => $variant($shirt, 'Black', 'M'), 'quantity' => 2, 'unit_price' => 1499]],
            'payments' => [['payment_account_id' => $drawer, 'amount' => 2998]],
        ]);

        $sales->create([
            'branch_id' => $main->id, 'customer_id' => $bilal->id, 'sale_date' => now()->subDays(4), 'discount' => 200,
            'items' => [
                ['variant_id' => $variant($denim, 'Navy Blue', 'L'), 'quantity' => 1, 'unit_price' => 2499],
                ['variant_id' => $variant($hoodie, 'Black', 'L'), 'quantity' => 1, 'unit_price' => 2999],
            ],
            'payments' => [['payment_account_id' => $drawer, 'amount' => 2000]], // Rs. 3,298 on credit
        ]);

        $sales->receivePayment($bilal, $hbl->id, 1000, now()->subDays(2)->toDateString(), 'HBL-IBFT-55120', 'Part payment');

        $sales->create([
            'branch_id' => $main->id, 'customer_id' => $sana->id, 'sale_date' => now()->subDay(),
            'items' => [['variant_id' => $variant($shirt, 'White', 'L'), 'quantity' => 1, 'unit_price' => 1499]],
            'payments' => [['payment_account_id' => $meezan->id, 'amount' => 1499, 'reference_no' => 'RAAST-7781']],
        ]);

        $sales->create([
            'branch_id' => $main->id, 'sale_date' => now(),
            'items' => [
                ['variant_id' => $variant($hoodie, 'Crimson Red', 'XL'), 'quantity' => 1, 'unit_price' => 2999, 'discount' => 300],
                ['variant_id' => $variant($shirt, 'Black', 'L'), 'quantity' => 1, 'unit_price' => 1499],
            ],
            'payments' => [['payment_account_id' => $drawer, 'amount' => 4198]],
        ]);

        app(ExpenseService::class)->create([
            'branch_id' => $main->id,
            'expense_category_id' => ExpenseCategory::query()->where('name', 'Utilities')->value('id'),
            'payment_account_id' => $drawer,
            'amount' => 8000,
            'expense_date' => now()->subDays(3)->toDateString(),
            'note' => 'Electricity bill (LESCO)',
        ]);

        $this->seedTradingHistory($main, $mills, $meezan, $hbl, $sana, [$shirt, $denim, $hoodie], $color, $size);

        // Reports: nightly stock snapshots, rebuilt for the demo period.
        app(StockSnapshotService::class)->backfill(today()->subDays(60), today());

        Auth::guard('web')->forgetUser();
    }

    /**
     * About seven weeks of back-dated trading so the reports have trends:
     * an earlier lot, daily sales, monthly rent/salaries, a return and a
     * stock count correction. Seeded RNG keeps the demo reproducible.
     */
    protected function seedTradingHistory(Branch $main, Vendor $mills, PaymentAccount $meezan, PaymentAccount $hbl, Customer $sana, array $products, $color, $size): void
    {
        mt_srand(2026);
        [$shirt, $denim, $hoodie] = $products;
        $drawer = $main->cashDrawer->id;
        $inventory = app(InventoryService::class);
        $sales = app(SaleService::class);

        $row = fn ($product, $colorName, $sizeCode, $qty, $cost, $retail) => [
            'product_id' => $product->id, 'color_id' => $color[$colorName], 'size_id' => $size[$sizeCode],
            'quantity' => $qty, 'cost_per_item' => $cost, 'retail_price' => $retail, 'wholesale_price' => round($retail * 0.8),
        ];

        $items = [];
        foreach (['S', 'M', 'L', 'XL'] as $s) {
            $items[] = $row($shirt, 'Black', $s, 15, 520, 1499);
        }
        foreach (['M', 'L'] as $s) {
            $items[] = $row($shirt, 'White', $s, 15, 520, 1499);
        }
        foreach (['M', 'L', 'XL'] as $s) {
            $items[] = $row($denim, 'Navy Blue', $s, 15, 880, 2499);
        }
        foreach (['L', 'XL'] as $s) {
            $items[] = $row($hoodie, 'Black', $s, 10, 980, 2999);
            $items[] = $row($hoodie, 'Crimson Red', $s, 10, 980, 2999);
        }

        $purchases = app(PurchaseService::class);
        $purchases->create([
            'branch_id' => $main->id,
            'vendor_id' => $mills->id,
            'lot_number' => 'LOT-AUT-01',
            'lot_name' => 'Autumn Restock Lot',
            'purchase_date' => today()->subDays(50)->toDateString(),
            'total_lot_cost' => 146600,
            'items' => $items,
        ]);
        $purchases->payVendor($mills, $meezan->id, 146600, today()->subDays(30)->toDateString(), 'MZN-CHQ-40112', 'Autumn lot settlement');

        $variants = ProductVariant::query()->with('product')->get();

        for ($day = 48; $day >= 12; $day--) {
            for ($n = mt_rand(0, 2); $n > 0; $n--) {
                $lines = [];
                foreach ($variants->random(mt_rand(1, 3)) as $v) {
                    $qty = mt_rand(1, 2);
                    if ($inventory->available($main->id, $v->id) >= $qty + ($lines[$v->id]['quantity'] ?? 0)) {
                        $lines[$v->id] = ['variant_id' => $v->id, 'quantity' => $qty, 'unit_price' => (float) $v->retail_price];
                    }
                }

                if (! $lines) {
                    continue;
                }

                $subtotal = collect($lines)->sum(fn ($l) => $l['quantity'] * $l['unit_price']);
                $discount = mt_rand(1, 5) === 1 ? 200 : 0;
                $onCredit = mt_rand(1, 8) === 1;
                $account = mt_rand(1, 4) === 1 ? $hbl->id : $drawer;

                $sales->create([
                    'branch_id' => $main->id,
                    'customer_id' => $onCredit ? $sana->id : null,
                    'sale_date' => now()->subDays($day)->setTime(mt_rand(11, 20), mt_rand(0, 59)),
                    'discount' => $discount,
                    'items' => array_values($lines),
                    'payments' => $onCredit ? [] : [['payment_account_id' => $account, 'amount' => $subtotal - $discount]],
                ]);
            }
        }

        // Sana settles her account in two instalments.
        if ((float) $sana->fresh()->current_balance > 0) {
            $sales->receivePayment($sana->fresh(), $hbl->id, round((float) $sana->fresh()->current_balance / 2), today()->subDays(15)->toDateString(), 'HBL-IBFT-60231');
        }

        // A size exchange that ended as a cash refund.
        $oldSale = Sale::query()->whereNull('customer_id')->where('sale_date', '<', now()->subDays(20))->latest('sale_date')->first();
        if ($oldSale) {
            app(SaleReturnService::class)->create($oldSale, [$oldSale->items()->value('id') => 1], 'cash', $drawer, 'Wrong size');
        }

        // Monthly overheads paid from the bank.
        $expenses = app(ExpenseService::class);
        $category = fn ($name) => ExpenseCategory::query()->where('name', $name)->value('id');
        foreach ([today()->subMonthNoOverflow()->startOfMonth(), today()->startOfMonth()] as $month) {
            $expenses->create(['branch_id' => $main->id, 'expense_category_id' => $category('Rent'), 'payment_account_id' => $meezan->id, 'amount' => 45000, 'expense_date' => $month->toDateString(), 'note' => 'Shop rent '.$month->format('F')]);
        }
        $expenses->create(['branch_id' => $main->id, 'expense_category_id' => $category('Salaries'), 'payment_account_id' => $meezan->id, 'amount' => 60000, 'expense_date' => today()->subMonthNoOverflow()->endOfMonth()->toDateString(), 'note' => 'Staff salaries']);
        $expenses->create(['branch_id' => $main->id, 'expense_category_id' => $category('Marketing'), 'payment_account_id' => $hbl->id, 'amount' => 12000, 'expense_date' => today()->subDays(25)->toDateString(), 'note' => 'Instagram ads']);

        // Stock count found one damaged hoodie.
        $damaged = $variants->first(fn ($v) => $v->product_id === $hoodie->id);
        $inventory->adjust($main->id, $damaged->id, -1, 'adjustment', note: 'Damaged in store (stock count)', allowNegative: true, movedAt: now()->subDays(20));
    }
}
