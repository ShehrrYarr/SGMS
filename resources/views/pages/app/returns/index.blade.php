<?php

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\PaymentAccount;
use App\Models\SaleReturn;
use App\Services\SaleReturnService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Returns & Exchanges')] class extends Component {
    use InteractsWithUi, WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    public ?int $settleId = null;

    public string $settleMethod = 'cash';

    public ?int $settleAccount = null;

    public function updating($name): void
    {
        if (in_array($name, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function startSettle(int $id): void
    {
        $return = SaleReturn::query()->inBranchScope()->findOrFail($id);
        $this->settleId = $return->id;
        $this->settleMethod = 'cash';
        $this->settleAccount = PaymentAccount::query()->where('branch_id', $return->branch_id)->where('account_type', PaymentAccount::TYPE_CASH)->value('id');
        $this->openModal('settle-exchange');
    }

    public function settle(SaleReturnService $service): void
    {
        $return = SaleReturn::query()->inBranchScope()->findOrFail($this->settleId);

        if ($this->attempt(fn () => $service->settlePendingExchange($return, $this->settleMethod, $this->settleAccount), 'Exchange credit settled.')) {
            $this->closeModal('settle-exchange');
        }
    }

    public function with(): array
    {
        $returns = SaleReturn::query()->inBranchScope()
            ->with('sale:id,invoice_number', 'customer:id,name', 'branch:id,code')
            ->withSum('items as qty', 'quantity')
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->where('return_number', 'like', "%{$this->search}%")
                ->orWhereHas('sale', fn ($s) => $s->where('invoice_number', 'like', "%{$this->search}%"))))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->latest('return_date');

        $settling = $this->settleId ? SaleReturn::query()->find($this->settleId) : null;

        return [
            'returns' => $returns->paginate(15),
            'settling' => $settling,
            'accounts' => $settling ? PaymentAccount::query()->active()->usableAt($settling->branch_id)->get() : collect(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Returns & exchanges" :breadcrumbs="['Dashboard' => route('app.dashboard'), 'Returns' => null]">
        <x-slot:actions>
            <a href="{{ route('app.returns.create') }}" class="btn btn-warning" wire:navigate><i class="ri-arrow-go-back-line me-1"></i>New return</a>
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <div class="card-header">
            <div class="row g-2 filter-bar">
                <div class="col-md-6 col-lg-4"><x-search-input wire:model.live.debounce.300ms="search" placeholder="Return or invoice number…" /></div>
                <div class="col-6 col-md-3 col-lg-2">
                    <select wire:model.live="status" class="form-select"><option value="">All</option><option value="completed">Completed</option><option value="pending_exchange">Pending exchange</option></select>
                </div>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 table-stack">
                <thead class="table-light"><tr><th>Return</th><th>Date</th><th>Invoice</th><th>Customer</th><th class="text-center">Pcs</th><th>Method</th><th class="text-end">Value</th><th class="text-end">Refunded</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($returns as $r)
                        <tr wire:key="r-{{ $r->id }}">
                            <td data-label="Return" class="fw-semibold">{{ $r->return_number }}</td>
                            <td data-label="Date" class="small">{{ $r->return_date->format('d M Y h:i A') }}</td>
                            <td data-label="Invoice"><a href="{{ route('app.sales.show', $r->sale_id) }}" wire:navigate>{{ $r->sale->invoice_number }}</a></td>
                            <td data-label="Customer">{{ $r->customer?->name ?? 'Walk-in' }}</td>
                            <td data-label="Pcs" class="text-md-center">{{ (int) $r->qty }}</td>
                            <td data-label="Method">{{ \App\Models\SaleReturn::METHODS[$r->refund_method] ?? $r->refund_method }}</td>
                            <td data-label="Value" class="text-end tabular-nums">{{ money($r->total_amount) }}</td>
                            <td data-label="Refunded" class="text-end tabular-nums">{{ money($r->refunded_amount) }}</td>
                            <td data-label="Status"><x-status-badge :status="$r->status" /></td>
                            <td class="text-end td-actions text-nowrap" data-label="">
                                @if ($r->isPendingExchange())
                                    <a href="{{ route('app.pos', ['exchange' => $r->id]) }}" class="btn btn-sm btn-info"><i class="ri-shopping-cart-2-line me-1"></i>Use in POS</a>
                                    <button class="btn btn-sm btn-light-warning" wire:click="startSettle({{ $r->id }})">Refund instead</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10"><x-empty-state icon="ri-arrow-go-back-line" title="No returns yet" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($returns->hasPages())<div class="card-footer">{{ $returns->links() }}</div>@endif
    </div>

    <x-modal name="settle-exchange" title="Refund exchange credit">
        @if ($settling)
            <div class="vstack gap-3">
                <div class="alert alert-light border mb-0">Unused credit on {{ $settling->return_number }}: <b>{{ money($settling->exchange_credit) }}</b></div>
                <div>
                    <label class="form-label">Settle as</label>
                    <select wire:model.live="settleMethod" class="form-select">
                        <option value="cash">Cash refund</option><option value="bank">Bank refund</option><option value="wallet">Wallet refund</option>
                        @if ($settling->customer_id)<option value="ledger">Credit to customer account</option>@endif
                    </select>
                </div>
                @if ($settleMethod !== 'ledger')
                    <div>
                        <label class="form-label">From account</label>
                        <select wire:model="settleAccount" class="form-select">@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->account_name }} — {{ money($a->current_balance) }}</option>@endforeach</select>
                    </div>
                @endif
            </div>
        @endif
        <x-slot:footer>
            <button type="button" class="btn btn-light" x-on:click="$dispatch('close-modal', { name: 'settle-exchange' })">Cancel</button>
            <button type="button" class="btn btn-warning" wire:click="settle">Settle</button>
        </x-slot:footer>
    </x-modal>
</div>
