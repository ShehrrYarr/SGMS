<?php

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Customer;
use App\Models\PaymentAccount;
use App\Services\LedgerService;
use App\Services\SaleService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Customer Account')] class extends Component {
    use InteractsWithUi, WithPagination;

    #[Locked]
    public int $customerId;

    #[Url(except: 'ledger')]
    public string $tab = 'ledger';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public ?int $account_id = null;

    public $amount = '';

    public string $date = '';

    public string $reference = '';

    public string $note = '';

    public string $adj_direction = 'credit';

    public $adj_amount = '';

    public string $adj_note = '';

    public function mount(Customer $customer): void
    {
        $this->customerId = $customer->id;
        $this->date = today()->toDateString();

        if (request()->boolean('receive') && auth('web')->user()->canManage()) {
            $this->openReceive();
        }
    }

    public function updating($name): void
    {
        if (in_array($name, ['from', 'to'], true)) {
            $this->resetPage('ledger-page');
        }
    }

    #[On('customer-saved')]
    public function refreshCustomer(): void
    {
    }

    public function openReceive(): void
    {
        $customer = Customer::query()->findOrFail($this->customerId);
        $this->amount = max(0, (float) $customer->current_balance) ?: '';
        $this->account_id = PaymentAccount::query()->active()->usableAt(tenancy()->operationalBranchId())->value('id');
        $this->resetValidation();
        $this->openModal('receive-payment');
    }

    public function receive(SaleService $sales): void
    {
        $this->validate([
            'account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $usable = PaymentAccount::query()->active()->usableAt(tenancy()->operationalBranchId())->pluck('id')->all();
        abort_unless(in_array((int) $this->account_id, $usable, true), 403);

        $ok = $this->attempt(fn () => $sales->receivePayment(Customer::query()->findOrFail($this->customerId), (int) $this->account_id, (float) $this->amount, $this->date, $this->reference ?: null, $this->note ?: null), 'Payment received and allocated to open invoices.');

        if ($ok) {
            $this->closeModal('receive-payment');
            $this->reset('amount', 'reference', 'note');
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
        $ledger->postCustomer($this->customerId, 'adjustment', $this->adj_direction === 'debit' ? $amount : 0, $this->adj_direction === 'credit' ? $amount : 0, ['note' => $this->adj_note]);

        $this->closeModal('customer-adjust');
        $this->reset('adj_amount', 'adj_note');
        $this->toast('Adjustment posted.');
    }

    public function with(): array
    {
        $customer = Customer::query()->withCount('sales')->withSum('sales as spent', 'total_amount')->findOrFail($this->customerId);

        return [
            'customer' => $customer,
            'entries' => $this->tab === 'ledger'
                ? $customer->ledgers()->with('sale:id,invoice_number', 'paymentAccount:id,account_name')
                    ->when($this->from, fn ($q) => $q->whereDate('transaction_date', '>=', $this->from))
                    ->when($this->to, fn ($q) => $q->whereDate('transaction_date', '<=', $this->to))
                    ->orderByDesc('id')->paginate(20, pageName: 'ledger-page')
                : null,
            'sales' => $this->tab === 'invoices' ? $customer->sales()->with('branch:id,code')->latest('sale_date')->paginate(15, pageName: 'sales-page') : null,
            'accounts' => PaymentAccount::query()->active()->usableAt(tenancy()->operationalBranchId())->get(),
        ];
    }
}; ?>

<div>
    <x-page-header :title="$customer->name" :breadcrumbs="['Dashboard' => route('app.dashboard'), 'Customers' => route('app.customers.index'), $customer->name => null]">
        <x-slot:actions>
            <button class="btn btn-light-secondary" x-on:click="$dispatch('customer-form', { id: {{ $customer->id }} })"><i class="ri-pencil-line me-1"></i>Edit</button>
            <a href="{{ route('app.customers.statement', array_filter(['customer' => $customer->id, 'from' => $from, 'to' => $to])) }}" class="btn btn-light-primary"><i class="ri-file-pdf-2-line me-1"></i>Statement</a>
            @if (auth('web')->user()->isOwner())
                <button class="btn btn-light-warning" x-on:click="$dispatch('open-modal', { name: 'customer-adjust' })"><i class="ri-scales-3-line me-1"></i>Adjust</button>
            @endif
            @if (auth('web')->user()->canManage())
                <button class="btn btn-success" wire:click="openReceive"><i class="ri-hand-coin-line me-1"></i>Receive payment</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3"><x-stat-card :label="(float) $customer->current_balance < 0 ? 'Advance balance' : 'Balance due'" :value="money(abs((float) $customer->current_balance))" icon="ri-scales-3-line" :color="(float) $customer->current_balance > 0 ? 'danger' : 'success'" /></div>
        <div class="col-6 col-xl-3"><x-stat-card label="Total purchases" :value="money($customer->spent)" icon="ri-shopping-bag-3-line" :hint="$customer->sales_count.' invoices'" /></div>
        <div class="col-6 col-xl-3">
            <div class="card card-h-100 mb-0"><div class="card-body p-4 small">
                <div><i class="ri-phone-line me-1 text-muted"></i>{{ $customer->phone }}</div>
                <div class="text-truncate"><i class="ri-mail-line me-1 text-muted"></i>{{ $customer->email ?? '—' }}</div>
                <div class="text-truncate"><i class="ri-map-pin-line me-1 text-muted"></i>{{ $customer->address ?? '—' }}</div>
            </div></div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card card-h-100 mb-0"><div class="card-body p-4 small">
                <div class="fw-semibold mb-1"><i class="ri-shield-user-line me-1"></i>Customer portal</div>
                @if ($customer->hasPortalAccess())
                    <span class="badge bg-success-subtle text-success mb-1">Enabled</span>
                    <div class="text-muted">Last login: {{ $customer->last_login_at?->diffForHumans() ?? 'never' }}</div>
                @else
                    <span class="badge bg-light text-muted">Not enabled</span>
                    <div class="text-muted">Edit the customer to set a password.</div>
                @endif
            </div></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex flex-wrap gap-2 align-items-center">
            <ul class="nav nav-pills nav-tabs-scroll me-auto">
                <li class="nav-item"><button class="nav-link {{ $tab === 'ledger' ? 'active' : '' }}" wire:click="$set('tab', 'ledger')"><i class="ri-book-open-line me-1"></i>Statement</button></li>
                <li class="nav-item"><button class="nav-link {{ $tab === 'invoices' ? 'active' : '' }}" wire:click="$set('tab', 'invoices')"><i class="ri-file-list-3-line me-1"></i>Invoices</button></li>
            </ul>
            @if ($tab === 'ledger')
                <div class="d-flex gap-2">
                    <input type="date" wire:model.live="from" class="form-control form-control-sm">
                    <input type="date" wire:model.live="to" class="form-control form-control-sm">
                </div>
            @endif
        </div>

        @if ($tab === 'ledger')
            <div class="table-responsive">
                <table class="table table-hover mb-0 table-stack">
                    <thead class="table-light"><tr><th>Date</th><th>Type</th><th>Reference</th><th>Details</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Balance</th></tr></thead>
                    <tbody>
                        @forelse ($entries as $entry)
                            <tr>
                                <td data-label="Date">{{ $entry->transaction_date->format('d M Y') }}</td>
                                <td data-label="Type"><span class="badge {{ $entry->debit > 0 ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success' }}">{{ $entry->type_label }}</span></td>
                                <td data-label="Reference">@if ($entry->sale)<a href="{{ route('app.sales.show', $entry->sale_id) }}" wire:navigate>{{ $entry->sale->invoice_number }}</a>@else{{ $entry->reference_no ?? '—' }}@endif</td>
                                <td data-label="Details" class="small">{{ $entry->note }}</td>
                                <td data-label="Debit" class="text-end tabular-nums">{{ (float) $entry->debit ? money($entry->debit) : '' }}</td>
                                <td data-label="Credit" class="text-end tabular-nums">{{ (float) $entry->credit ? money($entry->credit) : '' }}</td>
                                <td data-label="Balance" class="text-end fw-semibold tabular-nums">{{ money($entry->running_balance) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7"><x-empty-state icon="ri-book-open-line" title="No account activity" text="Only registered-customer sales and payments appear here." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($entries->hasPages())<div class="card-footer">{{ $entries->links() }}</div>@endif
        @else
            <div class="table-responsive">
                <table class="table table-hover mb-0 table-stack">
                    <thead class="table-light"><tr><th>Invoice</th><th>Date</th><th>Branch</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Due</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse ($sales as $sale)
                            <tr>
                                <td data-label="Invoice"><a href="{{ route('app.sales.show', $sale) }}" class="fw-semibold" wire:navigate>{{ $sale->invoice_number }}</a></td>
                                <td data-label="Date">{{ $sale->sale_date->format('d M Y') }}</td>
                                <td data-label="Branch">{{ $sale->branch->code }}</td>
                                <td data-label="Total" class="text-end tabular-nums">{{ money($sale->total_amount) }}</td>
                                <td data-label="Paid" class="text-end tabular-nums">{{ money($sale->paid_amount) }}</td>
                                <td data-label="Due" class="text-end tabular-nums fw-semibold">{{ money($sale->due_amount) }}</td>
                                <td data-label="Status"><x-status-badge :status="$sale->payment_status" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="7"><x-empty-state icon="ri-file-list-3-line" title="No invoices yet" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($sales?->hasPages())<div class="card-footer">{{ $sales->links() }}</div>@endif
        @endif
    </div>

    <x-modal name="receive-payment" title="Receive payment">
        <form wire:submit="receive" id="receive-form" class="vstack gap-3">
            <div class="alert alert-light border small mb-0">Current balance: <b>{{ money($customer->current_balance) }}</b>. Payments clear the oldest unpaid invoices first.</div>
            <div>
                <label class="form-label">Receive into</label>
                <select wire:model="account_id" class="form-select @error('account_id') is-invalid @enderror">
                    <option value="">Select account…</option>
                    @foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->account_name }}</option>@endforeach
                </select>
            </div>
            <div class="row g-2">
                <div class="col-6"><label class="form-label">Amount</label><input wire:model="amount" type="number" step="0.01" class="form-control @error('amount') is-invalid @enderror"></div>
                <div class="col-6"><label class="form-label">Date</label><input wire:model="date" type="date" class="form-control"></div>
            </div>
            <div><label class="form-label">Reference</label><input wire:model="reference" class="form-control" placeholder="Receipt / transfer ID"></div>
            <div><label class="form-label">Note</label><input wire:model="note" class="form-control"></div>
        </form>
        <x-slot:footer>
            <button type="button" class="btn btn-light" x-on:click="$dispatch('close-modal', { name: 'receive-payment' })">Cancel</button>
            <button type="submit" form="receive-form" class="btn btn-success"><i class="ri-check-line me-1"></i>Receive</button>
        </x-slot:footer>
    </x-modal>

    <x-modal name="customer-adjust" title="Account adjustment">
        <form wire:submit="adjust" id="cadj-form" class="vstack gap-3">
            <div class="btn-group w-100">
                <input type="radio" class="btn-check" id="cadj-c" value="credit" wire:model="adj_direction"><label class="btn btn-outline-success" for="cadj-c">Reduce balance (waive)</label>
                <input type="radio" class="btn-check" id="cadj-d" value="debit" wire:model="adj_direction"><label class="btn btn-outline-danger" for="cadj-d">Increase balance</label>
            </div>
            <div><label class="form-label">Amount</label><input wire:model="adj_amount" type="number" step="0.01" class="form-control @error('adj_amount') is-invalid @enderror"></div>
            <div><label class="form-label">Reason</label><input wire:model="adj_note" class="form-control @error('adj_note') is-invalid @enderror"></div>
        </form>
        <x-slot:footer>
            <button type="button" class="btn btn-light" x-on:click="$dispatch('close-modal', { name: 'customer-adjust' })">Cancel</button>
            <button type="submit" form="cadj-form" class="btn btn-warning">Post adjustment</button>
        </x-slot:footer>
    </x-modal>

    <livewire:customers.form />
</div>
