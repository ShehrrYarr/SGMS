<?php

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\PaymentAccount;
use App\Services\Store\OrderService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Online Order')] class extends Component {
    use InteractsWithUi;

    #[Locked]
    public int $orderId;

    public ?int $branchId = null;

    public string $courier = '';

    public string $tracking = '';

    public ?int $collectInto = null;

    public bool $collectNow = true;

    public string $cancelReason = '';

    public ?int $refundAccountId = null;

    public function mount(Order $order): void
    {
        $this->orderId = $order->id;

        if (! $order->seen_at) {
            $order->forceFill(['seen_at' => now()])->save();
        }

        $this->branchId = $order->branch_id ?? $this->suggestBranch($order);
        $this->courier = (string) $order->courier;
        $this->tracking = (string) $order->tracking_number;
        $this->refundAccountId = $order->payment_account_id;
        $this->collectInto = $this->defaultDrawer($order->branch_id ?? $this->branchId);
    }

    protected function order(): Order
    {
        return Order::query()->with(['items.variant.product', 'items.variant.color', 'items.variant.size', 'customer', 'sale', 'branch', 'paymentAccount', 'handler'])->findOrFail($this->orderId);
    }

    /** First branch (of those this user can use) holding every item. */
    protected function suggestBranch(Order $order): ?int
    {
        $stock = $this->stockMatrix($order);
        $branches = tenancy()->allowedBranchIds();

        foreach ($branches as $branchId) {
            if ($order->items->every(fn ($item) => ($stock[$item->product_variant_id][$branchId] ?? 0) >= $item->quantity)) {
                return $branchId;
            }
        }

        return tenancy()->operationalBranchId() ?? ($branches[0] ?? null);
    }

    /** @return array<int, array<int, int>> variant_id => [branch_id => qty] */
    protected function stockMatrix(Order $order): array
    {
        return Inventory::query()
            ->whereIn('product_variant_id', $order->items->pluck('product_variant_id'))
            ->get(['branch_id', 'product_variant_id', 'quantity'])
            ->groupBy('product_variant_id')
            ->map(fn ($rows) => $rows->pluck('quantity', 'branch_id')->all())
            ->all();
    }

    protected function defaultDrawer(?int $branchId): ?int
    {
        return PaymentAccount::query()->active()->where('branch_id', $branchId)->where('account_type', PaymentAccount::TYPE_CASH)->orderByDesc('is_default')->value('id');
    }

    public function updatedBranchId(): void
    {
        $this->collectInto = $this->defaultDrawer($this->branchId);
    }

    public function confirm(OrderService $orders): void
    {
        $this->validate(['branchId' => ['required', 'integer']], [], ['branchId' => 'branch']);

        $this->attempt(fn () => $orders->confirm($this->order(), (int) $this->branchId), 'Order confirmed. Invoice created and stock taken from the branch.');
    }

    public function markPaid(OrderService $orders): void
    {
        $this->attempt(fn () => $orders->markPaid($this->order()), 'Payment marked as received.');
    }

    public function ship(OrderService $orders): void
    {
        $this->validate(['courier' => ['nullable', 'string', 'max:60'], 'tracking' => ['nullable', 'string', 'max:80']]);

        if ($this->attempt(fn () => $orders->ship($this->order(), $this->courier, $this->tracking), 'Marked as shipped.')) {
            $this->closeModal('ship');
        }
    }

    public function deliver(OrderService $orders): void
    {
        $order = $this->order();
        $due = (float) ($order->sale?->due_amount ?? 0);

        if ($due > 0 && $this->collectNow && ! $this->collectInto) {
            $this->addError('collectInto', 'Choose where the cash goes.');

            return;
        }

        if ($this->attempt(fn () => $orders->deliver($order, $due > 0 && $this->collectNow ? $this->collectInto : null), 'Marked as delivered.')) {
            $this->closeModal('deliver');
        }
    }

    public function cancel(OrderService $orders): void
    {
        $this->validate(['cancelReason' => ['required', 'string', 'max:200']], [], ['cancelReason' => 'reason']);

        if ($this->attempt(fn () => $orders->cancel($this->order(), $this->cancelReason, $this->refundAccountId), 'Order cancelled.')) {
            $this->closeModal('cancel');
        }
    }

    public function with(): array
    {
        $order = $this->order();
        $context = tenancy();
        $branches = $context->branches()->whereIn('id', $context->allowedBranchIds())->values();
        $sale = $order->sale;
        $shopName = tenant()->name;

        return [
            'order' => $order,
            'branches' => $branches,
            'stock' => $this->stockMatrix($order),
            'accounts' => PaymentAccount::query()->active()->usableAt($order->branch_id ?? $this->branchId)->orderBy('account_name')->get(),
            'due' => (float) ($sale?->due_amount ?? 0),
            'paidOnSale' => (float) ($sale?->paid_amount ?? 0),
            'messages' => [
                'Confirm order' => "Assalam o Alaikum {$order->customer_name}, thank you for ordering from {$shopName}. Your order {$order->order_number} for ".money($order->total, decimals: 0).' is confirmed and will be dispatched soon.',
                'Ask to verify' => "Hi {$order->customer_name}, this is {$shopName}. Please confirm your order {$order->order_number} (".money($order->total, decimals: 0).") and delivery address: {$order->address}, {$order->city}.",
                'Shipped' => "Hi {$order->customer_name}, your order {$order->order_number} has been shipped".($order->courier ? " with {$order->courier}" : '').($order->tracking_number ? ", tracking number {$order->tracking_number}" : '').'.',
            ],
        ];
    }
}; ?>

<div>
    <x-page-header :title="'Order '.$order->order_number" :breadcrumbs="['Dashboard' => route('app.dashboard'), 'Online orders' => route('app.orders.index'), $order->order_number => null]">
        <x-slot:actions>
            <div class="dropdown">
                <button class="btn btn-light-success dropdown-toggle" data-bs-toggle="dropdown" type="button"><i class="ri-whatsapp-line me-1"></i>WhatsApp</button>
                <div class="dropdown-menu dropdown-menu-end">
                    @foreach ($messages as $label => $text)
                        <a class="dropdown-item" href="{{ $order->whatsappUrl($text) }}" target="_blank" rel="noopener">{{ $label }}</a>
                    @endforeach
                </div>
            </div>
            @if ($order->sale)
                <a href="{{ route('app.sales.show', $order->sale) }}" class="btn btn-light-primary" wire:navigate><i class="ri-file-list-3-line me-1"></i>Invoice {{ $order->sale->invoice_number }}</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3">
        <div class="col-xl-8">
            {{-- Status & next step --}}
            <div class="card">
                <div class="card-body">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                        <x-status-badge :status="$order->status" :label="$order->status_label" class="fs-6" />
                        <span class="text-muted small">Placed {{ $order->created_at->format('d M Y, h:i A') }} ({{ $order->created_at->diffForHumans() }})</span>
                    </div>

                    <div class="d-flex flex-wrap gap-4 small mb-3">
                        @foreach ($order->timeline() as $label => $at)
                            <div class="{{ $at ? '' : 'text-muted' }}"><i class="{{ $at ? 'ri-checkbox-circle-fill text-success' : 'ri-checkbox-blank-circle-line' }} me-1"></i>{{ $label }}@if ($at)<div class="text-muted ms-4">{{ $at->format('d M, h:i A') }}</div>@endif</div>
                        @endforeach
                    </div>

                    @if ($order->status === 'pending')
                        <div class="border rounded-3 p-3 bg-light-subtle">
                            <h6 class="mb-1">Confirm and choose the shipping branch</h6>
                            <p class="text-muted small mb-3">Confirming creates invoice{{ $order->isPrepaid() && $order->payment_status === 'paid' ? ' (paid)' : ' (due until paid)' }} in that branch and takes the items from its stock. Call or message the customer first if you need to verify the order.</p>
                            <div class="row g-2 align-items-end">
                                <div class="col-sm-6">
                                    <label class="form-label small">Ship from</label>
                                    <select wire:model.live="branchId" class="form-select @error('branchId') is-invalid @enderror">
                                        @foreach ($branches as $branch)
                                            @php($ok = $order->items->every(fn ($i) => ($stock[$i->product_variant_id][$branch->id] ?? 0) >= $i->quantity))
                                            <option value="{{ $branch->id }}">{{ $branch->name }} {{ $ok ? '(all items in stock)' : '(missing items)' }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-sm-6 d-flex gap-2">
                                    <button type="button" class="btn btn-primary flex-grow-1" wire:click="confirm" wire:loading.attr="disabled" wire:target="confirm"><i class="ri-check-double-line me-1"></i>Confirm order</button>
                                    <button type="button" class="btn btn-light-danger" x-on:click="$dispatch('open-modal', { name: 'cancel' })">Cancel</button>
                                </div>
                            </div>
                        </div>
                    @elseif (in_array($order->status, ['confirmed', 'shipped'], true))
                        <div class="d-flex flex-wrap gap-2">
                            @if ($order->status === 'confirmed')
                                <button type="button" class="btn btn-primary" x-on:click="$dispatch('open-modal', { name: 'ship' })"><i class="ri-truck-line me-1"></i>Mark shipped</button>
                            @endif
                            <button type="button" class="btn {{ $order->status === 'shipped' ? 'btn-primary' : 'btn-light-primary' }}" x-on:click="$dispatch('open-modal', { name: 'deliver' })"><i class="ri-home-smile-line me-1"></i>Mark delivered</button>
                            <button type="button" class="btn btn-light-danger ms-auto" x-on:click="$dispatch('open-modal', { name: 'cancel' })">{{ $order->status === 'shipped' ? 'Returned by courier' : 'Cancel order' }}</button>
                        </div>
                        @if ($order->courier || $order->tracking_number)
                            <p class="small text-muted mt-2 mb-0"><i class="ri-truck-line me-1"></i>{{ $order->courier ?: 'Courier' }} @if ($order->tracking_number), tracking {{ $order->tracking_number }}@endif</p>
                        @endif
                    @elseif ($order->status === 'cancelled')
                        <div class="alert alert-secondary mb-0"><i class="ri-close-circle-line me-1"></i>Cancelled {{ $order->cancelled_at?->format('d M Y, h:i A') }}. Reason: {{ $order->cancel_reason }}</div>
                    @else
                        <div class="alert alert-success mb-0"><i class="ri-checkbox-circle-line me-1"></i>Delivered {{ $order->delivered_at?->format('d M Y') }}. Returns and exchanges go through <a href="{{ route('app.returns.create', ['sale' => $order->sale_id]) }}" wire:navigate>Returns</a>.</div>
                    @endif
                </div>
            </div>

            {{-- Items with stock per branch --}}
            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">Items</h6></div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0 table-stack">
                        <thead class="table-light">
                            <tr>
                                <th>Product</th><th class="text-center">Qty</th><th class="text-end">Price</th><th class="text-end">Total</th>
                                @if ($order->status === 'pending')
                                    @foreach ($branches as $branch)<th class="text-center small" title="{{ $branch->name }} stock">{{ $branch->code }}</th>@endforeach
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($order->items as $item)
                                <tr>
                                    <td data-label="Product">
                                        <div class="fw-medium">{{ $item->product_name }}</div>
                                        <div class="small text-muted">{{ $item->variant_name }} <span class="font-monospace">{{ $item->variant?->sku }}</span></div>
                                    </td>
                                    <td data-label="Qty" class="text-md-center">{{ $item->quantity }}</td>
                                    <td data-label="Price" class="text-end tabular-nums">{{ money($item->unit_price) }}</td>
                                    <td data-label="Total" class="text-end tabular-nums fw-medium">{{ money($item->total) }}</td>
                                    @if ($order->status === 'pending')
                                        @foreach ($branches as $branch)
                                            @php($have = (int) ($stock[$item->product_variant_id][$branch->id] ?? 0))
                                            <td data-label="{{ $branch->code }}" class="text-md-center"><span class="badge {{ $have >= $item->quantity ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' }}">{{ $have }}</span></td>
                                        @endforeach
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="card-body border-top">
                    <div class="ms-auto" style="max-width: 320px">
                        <div class="d-flex justify-content-between small"><span class="text-muted">Subtotal</span><span>{{ money($order->subtotal) }}</span></div>
                        @if ((float) $order->discount > 0)<div class="d-flex justify-content-between small"><span class="text-muted">Discount {{ $order->coupon_code ? '('.$order->coupon_code.')' : '' }}</span><span class="text-success">-{{ money($order->discount) }}</span></div>@endif
                        @if ((float) $order->tax > 0)<div class="d-flex justify-content-between small"><span class="text-muted">Tax ({{ (float) $order->tax_rate }}%)</span><span>{{ money($order->tax) }}</span></div>@endif
                        <div class="d-flex justify-content-between small"><span class="text-muted">Delivery</span><span>{{ (float) $order->shipping_fee > 0 ? money($order->shipping_fee) : 'Free' }}</span></div>
                        <div class="d-flex justify-content-between fw-semibold fs-6 border-top mt-2 pt-2"><span>Total</span><span>{{ money($order->total) }}</span></div>
                        @if ($order->sale)
                            <div class="d-flex justify-content-between small mt-1"><span class="text-muted">Paid on invoice</span><span>{{ money($paidOnSale) }}</span></div>
                            <div class="d-flex justify-content-between small"><span class="text-muted">Due</span><span class="{{ $due > 0 ? 'text-danger fw-semibold' : '' }}">{{ money($due) }}</span></div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0"><i class="ri-user-line me-1"></i>Customer & delivery</h6></div>
                <div class="card-body">
                    <div class="fw-semibold">{{ $order->customer_name }}</div>
                    <div><a href="tel:{{ $order->phone }}">{{ $order->phone }}</a></div>
                    @if ($order->email)<div class="small"><a href="mailto:{{ $order->email }}">{{ $order->email }}</a></div>@endif
                    <hr>
                    <div class="small text-muted mb-1">Deliver to</div>
                    <div>{{ $order->address }}<br>{{ $order->city }}</div>
                    @if ($order->notes)<div class="alert alert-info small mt-3 mb-0"><i class="ri-sticky-note-line me-1"></i>{{ $order->notes }}</div>@endif
                    @if ($order->customer)
                        <a href="{{ route('app.customers.show', $order->customer) }}" class="btn btn-sm btn-light w-100 mt-3" wire:navigate>Customer account ({{ money($order->customer->current_balance) }} balance)</a>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0"><i class="ri-secure-payment-line me-1"></i>Payment</h6></div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span>{{ $order->payment_method_label }}</span>
                        <x-status-badge :status="$order->payment_status" :label="$order->payment_status_label" />
                    </div>
                    @if ($order->paymentAccount)
                        <div class="small text-muted">To {{ $order->paymentAccount->label }}</div>
                    @endif
                    @if ($order->payment_reference)<div class="small">Reference: <span class="font-monospace">{{ $order->payment_reference }}</span></div>@endif

                    @if ($order->payment_proof_url)
                        <div class="mt-3">
                            @if (\Illuminate\Support\Str::endsWith(strtolower($order->payment_proof), '.pdf'))
                                <a href="{{ $order->payment_proof_url }}" target="_blank" rel="noopener" class="btn btn-sm btn-light w-100"><i class="ri-file-pdf-line me-1"></i>Open receipt (PDF)</a>
                            @else
                                <a href="{{ $order->payment_proof_url }}" target="_blank" rel="noopener"><img src="{{ $order->payment_proof_url }}" alt="Payment receipt" class="img-fluid rounded border"></a>
                            @endif
                        </div>
                    @elseif ($order->isPrepaid() && $order->status !== 'cancelled')
                        <p class="small text-muted mt-2 mb-0">No receipt uploaded yet. The customer can upload it from their order page.</p>
                    @endif

                    @if ($order->isPrepaid() && in_array($order->payment_status, ['unpaid', 'awaiting'], true) && $order->status !== 'cancelled')
                        <button type="button" class="btn btn-success w-100 mt-3" wire:click="markPaid" wire:confirm="Confirm that {{ money($order->total) }} has arrived in {{ $order->paymentAccount?->account_name }}?"><i class="ri-check-line me-1"></i>Payment received</button>
                        <div class="form-text">{{ $order->sale ? 'Posts the payment to the invoice and the account.' : 'The money is posted to the account when you confirm the order.' }}</div>
                    @endif
                </div>
            </div>

            @if ($order->handler)
                <p class="small text-muted">Last handled by {{ $order->handler->name }}.</p>
            @endif
        </div>
    </div>

    <x-modal name="ship" title="Mark as shipped">
        <form wire:submit="ship" id="ship-form" class="row g-3">
            <div class="col-sm-6"><label class="form-label">Courier</label><input wire:model="courier" class="form-control" list="couriers" placeholder="TCS, Leopards, M&P…"><datalist id="couriers">@foreach (['TCS', 'Leopards', 'M&P', 'Trax', 'PostEx', 'Call Courier', 'BlueEx', 'Pakistan Post', 'Own rider'] as $c)<option value="{{ $c }}">@endforeach</datalist></div>
            <div class="col-sm-6"><label class="form-label">Tracking number</label><input wire:model="tracking" class="form-control font-monospace"></div>
            <div class="col-12 small text-muted">The customer sees the courier and tracking number on their order page.</div>
        </form>
        <x-slot:footer>
            <button type="button" class="btn btn-light" x-on:click="$dispatch('close-modal', { name: 'ship' })">Close</button>
            <button type="submit" form="ship-form" class="btn btn-primary">Mark shipped</button>
        </x-slot:footer>
    </x-modal>

    <x-modal name="deliver" title="Mark as delivered">
        @if ($due > 0)
            <p>{{ money($due) }} is still due on invoice {{ $order->sale?->invoice_number }}.</p>
            <div class="form-check form-switch mb-3">
                <input wire:model.live="collectNow" class="form-check-input" type="checkbox" id="collectNow">
                <label class="form-check-label" for="collectNow">Cash collected now</label>
            </div>
            @if ($collectNow)
                <label class="form-label">Deposit into</label>
                <select wire:model="collectInto" class="form-select @error('collectInto') is-invalid @enderror">
                    <option value="">Choose account…</option>
                    @foreach ($accounts as $account)<option value="{{ $account->id }}">{{ $account->label }}</option>@endforeach
                </select>
                @error('collectInto')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">Courier remitting later? Turn this off and record it from the customer's account when the money arrives.</div>
            @endif
        @else
            <p class="mb-0">The invoice is fully paid. This only updates the order status.</p>
        @endif
        <x-slot:footer>
            <button type="button" class="btn btn-light" x-on:click="$dispatch('close-modal', { name: 'deliver' })">Close</button>
            <button type="button" class="btn btn-primary" wire:click="deliver" wire:loading.attr="disabled">Mark delivered</button>
        </x-slot:footer>
    </x-modal>

    <x-modal name="cancel" :title="$order->status === 'shipped' ? 'Returned by courier' : 'Cancel order'">
        <form wire:submit="cancel" id="cancel-form" class="row g-3">
            <div class="col-12">
                <label class="form-label">Reason</label>
                <input wire:model="cancelReason" class="form-control @error('cancelReason') is-invalid @enderror" list="cancel-reasons" placeholder="Customer cancelled on call">
                <datalist id="cancel-reasons"><option value="Customer cancelled on call"><option value="Could not reach customer"><option value="Out of stock"><option value="Refused at delivery"><option value="Fake order"></datalist>
                @error('cancelReason')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            @if ($order->sale)
                <div class="col-12 small text-muted">The items go back into {{ $order->branch?->name }} stock and the invoice is cleared (delivery charge included).</div>
                @if ($paidOnSale > 0)
                    <div class="col-12">
                        <label class="form-label">Refund {{ money($paidOnSale) }} from</label>
                        <select wire:model="refundAccountId" class="form-select">
                            @foreach ($accounts as $account)<option value="{{ $account->id }}">{{ $account->label }} ({{ money($account->current_balance) }})</option>@endforeach
                        </select>
                    </div>
                @endif
            @else
                <div class="col-12 small text-muted">Nothing has been posted yet; the reserved items become available again.</div>
            @endif
        </form>
        <x-slot:footer>
            <button type="button" class="btn btn-light" x-on:click="$dispatch('close-modal', { name: 'cancel' })">Keep order</button>
            <button type="submit" form="cancel-form" class="btn btn-danger">{{ $order->status === 'shipped' ? 'Record return' : 'Cancel order' }}</button>
        </x-slot:footer>
    </x-modal>
</div>
