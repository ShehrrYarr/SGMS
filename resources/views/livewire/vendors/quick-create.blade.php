<?php

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Vendor;
use App\Services\LedgerService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

/**
 * Reusable "Add / edit vendor" modal. Dispatches `vendor-saved` with the new id + label.
 * Open with: $dispatch('vendor-form', { id: null })
 */
new class extends Component {
    use InteractsWithUi;

    public ?int $vendorId = null;

    public string $name = '';

    public string $company = '';

    public string $phone = '';

    public string $email = '';

    public string $address = '';

    public $opening_balance = 0;

    public string $status = 'active';

    #[On('vendor-form')]
    public function open(?int $id = null): void
    {
        $this->resetValidation();
        $this->reset('vendorId', 'name', 'company', 'phone', 'email', 'address', 'opening_balance', 'status');

        if ($id && ($vendor = Vendor::query()->find($id))) {
            $this->vendorId = $vendor->id;
            $this->fill($vendor->only('name', 'company', 'phone', 'email', 'address', 'status'));
            $this->opening_balance = (float) $vendor->opening_balance;
        }

        $this->openModal('vendor-form');
    }

    public function save(LedgerService $ledger): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:190'],
            'address' => ['nullable', 'string', 'max:255'],
            'opening_balance' => ['nullable', 'numeric'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        $vendor = DB::transaction(function () use ($data, $ledger) {
            if ($this->vendorId) {
                $vendor = Vendor::query()->findOrFail($this->vendorId);
                $vendor->update(collect($data)->except('opening_balance')->all());

                return $vendor;
            }

            $vendor = Vendor::create([...$data, 'opening_balance' => (float) ($data['opening_balance'] ?? 0), 'current_balance' => 0]);
            $ledger->openVendor($vendor, (float) ($data['opening_balance'] ?? 0));

            return $vendor;
        });

        $this->closeModal('vendor-form');
        $this->toast($this->vendorId ? 'Vendor updated.' : 'Vendor added.');
        $this->dispatch('vendor-saved', id: $vendor->id, label: $vendor->name.($vendor->company ? ' — '.$vendor->company : ''));
    }
}; ?>

<div>
    <x-modal name="vendor-form" :title="$vendorId ? 'Edit vendor' : 'Add vendor'" size="lg">
        <form wire:submit="save" id="vendor-form-el" class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Contact name <span class="text-danger">*</span></label>
                <input wire:model="name" class="form-control @error('name') is-invalid @enderror" autofocus>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label">Company / mill</label>
                <input wire:model="company" class="form-control">
            </div>
            <div class="col-md-6">
                <label class="form-label">Phone</label>
                <input wire:model="phone" class="form-control" inputmode="tel">
            </div>
            <div class="col-md-6">
                <label class="form-label">Email</label>
                <input wire:model="email" type="email" class="form-control @error('email') is-invalid @enderror">
                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12">
                <label class="form-label">Address</label>
                <input wire:model="address" class="form-control">
            </div>
            <div class="col-md-6">
                <label class="form-label">Opening balance (we owe)</label>
                <input wire:model="opening_balance" type="number" step="0.01" class="form-control" @disabled($vendorId)>
                <div class="form-text">{{ $vendorId ? 'Use a ledger adjustment to change balances.' : 'Negative = advance paid to vendor.' }}</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Status</label>
                <select wire:model="status" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select>
            </div>
        </form>
        <x-slot:footer>
            <button type="button" class="btn btn-light" x-on:click="$dispatch('close-modal', { name: 'vendor-form' })">Cancel</button>
            <button type="submit" form="vendor-form-el" class="btn btn-primary" wire:loading.attr="disabled"><i class="ri-save-line me-1"></i>Save vendor</button>
        </x-slot:footer>
    </x-modal>
</div>
