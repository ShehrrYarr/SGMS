<?php

namespace App\Support;

use App\Models\User;

/**
 * Sidebar menus per area. Items are filtered by role, so the menu only
 * shows what the signed-in user can open.
 */
class Navigation
{
    public static function staff(User $user): array
    {
        $manage = $user->canManage();
        $owner = $user->isOwner();

        return array_values(array_filter([
            ['title' => 'Main', 'items' => [
                ['label' => 'Dashboard', 'icon' => 'ri-dashboard-3-line', 'route' => 'app.dashboard', 'active' => 'app.dashboard'],
                ['label' => 'POS Terminal', 'icon' => 'ri-shopping-cart-2-line', 'route' => 'app.pos', 'active' => 'app.pos', 'navigate' => false],
            ]],
            ['title' => 'Sales', 'items' => [
                ['label' => 'Sales & Invoices', 'icon' => 'ri-file-list-3-line', 'route' => 'app.sales.index', 'active' => 'app.sales.*'],
                ['label' => 'Returns & Exchanges', 'icon' => 'ri-arrow-go-back-line', 'route' => 'app.returns.index', 'active' => 'app.returns.*'],
                ['label' => 'Customers', 'icon' => 'ri-group-line', 'route' => 'app.customers.index', 'active' => 'app.customers.*'],
            ]],
            $manage ? ['title' => 'Online Store', 'items' => [
                ['label' => 'Online Orders', 'icon' => 'ri-shopping-bag-3-line', 'route' => 'app.orders.index', 'active' => 'app.orders.*', 'badge' => static::count(fn () => \App\Models\Order::query()->where('status', 'pending')->count())],
                ['label' => 'Coupons', 'icon' => 'ri-coupon-3-line', 'route' => 'app.coupons', 'active' => 'app.coupons'],
                ['label' => 'Reviews', 'icon' => 'ri-star-smile-line', 'route' => 'app.reviews', 'active' => 'app.reviews', 'badge' => static::count(fn () => \App\Models\ProductReview::query()->where('status', 'pending')->count())],
            ]] : null,
            $manage ? ['title' => 'Purchasing', 'items' => [
                ['label' => 'Lot Purchases', 'icon' => 'ri-inbox-archive-line', 'route' => 'app.purchases.index', 'active' => 'app.purchases.*'],
                ['label' => 'Vendors', 'icon' => 'ri-truck-line', 'route' => 'app.vendors.index', 'active' => 'app.vendors.*'],
            ]] : null,
            $manage ? ['title' => 'Inventory', 'items' => [
                ['label' => 'Products', 'icon' => 'ri-t-shirt-2-line', 'route' => 'app.products.index', 'active' => 'app.products.*'],
                ['label' => 'Stock Levels', 'icon' => 'ri-stack-line', 'route' => 'app.inventory.index', 'active' => 'app.inventory.*'],
                ['label' => 'Stock Transfers', 'icon' => 'ri-arrow-left-right-line', 'route' => 'app.transfers.index', 'active' => 'app.transfers.*'],
                ['label' => 'Barcode Labels', 'icon' => 'ri-barcode-line', 'route' => 'app.barcodes', 'active' => 'app.barcodes'],
                ['label' => 'Attributes', 'icon' => 'ri-price-tag-3-line', 'route' => 'app.attributes', 'active' => 'app.attributes'],
            ]] : null,
            $manage ? ['title' => 'Reports', 'items' => [
                ['label' => 'Profit & Loss', 'icon' => 'ri-funds-line', 'route' => 'app.reports.profit-loss', 'active' => 'app.reports.profit-loss'],
                ['label' => 'Sales Analysis', 'icon' => 'ri-bar-chart-grouped-line', 'route' => 'app.reports.sales', 'active' => 'app.reports.sales'],
                ['label' => 'Stock Valuation', 'icon' => 'ri-line-chart-line', 'route' => 'app.reports.stock', 'active' => 'app.reports.stock'],
            ]] : null,
            $manage ? ['title' => 'Finance', 'items' => [
                ['label' => 'Payment Accounts', 'icon' => 'ri-bank-line', 'route' => 'app.accounts.index', 'active' => 'app.accounts.*'],
                ['label' => 'Expenses', 'icon' => 'ri-wallet-3-line', 'route' => 'app.expenses.index', 'active' => 'app.expenses.*'],
            ]] : null,
            $owner ? ['title' => 'Shop Settings', 'items' => [
                ['label' => 'Branches', 'icon' => 'ri-store-2-line', 'route' => 'app.branches', 'active' => 'app.branches'],
                ['label' => 'Staff Users', 'icon' => 'ri-user-settings-line', 'route' => 'app.users', 'active' => 'app.users'],
                ['label' => 'Settings', 'icon' => 'ri-settings-3-line', 'route' => 'app.settings', 'active' => 'app.settings*'],
            ]] : null,
        ]));
    }

    /** Badge value, or null (hidden) when zero. */
    protected static function count(\Closure $query): ?int
    {
        $count = (int) $query();

        return $count > 0 ? $count : null;
    }

    public static function admin(): array
    {
        return [
            ['title' => 'Platform', 'items' => [
                ['label' => 'Dashboard', 'icon' => 'ri-dashboard-3-line', 'route' => 'admin.dashboard', 'active' => 'admin.dashboard'],
                ['label' => 'Shops', 'icon' => 'ri-store-3-line', 'route' => 'admin.shops.index', 'active' => 'admin.shops.*'],
            ]],
            ['title' => 'Account', 'items' => [
                ['label' => 'My Profile', 'icon' => 'ri-user-line', 'route' => 'admin.profile', 'active' => 'admin.profile'],
            ]],
        ];
    }

    public static function portal(): array
    {
        return [
            ['title' => 'My Account', 'items' => [
                ['label' => 'Overview', 'icon' => 'ri-home-smile-line', 'route' => 'portal.dashboard', 'active' => 'portal.dashboard'],
                ['label' => 'Online Orders', 'icon' => 'ri-truck-line', 'route' => 'portal.orders', 'active' => 'portal.orders'],
                ['label' => 'My Purchases', 'icon' => 'ri-shopping-bag-3-line', 'route' => 'portal.purchases.index', 'active' => 'portal.purchases.*'],
                ['label' => 'Statement', 'icon' => 'ri-file-list-2-line', 'route' => 'portal.statement', 'active' => 'portal.statement'],
                ['label' => 'Profile & Password', 'icon' => 'ri-lock-password-line', 'route' => 'portal.profile', 'active' => 'portal.profile'],
            ]],
        ];
    }
}
