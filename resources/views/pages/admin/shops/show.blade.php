<?php

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('Shop details')] class extends Component {
    use InteractsWithUi;

    #[Locked]
    public int $tenantId;

    public array $form = [];

    public function mount(Tenant $tenant): void
    {
        $this->tenantId = $tenant->id;
        $this->fillForm($tenant);
    }

    protected function fillForm(Tenant $tenant): void
    {
        $this->form = [
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'plan' => $tenant->plan,
            'trial_ends_at' => $tenant->trial_ends_at?->toDateString(),
            'phone' => $tenant->phone,
            'address' => $tenant->address,
            'owner_email' => $tenant->owner_email,
        ];
    }

    public function save(): void
    {
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:120'],
            'form.slug' => ['required', 'alpha_dash', 'max:80', Rule::unique('tenants', 'slug')->ignore($this->tenantId), Rule::notIn(['admin', 'app', 'portal', 'api'])],
            'form.plan' => ['required', Rule::in(array_keys(Tenant::PLANS))],
            'form.trial_ends_at' => ['nullable', 'date'],
            'form.phone' => ['nullable', 'string', 'max:30'],
            'form.address' => ['nullable', 'string', 'max:255'],
            'form.owner_email' => ['required', 'email'],
        ])['form'];

        Tenant::query()->findOrFail($this->tenantId)->update($data);
        $this->toast('Shop details updated.');
    }

    public function toggleStatus(): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $suspend = $tenant->isActive();
        $tenant->update(['status' => $suspend ? Tenant::STATUS_SUSPENDED : Tenant::STATUS_ACTIVE]);

        if ($suspend) {
            DB::table('sessions')->whereIn('user_id', User::query()->forTenant($tenant->id)->pluck('id'))->delete();
        }

        $this->toast($suspend ? 'Shop suspended.' : 'Shop activated.', $suspend ? 'warning' : 'success');
    }

    public function toggleUser(int $userId): void
    {
        $user = User::query()->forTenant($this->tenantId)->findOrFail($userId);
        $user->update(['status' => $user->isActive() ? 'inactive' : 'active']);

        if (! $user->isActive()) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        $this->toast("{$user->name} is now {$user->status}.");
    }

    public function resetUserPassword(int $userId): void
    {
        $user = User::query()->forTenant($this->tenantId)->findOrFail($userId);
        $password = Str::password(10, symbols: false);
        $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();

        $this->dispatch('password-reset', email: $user->email, password: $password);
    }

    public function with(): array
    {
        $id = $this->tenantId;
        $tenant = Tenant::query()->findOrFail($id);

        return [
            'tenant' => $tenant,
            'stats' => [
                'branches' => Branch::query()->forTenant($id)->count(),
                'products' => Product::query()->forTenant($id)->count(),
                'customers' => Customer::query()->forTenant($id)->count(),
                'stock' => (int) Inventory::query()->forTenant($id)->sum('quantity'),
                'sales' => (float) Sale::query()->forTenant($id)->sum('total_amount'),
                'salesCount' => Sale::query()->forTenant($id)->count(),
                'purchases' => (float) Purchase::query()->forTenant($id)->sum('total_lot_cost'),
                'monthSales' => (float) Sale::query()->forTenant($id)->where('sale_date', '>=', now()->startOfMonth())->sum('total_amount'),
            ],
            'users' => User::query()->forTenant($id)->with('branch:id,name')->orderByRaw("FIELD(role, 'owner', 'manager', 'cashier')")->get(),
            'branches' => Branch::query()->forTenant($id)->orderByDesc('is_main')->get(),
        ];
    }
}; ?>

<div>
    <x-page-header :title="$tenant->name" :breadcrumbs="['Platform' => route('admin.dashboard'), 'Shops' => route('admin.shops.index'), $tenant->name => null]">
        <x-slot:actions>
            <x-status-badge :status="$tenant->status" class="fs-12 py-2 px-3" />
            <button type="button" class="btn {{ $tenant->isActive() ? 'btn-light-danger' : 'btn-success' }}"
                    x-on:click="SGMS.confirm({ title: @js($tenant->isActive() ? 'Suspend this shop?' : 'Activate this shop?'), text: @js($tenant->isActive() ? 'All staff and customers will be blocked immediately.' : ''), danger: @js($tenant->isActive()) }).then(ok => ok && $wire.toggleStatus())">
                <i class="{{ $tenant->isActive() ? 'ri-forbid-2-line' : 'ri-checkbox-circle-line' }} me-1"></i>{{ $tenant->isActive() ? 'Suspend shop' : 'Activate shop' }}
            </button>
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3"><x-stat-card label="Lifetime sales" :value="'Rs. '.number_format($stats['sales'])" icon="ri-line-chart-line" color="primary" :hint="number_format($stats['salesCount']).' invoices'" /></div>
        <div class="col-6 col-xl-3"><x-stat-card label="This month" :value="'Rs. '.number_format($stats['monthSales'])" icon="ri-calendar-check-line" color="success" /></div>
        <div class="col-6 col-xl-3"><x-stat-card label="Lot purchases" :value="'Rs. '.number_format($stats['purchases'])" icon="ri-inbox-archive-line" color="warning" /></div>
        <div class="col-6 col-xl-3"><x-stat-card label="Stock on hand" :value="number_format($stats['stock']).' pcs'" icon="ri-stack-line" color="info" :hint="$stats['products'].' products · '.$stats['customers'].' customers'" /></div>
    </div>

    <div class="row g-3">
        <div class="col-xl-5">
            <div class="card mb-0 h-100">
                <div class="card-header"><h6 class="card-title mb-0">Shop profile</h6></div>
                <div class="card-body">
                    <form wire:submit="save" class="row g-3">
                        <div class="col-md-6"><label class="form-label">Name</label><input wire:model="form.name" class="form-control @error('form.name') is-invalid @enderror"></div>
                        <div class="col-md-6"><label class="form-label">URL slug</label><input wire:model="form.slug" class="form-control @error('form.slug') is-invalid @enderror">@error('form.slug')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-md-6">
                            <label class="form-label">Plan</label>
                            <select wire:model="form.plan" class="form-select">@foreach (\App\Models\Tenant::PLANS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                        </div>
                        <div class="col-md-6"><label class="form-label">Trial ends</label><input wire:model="form.trial_ends_at" type="date" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label">Owner email</label><input wire:model="form.owner_email" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label">Phone</label><input wire:model="form.phone" class="form-control"></div>
                        <div class="col-12"><label class="form-label">Address</label><input wire:model="form.address" class="form-control"></div>
                        <div class="col-12 d-flex justify-content-between align-items-center">
                            <a href="{{ $tenant->portalUrl() }}" target="_blank" class="small"><i class="ri-external-link-line"></i> Customer portal</a>
                            <button class="btn btn-primary" type="submit"><i class="ri-save-line me-1"></i>Save</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-xl-7">
            <div class="card mb-3">
                <div class="card-header"><h6 class="card-title mb-0">Staff users</h6></div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 table-stack">
                        <thead class="table-light"><tr><th>User</th><th>Role</th><th>Branch</th><th>Last login</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                            @foreach ($users as $user)
                                <tr wire:key="u-{{ $user->id }}">
                                    <td data-label="User"><div class="fw-semibold">{{ $user->name }}</div><div class="small text-muted">{{ $user->email }}</div></td>
                                    <td data-label="Role"><x-status-badge :status="$user->role" /></td>
                                    <td data-label="Branch">{{ $user->branch?->name ?? '—' }}</td>
                                    <td data-label="Last login" class="small">{{ $user->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                                    <td data-label="Status"><x-status-badge :status="$user->status" /></td>
                                    <td class="text-end td-actions" data-label="">
                                        <button class="btn btn-sm btn-light-warning" title="Reset password"
                                                x-on:click="SGMS.confirm({ title: 'Reset password for {{ $user->name }}?', text: 'A new password is generated and the user is signed out everywhere.', danger: false }).then(ok => ok && $wire.resetUserPassword({{ $user->id }}))"><i class="ri-key-2-line"></i></button>
                                        @unless ($user->isOwner())
                                            <button class="btn btn-sm btn-light-secondary" wire:click="toggleUser({{ $user->id }})" title="Enable / disable"><i class="ri-user-forbid-line"></i></button>
                                        @endunless
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0">Branches</h6></div>
                <ul class="list-group list-group-flush">
                    @foreach ($branches as $branch)
                        <li class="list-group-item d-flex align-items-center gap-3">
                            <span class="stat-icon bg-primary-subtle text-primary"><i class="ri-store-2-line"></i></span>
                            <div class="flex-grow-1">
                                <div class="fw-semibold">{{ $branch->name }} @if ($branch->is_main)<span class="badge bg-primary-subtle text-primary ms-1">Main</span>@endif</div>
                                <div class="small text-muted">{{ $branch->code }} · {{ $branch->address ?? 'No address' }}</div>
                            </div>
                            <x-status-badge :status="$branch->status" />
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</div>

@script
<script>
    $wire.on('password-reset', ({ email, password }) => {
        Swal.fire({ icon: 'success', title: 'Password reset', html: `New password for <b>${email}</b>:<br><code class="fs-5">${password}</code>` });
    });
</script>
@endscript
