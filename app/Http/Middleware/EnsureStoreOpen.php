<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Online store gate (runs after ResolvePortalTenant).
 *  - blocked by the platform        -> 404
 *  - not switched on by the owner   -> "Coming soon" page
 *  - shop staff signed in to /app   -> may preview the store before launch
 */
class EnsureStoreOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = tenant();

        abort_if(! $tenant || $tenant->store_blocked, 404);

        $preview = ! $tenant->store_enabled && Auth::guard('web')->user()?->tenant_id === $tenant->id;

        if (! $tenant->store_enabled && ! $preview) {
            return response()->view('store.coming-soon', ['tenant' => $tenant], 200);
        }

        View::share('storePreview', $preview);

        return $next($request);
    }
}
