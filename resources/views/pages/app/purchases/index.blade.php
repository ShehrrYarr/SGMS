<?php

use App\Livewire\Concerns\WithSorting;
use App\Models\Purchase;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Lot Purchases')] class extends Component {
    use WithPagination, WithSorting;

    protected array $sortable = ['lot_number', 'purchase_date', 'total_lot_cost', 'due_amount', 'total_garment_qty'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function updating($name): void
    {
        if (in_array($name, ['search', 'status', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    protected function query()
    {
        return Purchase::query()->inBranchScope()
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->where('lot_number', 'like', "%{$this->search}%")
                ->orWhere('lot_name', 'like', "%{$this->search}%")
                ->orWhereHas('vendor', fn ($v) => $v->search($this->search))))
            ->when($this->status, fn ($q) => $q->where('payment_status', $this->status))
            ->when($this->from, fn ($q) => $q->whereDate('purchase_date', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('purchase_date', '<=', $this->to));
    }

    public function with(): array
    {
        return [
            'purchases' => $this->applySorting($this->query()->with('vendor:id,name,company', 'branch:id,code'), 'purchase_date')->paginate(15),
            'summary' => $this->query()->selectRaw('COUNT(*) cnt, COALESCE(SUM(total_lot_cost),0) total, COALESCE(SUM(due_amount),0) due, COALESCE(SUM(total_garment_qty),0) qty')->first(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Lot purchases" :breadcrumbs="['Dashboard' => route('app.dashboard'), 'Lot purchases' => null]">
        <x-slot:actions>
            <a href="{{ route('app.vendors.index') }}" class="btn btn-light-primary" wire:navigate><i class="ri-truck-line me-1"></i>Vendors</a>
            <a href="{{ route('app.purchases.create') }}" class="btn btn-primary" wire:navigate><i class="ri-add-line me-1"></i>New lot</a>
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><x-stat-card label="Lots" :value="number_format($summary->cnt)" icon="ri-inbox-archive-line" /></div>
        <div class="col-6 col-lg-3"><x-stat-card label="Pieces received" :value="number_format($summary->qty)" icon="ri-t-shirt-2-line" color="info" /></div>
        <div class="col-6 col-lg-3"><x-stat-card label="Purchase value" :value="money($summary->total)" icon="ri-money-dollar-box-line" color="success" /></div>
        <div class="col-6 col-lg-3"><x-stat-card label="Owed to vendors" :value="money($summary->due)" icon="ri-error-warning-line" color="danger" /></div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="row g-2 filter-bar">
                <div class="col-lg-5"><x-search-input wire:model.live.debounce.300ms="search" placeholder="Lot number, lot name or vendor…" /></div>
                <div class="col-4 col-lg-3">
                    <select wire:model.live="status" class="form-select"><option value="">Any status</option><option value="paid">Paid</option><option value="partial">Partial</option><option value="unpaid">Unpaid</option></select>
                </div>
                <div class="col-4 col-lg-2"><input type="date" wire:model.live="from" class="form-control"></div>
                <div class="col-4 col-lg-2"><input type="date" wire:model.live="to" class="form-control"></div>
            </div>
        </div>
        <div class="table-responsive loading-overlay" wire:loading.attr="data-loading">
            <table class="table table-hover mb-0 table-stack">
                <thead class="table-light">
                    <tr>
                        <x-th-sort field="lot_number" :sort="$sortField" :dir="$sortDirection">Lot</x-th-sort>
                        <x-th-sort field="purchase_date" :sort="$sortField" :dir="$sortDirection">Date</x-th-sort>
                        <th>Vendor</th><th>Branch</th>
                        <x-th-sort field="total_garment_qty" :sort="$sortField" :dir="$sortDirection" class="text-center">Pcs</x-th-sort>
                        <x-th-sort field="total_lot_cost" :sort="$sortField" :dir="$sortDirection" class="text-end">Cost</x-th-sort>
                        <x-th-sort field="due_amount" :sort="$sortField" :dir="$sortDirection" class="text-end">Due</x-th-sort>
                        <th>Payment</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($purchases as $p)
                        <tr wire:key="p-{{ $p->id }}">
                            <td data-label="Lot"><a href="{{ route('app.purchases.show', $p) }}" class="fw-semibold" wire:navigate>{{ $p->lot_number }}</a><div class="small text-muted text-truncate" style="max-width: 200px">{{ $p->lot_name }}</div></td>
                            <td data-label="Date">{{ $p->purchase_date->format('d M Y') }}</td>
                            <td data-label="Vendor">{{ $p->vendor->name }}<div class="small text-muted">{{ $p->vendor->company }}</div></td>
                            <td data-label="Branch"><span class="badge bg-light text-body border">{{ $p->branch->code }}</span></td>
                            <td data-label="Pcs" class="text-md-center">{{ number_format($p->total_garment_qty) }}</td>
                            <td data-label="Cost" class="text-end fw-semibold tabular-nums">{{ money($p->total_lot_cost) }}</td>
                            <td data-label="Due" class="text-end tabular-nums {{ (float) $p->due_amount > 0 ? 'text-danger fw-semibold' : 'text-muted' }}">{{ money($p->due_amount) }}</td>
                            <td data-label="Payment"><x-status-badge :status="$p->payment_status" /> <x-status-badge :status="$p->payment_method" /></td>
                            <td class="text-end td-actions text-nowrap" data-label="">
                                <a href="{{ route('app.purchases.pdf', $p) }}" class="btn btn-sm btn-light-secondary" title="PDF"><i class="ri-file-pdf-2-line"></i></a>
                                <a href="{{ route('app.purchases.show', $p) }}" class="btn btn-sm btn-light-primary" wire:navigate title="View"><i class="ri-eye-line"></i></a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9"><x-empty-state icon="ri-inbox-archive-line" title="No lot purchases yet" text="Record your first lot to put garments into stock."><a href="{{ route('app.purchases.create') }}" class="btn btn-primary btn-sm mt-3" wire:navigate>New lot</a></x-empty-state></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($purchases->hasPages())<div class="card-footer">{{ $purchases->links() }}</div>@endif
    </div>
</div>
