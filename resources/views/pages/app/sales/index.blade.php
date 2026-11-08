<?php

use App\Livewire\Concerns\WithSorting;
use App\Models\Sale;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Sales & Invoices')] class extends Component {
    use WithPagination, WithSorting;

    protected array $sortable = ['invoice_number', 'sale_date', 'total_amount', 'due_amount'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $method = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function updating($name): void
    {
        if (in_array($name, ['search', 'status', 'method', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'method', 'from', 'to');
        $this->resetPage();
    }

    protected function query()
    {
        $user = auth('web')->user();

        return Sale::query()
            ->inBranchScope()
            ->when($user->isCashier(), fn ($q) => $q->where('created_by', $user->id))
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->where('invoice_number', 'like', "%{$this->search}%")
                ->orWhereHas('customer', fn ($c) => $c->search($this->search))))
            ->when($this->status, fn ($q) => $q->where('payment_status', $this->status))
            ->when($this->method, fn ($q) => $q->where('payment_method', $this->method))
            ->when($this->from, fn ($q) => $q->whereDate('sale_date', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('sale_date', '<=', $this->to));
    }

    public function with(): array
    {
        $summary = $this->query()->selectRaw('COUNT(*) cnt, COALESCE(SUM(total_amount),0) total, COALESCE(SUM(paid_amount),0) paid, COALESCE(SUM(due_amount),0) due, COALESCE(SUM(returned_amount),0) returned')->first();

        return [
            'sales' => $this->applySorting($this->query()->with('customer:id,name,phone', 'branch:id,name,code')->withSum('items as qty', 'quantity'), 'sale_date')->paginate(15),
            'summary' => $summary,
        ];
    }
}; ?>

<div>
    <x-page-header title="Sales & invoices" :breadcrumbs="['Dashboard' => route('app.dashboard'), 'Sales' => null]">
        <x-slot:actions>
            <a href="{{ route('app.returns.create') }}" class="btn btn-light-warning" wire:navigate><i class="ri-arrow-go-back-line me-1"></i>Return / exchange</a>
            <a href="{{ route('app.pos') }}" class="btn btn-primary"><i class="ri-shopping-cart-2-line me-1"></i>New sale</a>
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><x-stat-card label="Invoices" :value="number_format($summary->cnt)" icon="ri-file-list-3-line" /></div>
        <div class="col-6 col-lg-3"><x-stat-card label="Sales value" :value="money($summary->total)" icon="ri-money-dollar-circle-line" color="success" /></div>
        <div class="col-6 col-lg-3"><x-stat-card label="Collected" :value="money($summary->paid)" icon="ri-hand-coin-line" color="info" /></div>
        <div class="col-6 col-lg-3"><x-stat-card label="Outstanding" :value="money($summary->due)" icon="ri-error-warning-line" color="danger" :hint="(float) $summary->returned ? money($summary->returned).' returned' : null" /></div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="row g-2 filter-bar">
                <div class="col-lg-4"><x-search-input wire:model.live.debounce.300ms="search" placeholder="Invoice #, customer name or phone…" /></div>
                <div class="col-6 col-lg-2">
                    <select wire:model.live="status" class="form-select">
                        <option value="">Any status</option><option value="paid">Paid</option><option value="partial">Partial</option><option value="unpaid">Unpaid</option>
                    </select>
                </div>
                <div class="col-6 col-lg-2">
                    <select wire:model.live="method" class="form-select">
                        <option value="">Any method</option><option value="cash">Cash</option><option value="bank">Bank</option><option value="split">Split</option><option value="credit">Credit</option><option value="exchange">Exchange</option>
                    </select>
                </div>
                <div class="col-6 col-lg-2"><input type="date" wire:model.live="from" class="form-control" title="From"></div>
                <div class="col-6 col-lg-2"><input type="date" wire:model.live="to" class="form-control" title="To"></div>
            </div>
            @if ($search || $status || $method || $from || $to)
                <button class="btn btn-link btn-sm px-0 mt-2" wire:click="clearFilters"><i class="ri-close-circle-line"></i> Clear filters</button>
            @endif
        </div>
        <div class="table-responsive loading-overlay" wire:loading.attr="data-loading">
            <table class="table table-hover mb-0 table-stack">
                <thead class="table-light">
                    <tr>
                        <x-th-sort field="invoice_number" :sort="$sortField" :dir="$sortDirection">Invoice</x-th-sort>
                        <x-th-sort field="sale_date" :sort="$sortField" :dir="$sortDirection">Date</x-th-sort>
                        <th>Customer</th>
                        <th>Branch</th>
                        <th class="text-center">Qty</th>
                        <x-th-sort field="total_amount" :sort="$sortField" :dir="$sortDirection" class="text-end">Total</x-th-sort>
                        <x-th-sort field="due_amount" :sort="$sortField" :dir="$sortDirection" class="text-end">Due</x-th-sort>
                        <th>Payment</th>
                        <th class="text-end"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sales as $sale)
                        <tr wire:key="sale-{{ $sale->id }}">
                            <td data-label="Invoice"><a href="{{ route('app.sales.show', $sale) }}" class="fw-semibold" wire:navigate>{{ $sale->invoice_number }}</a></td>
                            <td data-label="Date" class="small">{{ $sale->sale_date->format('d M Y') }}<div class="text-muted">{{ $sale->sale_date->format('h:i A') }}</div></td>
                            <td data-label="Customer">{{ $sale->customer_name }}@if ($sale->customer)<div class="small text-muted">{{ $sale->customer->phone }}</div>@endif</td>
                            <td data-label="Branch"><span class="badge bg-light text-body border">{{ $sale->branch->code }}</span></td>
                            <td data-label="Qty" class="text-md-center">{{ (int) $sale->qty }}</td>
                            <td data-label="Total" class="text-end fw-semibold tabular-nums">{{ money($sale->total_amount) }}</td>
                            <td data-label="Due" class="text-end tabular-nums {{ (float) $sale->due_amount > 0 ? 'text-danger fw-semibold' : 'text-muted' }}">{{ money($sale->due_amount) }}</td>
                            <td data-label="Payment"><x-status-badge :status="$sale->payment_status" /> <x-status-badge :status="$sale->payment_method" class="ms-1" /></td>
                            <td class="text-end td-actions text-nowrap" data-label="">
                                <button type="button" class="btn btn-sm btn-light-secondary" title="Print receipt" x-on:click="SGMS.print(@js(route('app.sales.receipt', ['sale' => $sale, 'autoprint' => 1])))"><i class="ri-printer-line"></i></button>
                                <a href="{{ route('app.sales.show', $sale) }}" class="btn btn-sm btn-light-primary" wire:navigate title="View"><i class="ri-eye-line"></i></a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9"><x-empty-state icon="ri-file-list-3-line" title="No sales found" text="Adjust the filters or make a sale at the POS." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($sales->hasPages())
            <div class="card-footer">{{ $sales->links() }}</div>
        @endif
    </div>
</div>
