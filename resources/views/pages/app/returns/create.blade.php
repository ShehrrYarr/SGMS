<?php

use App\Exceptions\BusinessException;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\PaymentAccount;
use App\Models\Sale;
use App\Services\SaleReturnService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Return / Exchange')] class extends Component {
    use InteractsWithUi;

    public string $invoice = '';

    public ?int $saleId = null;

    /** sale_item_id => qty */
    public array $quantities = [];

    public string $method = 'cash';

    public ?int $account_id = null;

    public string $reason = '';

    public bool $restock = true;

    public function mount(): void
    {
        if ($id = request()->integer('sale')) {
            $sale = Sale::query()->find($id);

            if ($sale) {
                $this->loadSale($sale);
            }
        }
    }

    public function findInvoice(): void
    {
        $this->validate(['invoice' => ['required', 'string', 'max:40']]);
        $term = strtoupper(trim($this->invoice));

        if (ctype_digit($term)) {
            $term = 'INV-'.str_pad($term, 6, '0', STR_PAD_LEFT); // "123" -> INV-000123
        }

        $sale = Sale::query()->where('invoice_number', $term)->first();

        if (! $sale) {
            $this->addError('invoice', 'No invoice found with that number.');

            return;
        }

        $this->loadSale($sale);
    }

    protected function loadSale(Sale $sale): void
    {
        if (! tenancy()->canAccessBranch($sale->branch_id)) {
            $this->addError('invoice', 'This invoice belongs to a branch you cannot access.');

            return;
        }

        $this->saleId = $sale->id;
        $this->invoice = $sale->invoice_number;
        $this->quantities = $sale->items()->pluck('id')->mapWithKeys(fn ($id) => [$id => 0])->all();
        $this->method = 'cash';
        $this->account_id = PaymentAccount::query()->where('branch_id', $sale->branch_id)->where('account_type', PaymentAccount::TYPE_CASH)->where('is_default', true)->value('id');
        $this->resetErrorBag();
    }

    public function returnAll(): void
    {
        $sale = Sale::query()->with('items')->findOrFail($this->saleId);

        foreach ($sale->items as $item) {
            $this->quantities[$item->id] = $item->returnable_quantity;
        }
    }

    public function updatedMethod(string $value): void
    {
        if (in_array($value, ['cash', 'bank', 'wallet'], true)) {
            $sale = Sale::query()->find($this->saleId);
            $type = ['cash' => PaymentAccount::TYPE_CASH, 'bank' => PaymentAccount::TYPE_BANK, 'wallet' => PaymentAccount::TYPE_WALLET][$value];
            $this->account_id = PaymentAccount::query()->active()->usableAt($sale?->branch_id)->where('account_type', $type)->value('id');
        }
    }

    public function save(SaleReturnService $service): void
    {
        $sale = Sale::query()->findOrFail($this->saleId);
        abort_unless(tenancy()->canAccessBranch($sale->branch_id), 403);

        $this->validate([
            'quantities' => ['required', 'array'],
            'quantities.*' => ['nullable', 'integer', 'min:0'],
            'method' => ['required', 'in:cash,bank,wallet,ledger,exchange'],
            'account_id' => [in_array($this->method, ['cash', 'bank', 'wallet'], true) ? 'required' : 'nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], ['account_id.required' => 'Choose the account to refund from.']);

        try {
            $return = $service->create($sale, $this->quantities, $this->method, $this->account_id, $this->reason ?: null, $this->restock);
        } catch (BusinessException $e) {
            $this->toast($e->getMessage(), 'error');

            return;
        }

        if ($return->isPendingExchange()) {
            session()->flash('success', "Return {$return->return_number} saved. Exchange credit ".money($return->exchange_credit).' applied — add the new items.');
            $this->redirect(route('app.pos', ['exchange' => $return->id]));

            return;
        }

        session()->flash('success', "Return {$return->return_number} completed".((float) $return->refunded_amount ? ' — refund '.money($return->refunded_amount) : '').'.');
        $this->redirectRoute('app.returns.index', navigate: true);
    }

    public function with(): array
    {
        $sale = $this->saleId ? Sale::query()->with([
            'items.variant.product:id,name', 'items.variant.color:id,name,hex_code', 'items.variant.size:id,code', 'customer', 'branch',
        ])->find($this->saleId) : null;

        $ratio = $sale && (float) $sale->subtotal > 0 ? (float) $sale->total_amount / (float) $sale->subtotal : 0;
        $value = 0.0;

        if ($sale) {
            foreach ($sale->items as $item) {
                $value += round($item->net_unit_price * $ratio, 2) * (int) ($this->quantities[$item->id] ?? 0);
            }
        }

        $value = round($value, 2);
        $adjusted = $sale ? min((float) $sale->due_amount, $value) : 0;

        return [
            'sale' => $sale,
            'ratio' => $ratio,
            'returnValue' => $value,
            'adjusted' => $adjusted,
            'accounts' => $sale ? PaymentAccount::query()->active()->usableAt($sale->branch_id)->get() : collect(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Return / exchange" :breadcrumbs="['Dashboard' => route('app.dashboard'), 'Returns' => route('app.returns.index'), 'New' => null]" />

    <div class="card">
        <div class="card-body">
            <form wire:submit="findInvoice" class="row g-2 align-items-end">
                <div class="col-md-6 col-lg-4">
                    <label class="form-label">Invoice number</label>
                    <div class="position-relative">
                        <i class="ri-barcode-line position-absolute text-muted" style="left: .8rem; top: 50%; transform: translateY(-50%)"></i>
                        <input wire:model="invoice" class="form-control ps-5 @error('invoice') is-invalid @enderror" placeholder="Scan receipt or type INV-000123" autofocus>
                    </div>
                    @error('invoice')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </div>
                <div class="col-auto"><button class="btn btn-primary" type="submit"><i class="ri-search-line me-1"></i>Find</button></div>
            </form>
        </div>
    </div>

    @if ($sale)
        <div class="row g-3">
            <div class="col-xl-8">
                <div class="card mb-0">
                    <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
                        <div>
                            <h6 class="card-title mb-0">{{ $sale->invoice_number }} · {{ $sale->customer_name }}</h6>
                            <small class="text-muted">{{ $sale->sale_date->format('d M Y h:i A') }} · {{ $sale->branch->name }} · Total {{ money($sale->total_amount) }}</small>
                        </div>
                        <button class="btn btn-sm btn-light-primary" wire:click="returnAll">Return everything</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table mb-0 table-stack">
                            <thead class="table-light"><tr><th>Item</th><th class="text-center">Sold</th><th class="text-center">Returnable</th><th class="text-end">Refund / pc</th><th style="width: 130px">Return qty</th></tr></thead>
                            <tbody>
                                @foreach ($sale->items as $item)
                                    <tr wire:key="ri-{{ $item->id }}">
                                        <td data-label="Item"><div class="fw-semibold">{{ $item->variant->product->name }}</div><small class="text-muted">{{ $item->variant->variant_name }}</small></td>
                                        <td data-label="Sold" class="text-md-center">{{ $item->quantity }}</td>
                                        <td data-label="Returnable" class="text-md-center">{{ $item->returnable_quantity }}</td>
                                        <td data-label="Refund / pc" class="text-end tabular-nums">{{ money(round($item->net_unit_price * $ratio, 2)) }}</td>
                                        <td data-label="Return qty">
                                            @if ($item->returnable_quantity > 0)
                                                <input wire:model.live.debounce.300ms="quantities.{{ $item->id }}" type="number" min="0" max="{{ $item->returnable_quantity }}" class="form-control form-control-sm">
                                            @else
                                                <span class="badge bg-light text-muted">Fully returned</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card mb-0">
                    <div class="card-header"><h6 class="card-title mb-0">Settlement</h6></div>
                    <div class="card-body vstack gap-3">
                        <div class="d-flex justify-content-between fs-5"><span>Return value</span><b class="tabular-nums">{{ money($returnValue) }}</b></div>
                        @if ($adjusted > 0)
                            <div class="alert alert-info small mb-0">{{ money($adjusted) }} clears this invoice's unpaid balance first. Remaining: <b>{{ money($returnValue - $adjusted) }}</b></div>
                        @endif
                        <div>
                            <label class="form-label">Give the customer</label>
                            <div class="vstack gap-2">
                                @foreach (['cash' => ['Cash refund', 'ri-safe-2-line'], 'bank' => ['Bank refund', 'ri-bank-line'], 'wallet' => ['Wallet refund', 'ri-smartphone-line'], 'ledger' => ['Credit to customer account', 'ri-book-open-line'], 'exchange' => ['Exchange for other items (POS)', 'ri-exchange-line']] as $key => [$label, $icon])
                                    @if ($key !== 'ledger' || $sale->customer_id)
                                        <label class="d-flex align-items-center gap-2 border rounded-3 p-2 cursor-pointer {{ $method === $key ? 'border-primary bg-primary-subtle' : '' }}">
                                            <input type="radio" class="form-check-input mt-0" wire:model.live="method" value="{{ $key }}"><i class="{{ $icon }}"></i><span>{{ $label }}</span>
                                        </label>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                        @if (in_array($method, ['cash', 'bank', 'wallet'], true))
                            <div>
                                <label class="form-label">Refund from</label>
                                <select wire:model="account_id" class="form-select @error('account_id') is-invalid @enderror">
                                    <option value="">Select account…</option>
                                    @foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->account_name }} — {{ money($a->current_balance) }}</option>@endforeach
                                </select>
                                @error('account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        @endif
                        <div><label class="form-label">Reason</label><input wire:model="reason" class="form-control" placeholder="e.g. Size too small"></div>
                        <div class="form-check"><input wire:model="restock" class="form-check-input" type="checkbox" id="restock"><label class="form-check-label" for="restock">Put items back into stock</label></div>
                        <button class="btn {{ $method === 'exchange' ? 'btn-info' : 'btn-warning' }} w-100" wire:click="save" @disabled($returnValue <= 0) wire:loading.attr="disabled">
                            <i class="{{ $method === 'exchange' ? 'ri-exchange-line' : 'ri-arrow-go-back-line' }} me-1"></i>{{ $method === 'exchange' ? 'Save & continue to POS' : 'Complete return' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
