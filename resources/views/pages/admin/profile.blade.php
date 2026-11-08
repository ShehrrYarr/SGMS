<?php

use App\Livewire\Concerns\InteractsWithUi;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('My Profile')] class extends Component {
    use InteractsWithUi;

    public string $name = '';

    public string $email = '';

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $admin = auth('superadmin')->user();
        $this->name = $admin->name;
        $this->email = $admin->email;
    }

    public function saveProfile(): void
    {
        $admin = auth('superadmin')->user();
        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', Rule::unique('super_admins', 'email')->ignore($admin->id)],
        ]);
        $admin->update($data);
        $this->toast('Profile updated.');
    }

    public function savePassword(): void
    {
        $admin = auth('superadmin')->user();
        $this->validate([
            'current_password' => ['required', fn ($a, $v, $fail) => Hash::check($v, $admin->password) ?: $fail('Current password is incorrect.')],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);
        $admin->update(['password' => $this->password]);
        $this->reset('current_password', 'password', 'password_confirmation');
        $this->toast('Password changed.');
    }
}; ?>

<div>
    <x-page-header title="My profile" :breadcrumbs="['Platform' => route('admin.dashboard'), 'Profile' => null]" />
    @include('partials.profile-forms')
</div>
