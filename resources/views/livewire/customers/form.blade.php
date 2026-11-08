<?php

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Customer;
use App\Services\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

/**
 * Add / edit customer, including customer portal access.
 * Open with: $dispatch('customer-form', { id: null })
 */
new class extends Component {
    use InteractsWithUi;

    public ?int $customerId = null;

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $address = '';

    public $opening_balance = 0;

    public string $status = 'active';

    public bool $portal = false;

    public string $password = '';

    public bool $hasPassword = false;

    #[On('customer-form')]
    public function open(?int $id = null): void
    {
        $this->resetValidation();
        $this->reset('customerId', 'name', 'phone', 'email', 'address', 'opening_balance', 'status', 'portal', 'password', 'hasPassword');

        if ($id && ($customer = Customer::query()->find($id))) {
            $this->customerId = $customer->id;
            $this->fill($customer->only('name', 'phone', 'address', 'status'));
            $this->email = (string) $customer->email;
            $this->opening_balance = (float) $customer->opening_balance;
            $this->hasPassword = ! empty($customer->password);
            $this->portal = $this->hasPassword;
        }

        $this->openModal('customer-form');
    }

    public function generatePassword(): void
    {
        $this->password = Str::lower(Str::random(4)).random_int(1000, 9999);
        $this->portal = true;
    }

    public function save(LedgerService $ledger): void
    {
        $this->phone = preg_replace('/[^0-9+]/', '', $this->phone);
        $this->email = Str::lower(trim($this->email));

        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'min:7', 'max:30', Rule::unique('customers', 'phone')->where('tenant_id', tenant_id())->ignore($this->customerId)],
            'email' => ['nullable', 'email', 'max:190', Rule::unique('customers', 'email')->where('tenant_id', tenant_id())->ignore($this->customerId)],
            'address' => ['nullable', 'string', 'max:255'],
            'opening_balance' => ['nullable', 'numeric'],
            'status' => ['required', 'in:active,inactive'],
            'password' => [$this->portal && ! $this->hasPassword ? 'required' : 'nullable', 'string', 'min:6', 'max:64'],
        ], ['password.required' => 'Set a password to enable portal access.']);

        $attributes = collect($data)->only('name', 'phone', 'address', 'status')->all() + ['email' => $data['email'] ?: null];

        if (! $this->portal) {
            $attributes['password'] = null;
        } elseif ($this->password !== '') {
            $attributes['password'] = $this->password;
        }

        $customer = DB::transaction(function () use ($attributes, $data, $ledger) {
            if ($this->customerId) {
                $customer = Customer::query()->findOrFail($this->customerId);
                $customer->update($attributes);

                return $customer;
            }

            $customer = Customer::create($attributes + ['opening_balance' => (float) ($data['opening_balance'] ?? 0)]);
            $ledger->openCustomer($customer, (float) ($data['opening_balance'] ?? 0));

            return $customer;
        });

        $this->closeModal('customer-form');
        $this->toast($this->customerId ? 'Customer updated.' : 'Customer added.');
        $this->dispatch('customer-saved', id: $customer->id, password: $this->password ?: null, portal: $this->portal ? tenant()->portalUrl() : null);
    }
}; ?>

<div>
    <x-modal name="customer-form" :title="$customerId ? 'Edit customer' : 'Add customer'" size="lg">
        <form wire:submit="save" id="customer-form-el" class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Full name <span class="text-danger">*</span></label>
                <input wire:model="name" class="form-control @error('name') is-invalid @enderror">
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label">Mobile number <span class="text-danger">*</span></label>
                <input wire:model="phone" class="form-control @error('phone') is-invalid @enderror" inputmode="tel" placeholder="03XXXXXXXXX">
                @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label">Email</label>
                <input wire:model="email" type="email" class="form-control @error('email') is-invalid @enderror">
                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label">Status</label>
                <select wire:model="status" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select>
            </div>
            <div class="col-12"><label class="form-label">Address</label><input wire:model="address" class="form-control"></div>
            <div class="col-md-6">
                <label class="form-label">Opening balance (customer owes)</label>
                <input wire:model="opening_balance" type="number" step="0.01" class="form-control" @disabled($customerId)>
                <div class="form-text">{{ $customerId ? 'Use account adjustments after creation.' : 'Negative = advance received.' }}</div>
            </div>
            <div class="col-12">
                <div class="border rounded-3 p-3">
                    <div class="form-check form-switch">
                        <input wire:model.live="portal" class="form-check-input" type="checkbox" id="portal-access">
                        <label class="form-check-label fw-semibold" for="portal-access">Customer portal access</label>
                    </div>
                    <div class="small text-muted mb-2">Lets the customer sign in at <code>{{ tenant()->portalUrl() }}</code> with their phone or email to view purchases and statements.</div>
                    @if ($portal)
                        <div class="input-group">
                            <input wire:model="password" class="form-control font-monospace @error('password') is-invalid @enderror" placeholder="{{ $hasPassword ? 'Leave blank to keep current password' : 'Portal password' }}">
                            <button type="button" class="btn btn-light border" wire:click="generatePassword"><i class="ri-refresh-line me-1"></i>Generate</button>
                            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    @endif
                </div>
            </div>
        </form>
        <x-slot:footer>
            <button type="button" class="btn btn-light" x-on:click="$dispatch('close-modal', { name: 'customer-form' })">Cancel</button>
            <button type="submit" form="customer-form-el" class="btn btn-primary"><i class="ri-save-line me-1"></i>Save customer</button>
        </x-slot:footer>
    </x-modal>
</div>

@script
<script>
    $wire.on('customer-saved', ({ password, portal }) => {
        if (password && portal) {
            Swal.fire({ icon: 'info', title: 'Portal access enabled', html: `Share with the customer:<br>Portal: <b>${portal}</b><br>Password: <code class="fs-5">${password}</code>` });
        }
    });
</script>
@endscript
