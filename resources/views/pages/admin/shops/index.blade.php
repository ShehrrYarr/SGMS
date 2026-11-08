<?php

use App\Livewire\Concerns\InteractsWithUi;
use App\Livewire\Concerns\WithSorting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Shops')] class extends Component {
    use InteractsWithUi, WithPagination, WithSorting;

    protected array $sortable = ['name', 'plan', 'status', 'created_at', 'trial_ends_at'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    // Create shop form
    public array $form = [];

    // Reset password
    public ?int $resetTenantId = null;

    public ?int $resetUserId = null;

    public string $newPassword = '';

    public function mount(): void
    {
        $this->resetForm();

        if (request()->boolean('create')) {
            $this->openModal('create-shop');
        }
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function updatedFormName(string $value): void
    {
        if (empty($this->form['slug_touched'])) {
            $this->form['slug'] = Str::slug($value);
        }
    }

    public function resetForm(): void
    {
        $this->form = [
            'name' => '', 'slug' => '', 'slug_touched' => false, 'plan' => 'basic', 'trial_days' => 14,
            'phone' => '', 'address' => '', 'owner_name' => '', 'owner_email' => '',
            'owner_password' => Str::password(10, symbols: false), 'opening_cash' => 0,
        ];
        $this->resetValidation();
    }

    public function createShop(TenantProvisioner $provisioner): void
    {
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:120'],
            'form.slug' => ['required', 'alpha_dash', 'max:80', Rule::unique('tenants', 'slug'), Rule::notIn(['admin', 'app', 'portal', 'api'])],
            'form.plan' => ['required', Rule::in(array_keys(Tenant::PLANS))],
            'form.trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'form.phone' => ['nullable', 'string', 'max:30'],
            'form.address' => ['nullable', 'string', 'max:255'],
            'form.owner_name' => ['required', 'string', 'max:120'],
            'form.owner_email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'form.owner_password' => ['required', 'string', 'min:8'],
            'form.opening_cash' => ['nullable', 'numeric', 'min:0'],
        ], [], ['form.owner_email' => 'owner email', 'form.slug' => 'shop URL'])['form'];

        $tenant = $provisioner->create($data);

        $this->closeModal('create-shop');
        $this->dispatch('shop-created', name: $tenant->name, email: $data['owner_email'], password: $data['owner_password'], portal: $tenant->portalUrl());
        $this->resetForm();
    }

    public function toggleStatus(int $tenantId): void
    {
        $tenant = Tenant::query()->findOrFail($tenantId);
        $suspend = $tenant->isActive();
        $tenant->update(['status' => $suspend ? Tenant::STATUS_SUSPENDED : Tenant::STATUS_ACTIVE]);

        if ($suspend) {
            // Kick signed-in staff immediately; customers are blocked by the portal middleware.
            DB::table('sessions')->whereIn('user_id', User::query()->forTenant($tenant->id)->pluck('id'))->delete();
        }

        $this->toast($suspend ? "{$tenant->name} suspended — all logins blocked." : "{$tenant->name} is active again.", $suspend ? 'warning' : 'success');
    }

    public function startReset(int $tenantId): void
    {
        $this->resetTenantId = $tenantId;
        $this->resetUserId = User::query()->forTenant($tenantId)->where('role', User::ROLE_OWNER)->value('id');
        $this->newPassword = Str::password(10, symbols: false);
        $this->resetValidation();
        $this->openModal('reset-password');
    }

    public function resetPassword(): void
    {
        $this->validate([
            'resetUserId' => ['required', 'integer'],
            'newPassword' => ['required', 'string', 'min:8'],
        ]);

        $user = User::query()->forTenant($this->resetTenantId)->findOrFail($this->resetUserId);
        $user->forceFill(['password' => $this->newPassword, 'remember_token' => Str::random(60)])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();

        $this->closeModal('reset-password');
        $this->dispatch('password-reset', email: $user->email, password: $this->newPassword);
    }

    public function with(): array
    {
        $shops = Tenant::query()
            ->withCount(['branches', 'users'])
            ->with('owner:id,tenant_id,name,email')
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('slug', 'like', "%{$this->search}%")
                ->orWhere('owner_email', 'like', "%{$this->search}%")))
            ->when($this->status, fn ($q) => $q->where('status', $this->status));

        return [
            'shops' => $this->applySorting($shops, 'created_at')->paginate(12),
            'resetUsers' => $this->resetTenantId
                ? User::query()->forTenant($this->resetTenantId)->orderByRaw("FIELD(role, 'owner', 'manager', 'cashier')")->get(['id', 'name', 'email', 'role'])
                : collect(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Shops" :breadcrumbs="['Platform' => route('admin.dashboard'), 'Shops' => null]">
        <x-slot:actions>
            <button type="button" class="btn btn-primary" wire:click="resetForm" x-on:click="$dispatch('open-modal', { name: 'create-shop' })">
                <i class="ri-add-line me-1"></i>Create shop
            </button>
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <div class="card-header">
            <div class="row g-2 filter-bar">
                <div class="col-md-6 col-lg-4"><x-search-input wire:model.live.debounce.300ms="search" placeholder="Search shop, URL or owner email…" /></div>
                <div class="col-6 col-md-3 col-lg-2">
                    <select wire:model.live="status" class="form-select">
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="table-responsive loading-overlay" wire:loading.attr="data-loading">
            <table class="table table-hover mb-0 table-stack">
                <thead class="table-light">
                    <tr>
                        <x-th-sort field="name" :sort="$sortField" :dir="$sortDirection">Shop</x-th-sort>
                        <th>Owner</th>
                        <x-th-sort field="plan" :sort="$sortField" :dir="$sortDirection">Plan</x-th-sort>
                        <th class="text-center">Branches</th>
                        <th class="text-center">Staff</th>
                        <x-th-sort field="trial_ends_at" :sort="$sortField" :dir="$sortDirection">Trial</x-th-sort>
                        <x-th-sort field="status" :sort="$sortField" :dir="$sortDirection">Active</x-th-sort>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($shops as $shop)
                        <tr wire:key="shop-{{ $shop->id }}">
                            <td data-label="Shop">
                                <a href="{{ route('admin.shops.show', $shop) }}" class="fw-semibold" wire:navigate>{{ $shop->name }}</a>
                                <div class="small text-muted">/portal/{{ $shop->slug }}</div>
                            </td>
                            <td data-label="Owner">
                                <div>{{ $shop->owner?->name ?? '—' }}</div>
                                <div class="small text-muted">{{ $shop->owner?->email ?? $shop->owner_email }}</div>
                            </td>
                            <td data-label="Plan"><span class="badge bg-light text-body border">{{ \App\Models\Tenant::PLANS[$shop->plan] ?? ucfirst($shop->plan) }}</span></td>
                            <td data-label="Branches" class="text-md-center">{{ $shop->branches_count }}</td>
                            <td data-label="Staff" class="text-md-center">{{ $shop->users_count }}</td>
                            <td data-label="Trial">
                                @if ($shop->onTrial())
                                    <span class="badge bg-info-subtle text-info">{{ (int) now()->diffInDays($shop->trial_ends_at) }} days left</span>
                                @else
                                    <span class="text-muted small">—</span>
                                @endif
                            </td>
                            <td data-label="Active">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch" @checked($shop->isActive())
                                           x-on:click.prevent="SGMS.confirm({ title: @js($shop->isActive() ? "Suspend {$shop->name}?" : "Activate {$shop->name}?"), text: @js($shop->isActive() ? 'All staff and customers of this shop will be blocked from signing in.' : 'Staff and customers will be able to sign in again.'), confirmText: @js($shop->isActive() ? 'Suspend' : 'Activate'), danger: @js($shop->isActive()) }).then(ok => ok && $wire.toggleStatus({{ $shop->id }}))">
                                </div>
                            </td>
                            <td class="text-end td-actions" data-label="">
                                <div class="d-inline-flex gap-1">
                                    <button type="button" class="btn btn-sm btn-light-warning" wire:click="startReset({{ $shop->id }})" title="Reset password"><i class="ri-key-2-line"></i></button>
                                    <a href="{{ route('admin.shops.show', $shop) }}" class="btn btn-sm btn-light-primary" wire:navigate title="Open"><i class="ri-eye-line"></i></a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><x-empty-state icon="ri-store-3-line" title="No shops found" text="Try a different search or create a new shop." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($shops->hasPages())
            <div class="card-footer">{{ $shops->links() }}</div>
        @endif
    </div>

    {{-- Create shop --}}
    <x-modal name="create-shop" title="Create a new shop" size="lg">
        <form wire:submit="createShop" id="create-shop-form" class="row g-3">
            <div class="col-12"><h6 class="text-uppercase text-muted fs-12 mb-0">Shop</h6></div>
            <div class="col-md-6">
                <label class="form-label">Shop name <span class="text-danger">*</span></label>
                <input wire:model.live.debounce.400ms="form.name" class="form-control @error('form.name') is-invalid @enderror" placeholder="e.g. Urban Garments">
                @error('form.name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label">Shop URL <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text small">/portal/</span>
                    <input wire:model="form.slug" x-on:input="$wire.set('form.slug_touched', true, false)" class="form-control @error('form.slug') is-invalid @enderror">
                    @error('form.slug')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Plan</label>
                <select wire:model="form.plan" class="form-select">
                    @foreach (\App\Models\Tenant::PLANS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Trial days</label>
                <input wire:model="form.trial_days" type="number" min="0" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">Opening cash in drawer</label>
                <input wire:model="form.opening_cash" type="number" min="0" step="0.01" class="form-control">
            </div>
            <div class="col-md-6">
                <label class="form-label">Phone</label>
                <input wire:model="form.phone" class="form-control">
            </div>
            <div class="col-md-6">
                <label class="form-label">Address</label>
                <input wire:model="form.address" class="form-control">
            </div>

            <div class="col-12 mt-4"><h6 class="text-uppercase text-muted fs-12 mb-0">Owner login</h6></div>
            <div class="col-md-6">
                <label class="form-label">Owner name <span class="text-danger">*</span></label>
                <input wire:model="form.owner_name" class="form-control @error('form.owner_name') is-invalid @enderror">
                @error('form.owner_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label">Owner email <span class="text-danger">*</span></label>
                <input wire:model="form.owner_email" type="email" class="form-control @error('form.owner_email') is-invalid @enderror">
                @error('form.owner_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label">Temporary password <span class="text-danger">*</span></label>
                <div class="input-group">
                    <input wire:model="form.owner_password" class="form-control font-monospace @error('form.owner_password') is-invalid @enderror">
                    <button type="button" class="btn btn-light" wire:click="$set('form.owner_password', '{{ \Illuminate\Support\Str::password(10, symbols: false) }}')" title="Generate"><i class="ri-refresh-line"></i></button>
                    @error('form.owner_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="col-12">
                <div class="alert alert-info small mb-0"><i class="ri-information-line me-1"></i>Creates the shop, its <strong>Main Store</strong> branch with a default cash drawer, the owner login and starter sizes, colours and expense categories.</div>
            </div>
        </form>
        <x-slot:footer>
            <button type="button" class="btn btn-light" x-on:click="$dispatch('close-modal', { name: 'create-shop' })">Cancel</button>
            <button type="submit" form="create-shop-form" class="btn btn-primary" wire:loading.attr="disabled" wire:target="createShop">
                <span wire:loading.remove wire:target="createShop">Create shop</span>
                <span wire:loading wire:target="createShop"><span class="spinner-border spinner-border-sm me-1"></span>Creating…</span>
            </button>
        </x-slot:footer>
    </x-modal>

    {{-- Reset password --}}
    <x-modal name="reset-password" title="Reset shop user password">
        <form wire:submit="resetPassword" id="reset-form" class="vstack gap-3">
            <div>
                <label class="form-label">User</label>
                <select wire:model="resetUserId" class="form-select">
                    @foreach ($resetUsers as $u)
                        <option value="{{ $u->id }}">{{ $u->name }} — {{ ucfirst($u->role) }} ({{ $u->email }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="form-label">New password</label>
                <input wire:model="newPassword" class="form-control font-monospace @error('newPassword') is-invalid @enderror">
                @error('newPassword')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">The user is signed out of every device immediately.</div>
            </div>
        </form>
        <x-slot:footer>
            <button type="button" class="btn btn-light" x-on:click="$dispatch('close-modal', { name: 'reset-password' })">Cancel</button>
            <button type="submit" form="reset-form" class="btn btn-warning"><i class="ri-key-2-line me-1"></i>Reset password</button>
        </x-slot:footer>
    </x-modal>
</div>

@script
<script>
    $wire.on('shop-created', ({ name, email, password, portal }) => {
        Swal.fire({
            icon: 'success', title: name + ' is ready',
            html: `<div class="text-start small"><p class="mb-2">Share these sign-in details with the owner:</p>
                   <div class="p-2 bg-light rounded border"><div>Staff login: <b>${location.origin}/app/login</b></div><div>Email: <b>${email}</b></div><div>Password: <code>${password}</code></div><div class="mt-1">Customer portal: <b>${portal}</b></div></div></div>`,
            confirmButtonText: 'Done',
        });
    });
    $wire.on('password-reset', ({ email, password }) => {
        Swal.fire({ icon: 'success', title: 'Password reset', html: `New password for <b>${email}</b>:<br><code class="fs-5">${password}</code>` });
    });
</script>
@endscript
