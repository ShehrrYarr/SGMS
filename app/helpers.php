<?php

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

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

if (! function_exists('business_time')) {
    /**
     * Timestamp for a business date: "now" for today, otherwise midday of that
     * date (so back-dated lots and transfers land on the right day in reports).
     */
    function business_time(DateTimeInterface|string|null $date): Carbon
    {
        if ($date === null) {
            return now();
        }

        $date = Carbon::parse($date);

        return $date->isToday() ? now() : $date->copy()->setTime(12, 0);
    }
}

if (! function_exists('round_money')) {
    function round_money(float|int|string|null $amount): float
    {
        return round((float) $amount, 2);
    }
}

if (! function_exists('normalize_phone')) {
    /** Pakistani mobile numbers in one shape: "+92 300-1234567" -> "03001234567". */
    function normalize_phone(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        if (str_starts_with($digits, '0092')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '92') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 2);
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '3')) {
            $digits = '0'.$digits;
        }

        return $digits;
    }
}

if (! function_exists('storefront')) {
    /** View state of the online store for the current request. */
    function storefront(): App\Support\Store\Storefront
    {
        return app(App\Support\Store\Storefront::class);
    }
}
