<?php

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\PaymentAccount;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Online Store Settings')] class extends Component {
    use InteractsWithUi;

    public bool $store_enabled = false;

    public $delivery_fee = 0;

    public bool $free_delivery = false;

    public $free_delivery_over = null;

    public string $delivery_note = '';

    public bool $cod = true;

    public array $bank_accounts = [];

    public array $wallet_accounts = [];

    public string $payment_instructions = '';

    public function mount(): void
    {
        $tenant = tenant();
        $store = $tenant->store();

        $this->store_enabled = (bool) $tenant->store_enabled;
        $this->delivery_fee = (float) $store->get('delivery_fee', 0);
        $this->free_delivery_over = $store->freeDeliveryOver();
        $this->free_delivery = $this->free_delivery_over !== null;
        $this->delivery_note = (string) $store->get('delivery_note');
        $this->cod = (bool) $store->get('cod', true);
        $this->bank_accounts = array_map('strval', (array) $store->get('bank_accounts', []));
        $this->wallet_accounts = array_map('strval', (array) $store->get('wallet_accounts', []));
        $this->payment_instructions = (string) $store->get('payment_instructions');
    }

    public function save(): void
    {
        $this->validate([
            'delivery_fee' => ['required', 'numeric', 'min:0', 'max:100000'],
            'free_delivery_over' => $this->free_delivery ? ['required', 'numeric', 'min:1', 'max:10000000'] : ['nullable'],
            'delivery_note' => ['nullable', 'string', 'max:120'],
            'bank_accounts.*' => ['integer'],
            'wallet_accounts.*' => ['integer'],
            'payment_instructions' => ['nullable', 'string', 'max:500'],
        ], [], ['free_delivery_over' => 'free delivery threshold']);

        $accounts = PaymentAccount::query()->active()->pluck('account_type', 'id');
        $banks = array_values(array_filter(array_map('intval', $this->bank_accounts), fn ($id) => ($accounts[$id] ?? null) === PaymentAccount::TYPE_BANK));
        $wallets = array_values(array_filter(array_map('intval', $this->wallet_accounts), fn ($id) => ($accounts[$id] ?? null) === PaymentAccount::TYPE_WALLET));

        if ($this->store_enabled && ! $this->cod && ! $banks && ! $wallets) {
            $this->addError('cod', 'Turn on at least one payment method before opening the store.');

            return;
        }

        $tenant = tenant();
        $settings = $tenant->settings ?? [];
        $settings['store'] = array_merge($settings['store'] ?? [], [
            'delivery_fee' => round((float) $this->delivery_fee, 2),
            'free_delivery_over' => $this->free_delivery ? round((float) $this->free_delivery_over, 2) : null,
            'delivery_note' => trim($this->delivery_note) ?: null,
            'cod' => $this->cod,
            'bank_accounts' => $banks,
            'wallet_accounts' => $wallets,
            'payment_instructions' => trim($this->payment_instructions) ?: null,
        ]);

        $tenant->update(['settings' => $settings, 'store_enabled' => $this->store_enabled]);

        $this->toast($this->store_enabled ? 'Saved. Your store is live.' : 'Saved. Your store is hidden from shoppers.');
    }

    public function with(): array
    {
        $accounts = PaymentAccount::query()->active()->whereIn('account_type', [PaymentAccount::TYPE_BANK, PaymentAccount::TYPE_WALLET])->orderBy('account_name')->get();

        return [
            'banks' => $accounts->where('account_type', PaymentAccount::TYPE_BANK),
            'wallets' => $accounts->where('account_type', PaymentAccount::TYPE_WALLET),
            'tenant' => tenant(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Shop settings" subtitle="Online orders, delivery charges and how customers pay." />
    @include('partials.settings-tabs', ['current' => 'app.settings.store'])

    @if ($tenant->store_blocked)
        <div class="alert alert-danger d-flex gap-2"><i class="ri-error-warning-line fs-5"></i><div>The platform has disabled your online store. Contact SGMS support to restore it.</div></div>
    @endif

    <form wire:submit="save" class="row g-3">
        <div class="col-12">
            <div class="card mb-0">
                <div class="card-body d-flex flex-wrap align-items-center gap-3">
                    <div class="avatar-md rounded-circle d-flex align-items-center justify-content-center {{ $store_enabled ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' }}">
                        <i class="ri-store-2-line fs-4"></i>
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <h6 class="mb-1">{{ $store_enabled ? 'Your online store is live' : 'Your online store is switched off' }}</h6>
                        <p class="text-muted mb-0 small">
                            <a href="{{ $tenant->storeUrl() }}" target="_blank" rel="noopener">{{ $tenant->storeUrl() }}</a>
                            {{ $store_enabled ? '' : '— shoppers see a "coming soon" page. You can preview it while signed in.' }}
                        </p>
                    </div>
                    <div class="form-check form-switch form-switch-lg mb-0">
                        <input wire:model.live="store_enabled" class="form-check-input" type="checkbox" id="store_enabled" role="switch">
                        <label class="form-check-label fw-medium" for="store_enabled">Store open</label>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-6">
            <div class="card h-100 mb-0">
                <div class="card-header"><h6 class="card-title mb-0"><i class="ri-truck-line me-1"></i>Delivery charges</h6></div>
                <div class="card-body row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="delivery_fee">Delivery charge per order</label>
                        <div class="input-group"><span class="input-group-text">{{ $tenant->currency_symbol }}</span><input wire:model.live.debounce.400ms="delivery_fee" id="delivery_fee" type="number" min="0" step="1" class="form-control @error('delivery_fee') is-invalid @enderror"></div>
                        @error('delivery_fee')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        <div class="form-text">0 makes every delivery free.</div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch mb-2">
                            <input wire:model.live="free_delivery" class="form-check-input" type="checkbox" id="free_delivery">
                            <label class="form-check-label" for="free_delivery">Free delivery above an order value</label>
                        </div>
                        @if ($free_delivery)
                            <div class="input-group"><span class="input-group-text">Over {{ $tenant->currency_symbol }}</span><input wire:model.live.debounce.400ms="free_delivery_over" type="number" min="1" step="1" class="form-control @error('free_delivery_over') is-invalid @enderror" placeholder="5000"></div>
                            @error('free_delivery_over')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        @endif
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="delivery_note">Delivery time note</label>
                        <input wire:model="delivery_note" id="delivery_note" class="form-control" maxlength="120" placeholder="Delivered in 3–5 working days">
                    </div>
                    <div class="col-12">
                        <div class="bg-light rounded-3 p-3 small">
                            <div class="fw-semibold mb-1">Shoppers will see</div>
                            @php($fee = (float) $delivery_fee)
                            @if ($fee <= 0)
                                Free delivery on every order.
                            @elseif ($free_delivery && (float) $free_delivery_over > 0)
                                Delivery {{ money($fee, decimals: 0) }}; free on orders of {{ money($free_delivery_over, decimals: 0) }} or more. The bag shows "Add Rs. X more for free delivery".
                            @else
                                Delivery {{ money($fee, decimals: 0) }} on every order.
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-6">
            <div class="card h-100 mb-0">
                <div class="card-header"><h6 class="card-title mb-0"><i class="ri-secure-payment-line me-1"></i>Payment methods at checkout</h6></div>
                <div class="card-body">
                    <div class="form-check form-switch mb-3">
                        <input wire:model="cod" class="form-check-input" type="checkbox" id="cod">
                        <label class="form-check-label" for="cod"><span class="fw-medium">Cash on delivery</span> <span class="text-muted small d-block">Collected cash is posted when you mark the order delivered.</span></label>
                        @error('cod')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <div class="fw-medium mb-1">Bank transfer to</div>
                        @forelse ($banks as $account)
                            <div class="form-check">
                                <input wire:model="bank_accounts" class="form-check-input" type="checkbox" value="{{ $account->id }}" id="bank-{{ $account->id }}">
                                <label class="form-check-label" for="bank-{{ $account->id }}">{{ $account->label }} @if ($account->account_number)<span class="text-muted small">({{ $account->account_number }})</span>@endif</label>
                            </div>
                        @empty
                            <p class="text-muted small mb-0">No bank accounts yet. <a href="{{ route('app.accounts.index') }}" wire:navigate>Add one in Accounts</a>.</p>
                        @endforelse
                    </div>

                    <div class="mb-3">
                        <div class="fw-medium mb-1">JazzCash / Easypaisa to</div>
                        @forelse ($wallets as $account)
                            <div class="form-check">
                                <input wire:model="wallet_accounts" class="form-check-input" type="checkbox" value="{{ $account->id }}" id="wallet-{{ $account->id }}">
                                <label class="form-check-label" for="wallet-{{ $account->id }}">{{ $account->label }} @if ($account->account_number)<span class="text-muted small">({{ $account->account_number }})</span>@endif</label>
                            </div>
                        @empty
                            <p class="text-muted small mb-0">No mobile wallets yet. <a href="{{ route('app.accounts.index') }}" wire:navigate>Add one in Accounts</a>.</p>
                        @endforelse
                    </div>

                    <label class="form-label" for="payment_instructions">Instructions for transfers</label>
                    <textarea wire:model="payment_instructions" id="payment_instructions" class="form-control" rows="2" maxlength="500" placeholder="Use your order number as the payment reference. Orders ship once payment is confirmed."></textarea>
                    <div class="form-text">Account title and number are shown to the shopper at checkout. Make sure they are filled in under Accounts.</div>
                </div>
            </div>
        </div>

        <div class="col-12 text-end">
            <button class="btn btn-primary" type="submit" wire:loading.attr="disabled"><i class="ri-save-line me-1"></i>Save store settings</button>
        </div>
    </form>
</div>
