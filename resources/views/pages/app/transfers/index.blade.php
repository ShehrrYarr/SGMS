<?php

use App\Models\StockTransfer;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Stock Transfers')] class extends Component {
    use WithPagination;

    public ?int $expanded = null;

    public function toggle(int $id): void
    {
        $this->expanded = $this->expanded === $id ? null : $id;
    }

    public function with(): array
    {
        return [
            'transfers' => StockTransfer::query()->inBranchScope()
                ->with('fromBranch:id,name,code', 'toBranch:id,name,code', 'creator:id,name')
                ->withCount('items')
                ->latest('transfer_date')->latest('id')->paginate(15),
            'items' => $this->expanded
                ? StockTransfer::query()->inBranchScope()->find($this->expanded)?->items()->with('variant.product:id,name', 'variant.color:id,name,hex_code', 'variant.size:id,code')->get()
                : collect(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Stock transfers" :breadcrumbs="['Dashboard' => route('app.dashboard'), 'Transfers' => null]">
        <x-slot:actions>
            <a href="{{ route('app.transfers.create') }}" class="btn btn-primary" wire:navigate><i class="ri-arrow-left-right-line me-1"></i>New transfer</a>
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 table-stack">
                <thead class="table-light"><tr><th>Transfer</th><th>Date</th><th>Route</th><th class="text-center">Items</th><th class="text-center">Pieces</th><th>By</th><th></th></tr></thead>
                <tbody>
                    @forelse ($transfers as $t)
                        <tr wire:key="t-{{ $t->id }}">
                            <td data-label="Transfer" class="fw-semibold">{{ $t->transfer_number }}</td>
                            <td data-label="Date">{{ $t->transfer_date->format('d M Y') }}</td>
                            <td data-label="Route"><span class="badge bg-light text-body border">{{ $t->fromBranch->code }}</span> <i class="ri-arrow-right-line text-primary"></i> <span class="badge bg-light text-body border">{{ $t->toBranch->code }}</span></td>
                            <td data-label="Items" class="text-md-center">{{ $t->items_count }}</td>
                            <td data-label="Pieces" class="text-md-center fw-semibold">{{ $t->total_quantity }}</td>
                            <td data-label="By" class="small">{{ $t->creator?->name ?? '—' }}</td>
                            <td class="text-end td-actions" data-label=""><button class="btn btn-sm btn-light-primary" wire:click="toggle({{ $t->id }})"><i class="{{ $expanded === $t->id ? 'ri-arrow-up-s-line' : 'ri-arrow-down-s-line' }}"></i></button></td>
                        </tr>
                        @if ($expanded === $t->id)
                            <tr class="bg-light">
                                <td colspan="7">
                                    <div class="small fw-semibold mb-1">{{ $t->notes }}</div>
                                    @foreach ($items as $item)
                                        <div class="d-flex justify-content-between py-1 border-bottom"><span><span class="color-dot me-1" style="background: {{ $item->variant->color?->hex_code ?? '#e5e7eb' }}"></span>{{ $item->variant->label }}</span><b>{{ $item->quantity }} pcs</b></div>
                                    @endforeach
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="7"><x-empty-state icon="ri-arrow-left-right-line" title="No transfers yet" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($transfers->hasPages())<div class="card-footer">{{ $transfers->links() }}</div>@endif
    </div>
</div>
