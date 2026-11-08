<?php

use App\Livewire\Concerns\WithSorting;
use App\Models\Vendor;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Vendors')] class extends Component {
    use WithPagination, WithSorting;

    protected array $sortable = ['name', 'company', 'current_balance', 'created_at'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $balance = '';

    public function updating($name): void
    {
        if (in_array($name, ['search', 'balance'], true)) {
            $this->resetPage();
        }
    }

    #[On('vendor-saved')]
    public function refreshList(): void
    {
    }

    public function with(): array
    {
        $vendors = Vendor::query()
            ->search($this->search)
            ->when($this->balance === 'due', fn ($q) => $q->where('current_balance', '>', 0))
            ->when($this->balance === 'clear', fn ($q) => $q->where('current_balance', '<=', 0))
            ->withCount('purchases')
            ->withSum('purchases as purchased', 'total_lot_cost');

        return [
            'vendors' => $this->applySorting($vendors, 'name', 'asc')->paginate(15),
            'payable' => (float) Vendor::query()->where('current_balance', '>', 0)->sum('current_balance'),
        ];
    }
}; ?>

<div>
    <x-page-header title="Vendors" :breadcrumbs="['Dashboard' => route('app.dashboard'), 'Vendors' => null]">
        <x-slot:actions>
            <span class="badge bg-danger-subtle text-danger fs-12 py-2 px-3">Total payable {{ money($payable) }}</span>
            <button type="button" class="btn btn-primary" x-on:click="$dispatch('vendor-form', { id: null })"><i class="ri-add-line me-1"></i>Add vendor</button>
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <div class="card-header">
            <div class="row g-2 filter-bar">
                <div class="col-md-6 col-lg-4"><x-search-input wire:model.live.debounce.300ms="search" placeholder="Name, company or phone…" /></div>
                <div class="col-6 col-md-3 col-lg-2">
                    <select wire:model.live="balance" class="form-select"><option value="">All vendors</option><option value="due">We owe them</option><option value="clear">Settled</option></select>
                </div>
            </div>
        </div>
        <div class="table-responsive loading-overlay" wire:loading.attr="data-loading">
            <table class="table table-hover mb-0 table-stack">
                <thead class="table-light">
                    <tr>
                        <x-th-sort field="name" :sort="$sortField" :dir="$sortDirection">Vendor</x-th-sort>
                        <x-th-sort field="company" :sort="$sortField" :dir="$sortDirection">Company</x-th-sort>
                        <th>Phone</th>
                        <th class="text-center">Lots</th>
                        <th class="text-end">Purchased</th>
                        <x-th-sort field="current_balance" :sort="$sortField" :dir="$sortDirection" class="text-end">Payable</x-th-sort>
                        <th>Status</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($vendors as $vendor)
                        <tr wire:key="v-{{ $vendor->id }}">
                            <td data-label="Vendor"><a href="{{ route('app.vendors.show', $vendor) }}" class="fw-semibold" wire:navigate>{{ $vendor->name }}</a></td>
                            <td data-label="Company">{{ $vendor->company ?? '—' }}</td>
                            <td data-label="Phone">{{ $vendor->phone ?? '—' }}</td>
                            <td data-label="Lots" class="text-md-center">{{ $vendor->purchases_count }}</td>
                            <td data-label="Purchased" class="text-end tabular-nums">{{ money($vendor->purchased) }}</td>
                            <td data-label="Payable" class="text-end fw-semibold tabular-nums {{ (float) $vendor->current_balance > 0 ? 'text-danger' : 'text-success' }}">{{ money($vendor->current_balance) }}</td>
                            <td data-label="Status"><x-status-badge :status="$vendor->status" /></td>
                            <td class="text-end td-actions text-nowrap" data-label="">
                                <button class="btn btn-sm btn-light-secondary" x-on:click="$dispatch('vendor-form', { id: {{ $vendor->id }} })" title="Edit"><i class="ri-pencil-line"></i></button>
                                <a href="{{ route('app.purchases.create', ['vendor' => $vendor->id]) }}" class="btn btn-sm btn-light-info" wire:navigate title="New lot"><i class="ri-inbox-archive-line"></i></a>
                                <a href="{{ route('app.vendors.show', $vendor) }}" class="btn btn-sm btn-light-primary" wire:navigate title="Ledger"><i class="ri-book-open-line"></i></a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><x-empty-state icon="ri-truck-line" title="No vendors yet" text="Add the mills and wholesalers you buy lots from." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($vendors->hasPages())<div class="card-footer">{{ $vendors->links() }}</div>@endif
    </div>

    <livewire:vendors.quick-create />
</div>
