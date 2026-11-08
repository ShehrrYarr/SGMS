<?php

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Branch;
use App\Models\Inventory;
use App\Models\Sale;
use App\Services\TenantProvisioner;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Branches')] class extends Component {
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
        $this->form = ['name' => '', 'code' => '', 'phone' => '', 'address' => '', 'default_cash_drawer_balance' => 0, 'status' => 'active'];
        $this->resetValidation();
    }

    public function edit(int $id): void
    {
        $branch = Branch::query()->findOrFail($id);
        $this->editId = $branch->id;
        $this->form = $branch->only('name', 'code', 'phone', 'address', 'status') + ['default_cash_drawer_balance' => (float) $branch->default_cash_drawer_balance];
        $this->openModal('branch-form');
    }

    public function save(TenantProvisioner $provisioner): void
    {
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:120'],
            'form.code' => ['required', 'alpha_dash', 'max:20', Rule::unique('branches', 'code')->where('tenant_id', tenant_id())->ignore($this->editId)],
            'form.phone' => ['nullable', 'string', 'max:30'],
            'form.address' => ['nullable', 'string', 'max:255'],
            'form.default_cash_drawer_balance' => ['nullable', 'numeric', 'min:0'],
            'form.status' => ['required', 'in:active,inactive'],
        ], [], ['form.code' => 'branch code'])['form'];

        if ($this->editId) {
            $branch = Branch::query()->findOrFail($this->editId);

            if ($branch->is_main && $data['status'] !== 'active') {
                $this->addError('form.status', 'The main branch cannot be deactivated.');

                return;
            }

            $branch->update(collect($data)->except('default_cash_drawer_balance')->all() + ['code' => strtoupper($data['code'])]);
        } else {
            $provisioner->createBranch($data);
        }

        $this->closeModal('branch-form');
        $this->toast('Branch saved.');
        $this->resetForm();
    }

    public function with(): array
    {
        return [
            'branches' => Branch::query()->withCount('users')->with('cashDrawer')
                ->addSelect(['stock' => Inventory::query()->selectRaw('COALESCE(SUM(quantity),0)')->whereColumn('branch_id', 'branches.id')])
                ->addSelect(['month_sales' => Sale::query()->selectRaw('COALESCE(SUM(total_amount),0)')->whereColumn('branch_id', 'branches.id')->where('sale_date', '>=', now()->startOfMonth())])
                ->orderByDesc('is_main')->orderBy('name')->get(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Branches" subtitle="Each branch has its own stock, cash drawer and staff.">
        <x-slot:actions>
            <button class="btn btn-primary" wire:click="resetForm" x-on:click="$dispatch('open-modal', { name: 'branch-form' })"><i class="ri-add-line me-1"></i>Add branch</button>
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3">
        @foreach ($branches as $branch)
            <div class="col-md-6 col-xl-4" wire:key="b-{{ $branch->id }}">
                <div class="card h-100 mb-0">
                    <div class="card-body">
                        <div class="d-flex gap-3 align-items-start">
                            <span class="stat-icon bg-primary-subtle text-primary"><i class="ri-store-2-line"></i></span>
                            <div class="flex-grow-1 min-w-0">
                                <h6 class="mb-0 fw-semibold">{{ $branch->name }}</h6>
                                <small class="text-muted">{{ $branch->code }} · {{ $branch->phone ?? 'No phone' }}</small>
                                <div class="small text-muted text-truncate">{{ $branch->address }}</div>
                            </div>
                            @if ($branch->is_main)<span class="badge bg-primary-subtle text-primary">Main</span>@endif
                            <x-status-badge :status="$branch->status" />
                        </div>
                        <div class="row g-2 mt-3 text-center small">
                            <div class="col-4"><div class="border rounded-3 p-2"><div class="text-muted">Stock</div><b>{{ number_format($branch->stock) }}</b></div></div>
                            <div class="col-4"><div class="border rounded-3 p-2"><div class="text-muted">Staff</div><b>{{ $branch->users_count }}</b></div></div>
                            <div class="col-4"><div class="border rounded-3 p-2"><div class="text-muted">Cash</div><b class="text-truncate d-block">{{ money($branch->cashDrawer?->current_balance, false, 0) }}</b></div></div>
                        </div>
                        <div class="small text-muted mt-2">Sales this month: <b class="text-body">{{ money($branch->month_sales) }}</b></div>
                    </div>
                    <div class="card-footer"><button class="btn btn-sm btn-light-primary w-100" wire:click="edit({{ $branch->id }})"><i class="ri-pencil-line me-1"></i>Edit</button></div>
                </div>
            </div>
        @endforeach
    </div>

    <x-modal name="branch-form" :title="$editId ? 'Edit branch' : 'Add branch'">
        <form wire:submit="save" id="branch-form-el" class="row g-3">
            <div class="col-8"><label class="form-label">Branch name</label><input wire:model="form.name" class="form-control @error('form.name') is-invalid @enderror" placeholder="e.g. Packages Mall"></div>
            <div class="col-4"><label class="form-label">Code</label><input wire:model="form.code" class="form-control text-uppercase @error('form.code') is-invalid @enderror" placeholder="PKM">@error('form.code')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-6"><label class="form-label">Phone</label><input wire:model="form.phone" class="form-control"></div>
            <div class="col-6"><label class="form-label">Status</label><select wire:model="form.status" class="form-select @error('form.status') is-invalid @enderror"><option value="active">Active</option><option value="inactive">Inactive</option></select>@error('form.status')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-12"><label class="form-label">Address</label><input wire:model="form.address" class="form-control"></div>
            @unless ($editId)
                <div class="col-12">
                    <label class="form-label">Opening cash in drawer</label>
                    <input wire:model="form.default_cash_drawer_balance" type="number" step="0.01" min="0" class="form-control">
                    <div class="form-text">A cash drawer account is created automatically for the branch.</div>
                </div>
            @endunless
        </form>
        <x-slot:footer>
            <button type="button" class="btn btn-light" x-on:click="$dispatch('close-modal', { name: 'branch-form' })">Cancel</button>
            <button type="submit" form="branch-form-el" class="btn btn-primary">Save branch</button>
        </x-slot:footer>
    </x-modal>
</div>
