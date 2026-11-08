<?php

use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.auth')] #[Title('Staff Sign In')] class extends Component {
    public string $email = '';

    public string $password = '';

    public bool $remember = true;

    public function login(): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = 'login:web:'.Str::lower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('email', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        if (! Auth::guard('web')->attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($key, 60);
            $this->addError('email', 'These credentials do not match our records.');

            return;
        }

        RateLimiter::clear($key);
        $user = Auth::guard('web')->user();
        $tenant = Tenant::query()->find($user->tenant_id);

        if (! $tenant?->isActive() || ! $user->isActive()) {
            Auth::guard('web')->logout();
            $this->addError('email', $tenant?->isActive() === false
                ? 'This shop has been suspended. Please contact the platform administrator.'
                : 'Your account is inactive. Please contact your shop owner.');

            return;
        }

        session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        $this->redirectIntended(route($user->isCashier() ? 'app.pos' : 'app.dashboard'));
    }
}; ?>

<x-auth-card heading="Welcome back 👋" tagline="Lot purchasing, branch stock and a fast POS — built for garment shops."
             :features="[['ri-inbox-archive-line', 'Lot intake with colour & size breakdown'], ['ri-store-2-line', 'Branch-aware stock, transfers and cash drawers'], ['ri-shopping-cart-2-line', 'Touch-friendly POS with thermal receipts']]">
    <p class="text-muted mb-4">Sign in to your shop account.</p>

    @if (session('error'))
        <div class="alert alert-danger d-flex gap-2 align-items-center"><i class="ri-error-warning-line"></i>{{ session('error') }}</div>
    @endif

    <form wire:submit="login" class="vstack gap-3">
        <div>
            <label class="form-label" for="email">Email address</label>
            <input wire:model="email" type="email" id="email" class="form-control form-control-lg @error('email') is-invalid @enderror" placeholder="you@shop.com" autocomplete="username" autofocus>
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div x-data="{ reveal: false }">
            <label class="form-label" for="password">Password</label>
            <div class="input-group input-group-lg">
                <input wire:model="password" :type="reveal ? 'text' : 'password'" id="password" class="form-control @error('password') is-invalid @enderror" placeholder="••••••••" autocomplete="current-password">
                <button type="button" class="input-group-text bg-transparent" x-on:click="reveal = !reveal" aria-label="Show password"><i :class="reveal ? 'ri-eye-line' : 'ri-eye-off-line'"></i></button>
            </div>
            @error('password')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
        </div>
        <div class="form-check">
            <input wire:model="remember" class="form-check-input" type="checkbox" id="remember">
            <label class="form-check-label" for="remember">Keep me signed in</label>
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-100" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="login">Sign in</span>
            <span wire:loading wire:target="login"><span class="spinner-border spinner-border-sm me-2"></span>Signing in…</span>
        </button>
    </form>

    @if (app()->isLocal())
        <div class="alert alert-light border mt-4 mb-0 small">
            <div class="fw-semibold mb-1"><i class="ri-information-line"></i> Demo accounts (password: <code>password</code>)</div>
            owner@urbangarments.com · manager@urbangarments.com · cashier@urbangarments.com
        </div>
    @endif
</x-auth-card>
