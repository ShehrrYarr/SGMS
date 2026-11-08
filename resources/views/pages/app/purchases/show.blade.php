<?php

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\PaymentAccount;
use App\Models\Purchase;
use App\Services\PurchaseService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Lot details')] class extends Component {
    use InteractsWithUi;

    #[Locked]
    public int $purchaseId;

    public ?int $account_id = null;

    public $amount = '';

    public string $reference = '';

    public string $payment_date = '';

    public function mount(Purchase $purchase): void
    {
        abort_unless(tenancy()->canAccessBranch($purchase->branch_id), 403);
        $this->purchaseId = $purchase->id;
        $this->payment_date = today()->toDateString();
    }

    public function openPayment(): void
    {
        $purchase = Purchase::query()->findOrFail($this->purchaseId);
        $this->amount = (float) $purchase->due_amount;
        $this->account_id = PaymentAccount::query()->active()->usableAt($purchase->branch_id)->value('id');
        $this->resetValidation();
        $this->openModal('pay-lot');
    }

    public function pay(PurchaseService $service): void
    {
        $this->validate([
            'account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:60'],
        ]);

        $ok = $this->attempt(fn () => $service->addPayment(Purchase::query()->findOrFail($this->purchaseId), (int) $this->account_id, (float) $this->amount, $this->payment_date, $this->reference ?: null), 'Payment recorded.');

        if ($ok) {
            $this->closeModal('pay-lot');
            $this->reset('reference');
        }
    }

    public function with(): array
    {
        $purchase = Purchase::query()->with([
            'vendor', 'branch', 'creator:id,name',
            'items.variant.product:id,name', 'items.variant.color:id,name,hex_code', 'items.variant.size:id,code',
            'payments.paymentAccount:id,account_name',
        ])->findOrFail($this->purchaseId);

        return [
            'purchase' => $purchase,
            'accounts' => PaymentAccount::query()->active()->usableAt($purchase->branch_id)->get(),
        ];
    }
}; ?>

<div>
    <x-page-header :title="'Lot '.$purchase->lot_number" :breadcrumbs="['Dashboard' => route('app.dashboard'), 'Lot purchases' => route('app.purchases.index'), $purchase->lot_number => null]">
        <x-slot:actions>
            <a href="{{ route('app.barcodes', ['purchase' => $purchase->id]) }}" class="btn btn-light-info" wire:navigate><i class="ri-barcode-line me-1"></i>Print labels</a>
            <a href="{{ route('app.purchases.pdf', $purchase) }}" class="btn btn-light-secondary"><i class="ri-file-pdf-2-line me-1"></i>PDF</a>
            @if ((float) $purchase->due_amount > 0)
                <button class="btn btn-success" wire:click="openPayment"><i class="ri-hand-coin-line me-1"></i>Pay vendor</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3"><x-stat-card label="Total lot cost" :value="money($purchase->total_lot_cost)" icon="ri-money-dollar-box-line" /></div>
        <div class="col-6 col-xl-3"><x-stat-card label="Paid" :value="money($purchase->paid_amount)" icon="ri-hand-coin-line" color="success" :hint="ucfirst($purchase->payment_method)" /></div>
        <div class="col-6 col-xl-3"><x-stat-card label="Due to vendor" :value="money($purchase->due_amount)" icon="ri-error-warning-line" :color="(float) $purchase->due_amount > 0 ? 'danger' : 'secondary'" /></div>
        <div class="col-6 col-xl-3"><x-stat-card label="Pieces" :value="number_format($purchase->total_garment_qty)" icon="ri-t-shirt-2-line" color="info" :hint="$purchase->total_garment_qty ? 'Avg '.money($purchase->total_lot_cost / $purchase->total_garment_qty).' / pc' : null" /></div>
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0">Garments received</h6><small class="text-muted">{{ $purchase->lot_name }}</small></div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 table-stack">
                        <thead class="table-light"><tr><th>Item</th><th>Barcode</th><th class="text-center">Qty</th><th class="text-end">Cost</th><th class="text-end">Retail</th><th class="text-end">Total</th></tr></thead>
                        <tbody>
                            @foreach ($purchase->items as $item)
                                <tr>
                                    <td data-label="Item">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="color-dot" style="background: {{ $item->variant->color?->hex_code ?? '#e5e7eb' }}"></span>
                                            <div><div class="fw-semibold">{{ $item->variant->product->name }}</div><small class="text-muted">{{ $item->variant->variant_name }} · {{ $item->variant->sku }}</small></div>
                                        </div>
                                    </td>
                                    <td data-label="Barcode" class="font-monospace small">{{ $item->variant->barcode }}</td>
                                    <td data-label="Qty" class="text-md-center">{{ $item->quantity }}</td>
                                    <td data-label="Cost" class="text-end tabular-nums">{{ money($item->cost_per_item) }}</td>
                                    <td data-label="Retail" class="text-end tabular-nums">{{ money($item->retail_price_set) }}</td>
                                    <td data-label="Total" class="text-end fw-semibold tabular-nums">{{ money($item->total_cost) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="table-light"><tr><td colspan="2" class="fw-semibold">Total</td><td class="text-center fw-semibold">{{ $purchase->items->sum('quantity') }}</td><td colspan="2"></td><td class="text-end fw-semibold">{{ money($purchase->items->sum('total_cost')) }}</td></tr></tfoot>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card mb-3">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <span class="stat-icon bg-primary-subtle text-primary"><i class="ri-truck-line"></i></span>
                        <div class="min-w-0">
                            <a href="{{ route('app.vendors.show', $purchase->vendor) }}" class="fw-semibold" wire:navigate>{{ $purchase->vendor->name }}</a>
                            <div class="small text-muted">{{ $purchase->vendor->company }}</div>
                        </div>
                    </div>
                    <div class="small vstack gap-1">
                        <div class="d-flex justify-content-between"><span class="text-muted">Date</span><span>{{ $purchase->purchase_date->format('d M Y') }}</span></div>
                        <div class="d-flex justify-content-between"><span class="text-muted">Branch</span><span>{{ $purchase->branch->name }}</span></div>
                        <div class="d-flex justify-content-between"><span class="text-muted">Recorded by</span><span>{{ $purchase->creator?->name ?? '—' }}</span></div>
                        <div class="d-flex justify-content-between"><span class="text-muted">Status</span><x-status-badge :status="$purchase->payment_status" /></div>
                        <div class="d-flex justify-content-between"><span class="text-muted">Vendor balance</span><b>{{ money($purchase->vendor->current_balance) }}</b></div>
                    </div>
                    @if ($purchase->notes)<p class="small text-muted mt-3 mb-0">{{ $purchase->notes }}</p>@endif
                </div>
            </div>
            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0">Payments</h6></div>
                <ul class="list-group list-group-flush">
                    @forelse ($purchase->payments as $payment)
                        <li class="list-group-item d-flex justify-content-between">
                            <div><div class="fw-medium">{{ $payment->paymentAccount->account_name }}</div><small class="text-muted">{{ $payment->payment_date->format('d M Y') }}@if ($payment->reference_no) · {{ $payment->reference_no }}@endif</small></div>
                            <b class="tabular-nums">{{ money($payment->amount) }}</b>
                        </li>
                    @empty
                        <li class="list-group-item small text-muted">Full credit — nothing paid yet.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>

    <x-modal name="pay-lot" title="Pay vendor for this lot">
        <form wire:submit="pay" id="pay-lot-form" class="vstack gap-3">
            <div class="alert alert-light border mb-0 small">Outstanding on {{ $purchase->lot_number }}: <b>{{ money($purchase->due_amount) }}</b></div>
            <div>
                <label class="form-label">Pay from</label>
                <select wire:model="account_id" class="form-select @error('account_id') is-invalid @enderror">
                    @foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->account_name }} — {{ money($a->current_balance) }}</option>@endforeach
                </select>
            </div>
            <div class="row g-2">
                <div class="col-6"><label class="form-label">Amount</label><input wire:model="amount" type="number" step="0.01" class="form-control @error('amount') is-invalid @enderror"></div>
                <div class="col-6"><label class="form-label">Date</label><input wire:model="payment_date" type="date" class="form-control"></div>
            </div>
            <div><label class="form-label">Reference</label><input wire:model="reference" class="form-control" placeholder="Cheque / transaction ID"></div>
        </form>
        <x-slot:footer>
            <button type="button" class="btn btn-light" x-on:click="$dispatch('close-modal', { name: 'pay-lot' })">Cancel</button>
            <button type="submit" form="pay-lot-form" class="btn btn-success"><i class="ri-check-line me-1"></i>Record payment</button>
        </x-slot:footer>
    </x-modal>
</div>
