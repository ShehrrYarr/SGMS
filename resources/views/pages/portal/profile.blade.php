<?php

use App\Livewire\Concerns\InteractsWithUi;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.portal')] #[Title('Profile')] class extends Component {
    use InteractsWithUi;

    public string $name = '';

    public string $email = '';

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $customer = auth('customer')->user();
        $this->name = $customer->name;
        $this->email = (string) $customer->email;
    }

    public function saveProfile(): void
    {
        $customer = auth('customer')->user();
        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', Rule::unique('customers', 'email')->where('tenant_id', tenant_id())->ignore($customer->id)],
        ]);
        $customer->update(['name' => $data['name'], 'email' => $data['email'] ?: null]);
        $this->toast('Profile updated.');
    }

    public function savePassword(): void
    {
        $customer = auth('customer')->user();
        $this->validate([
            'current_password' => ['required', fn ($a, $v, $fail) => Hash::check($v, $customer->password) ?: $fail('Current password is incorrect.')],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);
        $customer->update(['password' => $this->password]);
        $this->reset('current_password', 'password', 'password_confirmation');
        $this->toast('Password changed.');
    }
}; ?>

<div>
    <x-page-header title="Profile & password" :subtitle="'Mobile: '.auth('customer')->user()->phone.' (contact the shop to change it)'" />
    @include('partials.profile-forms')
</div>
