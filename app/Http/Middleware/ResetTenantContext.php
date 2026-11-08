<?php

namespace App\Http\Middleware;

use App\Services\Store\Cart;
use App\Services\Store\WishlistService;
use App\Support\Store\Storefront;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Start every web request with an empty tenant context. Protects long-lived
 * workers (Octane, queue-driven HTTP tests) from leaking a previous request's
 * shop into platform-level pages such as /admin.
 */
class ResetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        app(TenantContext::class)->forget();

        // Shopper state of the online store is per request too.
        foreach ([Storefront::class, Cart::class, WishlistService::class] as $service) {
            app()->forgetInstance($service);
        }

        return $next($request);
    }
}
