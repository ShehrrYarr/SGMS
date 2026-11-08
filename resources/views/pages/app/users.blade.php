<?php

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Staff Users')] class extends Component {
    use InteractsWithUi;

    public ?int $editId = null;

    public array $form = [];

    public function mount(): void
    {
        $this->resetForm();
    }

    public function resetForm(): void
    {
        $this->editId = null;
        $this->form = ['name' => '', 'email' => '', 'phone' => '', 'role' => 'cashier', 'branch_id' => tenancy()->operationalBranchId(), 'status' => 'active', 'password' => Str::password(10, symbols: false)];
        $this->resetValidation();
    }

    public function edit(int $id): void
    {
        $user = User::query()->findOrFail($id);
        $this->editId = $user->id;
        $this->form = $user->only('name', 'email', 'phone', 'role', 'branch_id', 'status') + ['password' => ''];
        $this->resetValidation();
        $this->openModal('user-form');
    }

    public function save(): void
    {
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:120'],
            'form.email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($this->editId)],
            'form.phone' => ['nullable', 'string', 'max:30'],
            'form.role' => ['required', Rule::in(array_keys(User::ROLES))],
            'form.branch_id' => [$this->form['role'] === 'owner' ? 'nullable' : 'required', Rule::in(tenancy()->branches()->pluck('id')->all())],
            'form.status' => ['required', 'in:active,inactive'],
            'form.password' => [$this->editId ? 'nullable' : 'required', 'string', 'min:8'],
        ], ['form.branch_id.required' => 'Managers and cashiers must belong to a branch.'])['form'];

        if ($this->editId === auth('web')->id() && ($data['role'] !== 'owner' || $data['status'] !== 'active')) {
            $this->addError('form.role', 'You cannot demote or deactivate your own account.');

            return;
        }

        $attributes = collect($data)->except('password')->all();

        if (! empty($data['password'])) {
            $attributes['password'] = $data['password'];
        }

        $user = $this->editId ? tap(User::query()->findOrFail($this->editId))->update($attributes) : User::create($attributes);

        if ($this->editId && (! empty($data['password']) || $data['status'] !== 'active')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        $this->closeModal('user-form');
        $this->dispatch('user-saved', email: $user->email, password: $data['password'] ?: null);
        $this->resetForm();
    }

    public function with(): array
    {
        return [
            'users' => User::query()->with('branch:id,name')->orderByRaw("FIELD(role, 'owner', 'manager', 'cashier')")->orderBy('name')->get(),
            'branches' => tenancy()->branches(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Staff users" subtitle="Owners see every branch. Managers and cashiers work in their assigned branch.">
        <x-slot:actions>
            <button class="btn btn-primary" wire:click="resetForm" x-on:click="$dispatch('open-modal', { name: 'user-form' })"><i class="ri-user-add-line me-1"></i>Add user</button>
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 table-stack">
                <thead class="table-light"><tr><th>User</th><th>Role</th><th>Branch</th><th>Last login</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @foreach ($users as $user)
                        <tr wire:key="u-{{ $user->id }}">
                            <td data-label="User">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="avatar avatar-sm avatar-item avatar-title bg-primary-subtle text-primary rounded-circle fw-semibold">{{ $user->initials }}</span>
                                    <div><div class="fw-semibold">{{ $user->name }} @if ($user->id === auth('web')->id())<span class="badge bg-light text-muted">You</span>@endif</div><small class="text-muted">{{ $user->email }}</small></div>
                                </div>
                            </td>
                            <td data-label="Role"><x-status-badge :status="$user->role" /></td>
                            <td data-label="Branch">{{ $user->isOwner() ? 'All branches' : ($user->branch?->name ?? '—') }}</td>
                            <td data-label="Last login" class="small">{{ $user->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                            <td data-label="Status"><x-status-badge :status="$user->status" /></td>
                            <td class="text-end td-actions" data-label=""><button class="btn btn-sm btn-light-primary" wire:click="edit({{ $user->id }})"><i class="ri-pencil-line"></i></button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="row g-3">
        @foreach ([['owner', 'ri-vip-crown-line', 'Everything in every branch: settings, staff, finance, reports.'], ['manager', 'ri-user-star-line', 'Purchasing, inventory, finance and sales — for their own branch.'], ['cashier', 'ri-user-line', 'POS, their own sales, returns and customer lookup.']] as [$role, $icon, $text])
            <div class="col-md-4"><div class="card mb-0 h-100"><div class="card-body d-flex gap-3"><span class="stat-icon bg-light text-body"><i class="{{ $icon }}"></i></span><div><div class="fw-semibold">{{ \App\Models\User::ROLES[$role] }}</div><small class="text-muted">{{ $text }}</small></div></div></div></div>
        @endforeach
    </div>

    <x-modal name="user-form" :title="$editId ? 'Edit user' : 'Add user'">
        <form wire:submit="save" id="user-form-el" class="row g-3">
            <div class="col-12"><label class="form-label">Name</label><input wire:model="form.name" class="form-control @error('form.name') is-invalid @enderror"></div>
            <div class="col-md-7"><label class="form-label">Email (login)</label><input wire:model="form.email" type="email" class="form-control @error('form.email') is-invalid @enderror">@error('form.email')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-md-5"><label class="form-label">Phone</label><input wire:model="form.phone" class="form-control"></div>
            <div class="col-6">
                <label class="form-label">Role</label>
                <select wire:model.live="form.role" class="form-select @error('form.role') is-invalid @enderror">@foreach (\App\Models\User::ROLES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                @error('form.role')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-6">
                <label class="form-label">Branch</label>
                <select wire:model="form.branch_id" class="form-select @error('form.branch_id') is-invalid @enderror"><option value="">{{ $form['role'] === 'owner' ? 'All branches' : 'Select…' }}</option>@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select>
                @error('form.branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-6"><label class="form-label">Status</label><select wire:model="form.status" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
            <div class="col-6">
                <label class="form-label">{{ $editId ? 'New password' : 'Password' }}</label>
                <input wire:model="form.password" class="form-control font-monospace @error('form.password') is-invalid @enderror" placeholder="{{ $editId ? 'Leave blank to keep' : '' }}">
                @error('form.password')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </form>
        <x-slot:footer>
            <button type="button" class="btn btn-light" x-on:click="$dispatch('close-modal', { name: 'user-form' })">Cancel</button>
            <button type="submit" form="user-form-el" class="btn btn-primary">Save user</button>
        </x-slot:footer>
    </x-modal>
</div>

@script
<script>
    $wire.on('user-saved', ({ email, password }) => {
        if (password) Swal.fire({ icon: 'success', title: 'User saved', html: `Login: <b>${email}</b><br>Password: <code class="fs-5">${password}</code>` });
        else SGMS.toast('success', 'User updated');
    });
</script>
@endscript
