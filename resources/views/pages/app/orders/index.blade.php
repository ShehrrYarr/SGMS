<?php

use App\Models\Order;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Online Orders')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $payment = '';

    public function updating($name): void
    {
        if (in_array($name, ['status', 'search', 'payment'], true)) {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        $counts = Order::query()->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');
        $awaiting = Order::query()->where('payment_status', 'awaiting')->where('status', '!=', 'cancelled')->count();

        return [
            'orders' => Order::query()
                ->status($this->status ?: null)
                ->search($this->search)
                ->when($this->payment === 'awaiting', fn ($q) => $q->where('payment_status', 'awaiting'))
                ->when(in_array($this->payment, array_keys(Order::PAYMENT_METHODS), true), fn ($q) => $q->where('payment_method', $this->payment))
                ->with('branch:id,code')
                ->withSum('items as qty', 'quantity')
                ->latest()
                ->paginate(20),
            'counts' => $counts,
            'awaiting' => $awaiting,
            'storeLive' => tenant()->storeIsLive(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Online orders" :breadcrumbs="['Dashboard' => route('app.dashboard'), 'Online orders' => null]">
        <x-slot:actions>
            <a href="{{ tenant()->storeUrl() }}" target="_blank" rel="noopener" class="btn btn-light"><i class="ri-external-link-line me-1"></i>Open store</a>
            @can('owner')<a href="{{ route('app.settings.store') }}" class="btn btn-light-primary" wire:navigate><i class="ri-settings-3-line me-1"></i>Store settings</a>@endcan
        </x-slot:actions>
    </x-page-header>

    @unless ($storeLive)
        <div class="alert alert-warning d-flex gap-2 align-items-center">
            <i class="ri-information-line fs-5"></i>
            <div class="flex-grow-1">Your online store is not open to shoppers yet.</div>
            @can('owner')<a href="{{ route('app.settings.store') }}" class="btn btn-sm btn-warning" wire:navigate>Open the store</a>@endcan
        </div>
    @endunless

    <ul class="nav nav-pills nav-tabs-scroll mb-3">
        <li class="nav-item"><button type="button" class="nav-link {{ $status === '' ? 'active' : '' }}" wire:click="$set('status', '')">All <span class="badge bg-light text-body ms-1">{{ $counts->sum() }}</span></button></li>
        @foreach (Order::STATUSES as $key => $label)
            <li class="nav-item">
                <button type="button" class="nav-link {{ $status === $key ? 'active' : '' }}" wire:click="$set('status', '{{ $key }}')">
                    {{ $label }} @if ($counts[$key] ?? 0)<span class="badge {{ $key === 'pending' ? 'bg-danger' : 'bg-light text-body' }} ms-1">{{ $counts[$key] }}</span>@endif
                </button>
            </li>
        @endforeach
    </ul>

    <div class="card">
        <div class="card-header">
            <div class="row g-2">
                <div class="col-lg-6"><x-search-input wire:model.live.debounce.300ms="search" placeholder="Order #, customer name or phone…" /></div>
                <div class="col-lg-3">
                    <select wire:model.live="payment" class="form-select">
                        <option value="">Any payment</option>
                        @foreach (Order::PAYMENT_METHODS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                        <option value="awaiting">Receipt to verify ({{ $awaiting }})</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="table-responsive loading-overlay" wire:loading.attr="data-loading">
            <table class="table table-hover mb-0 table-stack align-middle">
                <thead class="table-light">
                    <tr><th>Order</th><th>Placed</th><th>Customer</th><th>City</th><th class="text-center">Items</th><th class="text-end">Total</th><th>Payment</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse ($orders as $order)
                        <tr wire:key="order-{{ $order->id }}" class="{{ ! $order->seen_at ? 'table-warning' : '' }}">
                            <td data-label="Order">
                                <a href="{{ route('app.orders.show', $order) }}" class="fw-semibold" wire:navigate>{{ $order->order_number }}</a>
                                @if (! $order->seen_at)<span class="badge bg-danger ms-1">New</span>@endif
                            </td>
                            <td data-label="Placed" class="small">{{ $order->created_at->format('d M Y') }}<div class="text-muted">{{ $order->created_at->format('h:i A') }}</div></td>
                            <td data-label="Customer">{{ $order->customer_name }}<div class="small text-muted">{{ $order->phone }}</div></td>
                            <td data-label="City">{{ $order->city }}</td>
                            <td data-label="Items" class="text-md-center">{{ (int) $order->qty }}</td>
                            <td data-label="Total" class="text-end fw-semibold tabular-nums">{{ money($order->total) }}</td>
                            <td data-label="Payment">
                                <div class="small">{{ $order->payment_method_label }}</div>
                                <x-status-badge :status="$order->payment_status" :label="$order->payment_status_label" />
                            </td>
                            <td data-label="Status">
                                <x-status-badge :status="$order->status" :label="$order->status_label" />
                                @if ($order->branch)<div class="small text-muted mt-1">from {{ $order->branch->code }}</div>@endif
                            </td>
                            <td class="text-end td-actions"><a href="{{ route('app.orders.show', $order) }}" class="btn btn-sm btn-light-primary" wire:navigate>Open</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="9"><x-empty-state icon="ri-shopping-bag-3-line" title="No online orders yet" text="Orders placed on your online store appear here." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($orders->hasPages())<div class="card-footer">{{ $orders->links() }}</div>@endif
    </div>
</div>
