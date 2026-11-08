<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff area (/app): resolves the shop from the signed-in user, blocks
 * suspended shops / inactive users, and selects the working branch.
 *
 * Registered as Livewire persistent middleware so every component update
 * request is tenant scoped too.
 */
class IdentifyTenant
{
    public function __construct(protected TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        if (! $user) {
            return $next($request);
        }

        $tenant = Tenant::query()->find($user->tenant_id);

        if (! $tenant || ! $tenant->isActive() || ! $user->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->regenerateToken();

            $message = $tenant && ! $tenant->isActive()
                ? 'This shop has been suspended. Please contact the platform administrator.'
                : 'Your account is inactive. Please contact your shop owner.';

            return redirect()->route('app.login')->with('error', $message);
        }

        $this->context->set($tenant);
        $this->context->setUser($user);
        $this->context->setBranchId($this->resolveBranch($request, $user));

        return $next($request);
    }

    protected function resolveBranch(Request $request, $user): ?int
    {
        if (! $user->isOwner()) {
            return $user->branch_id;
        }

        $selected = $request->session()->get('branch_id.'.$user->tenant_id);

        if ($selected === 'all') {
            return null;
        }

        $branchIds = $this->context->branches()->pluck('id')->all();

        if ($selected && in_array((int) $selected, $branchIds, true)) {
            return (int) $selected;
        }

        return in_array($user->branch_id, $branchIds, true) ? $user->branch_id : ($branchIds[0] ?? null);
    }
}
