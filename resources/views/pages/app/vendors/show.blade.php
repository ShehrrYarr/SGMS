<?php

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\PaymentAccount;
use App\Models\Vendor;
use App\Services\LedgerService;
use App\Services\PurchaseService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Vendor Ledger')] class extends Component {
    use InteractsWithUi, WithPagination;

    #[Locked]
    public int $vendorId;

    #[Url(except: 'ledger')]
    public string $tab = 'ledger';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    // Pay vendor
    public ?int $account_id = null;

    public $amount = '';

    public string $date = '';

    public string $reference = '';

    public string $note = '';

    // Adjustment
    public string $adj_direction = 'debit';

    public $adj_amount = '';

    public string $adj_note = '';

    public function mount(Vendor $vendor): void
    {
        $this->vendorId = $vendor->id;
        $this->date = today()->toDateString();
    }

    public function updating($name): void
    {
        if (in_array($name, ['from', 'to'], true)) {
            $this->resetPage();
        }
    }

    #[On('vendor-saved')]
    public function refreshVendor(): void
    {
    }

    public function openPay(): void
    {
        $vendor = Vendor::query()->findOrFail($this->vendorId);
        $this->amount = max(0, (float) $vendor->current_balance) ?: '';
        $this->account_id = PaymentAccount::query()->active()->usableAt(tenancy()->operationalBranchId())->value('id');
        $this->resetValidation();
        $this->openModal('pay-vendor');
    }

    public function pay(PurchaseService $service): void
    {
        $this->validate([
            'account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $entry = $this->attempt(fn () => $service->payVendor(Vendor::query()->findOrFail($this->vendorId), (int) $this->account_id, (float) $this->amount, $this->date, $this->reference ?: null, $this->note ?: null));

        if ($entry) {
            $this->closeModal('pay-vendor');
            $this->reset('amount', 'reference', 'note');
            $this->dispatch('vendor-paid', url: route('app.vendors.receipt.pdf', ['vendor' => $this->vendorId, 'entry' => $entry->id]));
        }
    }

    public function adjust(LedgerService $ledger): void
    {
        abort_unless(auth('web')->user()->isOwner(), 403);
        $this->validate([
            'adj_direction' => ['required', 'in:debit,credit'],
            'adj_amount' => ['required', 'numeric', 'gt:0'],
            'adj_note' => ['required', 'string', 'max:255'],
        ]);

        $amount = (float) $this->adj_amount;
        $ledger->postVendor($this->vendorId, 'adjustment', $this->adj_direction === 'debit' ? $amount : 0, $this->adj_direction === 'credit' ? $amount : 0, ['note' => $this->adj_note]);

        $this->closeModal('vendor-adjust');
        $this->reset('adj_amount', 'adj_note');
        $this->toast('Adjustment posted.');
    }

    public function with(): array
    {
        $vendor = Vendor::query()->withSum('purchases as purchased', 'total_lot_cost')->withSum('purchases as paid', 'paid_amount')->withCount('purchases')->findOrFail($this->vendorId);

        $entries = $vendor->ledgers()->with('paymentAccount:id,account_name', 'purchase:id,lot_number')
            ->when($this->from, fn ($q) => $q->whereDate('transaction_date', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('transaction_date', '<=', $this->to))
            ->orderByDesc('id')->paginate(20, pageName: 'ledger-page');

        return [
            'vendor' => $vendor,
            'entries' => $entries,
            'purchases' => $this->tab === 'lots' ? $vendor->purchases()->with('branch:id,code')->latest('purchase_date')->paginate(15, pageName: 'lots-page') : null,
            'accounts' => PaymentAccount::query()->active()->usableAt(tenancy()->operationalBranchId())->get(),
        ];
    }
}; ?>

<div>
    <x-page-header :title="$vendor->name" :breadcrumbs="['Dashboard' => route('app.dashboard'), 'Vendors' => route('app.vendors.index'), $vendor->name => null]">
        <x-slot:actions>
            <button class="btn btn-light-secondary" x-on:click="$dispatch('vendor-form', { id: {{ $vendor->id }} })"><i class="ri-pencil-line me-1"></i>Edit</button>
            <a href="{{ route('app.vendors.ledger.pdf', array_filter(['vendor' => $vendor->id, 'from' => $from, 'to' => $to])) }}" class="btn btn-light-primary"><i class="ri-file-pdf-2-line me-1"></i>Ledger PDF</a>
            @if (auth('web')->user()->isOwner())
                <button class="btn btn-light-warning" x-on:click="$dispatch('open-modal', { name: 'vendor-adjust' })"><i class="ri-scales-3-line me-1"></i>Adjust</button>
            @endif
            <a href="{{ route('app.purchases.create', ['vendor' => $vendor->id]) }}" class="btn btn-light-info" wire:navigate><i class="ri-inbox-archive-line me-1"></i>New lot</a>
            <button class="btn btn-success" wire:click="openPay"><i class="ri-hand-coin-line me-1"></i>Pay vendor</button>
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3"><x-stat-card label="Current payable" :value="money($vendor->current_balance)" icon="ri-scales-3-line" :color="(float) $vendor->current_balance > 0 ? 'danger' : 'success'" :hint="(float) $vendor->current_balance < 0 ? 'Advance with vendor' : null" /></div>
        <div class="col-6 col-xl-3"><x-stat-card label="Total purchased" :value="money($vendor->purchased)" icon="ri-inbox-archive-line" :hint="$vendor->purchases_count.' lots'" /></div>
        <div class="col-6 col-xl-3"><x-stat-card label="Paid on lots" :value="money($vendor->paid)" icon="ri-hand-coin-line" color="success" /></div>
        <div class="col-6 col-xl-3">
            <div class="card card-h-100 mb-0"><div class="card-body p-4 small">
                <div class="fw-semibold mb-1">{{ $vendor->company ?? 'Vendor' }}</div>
                <div class="text-muted"><i class="ri-phone-line me-1"></i>{{ $vendor->phone ?? '—' }}</div>
                <div class="text-muted text-truncate"><i class="ri-map-pin-line me-1"></i>{{ $vendor->address ?? '—' }}</div>
            </div></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex flex-wrap gap-2 align-items-center">
            <ul class="nav nav-pills nav-tabs-scroll me-auto">
                <li class="nav-item"><button class="nav-link {{ $tab === 'ledger' ? 'active' : '' }}" wire:click="$set('tab', 'ledger')"><i class="ri-book-open-line me-1"></i>Ledger</button></li>
                <li class="nav-item"><button class="nav-link {{ $tab === 'lots' ? 'active' : '' }}" wire:click="$set('tab', 'lots')"><i class="ri-inbox-archive-line me-1"></i>Lots</button></li>
            </ul>
            @if ($tab === 'ledger')
                <div class="d-flex gap-2">
                    <input type="date" wire:model.live="from" class="form-control form-control-sm" title="From">
                    <input type="date" wire:model.live="to" class="form-control form-control-sm" title="To">
                </div>
            @endif
        </div>

        @if ($tab === 'ledger')
            <div class="table-responsive">
                <table class="table table-hover mb-0 table-stack">
                    <thead class="table-light"><tr><th>Date</th><th>Type</th><th>Reference</th><th>Details</th><th class="text-end">Debit (paid)</th><th class="text-end">Credit (owed)</th><th class="text-end">Balance</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($entries as $entry)
                            <tr>
                                <td data-label="Date">{{ $entry->transaction_date->format('d M Y') }}</td>
                                <td data-label="Type"><span class="badge {{ $entry->type === 'payment' ? 'bg-success-subtle text-success' : ($entry->type === 'purchase' ? 'bg-primary-subtle text-primary' : 'bg-secondary-subtle text-secondary') }}">{{ $entry->type_label }}</span></td>
                                <td data-label="Reference">
                                    @if ($entry->purchase)<a href="{{ route('app.purchases.show', $entry->purchase_id) }}" wire:navigate>{{ $entry->purchase->lot_number }}</a>@else{{ $entry->reference_no ?? '—' }}@endif
                                </td>
                                <td data-label="Details" class="small">{{ $entry->note }}@if ($entry->paymentAccount)<div class="text-muted">{{ $entry->paymentAccount->account_name }}</div>@endif</td>
                                <td data-label="Debit" class="text-end tabular-nums text-success">{{ (float) $entry->debit ? money($entry->debit) : '' }}</td>
                                <td data-label="Credit" class="text-end tabular-nums text-danger">{{ (float) $entry->credit ? money($entry->credit) : '' }}</td>
                                <td data-label="Balance" class="text-end fw-semibold tabular-nums">{{ money($entry->running_balance) }}</td>
                                <td class="text-end td-actions" data-label="">
                                    @if ($entry->type === 'payment')
                                        <a href="{{ route('app.vendors.receipt.pdf', ['vendor' => $vendor->id, 'entry' => $entry->id]) }}" class="btn btn-sm btn-light-secondary" title="Payment voucher PDF"><i class="ri-file-download-line"></i></a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8"><x-empty-state icon="ri-book-open-line" title="No ledger entries" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($entries->hasPages())<div class="card-footer">{{ $entries->links() }}</div>@endif
        @else
            <div class="table-responsive">
                <table class="table table-hover mb-0 table-stack">
                    <thead class="table-light"><tr><th>Lot</th><th>Date</th><th>Branch</th><th class="text-center">Pcs</th><th class="text-end">Cost</th><th class="text-end">Paid</th><th class="text-end">Due</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse ($purchases as $p)
                            <tr>
                                <td data-label="Lot"><a href="{{ route('app.purchases.show', $p) }}" class="fw-semibold" wire:navigate>{{ $p->lot_number }}</a><div class="small text-muted">{{ $p->lot_name }}</div></td>
                                <td data-label="Date">{{ $p->purchase_date->format('d M Y') }}</td>
                                <td data-label="Branch">{{ $p->branch->code }}</td>
                                <td data-label="Pcs" class="text-md-center">{{ $p->total_garment_qty }}</td>
                                <td data-label="Cost" class="text-end tabular-nums">{{ money($p->total_lot_cost) }}</td>
                                <td data-label="Paid" class="text-end tabular-nums">{{ money($p->paid_amount) }}</td>
                                <td data-label="Due" class="text-end tabular-nums fw-semibold">{{ money($p->due_amount) }}</td>
                                <td data-label="Status"><x-status-badge :status="$p->payment_status" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="8"><x-empty-state icon="ri-inbox-archive-line" title="No lots from this vendor" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($purchases?->hasPages())<div class="card-footer">{{ $purchases->links() }}</div>@endif
        @endif
    </div>

    <x-modal name="pay-vendor" title="Pay vendor">
        <form wire:submit="pay" id="pay-vendor-form" class="vstack gap-3">
            <div class="alert alert-light border small mb-0">Payments are allocated to the oldest unpaid lots first. Any extra is kept as an advance.</div>
            <div>
                <label class="form-label">Pay from</label>
                <select wire:model="account_id" class="form-select @error('account_id') is-invalid @enderror">
                    <option value="">Select account…</option>
                    @foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->account_name }} — {{ money($a->current_balance) }}</option>@endforeach
                </select>
            </div>
            <div class="row g-2">
                <div class="col-6"><label class="form-label">Amount</label><input wire:model="amount" type="number" step="0.01" class="form-control @error('amount') is-invalid @enderror">@error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="col-6"><label class="form-label">Date</label><input wire:model="date" type="date" class="form-control"></div>
            </div>
            <div><label class="form-label">Reference</label><input wire:model="reference" class="form-control" placeholder="Cheque no. / bank transfer ID"></div>
            <div><label class="form-label">Note</label><input wire:model="note" class="form-control"></div>
        </form>
        <x-slot:footer>
            <button type="button" class="btn btn-light" x-on:click="$dispatch('close-modal', { name: 'pay-vendor' })">Cancel</button>
            <button type="submit" form="pay-vendor-form" class="btn btn-success" wire:loading.attr="disabled"><i class="ri-check-line me-1"></i>Record payment</button>
        </x-slot:footer>
    </x-modal>

    <x-modal name="vendor-adjust" title="Ledger adjustment">
        <form wire:submit="adjust" id="adj-form" class="vstack gap-3">
            <div class="btn-group w-100">
                <input type="radio" class="btn-check" id="adj-d" value="debit" wire:model="adj_direction"><label class="btn btn-outline-success" for="adj-d">Reduce payable (discount)</label>
                <input type="radio" class="btn-check" id="adj-c" value="credit" wire:model="adj_direction"><label class="btn btn-outline-danger" for="adj-c">Increase payable</label>
            </div>
            <div><label class="form-label">Amount</label><input wire:model="adj_amount" type="number" step="0.01" class="form-control @error('adj_amount') is-invalid @enderror"></div>
            <div><label class="form-label">Reason</label><input wire:model="adj_note" class="form-control @error('adj_note') is-invalid @enderror" placeholder="e.g. Damaged pieces returned to vendor">@error('adj_note')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        </form>
        <x-slot:footer>
            <button type="button" class="btn btn-light" x-on:click="$dispatch('close-modal', { name: 'vendor-adjust' })">Cancel</button>
            <button type="submit" form="adj-form" class="btn btn-warning">Post adjustment</button>
        </x-slot:footer>
    </x-modal>

    <livewire:vendors.quick-create />
</div>

@script
<script>
    $wire.on('vendor-paid', ({ url }) => {
        Swal.fire({ icon: 'success', title: 'Payment recorded', text: 'Download the payment voucher?', showCancelButton: true, confirmButtonText: 'Download PDF', cancelButtonText: 'Later' })
            .then((r) => { if (r.isConfirmed) window.location.href = url; });
    });
</script>
@endscript
