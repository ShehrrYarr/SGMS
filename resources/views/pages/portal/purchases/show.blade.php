<?php

use App\Models\Sale;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.portal')] #[Title('Invoice')] class extends Component {
    #[Locked]
    public int $saleId;

    public function mount(Sale $sale): void
    {
        // Tenant scope already applies; also ensure the invoice is this customer's.
        abort_unless($sale->customer_id === auth('customer')->id(), 404);
        $this->saleId = $sale->id;
    }

    public function with(): array
    {
        return [
            'sale' => Sale::query()->where('customer_id', auth('customer')->id())->with([
                'items.variant.product:id,name', 'items.variant.color:id,name,hex_code', 'items.variant.size:id,code',
                'payments.paymentAccount:id,account_name', 'branch', 'returns',
            ])->findOrFail($this->saleId),
        ];
    }
}; ?>

<div>
    <x-page-header :title="'Invoice '.$sale->invoice_number" :breadcrumbs="['Overview' => route('portal.dashboard'), 'Purchases' => route('portal.purchases.index'), $sale->invoice_number => null]">
        <x-slot:actions>
            <button class="btn btn-light-primary" x-on:click="SGMS.print(@js(route('portal.purchases.receipt', ['sale' => $sale->id, 'autoprint' => 1])))"><i class="ri-printer-line me-1"></i>Print receipt</button>
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0">Items</h6><small class="text-muted">{{ $sale->sale_date->format('d M Y, h:i A') }} · {{ $sale->branch->name }}</small></div>
                <div class="table-responsive">
                    <table class="table mb-0 table-stack">
                        <thead class="table-light"><tr><th>Item</th><th class="text-center">Qty</th><th class="text-end">Price</th><th class="text-end">Total</th></tr></thead>
                        <tbody>
                            @foreach ($sale->items as $item)
                                <tr>
                                    <td data-label="Item"><span class="color-dot me-1" style="background: {{ $item->variant->color?->hex_code ?? '#e5e7eb' }}"></span><span class="fw-semibold">{{ $item->variant->product->name }}</span><div class="small text-muted">{{ $item->variant->variant_name }}</div></td>
                                    <td data-label="Qty" class="text-md-center">{{ $item->quantity }}@if ($item->returned_quantity)<div class="small text-warning">{{ $item->returned_quantity }} returned</div>@endif</td>
                                    <td data-label="Price" class="text-end tabular-nums">{{ money($item->unit_price) }}</td>
                                    <td data-label="Total" class="text-end fw-semibold tabular-nums">{{ money($item->total_price) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between py-1"><span class="text-muted">Subtotal</span><span>{{ money($sale->subtotal) }}</span></div>
                    @if ((float) $sale->discount)<div class="d-flex justify-content-between py-1"><span class="text-muted">Discount</span><span class="text-danger">-{{ money($sale->discount) }}</span></div>@endif
                    @if ((float) $sale->tax)<div class="d-flex justify-content-between py-1"><span class="text-muted">Tax</span><span>{{ money($sale->tax) }}</span></div>@endif
                    <div class="d-flex justify-content-between py-2 border-top fs-5 fw-bold"><span>Total</span><span>{{ money($sale->total_amount) }}</span></div>
                    <div class="d-flex justify-content-between py-1"><span class="text-muted">Paid</span><span class="text-success">{{ money($sale->paid_amount) }}</span></div>
                    <div class="d-flex justify-content-between py-1 fw-semibold"><span>Balance due</span><span class="{{ (float) $sale->due_amount > 0 ? 'text-danger' : '' }}">{{ money($sale->due_amount) }}</span></div>
                    <div class="mt-2"><x-status-badge :status="$sale->payment_status" /></div>
                </div>
            </div>
            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0">Payments</h6></div>
                <ul class="list-group list-group-flush">
                    @forelse ($sale->payments as $payment)
                        <li class="list-group-item d-flex justify-content-between"><div><div>{{ $payment->payment_method === 'exchange' ? 'Exchange credit' : ucfirst($payment->payment_method) }}</div><small class="text-muted">{{ $payment->payment_date->format('d M Y') }}</small></div><b>{{ money($payment->amount) }}</b></li>
                    @empty
                        <li class="list-group-item small text-muted">On account — no payments yet.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>
