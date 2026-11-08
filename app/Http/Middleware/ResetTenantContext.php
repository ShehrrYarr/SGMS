<?php

namespace App\Http\Middleware;

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

        return $next($request);
    }
}
