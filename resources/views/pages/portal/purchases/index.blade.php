<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.portal')] #[Title('My Purchases')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $search = '';

    public function updating($name): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'sales' => auth('customer')->user()->sales()
                ->with('branch:id,name')
                ->withSum('items as qty', 'quantity')
                ->when($this->status, fn ($q) => $q->where('payment_status', $this->status))
                ->when($this->search, fn ($q) => $q->where('invoice_number', 'like', "%{$this->search}%"))
                ->latest('sale_date')->paginate(12),
        ];
    }
}; ?>

<div>
    <x-page-header title="My purchases" :breadcrumbs="['Overview' => route('portal.dashboard'), 'Purchases' => null]" />

    <div class="card">
        <div class="card-header">
            <div class="row g-2 filter-bar">
                <div class="col-md-6 col-lg-4"><x-search-input wire:model.live.debounce.300ms="search" placeholder="Invoice number…" /></div>
                <div class="col-6 col-md-3 col-lg-2">
                    <select wire:model.live="status" class="form-select"><option value="">All</option><option value="paid">Paid</option><option value="partial">Partial</option><option value="unpaid">Unpaid</option></select>
                </div>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 table-stack">
                <thead class="table-light"><tr><th>Invoice</th><th>Date</th><th>Branch</th><th class="text-center">Items</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Due</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($sales as $sale)
                        <tr>
                            <td data-label="Invoice"><a href="{{ route('portal.purchases.show', ['sale' => $sale->id]) }}" class="fw-semibold" wire:navigate>{{ $sale->invoice_number }}</a></td>
                            <td data-label="Date">{{ $sale->sale_date->format('d M Y, h:i A') }}</td>
                            <td data-label="Branch">{{ $sale->branch->name }}</td>
                            <td data-label="Items" class="text-md-center">{{ (int) $sale->qty }}</td>
                            <td data-label="Total" class="text-end tabular-nums">{{ money($sale->total_amount) }}</td>
                            <td data-label="Paid" class="text-end tabular-nums">{{ money($sale->paid_amount) }}</td>
                            <td data-label="Due" class="text-end tabular-nums fw-semibold {{ (float) $sale->due_amount > 0 ? 'text-danger' : '' }}">{{ money($sale->due_amount) }}</td>
                            <td data-label="Status"><x-status-badge :status="$sale->payment_status" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><x-empty-state icon="ri-shopping-bag-3-line" title="No purchases found" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($sales->hasPages())<div class="card-footer">{{ $sales->links() }}</div>@endif
    </div>
</div>
