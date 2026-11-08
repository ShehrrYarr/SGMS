<?php

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\PaymentAccount;
use App\Services\AccountService;
use App\Services\TenantProvisioner;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Payment Accounts')] class extends Component {
    use InteractsWithUi;

    public array $form = ['account_name' => '', 'account_type' => 'bank', 'bank_name' => '', 'account_number' => '', 'opening_balance' => 0, 'branch_id' => null];

    public ?int $editId = null;

    // Fund transfer
    public ?int $from_id = null;

    public ?int $to_id = null;

    public $transfer_amount = '';

    public string $transfer_note = '';

    public function startCreate(): void
    {
        abort_unless(auth('web')->user()->isOwner(), 403);
        $this->editId = null;
        $this->form = ['account_name' => '', 'account_type' => 'bank', 'bank_name' => '', 'account_number' => '', 'opening_balance' => 0, 'branch_id' => null];
        $this->resetValidation();
        $this->openModal('account-form');
    }

    public function startEdit(int $id): void
    {
        abort_unless(auth('web')->user()->isOwner(), 403);
        $a = PaymentAccount::query()->findOrFail($id);
        $this->editId = $a->id;
        $this->form = $a->only('account_name', 'account_type', 'bank_name', 'account_number', 'branch_id') + ['opening_balance' => (float) $a->opening_balance];
        $this->resetValidation();
        $this->openModal('account-form');
    }

    public function save(TenantProvisioner $provisioner): void
    {
        abort_unless(auth('web')->user()->isOwner(), 403);
        $data = $this->validate([
            'form.account_name' => ['required', 'string', 'max:120'],
            'form.account_type' => ['required', Rule::in([PaymentAccount::TYPE_BANK, PaymentAccount::TYPE_WALLET])],
            'form.bank_name' => ['nullable', 'string', 'max:120'],
            'form.account_number' => ['nullable', 'string', 'max:60'],
            'form.opening_balance' => ['nullable', 'numeric', 'min:0'],
            'form.branch_id' => ['nullable', Rule::in(tenancy()->branches()->pluck('id')->all())],
        ])['form'];

        if ($this->editId) {
            PaymentAccount::query()->findOrFail($this->editId)->update(collect($data)->except('opening_balance', 'account_type')->all());
        } else {
            $provisioner->createAccount($data);
        }

        $this->closeModal('account-form');
        $this->toast('Account saved.');
    }

    public function toggle(int $id): void
    {
        abort_unless(auth('web')->user()->isOwner(), 403);
        $a = PaymentAccount::query()->findOrFail($id);

        if ($a->is_default) {
            $this->toast('The default cash drawer of a branch cannot be disabled.', 'error');

            return;
        }

        $a->update(['status' => $a->status === 'active' ? 'inactive' : 'active']);
    }

    public function startTransfer(): void
    {
        $this->reset('from_id', 'to_id', 'transfer_amount', 'transfer_note');
        $this->resetValidation();
        $this->openModal('fund-transfer');
    }

    public function transfer(AccountService $accounts): void
    {
        $ids = $this->visibleAccounts()->pluck('id')->all();
        $this->validate([
            'from_id' => ['required', Rule::in($ids)],
            'to_id' => ['required', 'different:from_id', Rule::in($ids)],
            'transfer_amount' => ['required', 'numeric', 'gt:0'],
            'transfer_note' => ['nullable', 'string', 'max:255'],
        ], ['to_id.different' => 'Choose a different account.']);

        if ($this->attempt(fn () => $accounts->transferFunds((int) $this->from_id, (int) $this->to_id, (float) $this->transfer_amount, today()->toDateString(), $this->transfer_note ?: null), 'Funds transferred.')) {
            $this->closeModal('fund-transfer');
        }
    }

    protected function visibleAccounts()
    {
        $branchIds = tenancy()->allowedBranchIds();

        return PaymentAccount::query()
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhereIn('branch_id', $branchIds))
            ->with('branch:id,name')
            ->orderByRaw("FIELD(account_type, 'cash_drawer', 'bank', 'wallet')")->orderBy('account_name')
            ->get();
    }

    public function with(): array
    {
        $accounts = $this->visibleAccounts();

        return [
            'accounts' => $accounts,
            'activeAccounts' => $accounts->where('status', 'active'),
            'totals' => $accounts->where('status', 'active')->groupBy('account_type')->map->sum('current_balance'),
        ];
    }
}; ?>

<div>
    <x-page-header title="Payment accounts" subtitle="Cash drawers, bank accounts and mobile wallets with live balances.">
        <x-slot:actions>
            <button class="btn btn-light-primary" wire:click="startTransfer"><i class="ri-exchange-funds-line me-1"></i>Transfer funds</button>
            @if (auth('web')->user()->isOwner())
                <button class="btn btn-primary" wire:click="startCreate"><i class="ri-add-line me-1"></i>Add account</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3 mb-3">
        <div class="col-md-4"><x-stat-card label="Cash in drawers" :value="money($totals['cash_drawer'] ?? 0)" icon="ri-safe-2-line" color="success" /></div>
        <div class="col-md-4"><x-stat-card label="Bank balances" :value="money($totals['bank'] ?? 0)" icon="ri-bank-line" /></div>
        <div class="col-md-4"><x-stat-card label="Mobile wallets" :value="money($totals['wallet'] ?? 0)" icon="ri-smartphone-line" color="info" /></div>
    </div>

    <div class="row g-3">
        @foreach ($accounts as $account)
            <div class="col-md-6 col-xl-4" wire:key="acc-{{ $account->id }}">
                <div class="card h-100 mb-0 {{ $account->status !== 'active' ? 'opacity-50' : '' }}">
                    <div class="card-body">
                        <div class="d-flex align-items-start gap-3">
                            <span class="stat-icon {{ $account->isCash() ? 'bg-success-subtle text-success' : 'bg-primary-subtle text-primary' }}"><i class="{{ $account->icon }}"></i></span>
                            <div class="flex-grow-1 min-w-0">
                                <a href="{{ route('app.accounts.show', $account) }}" class="fw-semibold d-block text-truncate" wire:navigate>{{ $account->account_name }}</a>
                                <small class="text-muted">{{ \App\Models\PaymentAccount::TYPES[$account->account_type] }}{{ $account->branch ? ' · '.$account->branch->name : ' · Shop-wide' }}</small>
                                @if ($account->account_number)<div class="small font-monospace text-muted">{{ $account->bank_name }} {{ $account->account_number }}</div>@endif
                            </div>
                            @if ($account->is_default)<span class="badge bg-success-subtle text-success">Default</span>@endif
                        </div>
                        <div class="fs-4 fw-bold mt-3 tabular-nums {{ (float) $account->current_balance < 0 ? 'text-danger' : '' }}">{{ money($account->current_balance) }}</div>
                    </div>
                    <div class="card-footer d-flex gap-2">
                        <a href="{{ route('app.accounts.show', $account) }}" class="btn btn-sm btn-light-primary flex-grow-1" wire:navigate><i class="ri-file-list-2-line me-1"></i>Statement</a>
                        @if (auth('web')->user()->isOwner() && ! $account->isCash())
                            <button class="btn btn-sm btn-light-secondary" wire:click="startEdit({{ $account->id }})"><i class="ri-pencil-line"></i></button>
                        @endif
                        @if (auth('web')->user()->isOwner() && ! $account->is_default)
                            <button class="btn btn-sm btn-light-warning" wire:click="toggle({{ $account->id }})" title="Enable / disable"><i class="ri-toggle-line"></i></button>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <x-modal name="account-form" :title="$editId ? 'Edit account' : 'Add bank / wallet account'">
        <form wire:submit="save" id="acc-form" class="vstack gap-3">
            <div><label class="form-label">Account name</label><input wire:model="form.account_name" class="form-control @error('form.account_name') is-invalid @enderror" placeholder="e.g. Meezan Current"></div>
            <div class="row g-2">
                <div class="col-6">
                    <label class="form-label">Type</label>
                    <select wire:model="form.account_type" class="form-select" @disabled($editId)><option value="bank">Bank account</option><option value="wallet">Mobile wallet</option></select>
                </div>
                <div class="col-6">
                    <label class="form-label">Usable at</label>
                    <select wire:model="form.branch_id" class="form-select"><option value="">All branches</option>@foreach (tenancy()->branches() as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select>
                </div>
            </div>
            <div class="row g-2">
                <div class="col-6"><label class="form-label">Bank / provider</label><input wire:model="form.bank_name" class="form-control"></div>
                <div class="col-6"><label class="form-label">Account / IBAN / number</label><input wire:model="form.account_number" class="form-control"></div>
            </div>
            @unless ($editId)
                <div><label class="form-label">Opening balance</label><input wire:model="form.opening_balance" type="number" step="0.01" min="0" class="form-control"></div>
            @endunless
        </form>
        <x-slot:footer>
            <button type="button" class="btn btn-light" x-on:click="$dispatch('close-modal', { name: 'account-form' })">Cancel</button>
            <button type="submit" form="acc-form" class="btn btn-primary">Save</button>
        </x-slot:footer>
    </x-modal>

    <x-modal name="fund-transfer" title="Transfer funds between accounts">
        <form wire:submit="transfer" id="ft-form" class="vstack gap-3">
            <div>
                <label class="form-label">From</label>
                <select wire:model="from_id" class="form-select @error('from_id') is-invalid @enderror"><option value="">Select…</option>@foreach ($activeAccounts as $a)<option value="{{ $a->id }}">{{ $a->account_name }} — {{ money($a->current_balance) }}</option>@endforeach</select>
            </div>
            <div>
                <label class="form-label">To</label>
                <select wire:model="to_id" class="form-select @error('to_id') is-invalid @enderror"><option value="">Select…</option>@foreach ($activeAccounts as $a)<option value="{{ $a->id }}">{{ $a->account_name }}</option>@endforeach</select>
                @error('to_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div><label class="form-label">Amount</label><input wire:model="transfer_amount" type="number" step="0.01" class="form-control @error('transfer_amount') is-invalid @enderror"></div>
            <div><label class="form-label">Note</label><input wire:model="transfer_note" class="form-control" placeholder="e.g. Daily cash deposit"></div>
        </form>
        <x-slot:footer>
            <button type="button" class="btn btn-light" x-on:click="$dispatch('close-modal', { name: 'fund-transfer' })">Cancel</button>
            <button type="submit" form="ft-form" class="btn btn-primary">Transfer</button>
        </x-slot:footer>
    </x-modal>
</div>
