<?php

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\PaymentAccount;
use App\Models\Transaction;
use App\Services\AccountService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Account Statement')] class extends Component {
    use InteractsWithUi, WithPagination;

    #[Locked]
    public int $accountId;

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public $counted = '';

    public string $countNote = '';

    public function mount(PaymentAccount $account): void
    {
        abort_unless($account->branch_id === null || tenancy()->canAccessBranch($account->branch_id), 403);
        $this->accountId = $account->id;
    }

    public function updating($name): void
    {
        if (in_array($name, ['type', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function reconcile(AccountService $accounts): void
    {
        abort_unless(auth('web')->user()->isOwner(), 403);
        $this->validate(['counted' => ['required', 'numeric'], 'countNote' => ['required', 'string', 'max:255']]);
        $result = $accounts->adjustTo($this->accountId, (float) $this->counted, $this->countNote);
        $this->closeModal('reconcile');
        $this->reset('counted', 'countNote');
        $this->toast($result ? 'Balance adjusted by '.money($result->type === 'credit' ? $result->amount : -$result->amount).'.' : 'Balance already matches.');
    }

    public function with(): array
    {
        $account = PaymentAccount::query()->with('branch')->findOrFail($this->accountId);
        $base = Transaction::query()->where('payment_account_id', $account->id)
            ->when($this->type, fn ($q) => $q->where('type', $this->type))
            ->when($this->from, fn ($q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('created_at', '<=', $this->to));

        return [
            'account' => $account,
            'transactions' => (clone $base)->with('creator:id,name')->latest('id')->paginate(25),
            'sums' => (clone $base)->selectRaw("COALESCE(SUM(CASE WHEN type='credit' THEN amount END),0) inflow, COALESCE(SUM(CASE WHEN type='debit' THEN amount END),0) outflow")->first(),
        ];
    }
}; ?>

<div>
    <x-page-header :title="$account->account_name" :breadcrumbs="['Dashboard' => route('app.dashboard'), 'Accounts' => route('app.accounts.index'), $account->account_name => null]">
        <x-slot:actions>
            @if (auth('web')->user()->isOwner())
                <button class="btn btn-light-warning" x-on:click="$dispatch('open-modal', { name: 'reconcile' })"><i class="ri-scales-3-line me-1"></i>Reconcile / count</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3 mb-3">
        <div class="col-md-4"><x-stat-card label="Current balance" :value="money($account->current_balance)" :icon="$account->icon" color="primary" :hint="\App\Models\PaymentAccount::TYPES[$account->account_type].($account->branch ? ' · '.$account->branch->name : '')" /></div>
        <div class="col-md-4"><x-stat-card label="Money in (filtered)" :value="money($sums->inflow)" icon="ri-arrow-down-circle-line" color="success" /></div>
        <div class="col-md-4"><x-stat-card label="Money out (filtered)" :value="money($sums->outflow)" icon="ri-arrow-up-circle-line" color="danger" /></div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="row g-2 filter-bar">
                <div class="col-4 col-lg-2"><select wire:model.live="type" class="form-select"><option value="">In & out</option><option value="credit">Money in</option><option value="debit">Money out</option></select></div>
                <div class="col-4 col-lg-2"><input type="date" wire:model.live="from" class="form-control"></div>
                <div class="col-4 col-lg-2"><input type="date" wire:model.live="to" class="form-control"></div>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 table-stack">
                <thead class="table-light"><tr><th>Date</th><th>Source</th><th>Details</th><th>By</th><th class="text-end">In</th><th class="text-end">Out</th><th class="text-end">Balance</th></tr></thead>
                <tbody>
                    @forelse ($transactions as $t)
                        <tr>
                            <td data-label="Date" class="small">{{ $t->created_at->format('d M Y h:i A') }}</td>
                            <td data-label="Source"><span class="badge bg-light text-body border">{{ $t->reference_label }}</span></td>
                            <td data-label="Details" class="small">
                                @if ($t->reference_type === 'sale' && $t->reference_id)<a href="{{ route('app.sales.show', $t->reference_id) }}" wire:navigate>{{ $t->note }}</a>
                                @elseif ($t->reference_type === 'purchase' && $t->reference_id && auth('web')->user()->canManage())<a href="{{ route('app.purchases.show', $t->reference_id) }}" wire:navigate>{{ $t->note }}</a>
                                @else{{ $t->note }}@endif
                            </td>
                            <td data-label="By" class="small">{{ $t->creator?->name ?? 'System' }}</td>
                            <td data-label="In" class="text-end text-success tabular-nums">{{ $t->type === 'credit' ? money($t->amount) : '' }}</td>
                            <td data-label="Out" class="text-end text-danger tabular-nums">{{ $t->type === 'debit' ? money($t->amount) : '' }}</td>
                            <td data-label="Balance" class="text-end fw-semibold tabular-nums">{{ money($t->balance_after) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><x-empty-state icon="ri-exchange-dollar-line" title="No transactions" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($transactions->hasPages())<div class="card-footer">{{ $transactions->links() }}</div>@endif
    </div>

    <x-modal name="reconcile" title="Reconcile balance">
        <form wire:submit="reconcile" id="rec-form" class="vstack gap-3">
            <div class="alert alert-light border mb-0 small">System balance: <b>{{ money($account->current_balance) }}</b>. Enter the counted cash / bank statement balance; the difference is posted as an adjustment.</div>
            <div><label class="form-label">Actual balance</label><input wire:model="counted" type="number" step="0.01" class="form-control form-control-lg @error('counted') is-invalid @enderror"></div>
            <div><label class="form-label">Reason</label><input wire:model="countNote" class="form-control @error('countNote') is-invalid @enderror" placeholder="End of day cash count"></div>
        </form>
        <x-slot:footer>
            <button type="button" class="btn btn-light" x-on:click="$dispatch('close-modal', { name: 'reconcile' })">Cancel</button>
            <button type="submit" form="rec-form" class="btn btn-warning">Post adjustment</button>
        </x-slot:footer>
    </x-modal>
</div>
