<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.auth')] #[Title('Platform Admin')] class extends Component {
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public function login(): void
    {
        $this->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        $key = 'login:admin:'.Str::lower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('email', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        if (! Auth::guard('superadmin')->attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($key, 60);
            $this->addError('email', 'These credentials do not match our records.');

            return;
        }

        RateLimiter::clear($key);
        session()->regenerate();

        $this->redirectIntended(route('admin.dashboard'));
    }
}; ?>

<x-auth-card heading="Platform administration" badge="SGMS Platform" tagline="Manage every shop on the platform."
             :features="[['ri-store-3-line', 'Create shops with owner & cash drawer in one step'], ['ri-shield-check-line', 'Instantly suspend or reactivate any shop'], ['ri-key-2-line', 'Reset any shop user password']]">
    <p class="text-muted mb-4">Super admin access only.</p>

    <form wire:submit="login" class="vstack gap-3">
        <div>
            <label class="form-label" for="email">Email address</label>
            <input wire:model="email" type="email" id="email" class="form-control form-control-lg @error('email') is-invalid @enderror" autocomplete="username" autofocus>
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div>
            <label class="form-label" for="password">Password</label>
            <input wire:model="password" type="password" id="password" class="form-control form-control-lg" autocomplete="current-password">
        </div>
        <div class="form-check">
            <input wire:model="remember" class="form-check-input" type="checkbox" id="remember">
            <label class="form-check-label" for="remember">Remember me</label>
        </div>
        <button type="submit" class="btn btn-dark btn-lg w-100" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="login"><i class="ri-shield-keyhole-line me-1"></i>Sign in</span>
            <span wire:loading wire:target="login"><span class="spinner-border spinner-border-sm me-2"></span>Signing in…</span>
        </button>
    </form>

    @if (app()->isLocal())
        <div class="alert alert-light border mt-4 mb-0 small">Demo: <code>admin@platform.com</code> / <code>password</code></div>
    @endif
</x-auth-card>
