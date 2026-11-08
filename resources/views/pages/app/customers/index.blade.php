<?php

use App\Livewire\Concerns\WithSorting;
use App\Models\Customer;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Customers')] class extends Component {
    use WithPagination, WithSorting;

    protected array $sortable = ['name', 'current_balance', 'created_at', 'last_login_at'];

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

    #[On('customer-saved')]
    public function refreshList(): void
    {
    }

    public function with(): array
    {
        $customers = Customer::query()
            ->search($this->search)
            ->when($this->balance === 'due', fn ($q) => $q->where('current_balance', '>', 0))
            ->when($this->balance === 'advance', fn ($q) => $q->where('current_balance', '<', 0))
            ->withCount('sales')
            ->withSum('sales as spent', 'total_amount');

        return [
            'customers' => $this->applySorting($customers, 'name', 'asc')->paginate(15),
            'receivable' => (float) Customer::query()->where('current_balance', '>', 0)->sum('current_balance'),
            'total' => Customer::query()->count(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Customers" :breadcrumbs="['Dashboard' => route('app.dashboard'), 'Customers' => null]">
        <x-slot:actions>
            <span class="badge bg-danger-subtle text-danger fs-12 py-2 px-3">Receivable {{ money($receivable) }}</span>
            <button class="btn btn-primary" x-on:click="$dispatch('customer-form', { id: null })"><i class="ri-user-add-line me-1"></i>Add customer</button>
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <div class="card-header">
            <div class="row g-2 filter-bar align-items-center">
                <div class="col-md-6 col-lg-4"><x-search-input wire:model.live.debounce.300ms="search" placeholder="Name, phone or email…" /></div>
                <div class="col-6 col-md-3 col-lg-2">
                    <select wire:model.live="balance" class="form-select"><option value="">All customers</option><option value="due">Owes money</option><option value="advance">Has advance</option></select>
                </div>
                <div class="col text-end text-muted small">{{ number_format($total) }} customers</div>
            </div>
        </div>
        <div class="table-responsive loading-overlay" wire:loading.attr="data-loading">
            <table class="table table-hover mb-0 table-stack">
                <thead class="table-light">
                    <tr>
                        <x-th-sort field="name" :sort="$sortField" :dir="$sortDirection">Customer</x-th-sort>
                        <th>Contact</th>
                        <th class="text-center">Invoices</th>
                        <th class="text-end">Total spent</th>
                        <x-th-sort field="current_balance" :sort="$sortField" :dir="$sortDirection" class="text-end">Balance</x-th-sort>
                        <th>Portal</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($customers as $customer)
                        <tr wire:key="c-{{ $customer->id }}">
                            <td data-label="Customer">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="avatar avatar-sm avatar-item avatar-title bg-primary-subtle text-primary rounded-circle fw-semibold">{{ mb_strtoupper(mb_substr($customer->name, 0, 1)) }}</span>
                                    <div><a href="{{ route('app.customers.show', $customer) }}" class="fw-semibold" wire:navigate>{{ $customer->name }}</a>@if ($customer->status !== 'active') <x-status-badge :status="$customer->status" />@endif</div>
                                </div>
                            </td>
                            <td data-label="Contact">{{ $customer->phone }}<div class="small text-muted">{{ $customer->email }}</div></td>
                            <td data-label="Invoices" class="text-md-center">{{ $customer->sales_count }}</td>
                            <td data-label="Total spent" class="text-end tabular-nums">{{ money($customer->spent) }}</td>
                            <td data-label="Balance" class="text-end fw-semibold tabular-nums {{ (float) $customer->current_balance > 0 ? 'text-danger' : ((float) $customer->current_balance < 0 ? 'text-success' : 'text-muted') }}">
                                {{ money(abs((float) $customer->current_balance)) }}
                                @if ((float) $customer->current_balance < 0)<div class="small fw-normal">advance</div>@endif
                            </td>
                            <td data-label="Portal">@if ($customer->password)<span class="badge bg-success-subtle text-success"><i class="ri-shield-check-line"></i> Enabled</span>@else<span class="badge bg-light text-muted">Off</span>@endif</td>
                            <td class="text-end td-actions text-nowrap" data-label="">
                                <button class="btn btn-sm btn-light-secondary" x-on:click="$dispatch('customer-form', { id: {{ $customer->id }} })" title="Edit"><i class="ri-pencil-line"></i></button>
                                <a href="{{ route('app.customers.show', $customer) }}" class="btn btn-sm btn-light-primary" wire:navigate title="Account"><i class="ri-book-open-line"></i></a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><x-empty-state icon="ri-group-line" title="No customers found" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($customers->hasPages())<div class="card-footer">{{ $customers->links() }}</div>@endif
    </div>

    <livewire:customers.form />
</div>
