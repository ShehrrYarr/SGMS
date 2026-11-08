<?php

use App\Models\Order;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.portal')] #[Title('Online Orders')] class extends Component {
    use WithPagination;

    public function with(): array
    {
        return [
            'orders' => Order::query()
                ->where('customer_id', auth('customer')->id())
                ->withSum('items as qty', 'quantity')
                ->latest()
                ->paginate(15),
        ];
    }
}; ?>

<div>
    <x-page-header title="Online orders" subtitle="Orders you placed on our online store." />

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 table-stack">
                <thead class="table-light"><tr><th>Order</th><th>Placed</th><th class="text-center">Items</th><th class="text-end">Total</th><th>Payment</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($orders as $order)
                        <tr>
                            <td data-label="Order" class="fw-semibold">{{ $order->order_number }}</td>
                            <td data-label="Placed" class="small">{{ $order->created_at->format('d M Y, h:i A') }}</td>
                            <td data-label="Items" class="text-md-center">{{ (int) $order->qty }}</td>
                            <td data-label="Total" class="text-end tabular-nums fw-medium">{{ money($order->total) }}</td>
                            <td data-label="Payment"><div class="small">{{ $order->payment_method_label }}</div><x-status-badge :status="$order->payment_status" :label="$order->payment_status_label" /></td>
                            <td data-label="Status"><x-status-badge :status="$order->status" :label="$order->status_label" /></td>
                            <td class="text-end td-actions">
                                @if (tenant()->storeIsLive())
                                    <a href="{{ route('store.order', $order) }}" class="btn btn-sm btn-light-primary">Track</a>
                                @endif
                                @if ($order->sale_id)
                                    <a href="{{ route('portal.purchases.show', $order->sale_id) }}" class="btn btn-sm btn-light" wire:navigate>Invoice</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7">
                            <x-empty-state icon="ri-shopping-bag-3-line" title="No online orders yet" text="Orders you place on our online store will appear here." />
                            @if (tenant()->storeIsLive())<div class="text-center pb-3"><a href="{{ tenant()->storeUrl() }}" class="btn btn-primary">Visit the store</a></div>@endif
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($orders->hasPages())<div class="card-footer">{{ $orders->links() }}</div>@endif
    </div>
</div>
