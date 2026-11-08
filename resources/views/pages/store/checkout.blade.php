<?php

use App\Exceptions\BusinessException;
use App\Services\Store\Cart;
use App\Services\Store\OrderService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.store')] #[Title('Checkout')] class extends Component {
    use WithFileUploads;

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $address = '';

    public string $city = '';

    public string $notes = '';

    public string $payment_method = '';

    public ?int $payment_account_id = null;

    public string $payment_reference = '';

    public $proof = null;

    public bool $create_account = false;

    public string $password = '';

    public string $coupon = '';

    public function mount(Cart $cart)
    {
        if ($cart->isEmpty()) {
            return $this->redirectRoute('store.cart');
        }

        if ($customer = auth('customer')->user()) {
            $this->name = $customer->name;
            $this->phone = (string) $customer->phone;
            $this->email = (string) $customer->email;
            $this->address = (string) $customer->address;
        }

        $methods = tenant()->store()->paymentMethods();
        $this->payment_method = (string) array_key_first($methods);
        $this->payment_account_id = $methods[$this->payment_method]['accounts']->first()?->id ?? null;
    }

    public function updatedPaymentMethod(string $method): void
    {
        $this->payment_account_id = tenant()->store()->paymentMethods()[$method]['accounts']->first()?->id ?? null;
        $this->resetValidation(['payment_account_id', 'proof']);
    }

    public function applyCoupon(Cart $cart): void
    {
        $this->validate(['coupon' => ['required', 'string', 'max:40']]);

        try {
            $applied = $cart->applyCoupon($this->coupon);
            $this->coupon = '';
            $this->resetErrorBag('coupon');
            $this->dispatch('sf-toast', text: "{$applied->code} applied: {$applied->label}.");
        } catch (BusinessException $e) {
            $this->addError('coupon', $e->getMessage());
        }
    }

    public function removeCoupon(Cart $cart): void
    {
        $cart->removeCoupon();
    }

    public function place(OrderService $orders)
    {
        $guest = ! auth('customer')->check();

        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{10,20}$/'],
            'email' => ['nullable', 'email', 'max:120'],
            'address' => ['required', 'string', 'max:400'],
            'city' => ['required', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
            'payment_method' => ['required', 'string'],
            'payment_account_id' => ['nullable', 'integer'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:4096'],
            'password' => $guest && $this->create_account ? ['required', 'string', 'min:8', 'max:100'] : ['nullable'],
        ], [
            'phone.regex' => 'Enter a valid mobile number, e.g. 0300 1234567.',
            'address.required' => 'Enter the street address for delivery.',
        ]);

        try {
            $order = $orders->place([
                ...$data,
                'email' => $data['email'] ?: null,
                'notes' => $data['notes'] ?: null,
                'payment_reference' => $data['payment_reference'] ?: null,
                'password' => $guest && $this->create_account ? $data['password'] : null,
            ], auth('customer')->user(), $this->proof);
        } catch (BusinessException $e) {
            $this->addError('order', $e->getMessage());

            return null;
        }

        session()->flash('store.placed', $order->id);

        return $this->redirectRoute('store.order', ['order' => $order->order_number]);
    }

    public function with(Cart $cart): array
    {
        return [
            'lines' => $cart->lines(),
            'summary' => $cart->summary(),
            'methods' => tenant()->store()->paymentMethods(),
            'sf' => storefront(),
        ];
    }
}; ?>

<div class="sf-container sf-checkout-page">
    <h1 class="sf-page-title">Checkout</h1>

    @if ($lines->isEmpty())
        <div class="sf-empty">
            <p class="sf-empty__title">Your bag is empty</p>
            <a href="{{ route('store.shop') }}" class="sf-btn sf-btn--accent">Continue shopping</a>
        </div>
    @else
    <form wire:submit="place" class="sf-checkout" novalidate>
        <div class="sf-checkout__main">
            @error('order')
                <div class="sf-notice sf-notice--error" role="alert"><x-store.icon name="info" /> <span>{{ $message }} <a href="{{ route('store.cart') }}">Review your bag</a></span></div>
            @enderror

            <section class="sf-card-box sf-step">
                <h2 class="sf-step__title">Contact</h2>
                @guest('customer')
                    <p class="sf-muted sf-step__aside">Have an account? <a href="{{ route('portal.login') }}">Sign in</a> for faster checkout.</p>
                @endguest
                <div class="sf-form-grid">
                    <label class="sf-field sf-span-2">
                        <span class="sf-label">Full name</span>
                        <input wire:model.blur="name" class="sf-input @error('name') is-invalid @enderror" autocomplete="name" required>
                        @error('name')<span class="sf-error">{{ $message }}</span>@enderror
                    </label>
                    <label class="sf-field">
                        <span class="sf-label">Mobile number</span>
                        <input wire:model.blur="phone" type="tel" inputmode="tel" class="sf-input @error('phone') is-invalid @enderror" autocomplete="tel" placeholder="0300 1234567" required>
                        @error('phone')<span class="sf-error">{{ $message }}</span>@enderror
                    </label>
                    <label class="sf-field">
                        <span class="sf-label">Email (optional)</span>
                        <input wire:model.blur="email" type="email" inputmode="email" class="sf-input @error('email') is-invalid @enderror" autocomplete="email">
                        @error('email')<span class="sf-error">{{ $message }}</span>@enderror
                    </label>
                </div>
            </section>

            <section class="sf-card-box sf-step">
                <h2 class="sf-step__title">Delivery address</h2>
                <div class="sf-form-grid">
                    <label class="sf-field sf-span-2">
                        <span class="sf-label">Street address</span>
                        <input wire:model.blur="address" class="sf-input @error('address') is-invalid @enderror" autocomplete="street-address" placeholder="House, street, area" required>
                        @error('address')<span class="sf-error">{{ $message }}</span>@enderror
                    </label>
                    <label class="sf-field">
                        <span class="sf-label">City</span>
                        <input wire:model.blur="city" list="sf-cities" class="sf-input @error('city') is-invalid @enderror" autocomplete="address-level2" required>
                        <datalist id="sf-cities">
                            @foreach (['Karachi', 'Lahore', 'Islamabad', 'Rawalpindi', 'Faisalabad', 'Multan', 'Peshawar', 'Quetta', 'Sialkot', 'Gujranwala', 'Hyderabad', 'Bahawalpur', 'Sargodha', 'Abbottabad'] as $c)<option value="{{ $c }}">@endforeach
                        </datalist>
                        @error('city')<span class="sf-error">{{ $message }}</span>@enderror
                    </label>
                    <label class="sf-field">
                        <span class="sf-label">Order note (optional)</span>
                        <input wire:model.blur="notes" class="sf-input" placeholder="e.g. call before delivery">
                    </label>
                </div>
            </section>

            <section class="sf-card-box sf-step">
                <h2 class="sf-step__title">Payment</h2>
                @if (! $methods)
                    <p class="sf-error">This shop has not set up a payment method yet. Please contact the shop.</p>
                @endif
                <div class="sf-paymethods" role="radiogroup" aria-label="Payment method">
                    @foreach ($methods as $key => $method)
                        <label @class(['sf-paymethod', 'is-on' => $payment_method === $key])>
                            <input type="radio" wire:model.live="payment_method" value="{{ $key }}" name="payment_method">
                            <x-store.icon :name="$key === 'cod' ? 'cash' : ($key === 'wallet' ? 'wallet' : 'bank')" />
                            <span class="sf-paymethod__text">
                                <b>{{ $method['label'] }}</b>
                                <small>{{ match ($key) { 'cod' => 'Pay the courier in cash when your order arrives.', 'bank' => 'Transfer to our bank account and upload the receipt.', default => 'Send from your mobile wallet and upload the screenshot.' } }}</small>
                            </span>
                        </label>
                    @endforeach
                </div>

                @if ($payment_method && $payment_method !== 'cod' && isset($methods[$payment_method]))
                    <div class="sf-paydetail">
                        <p class="sf-label">Pay {{ $sf->price($summary['total']) }} to</p>
                        @foreach ($methods[$payment_method]['accounts'] as $acct)
                            <label @class(['sf-acct', 'is-on' => $payment_account_id === $acct->id])>
                                <input type="radio" wire:model.live="payment_account_id" value="{{ $acct->id }}" name="payment_account_id">
                                <span>
                                    <b>{{ $acct->bank_name ?: $acct->account_name }}</b>
                                    <span>{{ $acct->account_name }}</span>
                                    @if ($acct->account_number)<span class="sf-mono">{{ $acct->account_number }}</span>@endif
                                </span>
                            </label>
                        @endforeach
                        @if ($instructions = $sf->settings->get('payment_instructions'))<p class="sf-muted">{{ $instructions }}</p>@endif
                        <label class="sf-upload">
                            <x-store.icon name="upload" />
                            <span>
                                @if ($proof) {{ $proof->getClientOriginalName() }} @else Upload receipt now (optional) @endif
                                <small>You can also upload it after placing the order.</small>
                            </span>
                            <input type="file" wire:model="proof" accept="image/*,application/pdf">
                        </label>
                        <div wire:loading wire:target="proof" class="sf-muted">Uploading…</div>
                        @error('proof')<span class="sf-error">{{ $message }}</span>@enderror
                        <label class="sf-field">
                            <span class="sf-label">Transaction ID (optional)</span>
                            <input wire:model.blur="payment_reference" class="sf-input" maxlength="100">
                        </label>
                    </div>
                @endif
            </section>

            @guest('customer')
                <section class="sf-card-box sf-step">
                    <label class="sf-check">
                        <input type="checkbox" wire:model.live="create_account">
                        <span><b>Create an account</b> to track orders and see your purchase history</span>
                    </label>
                    @if ($create_account)
                        <label class="sf-field">
                            <span class="sf-label">Choose a password</span>
                            <input wire:model.blur="password" type="password" class="sf-input @error('password') is-invalid @enderror" autocomplete="new-password" minlength="8">
                            <span class="sf-help">At least 8 characters. You'll sign in with your mobile number.</span>
                            @error('password')<span class="sf-error">{{ $message }}</span>@enderror
                        </label>
                    @endif
                </section>
            @endguest
        </div>

        <aside class="sf-checkout__side">
            <div class="sf-summary">
                <h2 class="sf-summary__title">Order summary <a href="{{ route('store.cart') }}" class="sf-link">Edit bag</a></h2>
                <ul class="sf-lines sf-lines--mini">
                    @foreach ($lines as $line)
                        <li class="sf-line">
                            <span class="sf-line__media">
                                @if ($line['image'])<img src="{{ $line['image'] }}" alt="" width="56" height="70">@endif
                                <span class="sf-line__qty">{{ $line['quantity'] }}</span>
                            </span>
                            <div class="sf-line__body">
                                <span class="sf-line__title">{{ $line['variant']->product->name }}</span>
                                <span class="sf-line__variant">{{ $line['variant']->variant_name }}</span>
                            </div>
                            <span class="sf-line__price">{{ $sf->price($line['total']) }}</span>
                        </li>
                    @endforeach
                </ul>

                @if ($summary['coupon'])
                    <div class="sf-coupon sf-coupon--applied">
                        <span><x-store.icon name="tag" /> <b>{{ $summary['coupon']->code }}</b> {{ $summary['coupon']->label }}</span>
                        <button type="button" class="sf-link" wire:click="removeCoupon">Remove</button>
                    </div>
                @else
                    <div class="sf-coupon">
                        <label for="sf-co-coupon" class="sf-visually-hidden">Discount code</label>
                        <input id="sf-co-coupon" wire:model="coupon" wire:keydown.enter.prevent="applyCoupon" class="sf-input" placeholder="Discount code" autocomplete="off">
                        <button type="button" class="sf-btn sf-btn--ghost" wire:click="applyCoupon" wire:loading.attr="disabled" wire:target="applyCoupon">Apply</button>
                    </div>
                    @error('coupon')<p class="sf-error">{{ $message }}</p>@enderror
                @endif

                <dl class="sf-totals">
                    <div><dt>Subtotal</dt><dd>{{ $sf->price($summary['subtotal']) }}</dd></div>
                    @if ($summary['discount'] > 0)<div class="sf-totals__save"><dt>Discount</dt><dd>-{{ $sf->price($summary['discount']) }}</dd></div>@endif
                    <div><dt>Delivery</dt><dd>{{ $summary['delivery'] > 0 ? $sf->price($summary['delivery']) : 'Free' }}</dd></div>
                    @if ($summary['tax'] > 0)<div><dt>Tax</dt><dd>{{ $sf->price($summary['tax']) }}</dd></div>@endif
                    <div class="sf-totals__total"><dt>Total</dt><dd>{{ $sf->price($summary['total']) }}</dd></div>
                </dl>
                @if ($summary['free_delivery_gap'] > 0)
                    <p class="sf-muted sf-small">Add {{ $sf->price($summary['free_delivery_gap']) }} more for free delivery.</p>
                @endif

                <button type="submit" class="sf-btn sf-btn--accent sf-btn--block sf-btn--lg sf-place" wire:loading.class="is-loading" wire:target="place" wire:loading.attr="disabled" @disabled(! $methods)>
                    Place order, {{ $sf->price($summary['total']) }}
                </button>
                <p class="sf-muted sf-small sf-center"><x-store.icon name="shield" /> We only use your details to deliver this order.</p>
            </div>
        </aside>
    </form>
    @endif
</div>

@script
<script>
    $wire.on('sf-toast', ({ text }) => Alpine.store('shop').toast(text));
    // After "Place order", bring the first problem into view.
    $wire.hook('commit', ({ commit, succeed }) => {
        if (!commit.calls?.some((call) => call.method === 'place')) return;
        succeed(() => queueMicrotask(() => {
            const error = document.querySelector('.sf-checkout__main .sf-error, .sf-checkout__main .sf-notice--error');
            error?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }));
    });
</script>
@endscript
