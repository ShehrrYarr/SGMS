<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.portal')] #[Title('My Account')] class extends Component {
    public function with(): array
    {
        $customer = auth('customer')->user();

        return [
            'customer' => $customer,
            'stats' => $customer->sales()->selectRaw('COUNT(*) cnt, COALESCE(SUM(total_amount),0) total, COALESCE(SUM(due_amount),0) due, MAX(sale_date) last')->first(),
            'recent' => $customer->sales()->with('branch:id,name')->withSum('items as qty', 'quantity')->latest('sale_date')->limit(5)->get(),
            'payments' => $customer->ledgers()->where('type', 'payment')->latest('id')->limit(5)->get(),
        ];
    }
}; ?>

<div>
    <x-page-header :title="'Hello, '.str($customer->name)->before(' ').' 👋'" :subtitle="'Welcome to your '.tenant()->name.' account.'" />

    <div class="row g-3 mb-3">
        <div class="col-md-6 col-xl-3">
            <x-stat-card :label="(float) $customer->current_balance < 0 ? 'Advance credit' : 'Balance due'" :value="money(abs((float) $customer->current_balance))" icon="ri-scales-3-line" :color="(float) $customer->current_balance > 0 ? 'danger' : 'success'" :href="route('portal.statement')" />
        </div>
        <div class="col-md-6 col-xl-3"><x-stat-card label="Total purchases" :value="money($stats->total)" icon="ri-shopping-bag-3-line" :hint="$stats->cnt.' invoices'" :href="route('portal.purchases.index')" /></div>
        <div class="col-md-6 col-xl-3"><x-stat-card label="Unpaid on invoices" :value="money($stats->due)" icon="ri-error-warning-line" color="warning" /></div>
        <div class="col-md-6 col-xl-3"><x-stat-card label="Last visit" :value="$stats->last ? \Illuminate\Support\Carbon::parse($stats->last)->format('d M Y') : '—'" icon="ri-calendar-line" color="info" /></div>
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card mb-0 h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="card-title mb-0">Recent purchases</h6>
                    <a href="{{ route('portal.purchases.index') }}" class="btn btn-sm btn-light-primary" wire:navigate>View all</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 table-stack">
                        <thead class="table-light"><tr><th>Invoice</th><th>Date</th><th>Branch</th><th class="text-center">Items</th><th class="text-end">Total</th><th>Status</th></tr></thead>
                        <tbody>
                            @forelse ($recent as $sale)
                                <tr>
                                    <td data-label="Invoice"><a href="{{ route('portal.purchases.show', ['sale' => $sale->id]) }}" class="fw-semibold" wire:navigate>{{ $sale->invoice_number }}</a></td>
                                    <td data-label="Date">{{ $sale->sale_date->format('d M Y') }}</td>
                                    <td data-label="Branch">{{ $sale->branch->name }}</td>
                                    <td data-label="Items" class="text-md-center">{{ (int) $sale->qty }}</td>
                                    <td data-label="Total" class="text-end fw-semibold tabular-nums">{{ money($sale->total_amount) }}</td>
                                    <td data-label="Status"><x-status-badge :status="$sale->payment_status" /></td>
                                </tr>
                            @empty
                                <tr><td colspan="6"><x-empty-state icon="ri-shopping-bag-3-line" title="No purchases yet" /></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card mb-0 h-100">
                <div class="card-header"><h6 class="card-title mb-0">Recent payments</h6></div>
                <ul class="list-group list-group-flush">
                    @forelse ($payments as $p)
                        <li class="list-group-item d-flex justify-content-between">
                            <div><div class="fw-medium">{{ $p->transaction_date->format('d M Y') }}</div><small class="text-muted">{{ $p->reference_no ?? $p->note }}</small></div>
                            <b class="text-success tabular-nums">{{ money($p->credit) }}</b>
                        </li>
                    @empty
                        <li class="list-group-item"><x-empty-state icon="ri-hand-coin-line" title="No payments yet" /></li>
                    @endforelse
                </ul>
                <div class="card-footer"><a href="{{ route('portal.statement.pdf') }}" class="btn btn-light-primary w-100"><i class="ri-download-2-line me-1"></i>Download statement (PDF)</a></div>
            </div>
        </div>
    </div>
</div>
