<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Demo;
use App\Support\Tenancy\TenantScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * One-click sign-in to the public demo shop (landing page portals).
 */
class DemoController extends Controller
{
    public function enter(Request $request, string $role): RedirectResponse
    {
        abort_unless(Demo::enabled(), 404);

        $shop = Tenant::query()->where('slug', Demo::shop())->first();

        if (! $shop?->isActive()) {
            return $this->unavailable();
        }

        if ($role === 'customer') {
            $customer = Customer::query()->withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $shop->id)
                ->where('phone', normalize_phone(config('sgms.demo.customer_phone')))
                ->where('status', 'active')
                ->first();

            if (! $customer) {
                return $this->unavailable();
            }

            Auth::guard('customer')->login($customer);
            $request->session()->regenerate();

            return redirect()->route('portal.dashboard', $shop->slug);
        }

        $user = User::query()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $shop->id)
            ->where('email', Demo::staffEmail($role))
            ->first();

        if (! $user?->isActive()) {
            return $this->unavailable();
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->route($user->isCashier() ? 'app.pos' : 'app.dashboard');
    }

    protected function unavailable(): RedirectResponse
    {
        return redirect()->to(route('landing').'#demo')
            ->with('demo_error', 'The demo is being refreshed. Try again in a minute.');
    }
}
