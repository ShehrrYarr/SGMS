<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BranchSwitchController;
use App\Http\Controllers\PdfController;
use App\Http\Controllers\PrintController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

/*
|--------------------------------------------------------------------------
| SGMS routes
|--------------------------------------------------------------------------
| /admin            Platform super admin        (superadmin guard)
| /app              Shop staff + POS            (web guard, tenant from user)
| /portal/{shop}    Customer portal             (customer guard, tenant from slug)
|
| Every page is a Livewire Volt class component in resources/views/pages.
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
        });

        // Owner only
        Route::middleware('role:owner')->group(function () {
            Volt::route('branches', 'app.branches')->name('branches');
            Volt::route('users', 'app.users')->name('users');
            Volt::route('settings', 'app.settings')->name('settings');
        });
    });
});

/* -------------------------------------------------------------------------
 | Customer portal — shop resolved from the slug before authentication
 * ---------------------------------------------------------------------- */
Route::prefix('portal/{tenant}')->name('portal.')->middleware('portal.tenant')->group(function () {
    Route::middleware('guest:customer')->group(function () {
        Volt::route('login', 'portal.auth.login')->name('login');
    });

    Route::middleware('auth:customer')->group(function () {
        Volt::route('/', 'portal.dashboard')->name('dashboard');
        Volt::route('purchases', 'portal.purchases.index')->name('purchases.index');
        Volt::route('purchases/{sale}', 'portal.purchases.show')->name('purchases.show');
        Route::get('purchases/{sale}/receipt', [PrintController::class, 'portalReceipt'])->name('purchases.receipt');
        Volt::route('statement', 'portal.statement')->name('statement');
        Route::get('statement.pdf', [PdfController::class, 'portalStatement'])->name('statement.pdf');
        Volt::route('profile', 'portal.profile')->name('profile');
        Route::post('logout', [AuthController::class, 'logoutCustomer'])->name('logout');
    });
});
