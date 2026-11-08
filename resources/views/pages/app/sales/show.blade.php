<?php

use App\Models\Sale;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Invoice')] class extends Component {
    #[Locked]
    public int $saleId;

    public function mount(Sale $sale): void
    {
        $user = auth('web')->user();
        abort_unless(tenancy()->canAccessBranch($sale->branch_id), 403);
        abort_if($user->isCashier() && $sale->created_by !== $user->id, 403);
        $this->saleId = $sale->id;
    }

    public function with(): array
    {
        return [
            'sale' => Sale::query()->with([
                'items.variant.product:id,name', 'items.variant.color:id,name,hex_code', 'items.variant.size:id,code',
                'payments.paymentAccount:id,account_name', 'customer', 'branch', 'creator:id,name',
                'returns' => fn ($q) => $q->withSum('items as qty', 'quantity'),
            ])->findOrFail($this->saleId),
        ];
    }
}; ?>

<div>
    <x-page-header :title="'Invoice '.$sale->invoice_number" :breadcrumbs="['Dashboard' => route('app.dashboard'), 'Sales' => route('app.sales.index'), $sale->invoice_number => null]">
        <x-slot:actions>
            <div class="btn-group">
                <button type="button" class="btn btn-light-secondary" x-on:click="SGMS.print(@js(route('app.sales.receipt', ['sale' => $sale, 'autoprint' => 1])))"><i class="ri-printer-line me-1"></i>Receipt</button>
                <button type="button" class="btn btn-light-secondary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown"></button>
                <div class="dropdown-menu dropdown-menu-end">
                    <button type="button" class="dropdown-item" x-on:click="SGMS.print(@js(route('app.sales.receipt', ['sale' => $sale, 'autoprint' => 1, 'width' => 80])))">80mm thermal</button>
                    <button type="button" class="dropdown-item" x-on:click="SGMS.print(@js(route('app.sales.receipt', ['sale' => $sale, 'autoprint' => 1, 'width' => 58])))">58mm thermal</button>
                </div>
            </div>
            <a href="{{ route('app.sales.pdf', $sale) }}" class="btn btn-light-primary"><i class="ri-file-pdf-2-line me-1"></i>PDF</a>
            @if ($sale->items->sum('quantity') > $sale->items->sum('returned_quantity'))
                <a href="{{ route('app.returns.create', ['sale' => $sale->id]) }}" class="btn btn-warning" wire:navigate><i class="ri-arrow-go-back-line me-1"></i>Return / exchange</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card mb-3">
                <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title mb-0">Items</h6>
                        <small class="text-muted">{{ $sale->sale_date->format('d M Y, h:i A') }} · {{ $sale->branch->name }} · by {{ $sale->creator?->name ?? '—' }}</small>
                    </div>
                    <div><x-status-badge :status="$sale->payment_status" class="fs-12" /> <x-status-badge :status="$sale->payment_method" class="fs-12" /></div>
                </div>
                <div class="table-responsive">
                    <table class="table mb-0 table-stack">
                        <thead class="table-light"><tr><th>Item</th><th class="text-center">Qty</th><th class="text-end">Price</th><th class="text-end">Discount</th><th class="text-end">Total</th></tr></thead>
                        <tbody>
                            @foreach ($sale->items as $item)
                                <tr>
                                    <td data-label="Item">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="color-dot" style="background: {{ $item->variant->color?->hex_code ?? '#e5e7eb' }}"></span>
                                            <div>
                                                <div class="fw-semibold">{{ $item->variant->product->name }}</div>
                                                <small class="text-muted">{{ $item->variant->variant_name }} · {{ $item->variant->sku }}</small>
                                            </div>
                                        </div>
                                    </td>
                                    <td data-label="Qty" class="text-md-center">{{ $item->quantity }}@if ($item->returned_quantity)<div class="small text-warning">{{ $item->returned_quantity }} returned</div>@endif</td>
                                    <td data-label="Price" class="text-end tabular-nums">{{ money($item->unit_price) }}</td>
                                    <td data-label="Discount" class="text-end tabular-nums">{{ (float) $item->discount ? money($item->discount) : '—' }}</td>
                                    <td data-label="Total" class="text-end fw-semibold tabular-nums">{{ money($item->total_price) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($sale->returns->isNotEmpty())
                <div class="card mb-3">
                    <div class="card-header"><h6 class="card-title mb-0">Returns & exchanges</h6></div>
                    <ul class="list-group list-group-flush">
                        @foreach ($sale->returns as $return)
                            <li class="list-group-item d-flex flex-wrap gap-2 align-items-center">
                                <span class="fw-semibold">{{ $return->return_number }}</span>
                                <span class="text-muted small">{{ $return->return_date->format('d M Y') }} · {{ (int) $return->qty }} pcs · {{ \App\Models\SaleReturn::METHODS[$return->refund_method] ?? $return->refund_method }}</span>
                                <x-status-badge :status="$return->status" />
                                <span class="ms-auto fw-semibold tabular-nums">{{ money($return->total_amount) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <div class="col-xl-4">
            <div class="card mb-3">
                <div class="card-header"><h6 class="card-title mb-0">Customer</h6></div>
                <div class="card-body">
                    @if ($sale->customer)
                        <a href="{{ route('app.customers.show', $sale->customer) }}" class="fw-semibold" wire:navigate>{{ $sale->customer->name }}</a>
                        <div class="text-muted small">{{ $sale->customer->phone }}</div>
                        <div class="mt-2 small">Account balance: <b class="{{ (float) $sale->customer->current_balance > 0 ? 'text-danger' : 'text-success' }}">{{ money($sale->customer->current_balance) }}</b></div>
                    @else
                        <div class="fw-semibold">Walk-in Customer</div>
                    @endif
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between py-1"><span class="text-muted">Subtotal</span><span class="tabular-nums">{{ money($sale->subtotal) }}</span></div>
                    @if ((float) $sale->discount)<div class="d-flex justify-content-between py-1"><span class="text-muted">Discount</span><span class="tabular-nums text-danger">-{{ money($sale->discount) }}</span></div>@endif
                    @if ((float) $sale->tax)<div class="d-flex justify-content-between py-1"><span class="text-muted">Tax</span><span class="tabular-nums">{{ money($sale->tax) }}</span></div>@endif
                    <div class="d-flex justify-content-between py-2 border-top mt-1 fs-5 fw-bold"><span>Total</span><span class="tabular-nums">{{ money($sale->total_amount) }}</span></div>
                    <div class="d-flex justify-content-between py-1"><span class="text-muted">Paid</span><span class="tabular-nums text-success">{{ money($sale->paid_amount) }}</span></div>
                    @if ((float) $sale->returned_amount)<div class="d-flex justify-content-between py-1"><span class="text-muted">Returned value</span><span class="tabular-nums text-warning">{{ money($sale->returned_amount) }}</span></div>@endif
                    <div class="d-flex justify-content-between py-1 fw-semibold"><span>Balance due</span><span class="tabular-nums {{ (float) $sale->due_amount > 0 ? 'text-danger' : '' }}">{{ money($sale->due_amount) }}</span></div>
                    @if ((float) $sale->due_amount > 0 && $sale->customer && auth('web')->user()->canManage())
                        <a href="{{ route('app.customers.show', ['customer' => $sale->customer, 'receive' => 1]) }}" class="btn btn-success w-100 mt-2" wire:navigate><i class="ri-hand-coin-line me-1"></i>Receive payment</a>
                    @endif
                </div>
            </div>

            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0">Payments</h6></div>
                <ul class="list-group list-group-flush">
                    @forelse ($sale->payments as $payment)
                        <li class="list-group-item d-flex justify-content-between">
                            <div>
                                <div class="fw-medium">{{ $payment->payment_method === 'exchange' ? 'Exchange credit' : $payment->paymentAccount?->account_name }}</div>
                                <small class="text-muted">{{ $payment->payment_date->format('d M Y h:i A') }}@if ($payment->reference_no) · {{ $payment->reference_no }}@endif</small>
                            </div>
                            <span class="fw-semibold tabular-nums">{{ money($payment->amount) }}</span>
                        </li>
                    @empty
                        <li class="list-group-item text-muted small">Sold on credit — no payments yet.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>
