<?php

use App\Models\Order;
use Livewire\Volt\Component;

/**
 * Header bell for new online orders. Polls every 30 seconds and plays a
 * short chime when a new order arrives.
 */
new class extends Component {
    public int $known = -1;

    public function with(): array
    {
        $pending = Order::query()->where('status', 'pending');
        $count = (clone $pending)->count();
        $unseen = (clone $pending)->whereNull('seen_at')->count();

        $chime = $this->known >= 0 && $unseen > $this->known;
        $this->known = $unseen;

        return [
            'count' => $count,
            'unseen' => $unseen,
            'chime' => $chime,
            'latest' => (clone $pending)->latest()->limit(5)->get(['id', 'order_number', 'customer_name', 'total', 'created_at', 'seen_at']),
        ];
    }
}; ?>

<div class="dropdown" wire:poll.30s.visible x-data x-init="@if ($chime) SGMS.chime && SGMS.chime() @endif">
    <button type="button" class="btn icon-btn btn-text-primary rounded-circle position-relative" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Online orders{{ $count ? ': '.$count.' new' : '' }}" title="Online orders">
        <i class="ri-notification-3-line fs-16"></i>
        @if ($count)
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill {{ $unseen ? 'bg-danger' : 'bg-secondary' }}" style="font-size: .65rem">{{ $count }}</span>
        @endif
    </button>
    <div class="dropdown-menu dropdown-menu-end p-0" style="width: 320px">
        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
            <h6 class="mb-0">New online orders</h6>
            <a href="{{ route('app.orders.index') }}" class="small" wire:navigate>View all</a>
        </div>
        @forelse ($latest as $order)
            <a href="{{ route('app.orders.show', $order) }}" class="dropdown-item d-flex justify-content-between align-items-start gap-2 py-2 border-bottom" wire:navigate>
                <span class="min-w-0">
                    <span class="d-block fw-semibold">{{ $order->order_number }} @if (! $order->seen_at)<span class="badge bg-danger-subtle text-danger ms-1">New</span>@endif</span>
                    <span class="d-block small text-muted text-truncate">{{ $order->customer_name }}, {{ $order->created_at->diffForHumans() }}</span>
                </span>
                <span class="fw-medium small text-nowrap">{{ money($order->total, decimals: 0) }}</span>
            </a>
        @empty
            <div class="text-center text-muted small py-4"><i class="ri-checkbox-circle-line fs-3 d-block mb-1"></i>No orders waiting.</div>
        @endforelse
    </div>
</div>
