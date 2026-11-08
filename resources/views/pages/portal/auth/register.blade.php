<?php

use App\Models\Customer;
use App\Services\Store\WishlistService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.auth')] #[Title('Create Account')] class extends Component {
    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function register(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{10,20}$/'],
            'email' => ['nullable', 'email', 'max:120'],
            'password' => ['required', 'string', 'min:8', 'max:100', 'confirmed'],
        ], ['phone.regex' => 'Enter a valid mobile number, e.g. 0300 1234567.']);

        $key = 'register:customer:'.tenant_id().'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('phone', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        RateLimiter::hit($key, 600);

        $phone = normalize_phone($this->phone);
        $email = trim($this->email) ?: null;

        // Accounts are matched by phone. An existing customer record (from an
        // in-store sale or an earlier order) is never claimed without the shop,
        // because the phone number is not verified here.
        if (Customer::query()->where('phone', $phone)->exists()) {
            $this->addError('phone', 'This number is already registered with the shop. Sign in, or ask the shop to turn on online access for your account.');

            return;
        }

        if ($email && Customer::query()->where('email', $email)->exists()) {
            $this->addError('email', 'This email is already used by another account.');

            return;
        }

        $customer = Customer::create([
            'name' => trim($this->name),
            'phone' => $phone,
            'email' => $email,
            'password' => $this->password,
        ]);

        Auth::guard('customer')->login($customer, true);
        session()->regenerate();
        $customer->forceFill(['last_login_at' => now()])->save();
        app(WishlistService::class)->mergeGuestList($customer);

        $this->redirectIntended(tenant()->storeIsLive() ? tenant()->storeUrl() : route('portal.dashboard'));
    }
}; ?>

<x-auth-card heading="Create your account" :badge="tenant()->name" tagline="Track online orders, save a wishlist and see your statement."
             :features="[['ri-truck-line', 'Follow every order to your door'], ['ri-heart-3-line', 'Keep a wishlist on every device'], ['ri-file-list-2-line', 'Purchases from the shop and online in one place']]">
    <p class="text-muted mb-4">Sign up with your mobile number. You'll use it to sign in.</p>

    <form wire:submit="register" class="vstack gap-3">
        <div>
            <label class="form-label" for="name">Full name</label>
            <input wire:model="name" id="name" class="form-control form-control-lg @error('name') is-invalid @enderror" autocomplete="name" autofocus>
            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div>
            <label class="form-label" for="phone">Mobile number</label>
            <input wire:model="phone" id="phone" type="tel" inputmode="tel" class="form-control form-control-lg @error('phone') is-invalid @enderror" placeholder="0300 1234567" autocomplete="tel">
            @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div>
            <label class="form-label" for="email">Email (optional)</label>
            <input wire:model="email" id="email" type="email" class="form-control form-control-lg @error('email') is-invalid @enderror" autocomplete="email">
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="row g-3">
            <div class="col-sm-6">
                <label class="form-label" for="password">Password</label>
                <input wire:model="password" id="password" type="password" class="form-control form-control-lg @error('password') is-invalid @enderror" autocomplete="new-password">
                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6">
                <label class="form-label" for="password_confirmation">Repeat password</label>
                <input wire:model="password_confirmation" id="password_confirmation" type="password" class="form-control form-control-lg" autocomplete="new-password">
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-100" wire:loading.attr="disabled">Create account</button>
    </form>

    <div class="d-flex flex-wrap justify-content-between gap-2 mt-4 small">
        <a href="{{ route('portal.login') }}" wire:navigate>Already have an account? Sign in</a>
        @if (tenant()->storeIsLive())<a href="{{ tenant()->storeUrl() }}">Back to the store</a>@endif
    </div>
</x-auth-card>
