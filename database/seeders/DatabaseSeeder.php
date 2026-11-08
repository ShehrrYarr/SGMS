<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Color;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\ProductColorImage;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\Size;
use App\Models\StoreBanner;
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
use App\Services\Store\Cart;
use App\Services\Store\OrderService;
use App\Services\TenantProvisioner;
use Illuminate\Database\Seeder;
use Database\Seeders\Support\GarmentArt;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Demo data. Every financial record is created through the real services,
 * so ledgers, account balances and stock all reconcile.
 *
 *   Super admin   admin@platform.com        / password   -> /admin
 *   Shop owner    owner@urbangarments.com  / password   -> /app
 *   Manager       manager@urbangarments.com / password  -> /app (City Mall)
 *   Cashier       cashier@urbangarments.com / password  -> /app (Main Store)
 *   Customer      03001234567              / password   -> /urban-garments/account
 *   Online store                                         -> /urban-garments
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
        $jazz = $provisioner->createAccount(['account_name' => 'JazzCash', 'account_type' => PaymentAccount::TYPE_WALLET, 'account_number' => '03001112233', 'opening_balance' => 0]);

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
        $this->seedOnlineStore($tenant, $main, $mills, $meezan, $jazz, $bilal, $sana);

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

    /**
     * Online store demo: more products with a photo per colour and size
     * charts, banners, delivery and payment settings, coupons, a handful of
     * online orders at different stages and some reviews.
     */
    protected function seedOnlineStore(Tenant $tenant, Branch $main, Vendor $mills, PaymentAccount $meezan, PaymentAccount $jazz, Customer $bilal, Customer $sana): void
    {
        $disk = Storage::disk('public');
        $dir = 'products/'.$tenant->id.'/demo';

        foreach ([['Olive', '#5B6B3A'], ['Khaki', '#C3B091'], ['Sky Blue', '#8EC5E8']] as [$name, $hex]) {
            Color::firstOrCreate(['name' => $name], ['hex_code' => $hex]);
        }

        $color = Color::query()->pluck('id', 'name');
        $hexes = Color::query()->pluck('hex_code', 'id');
        $size = Size::query()->pluck('id', 'code');
        $categories = Category::query()->pluck('id', 'name');

        foreach (['Trousers', 'Polos & Tees', 'Eastern Wear'] as $name) {
            $categories[$name] = Category::create(['name' => $name])->id;
        }

        $service = app(ProductService::class);
        $new = [
            // name, art, category, cost, price, compare-at, colours, sizes, description
            ['Stretch Chino Trousers', 'chinos', 'Trousers', 1100, 2799, 3299, ['Khaki', 'Navy Blue', 'Olive', 'Black'], ['M', 'L', 'XL'], "Cotton twill with a little stretch, slim straight leg.\nFlat front, button fly, two back pockets."],
            ['Cargo Jogger Trousers', 'joggers', 'Trousers', 950, 2299, null, ['Black', 'Olive'], ['M', 'L', 'XL'], "Soft cotton jersey with elastic waist and ankle cuffs.\nSide cargo pocket with flap."],
            ['Classic Pique Polo', 'polo', 'Polos & Tees', 700, 1799, null, ['White', 'Navy Blue', 'Crimson Red'], ['S', 'M', 'L', 'XL'], 'Breathable cotton pique, two-button placket, ribbed collar.'],
            ['Heavyweight Crew Tee', 'tee', 'Polos & Tees', 420, 1199, 1499, ['Black', 'White', 'Olive'], ['S', 'M', 'L', 'XL'], '220 gsm combed cotton. Boxy fit that keeps its shape wash after wash.'],
            ['Embroidered Lawn Kurta', 'kurta', 'Eastern Wear', 1500, 3499, null, ['White', 'Sky Blue'], ['M', 'L', 'XL'], "Fine lawn with tonal thread embroidery on the placket and hem.\nSide slits, band collar."],
        ];

        $art = ['Oxford Cotton Shirt' => 'shirt', 'Slim Fit Denim Jeans' => 'jeans', 'Fleece Pullover Hoodie' => 'hoodie'];
        $charts = [
            'shirt' => GarmentArt::sizeChart('Shirt size chart', [['Size', 'Chest', 'Length', 'Sleeve'], ['S', '38', '28', '24'], ['M', '40', '29', '24.5'], ['L', '42', '30', '25'], ['XL', '44', '31', '25.5']]),
            'trousers' => GarmentArt::sizeChart('Trouser size chart', [['Size', 'Waist', 'Hip', 'Inseam'], ['M', '30-32', '40', '31'], ['L', '33-34', '42', '32'], ['XL', '35-36', '44', '32']]),
            'kurta' => GarmentArt::sizeChart('Kurta size chart', [['Size', 'Chest', 'Length', 'Shoulder'], ['M', '42', '40', '18'], ['L', '44', '41', '18.5'], ['XL', '46', '42', '19']]),
        ];
        foreach ($charts as $key => $svg) {
            $disk->put("{$dir}/size-chart-{$key}.svg", $svg);
        }

        $items = [];

        foreach ($new as [$name, $type, $category, $cost, $price, $was, $colors, $sizes, $description]) {
            $product = $service->create(['name' => $name, 'category_id' => $categories[$category], 'base_cost' => $cost, 'base_price' => $price, 'compare_at_price' => $was, 'description' => $description, 'is_featured' => $type === 'chinos']);
            $art[$name] = $type;

            foreach ($colors as $c) {
                foreach ($sizes as $s) {
                    $items[] = ['product_id' => $product->id, 'color_id' => $color[$c], 'size_id' => $size[$s], 'quantity' => in_array($s, ['M', 'L'], true) ? 8 : 5, 'cost_per_item' => $cost, 'retail_price' => $price, 'wholesale_price' => round($price * 0.8)];
                }
            }
        }

        app(PurchaseService::class)->create([
            'branch_id' => $main->id,
            'vendor_id' => $mills->id,
            'lot_name' => 'Spring Online Collection',
            'purchase_date' => now()->subDays(9)->toDateString(),
            'total_lot_cost' => collect($items)->sum(fn ($i) => $i['quantity'] * $i['cost_per_item']),
            'items' => $items,
            'payments' => [['payment_account_id' => $meezan->id, 'amount' => 80000, 'reference_no' => 'MZN-TRX-90412']], // rest on vendor credit
        ]);

        // A photo per colour (generated illustrations) and size charts.
        $chartFor = ['shirt' => 'shirt', 'polo' => 'shirt', 'tee' => 'shirt', 'hoodie' => 'shirt', 'jeans' => 'trousers', 'chinos' => 'trousers', 'joggers' => 'trousers', 'kurta' => 'kurta'];

        // Main photo per product (also used as the category cover).
        $hero = ['Stretch Chino Trousers' => 'Khaki', 'Cargo Jogger Trousers' => 'Olive', 'Classic Pique Polo' => 'Navy Blue', 'Heavyweight Crew Tee' => 'Olive', 'Embroidered Lawn Kurta' => 'Sky Blue', 'Oxford Cotton Shirt' => 'White', 'Slim Fit Denim Jeans' => 'Navy Blue', 'Fleece Pullover Hoodie' => 'Crimson Red'];

        Product::query()->with('variants')->get()->each(function (Product $product) use ($art, $disk, $dir, $hexes, $chartFor, $hero, $color) {
            $type = $art[$product->name] ?? 'tee';
            $colorIds = $product->variants->pluck('color_id')->filter()->unique()->values();

            foreach ($colorIds as $colorId) {
                $path = "{$dir}/{$product->slug}-{$colorId}.svg";
                $disk->put($path, GarmentArt::garment($type, $hexes[$colorId]));
                ProductColorImage::create(['product_id' => $product->id, 'color_id' => $colorId, 'image' => $path]);
            }

            $product->update([
                'image' => "{$dir}/{$product->slug}-".($color[$hero[$product->name] ?? ''] ?? $colorIds->first()).'.svg',
                'size_chart' => "{$dir}/size-chart-{$chartFor[$type]}.svg",
            ]);
        });

        Product::query()->where('name', 'Fleece Pullover Hoodie')->update(['compare_at_price' => 3499]);

        // Home page banners.
        $banners = [
            ['Layers for cold mornings', 'Brushed fleece hoodies and heavy denim, made for Lahore winters.', 'Shop hoodies', 'category/hoodies', GarmentArt::banner('#1F2A44', '#2E3D5E', '#DC143C', [['hoodie', '#DC143C'], ['jeans', '#3B5A8A'], ['hoodie', '#111111']])],
            ['Chinos for every day', 'Four colours, a little stretch and a fit that works from office to weekend.', 'Shop trousers', 'category/trousers', GarmentArt::banner('#E9E1CF', '#D8CBB0', '#5B6B3A', [['chinos', '#C3B091'], ['chinos', '#5B6B3A'], ['chinos', '#1F2A44']])],
            ['Lawn kurtas for warm days', 'Light, breathable and finished with tonal embroidery.', 'Shop eastern wear', 'category/eastern-wear', GarmentArt::banner('#DCEEF8', '#BFDDF0', '#C9A24A', [['kurta', '#FFFFFF'], ['kurta', '#8EC5E8']])],
        ];

        foreach ($banners as $i => [$heading, $sub, $button, $link, $svg]) {
            $path = 'banners/'.$tenant->id."/demo-{$i}.svg";
            $disk->put($path, $svg);
            StoreBanner::create(['image' => $path, 'heading' => $heading, 'subheading' => $sub, 'button_text' => $button, 'link' => url($tenant->slug.'/'.$link), 'sort_order' => $i]);
        }

        // Store settings: open, Linen theme, delivery Rs. 250 (free over Rs. 5,000), COD + Meezan + JazzCash.
        $settings = $tenant->settings ?? [];
        $settings['store'] = [
            'theme' => 'linen',
            'tagline' => 'Everyday menswear, made in Pakistan',
            'announcement' => 'Free delivery on orders over Rs. 5,000. Cash on delivery across Pakistan.',
            'instagram' => 'https://instagram.com/urbangarments.pk',
            'facebook' => 'https://facebook.com/urbangarments.pk',
            'whatsapp' => '03001112233',
            'whatsapp_button' => true,
            'featured_categories' => [$categories['Trousers'], $categories['Shirts'], $categories['Hoodies'], $categories['Polos & Tees']],
            'delivery_fee' => 250,
            'free_delivery_over' => 5000,
            'delivery_note' => 'Delivered in 2 to 4 working days',
            'cod' => true,
            'bank_accounts' => [$meezan->id],
            'wallet_accounts' => [$jazz->id],
            'payment_instructions' => 'Use your order number as the payment reference. We ship once the payment shows in our account.',
        ];
        $tenant->update(['settings' => $settings, 'store_enabled' => true]);
        tenancy()->set($tenant->fresh());

        Coupon::create(['code' => 'WELCOME10', 'type' => 'percent', 'value' => 10, 'max_discount' => 500]);
        Coupon::create(['code' => 'EID500', 'type' => 'fixed', 'value' => 500, 'min_order' => 4000, 'expires_at' => today()->addMonth()]);

        // Online orders at every stage, placed through the real checkout service.
        $variant = fn (string $product, string $colorName, string $sizeCode) => ProductVariant::query()
            ->whereHas('product', fn ($q) => $q->where('name', $product))
            ->where('color_id', $color[$colorName])->where('size_id', $size[$sizeCode])->value('id');
        $cart = app(Cart::class);
        $orders = app(OrderService::class);
        $place = function (array $lines, array $details, ?Customer $customer = null) use ($cart, $orders) {
            foreach ($lines as [$variantId, $qty]) {
                $cart->add($variantId, $qty);
            }

            return $orders->place($details + ['address' => 'House 12, Street 4', 'city' => 'Lahore'], $customer);
        };

        // Delivered, cash collected into the drawer.
        $delivered = $place([[$variant('Slim Fit Denim Jeans', 'Navy Blue', 'L'), 1], [$variant('Classic Pique Polo', 'Navy Blue', 'L'), 1]], ['name' => 'Bilal Ahmed', 'phone' => '0300 1234567', 'payment_method' => 'cod', 'address' => 'DHA Phase 5, Street 9', 'city' => 'Lahore'], $bilal);
        $orders->confirm($delivered, $main->id);
        $orders->ship($delivered->fresh(), 'TCS', '7712039441');
        $orders->deliver($delivered->fresh(), $main->cashDrawer->id);

        // Paid by bank transfer, confirmed and on its way.
        $shipped = $place([[$variant('Stretch Chino Trousers', 'Khaki', 'M'), 2]], ['name' => 'Ayesha Siddiqui', 'phone' => '0333 4567890', 'email' => 'ayesha@example.com', 'payment_method' => 'bank', 'payment_account_id' => $meezan->id, 'payment_reference' => 'IBFT 55821', 'address' => 'Flat 3, Clifton Block 5', 'city' => 'Karachi']);
        $orders->markPaid($shipped);
        $orders->confirm($shipped->fresh(), $main->id);
        $orders->ship($shipped->fresh(), 'Leopards', 'LP90311827');

        // New orders waiting for the shop.
        $place([[$variant('Fleece Pullover Hoodie', 'Crimson Red', 'XL'), 1], [$variant('Heavyweight Crew Tee', 'Black', 'L'), 2]], ['name' => 'Sana Khan', 'phone' => '0321 1234567', 'payment_method' => 'cod', 'notes' => 'Please call before delivery', 'address' => 'Model Town, Block C', 'city' => 'Lahore']);
        $place([[$variant('Embroidered Lawn Kurta', 'Sky Blue', 'L'), 1]], ['name' => 'Usman Tariq', 'phone' => '0345 9876543', 'payment_method' => 'wallet', 'payment_account_id' => $jazz->id, 'address' => 'G-11/3, Street 21', 'city' => 'Islamabad']);

        // Cancelled before confirmation.
        $cancelled = $place([[$variant('Cargo Jogger Trousers', 'Olive', 'M'), 1]], ['name' => 'Hamid Raza', 'phone' => '0312 3344556', 'payment_method' => 'cod', 'address' => 'Satellite Town', 'city' => 'Rawalpindi']);
        $orders->cancel($cancelled, 'Could not reach customer');

        Order::query()->whereIn('id', [$delivered->id, $shipped->id, $cancelled->id])->update(['seen_at' => now()]);

        // Reviews from customers who bought the product.
        $productId = fn (string $name) => Product::query()->where('name', $name)->value('id');
        ProductReview::create(['product_id' => $productId('Slim Fit Denim Jeans'), 'customer_id' => $bilal->id, 'rating' => 5, 'title' => 'Fits exactly as listed', 'body' => 'Ordered my usual L and the waist and length are spot on. The stretch makes it comfortable all day.', 'status' => 'approved']);
        ProductReview::create(['product_id' => $productId('Fleece Pullover Hoodie'), 'customer_id' => $bilal->id, 'rating' => 4, 'title' => 'Warm and soft', 'body' => 'Good thick fleece. Sleeves are slightly long for me but it is very warm.', 'status' => 'approved']);
        ProductReview::create(['product_id' => $productId('Oxford Cotton Shirt'), 'customer_id' => $sana->id, 'rating' => 5, 'title' => 'Bought for my husband', 'body' => 'Nice crisp fabric and the white one did not go see-through.', 'status' => 'pending']);
    }
}
