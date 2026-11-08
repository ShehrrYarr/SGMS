<?php

use App\Livewire\Concerns\InteractsWithUi;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('My Profile')] class extends Component {
    use InteractsWithUi;

    public string $name = '';

    public string $email = '';

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $user = auth('web')->user();
        $this->name = $user->name;
        $this->email = $user->email;
    }

    public function saveProfile(): void
    {
        $user = auth('web')->user();
        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
        ]);
        $user->update($data);
        $this->toast('Profile updated.');
    }

    public function savePassword(): void
    {
        $user = auth('web')->user();
        $this->validate([
            'current_password' => ['required', fn ($a, $v, $fail) => Hash::check($v, $user->password) ?: $fail('Current password is incorrect.')],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);
        $user->update(['password' => $this->password]);
        $this->reset('current_password', 'password', 'password_confirmation');
        $this->toast('Password changed.');
    }
}; ?>

<div>
    <x-page-header title="My profile" :subtitle="auth('web')->user()->role_label.' at '.tenant()->name" />
    @include('partials.profile-forms')
</div>
