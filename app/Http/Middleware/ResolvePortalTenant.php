<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Online store and customer account (/{tenant}, /{tenant}/account): resolves the shop from the URL slug.
 * Runs before authentication so the customer guard only finds customers
 * belonging to this shop.
 */
class ResolvePortalTenant
{
    public function __construct(protected TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $param = $request->route('tenant');
        $tenant = $param instanceof Tenant ? $param : Tenant::query()->where('slug', (string) $param)->first();

        abort_if(! $tenant, 404, 'Shop not found.');

        if (! $tenant->isActive()) {
            Auth::guard('customer')->logout();
            abort(403, 'This shop is currently unavailable.');
        }

        $this->context->set($tenant);
        URL::defaults(['tenant' => $tenant->slug]);

        $customer = Auth::guard('customer')->user();

        if ($customer && $customer->status !== 'active') {
            Auth::guard('customer')->logout();

            return redirect()->route('portal.login', $tenant->slug)->with('error', 'Your account has been deactivated by the shop.');
        }

        return $next($request);
    }
}
