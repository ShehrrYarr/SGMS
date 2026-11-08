<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Logout per guard. Only the guard being signed out is cleared, so a
 * super admin and a shop user can stay signed in side by side.
 */
class AuthController extends Controller
{
    public function logoutStaff(Request $request): RedirectResponse
    {
        return $this->logout($request, 'web', route('app.login'));
    }

    public function logoutAdmin(Request $request): RedirectResponse
    {
        return $this->logout($request, 'superadmin', route('admin.login'));
    }

    public function logoutCustomer(Request $request, string $tenant): RedirectResponse
    {
        return $this->logout($request, 'customer', route('portal.login', $tenant));
    }

    protected function logout(Request $request, string $guard, string $redirect): RedirectResponse
    {
        Auth::guard($guard)->logout();
        $request->session()->regenerateToken();

        return redirect($redirect)->with('success', 'You have been signed out.');
    }
}
