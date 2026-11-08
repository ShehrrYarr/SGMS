<?php

use App\Livewire\Concerns\InteractsWithUi;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Shop Settings')] class extends Component {
    use InteractsWithUi;

    public string $name = '';

    public string $phone = '';

    public string $address = '';

    public string $currency_symbol = 'Rs.';

    public $tax_rate = 0;

    public string $receipt_width = '80';

    public string $receipt_footer = '';

    public bool $auto_print = true;

    public bool $allow_negative_balance = false;

    public function mount(): void
    {
        $tenant = tenant();
        $this->fill($tenant->only('name', 'phone', 'address', 'currency_symbol'));
        $this->phone = (string) $tenant->phone;
        $this->address = (string) $tenant->address;
        $this->tax_rate = (float) $tenant->tax_rate;
        $this->receipt_width = (string) $tenant->setting('receipt_width', '80');
        $this->receipt_footer = (string) $tenant->setting('receipt_footer', '');
        $this->auto_print = (bool) $tenant->setting('auto_print', true);
        $this->allow_negative_balance = (bool) $tenant->setting('allow_negative_balance', false);
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'currency_symbol' => ['required', 'string', 'max:10'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:50'],
            'receipt_width' => ['required', 'in:58,80'],
            'receipt_footer' => ['nullable', 'string', 'max:255'],
        ]);

        $tenant = tenant();
        $tenant->update([
            'name' => $this->name,
            'phone' => $this->phone ?: null,
            'address' => $this->address ?: null,
            'currency_symbol' => $this->currency_symbol,
            'tax_rate' => $this->tax_rate,
            'settings' => array_merge($tenant->settings ?? [], [
                'receipt_width' => $this->receipt_width,
                'receipt_footer' => $this->receipt_footer,
                'auto_print' => $this->auto_print,
                'allow_negative_balance' => $this->allow_negative_balance,
            ]),
        ]);

        $this->toast('Settings saved.');
    }
}; ?>

<div>
    <x-page-header title="Shop settings" subtitle="Applies to every branch of {{ tenant()->name }}." />
    @include('partials.settings-tabs', ['current' => 'app.settings'])

    <form wire:submit="save" class="row g-3">
        <div class="col-xl-6">
            <div class="card h-100 mb-0">
                <div class="card-header"><h6 class="card-title mb-0"><i class="ri-store-3-line me-1"></i>Shop profile</h6></div>
                <div class="card-body row g-3">
                    <div class="col-12"><label class="form-label">Shop name</label><input wire:model="name" class="form-control @error('name') is-invalid @enderror"></div>
                    <div class="col-md-6"><label class="form-label">Phone</label><input wire:model="phone" class="form-control"></div>
                    <div class="col-md-6"><label class="form-label">Online store address</label><input class="form-control" value="{{ tenant()->storeUrl() }}" readonly onclick="this.select()"></div>
                    <div class="col-12"><label class="form-label">Address</label><input wire:model="address" class="form-control"></div>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card h-100 mb-0">
                <div class="card-header"><h6 class="card-title mb-0"><i class="ri-percent-line me-1"></i>Currency & tax</h6></div>
                <div class="card-body row g-3">
                    <div class="col-md-6"><label class="form-label">Currency symbol</label><input wire:model="currency_symbol" class="form-control @error('currency_symbol') is-invalid @enderror"></div>
                    <div class="col-md-6">
                        <label class="form-label">Default sales tax %</label>
                        <div class="input-group"><input wire:model="tax_rate" type="number" step="0.01" min="0" class="form-control @error('tax_rate') is-invalid @enderror"><span class="input-group-text">%</span></div>
                        <div class="form-text">0 hides the tax line at the POS.</div>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input wire:model="allow_negative_balance" class="form-check-input" type="checkbox" id="neg">
                            <label class="form-check-label" for="neg">Allow payments that take an account below zero</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12">
            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0"><i class="ri-printer-line me-1"></i>Receipts</h6></div>
                <div class="card-body row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Thermal paper width</label>
                        <select wire:model="receipt_width" class="form-select"><option value="80">80 mm</option><option value="58">58 mm</option></select>
                    </div>
                    <div class="col-md-8"><label class="form-label">Receipt footer</label><input wire:model="receipt_footer" class="form-control" placeholder="Thank you! Exchange within 7 days with receipt."></div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input wire:model="auto_print" class="form-check-input" type="checkbox" id="ap">
                            <label class="form-check-label" for="ap">Open the print dialog automatically after each POS sale</label>
                        </div>
                    </div>
                </div>
                <div class="card-footer text-end"><button class="btn btn-primary" type="submit"><i class="ri-save-line me-1"></i>Save settings</button></div>
            </div>
        </div>
    </form>
</div>
