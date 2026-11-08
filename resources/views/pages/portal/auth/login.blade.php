<?php

use App\Models\Customer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.auth')] #[Title('Customer Sign In')] class extends Component {
    public string $login = '';

    public string $password = '';

    public bool $remember = true;

    public function signIn(): void
    {
        $this->validate(['login' => ['required', 'string', 'max:120'], 'password' => ['required', 'string']]);

        $key = 'login:customer:'.tenant_id().':'.Str::lower($this->login).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('login', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        // Customer queries are tenant scoped: only this shop's customers match.
        $field = str_contains($this->login, '@') ? 'email' : 'phone';
        $value = $field === 'phone' ? preg_replace('/[^0-9+]/', '', $this->login) : Str::lower(trim($this->login));
        $customer = Customer::query()->where($field, $value)->first();

        if (! $customer || ! $customer->password || ! Hash::check($this->password, $customer->password)) {
            RateLimiter::hit($key, 60);
            $this->addError('login', $customer && ! $customer->password
                ? 'Portal access is not enabled for your account yet. Please ask the shop to set your password.'
                : 'These credentials do not match our records.');

            return;
        }

        if ($customer->status !== 'active') {
            $this->addError('login', 'Your account is inactive. Please contact the shop.');

            return;
        }

        RateLimiter::clear($key);
        Auth::guard('customer')->login($customer, $this->remember);
        session()->regenerate();
        $customer->forceFill(['last_login_at' => now()])->save();

        $this->redirectIntended(route('portal.dashboard'));
    }
}; ?>

<x-auth-card heading="{{ tenant()->name }}" :badge="tenant()->name" tagline="Your purchases and account statement, any time."
             :features="[['ri-shopping-bag-3-line', 'Every invoice with item-level detail'], ['ri-file-list-2-line', 'Transparent running statement'], ['ri-download-2-line', 'Download receipts and statements']]">
    <p class="text-muted mb-4">Customer portal — sign in with your phone number or email.</p>

    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <form wire:submit="signIn" class="vstack gap-3">
        <div>
            <label class="form-label" for="login">Phone or email</label>
            <input wire:model="login" type="text" id="login" class="form-control form-control-lg @error('login') is-invalid @enderror" placeholder="03XX XXXXXXX" autocomplete="username" autofocus>
            @error('login')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div>
            <label class="form-label" for="password">Password</label>
            <input wire:model="password" type="password" id="password" class="form-control form-control-lg" autocomplete="current-password">
        </div>
        <div class="form-check">
            <input wire:model="remember" class="form-check-input" type="checkbox" id="remember">
            <label class="form-check-label" for="remember">Keep me signed in</label>
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-100" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="signIn">Sign in</span>
            <span wire:loading wire:target="signIn"><span class="spinner-border spinner-border-sm me-2"></span>Signing in…</span>
        </button>
    </form>

    @if (app()->isLocal() && tenant()->slug === 'urban-garments')
        <div class="alert alert-light border mt-4 mb-0 small">Demo: <code>03001234567</code> / <code>password</code></div>
    @endif
</x-auth-card>
