<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BranchSwitchController;
use App\Http\Controllers\PdfController;
use App\Http\Controllers\PrintController;
use App\Http\Controllers\Store\CartController;
use App\Http\Controllers\Store\StorefrontController;
use App\Http\Controllers\Store\StoreOrderController;
use App\Http\Controllers\Store\WishlistController;
use App\Models\Tenant;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

/*
|--------------------------------------------------------------------------
| SGMS routes
|--------------------------------------------------------------------------
| /admin            Platform super admin        (superadmin guard)
| /app              Shop staff + POS            (web guard, tenant from user)
| /{shop}           Online store                (public, tenant from slug)
| /{shop}/account   Customer account            (customer guard, tenant from slug)
|
| Back-office pages are Livewire Volt class components in resources/views/pages;
| storefront pages are plain controllers + Blade (fast, cacheable, SEO-friendly).
*/

Route::get('/', fn () => redirect()->route('app.dashboard'));

/* -------------------------------------------------------------------------
 | Super Admin
 * ---------------------------------------------------------------------- */
Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest:superadmin')->group(function () {
        Volt::route('login', 'admin.auth.login')->name('login');
    });

    Route::middleware('auth:superadmin')->group(function () {
        Volt::route('/', 'admin.dashboard')->name('dashboard');
        Volt::route('shops', 'admin.shops.index')->name('shops.index');
        Volt::route('shops/{tenant}', 'admin.shops.show')->name('shops.show');
        Volt::route('profile', 'admin.profile')->name('profile');
        Route::post('logout', [AuthController::class, 'logoutAdmin'])->name('logout');
    });
});

/* -------------------------------------------------------------------------
 | Shop staff (owner / manager / cashier)
 * ---------------------------------------------------------------------- */
Route::prefix('app')->name('app.')->group(function () {
    Route::middleware('guest:web')->group(function () {
        Volt::route('login', 'app.auth.login')->name('login');
    });

    Route::middleware(['auth:web', 'tenant'])->group(function () {
        Route::post('logout', [AuthController::class, 'logoutStaff'])->name('logout');
        Route::post('branch', BranchSwitchController::class)->name('branch.switch');

        // Every role
        Volt::route('/', 'app.dashboard')->name('dashboard');
        Volt::route('pos', 'app.pos')->name('pos');
        Volt::route('profile', 'app.profile')->name('profile');

        Volt::route('sales', 'app.sales.index')->name('sales.index');
        Volt::route('sales/{sale}', 'app.sales.show')->name('sales.show');
        Route::get('sales/{sale}/receipt', [PrintController::class, 'receipt'])->name('sales.receipt');
        Route::get('sales/{sale}/invoice.pdf', [PdfController::class, 'saleInvoice'])->name('sales.pdf');

        Volt::route('returns', 'app.returns.index')->name('returns.index');
        Volt::route('returns/create', 'app.returns.create')->name('returns.create');

        Volt::route('customers', 'app.customers.index')->name('customers.index');
        Volt::route('customers/{customer}', 'app.customers.show')->name('customers.show');
        Route::get('customers/{customer}/statement.pdf', [PdfController::class, 'customerStatement'])->name('customers.statement');

        // Owner + branch manager
        Route::middleware('role:owner,manager')->group(function () {
            Volt::route('purchases', 'app.purchases.index')->name('purchases.index');
            Volt::route('purchases/create', 'app.purchases.create')->name('purchases.create');
            Volt::route('purchases/{purchase}', 'app.purchases.show')->name('purchases.show');
            Route::get('purchases/{purchase}/receipt.pdf', [PdfController::class, 'purchase'])->name('purchases.pdf');

            Volt::route('vendors', 'app.vendors.index')->name('vendors.index');
            Volt::route('vendors/{vendor}', 'app.vendors.show')->name('vendors.show');
            Route::get('vendors/{vendor}/ledger.pdf', [PdfController::class, 'vendorLedger'])->name('vendors.ledger.pdf');
            Route::get('vendors/{vendor}/receipts/{entry}.pdf', [PdfController::class, 'vendorReceipt'])->name('vendors.receipt.pdf');

            Volt::route('products', 'app.products.index')->name('products.index');
            Volt::route('products/create', 'app.products.form')->name('products.create');
            Volt::route('products/{product}/edit', 'app.products.form')->name('products.edit');

            Volt::route('inventory', 'app.inventory.index')->name('inventory.index');
            Volt::route('transfers', 'app.transfers.index')->name('transfers.index');
            Volt::route('transfers/create', 'app.transfers.create')->name('transfers.create');
            Volt::route('barcodes', 'app.barcodes')->name('barcodes');
            Volt::route('attributes', 'app.attributes')->name('attributes');

            Volt::route('accounts', 'app.accounts.index')->name('accounts.index');
            Volt::route('accounts/{account}', 'app.accounts.show')->name('accounts.show');
            Volt::route('expenses', 'app.expenses.index')->name('expenses.index');

            Volt::route('orders', 'app.orders.index')->name('orders.index');
            Volt::route('orders/{order}', 'app.orders.show')->name('orders.show');
            Volt::route('coupons', 'app.coupons')->name('coupons');
            Volt::route('reviews', 'app.reviews')->name('reviews');

            Route::redirect('reports', '/app/reports/profit-loss')->name('reports');
            Volt::route('reports/profit-loss', 'app.reports.profit-loss')->name('reports.profit-loss');
            Volt::route('reports/sales', 'app.reports.sales')->name('reports.sales');
            Volt::route('reports/stock', 'app.reports.stock')->name('reports.stock');
        });

        // Owner only
        Route::middleware('role:owner')->group(function () {
            Volt::route('branches', 'app.branches')->name('branches');
            Volt::route('users', 'app.users')->name('users');
            Volt::route('settings', 'app.settings.index')->name('settings');
            Volt::route('settings/store', 'app.settings.store')->name('settings.store');
            Volt::route('settings/appearance', 'app.settings.appearance')->name('settings.appearance');
        });
    });
});

/* -------------------------------------------------------------------------
 | Online store + customer account — /{shop}
 | Registered last so every fixed path above wins. The shop is resolved from
 | the slug before authentication, so the customer guard is shop-scoped.
 * ---------------------------------------------------------------------- */
Route::get('portal/{tenant}/{path?}', fn (string $tenant, string $path = '') => redirect('/'.$tenant.'/account'.($path !== '' ? '/'.$path : ''), 301))
    ->where('path', '.*');

Route::prefix('{tenant}')->where(['tenant' => Tenant::SLUG_ROUTE_PATTERN])->middleware('portal.tenant')->group(function () {
    // Customer account (purchase history, statement, online orders)
    Route::prefix('account')->name('portal.')->group(function () {
        Route::middleware('guest:customer')->group(function () {
            Volt::route('login', 'portal.auth.login')->name('login');
            Volt::route('register', 'portal.auth.register')->name('register');
        });

        Route::middleware('auth:customer')->group(function () {
            Volt::route('/', 'portal.dashboard')->name('dashboard');
            Volt::route('orders', 'portal.orders')->name('orders');
            Volt::route('purchases', 'portal.purchases.index')->name('purchases.index');
            Volt::route('purchases/{sale}', 'portal.purchases.show')->name('purchases.show');
            Route::get('purchases/{sale}/receipt', [PrintController::class, 'portalReceipt'])->name('purchases.receipt');
            Volt::route('statement', 'portal.statement')->name('statement');
            Route::get('statement.pdf', [PdfController::class, 'portalStatement'])->name('statement.pdf');
            Volt::route('profile', 'portal.profile')->name('profile');
            Route::post('logout', [AuthController::class, 'logoutCustomer'])->name('logout');
        });
    });

    // Storefront
    Route::middleware('store.open')->name('store.')->group(function () {
        Route::get('/', [StorefrontController::class, 'home'])->name('home');
        Route::get('shop', [StorefrontController::class, 'shop'])->name('shop');
        Route::get('category/{category:slug}', [StorefrontController::class, 'category'])->name('category');
        Route::get('product/{slug}', [StorefrontController::class, 'product'])->name('product');
        Route::post('product/{product}/reviews', [StorefrontController::class, 'review'])->name('review')->whereNumber('product')->middleware('throttle:10,1');

        Route::get('bag', [CartController::class, 'show'])->name('cart');
        Route::get('bag/drawer', [CartController::class, 'drawer'])->name('cart.drawer');
        Route::post('bag', [CartController::class, 'add'])->name('cart.add')->middleware('throttle:60,1');
        Route::post('bag/coupon', [CartController::class, 'applyCoupon'])->name('cart.coupon')->middleware('throttle:20,1');
        Route::delete('bag/coupon', [CartController::class, 'removeCoupon'])->name('cart.coupon.remove');
        Route::patch('bag/{variant}', [CartController::class, 'update'])->name('cart.update')->whereNumber('variant');
        Route::delete('bag/{variant}', [CartController::class, 'remove'])->name('cart.remove')->whereNumber('variant');

        Volt::route('checkout', 'store.checkout')->name('checkout');

        Route::get('track', [StoreOrderController::class, 'track'])->name('track');
        Route::post('track', [StoreOrderController::class, 'lookup'])->name('track.lookup')->middleware('throttle:10,1');
        Route::get('orders/{order}', [StoreOrderController::class, 'show'])->name('order');
        Route::post('orders/{order}/receipt', [StoreOrderController::class, 'receipt'])->name('order.receipt')->middleware('throttle:10,1');

        Route::get('wishlist', [WishlistController::class, 'index'])->name('wishlist');
        Route::post('wishlist/{product}', [WishlistController::class, 'toggle'])->name('wishlist.toggle')->whereNumber('product');
    });
});
