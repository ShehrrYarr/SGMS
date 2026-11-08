<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.portal')] #[Title('Statement')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function updating($name): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $customer = auth('customer')->user();
        $base = $customer->ledgers()
            ->when($this->from, fn ($q) => $q->whereDate('transaction_date', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('transaction_date', '<=', $this->to));

        return [
            'customer' => $customer,
            'entries' => (clone $base)->with('sale:id,invoice_number')->orderByDesc('id')->paginate(20),
            'sums' => (clone $base)->selectRaw('COALESCE(SUM(debit),0) debit, COALESCE(SUM(credit),0) credit')->first(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Account statement" :breadcrumbs="['Overview' => route('portal.dashboard'), 'Statement' => null]">
        <x-slot:actions>
            <a href="{{ route('portal.statement.pdf', array_filter(['from' => $from, 'to' => $to])) }}" class="btn btn-primary"><i class="ri-download-2-line me-1"></i>Download PDF</a>
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3 mb-3">
        <div class="col-md-4"><x-stat-card :label="(float) $customer->current_balance < 0 ? 'Advance credit' : 'Current balance due'" :value="money(abs((float) $customer->current_balance))" icon="ri-scales-3-line" :color="(float) $customer->current_balance > 0 ? 'danger' : 'success'" /></div>
        <div class="col-md-4"><x-stat-card label="Charged (period)" :value="money($sums->debit)" icon="ri-shopping-bag-3-line" color="warning" /></div>
        <div class="col-md-4"><x-stat-card label="Paid & credited (period)" :value="money($sums->credit)" icon="ri-hand-coin-line" color="success" /></div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="row g-2 filter-bar">
                <div class="col-6 col-md-3"><input type="date" wire:model.live="from" class="form-control" title="From"></div>
                <div class="col-6 col-md-3"><input type="date" wire:model.live="to" class="form-control" title="To"></div>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 table-stack">
                <thead class="table-light"><tr><th>Date</th><th>Description</th><th>Reference</th><th class="text-end">Charged</th><th class="text-end">Paid / credit</th><th class="text-end">Balance</th></tr></thead>
                <tbody>
                    @forelse ($entries as $entry)
                        <tr>
                            <td data-label="Date">{{ $entry->transaction_date->format('d M Y') }}</td>
                            <td data-label="Description">{{ $entry->type_label }}<div class="small text-muted">{{ $entry->note }}</div></td>
                            <td data-label="Reference">@if ($entry->sale)<a href="{{ route('portal.purchases.show', ['sale' => $entry->sale_id]) }}" wire:navigate>{{ $entry->sale->invoice_number }}</a>@else{{ $entry->reference_no ?? '—' }}@endif</td>
                            <td data-label="Charged" class="text-end tabular-nums">{{ (float) $entry->debit ? money($entry->debit) : '' }}</td>
                            <td data-label="Paid / credit" class="text-end tabular-nums text-success">{{ (float) $entry->credit ? money($entry->credit) : '' }}</td>
                            <td data-label="Balance" class="text-end fw-semibold tabular-nums">{{ money($entry->running_balance) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><x-empty-state icon="ri-file-list-2-line" title="No activity in this period" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($entries->hasPages())<div class="card-footer">{{ $entries->links() }}</div>@endif
    </div>
</div>
