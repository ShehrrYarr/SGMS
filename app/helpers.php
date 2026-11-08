<?php

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;

if (! function_exists('tenancy')) {
    function tenancy(): TenantContext
    {
        return app(TenantContext::class);
    }
}

if (! function_exists('tenant')) {
    /** The active shop, or null outside a tenant context. */
    function tenant(): ?Tenant
    {
        return app(TenantContext::class)->tenant();
    }
}

if (! function_exists('tenant_id')) {
    function tenant_id(): ?int
    {
        return app(TenantContext::class)->id();
    }
}

if (! function_exists('branch_id')) {
    /** Selected branch id (null = all branches for owners). */
    function branch_id(): ?int
    {
        return app(TenantContext::class)->branchId();
    }
}

if (! function_exists('money')) {
    /** Format an amount with the shop currency, e.g. "Rs. 1,250.00". */
    function money(float|int|string|null $amount, bool $symbol = true, int $decimals = 2): string
    {
        $amount = (float) $amount;
        $formatted = number_format(abs($amount), $decimals);
        $sign = $amount < 0 ? '-' : '';

        if (! $symbol) {
            return $sign.$formatted;
        }

        $currency = tenant()?->currency_symbol ?? 'Rs.';

        return $sign.$currency.' '.$formatted;
    }
}

if (! function_exists('round_money')) {
    function round_money(float|int|string|null $amount): float
    {
        return round((float) $amount, 2);
    }
}
