<?php

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Expense;
use App\Models\PaymentAccount;
use App\Services\ExpenseService;
use App\Support\Catalog;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Expenses')] class extends Component {
    use InteractsWithUi, WithPagination;

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public array $form = [];

    public function mount(): void
    {
        $this->resetForm();
    }

    public function updating($name): void
    {
        if (in_array($name, ['category', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function resetForm(): void
    {
        $branchId = tenancy()->operationalBranchId();
        $this->form = [
            'branch_id' => $branchId,
            'expense_category_id' => null,
            'payment_account_id' => PaymentAccount::query()->where('branch_id', $branchId)->where('account_type', PaymentAccount::TYPE_CASH)->value('id'),
            'amount' => '',
            'expense_date' => today()->toDateString(),
            'reference_no' => '',
            'note' => '',
        ];
        $this->resetValidation();
    }

    public function save(ExpenseService $service): void
    {
        $context = tenancy();
        $data = $this->validate([
            'form.branch_id' => ['required', Rule::in($context->allowedBranchIds())],
            'form.expense_category_id' => ['nullable', 'integer'],
            'form.payment_account_id' => ['required', 'integer', Rule::in(PaymentAccount::query()->active()->usableAt($this->form['branch_id'])->pluck('id')->all())],
            'form.amount' => ['required', 'numeric', 'gt:0'],
            'form.expense_date' => ['required', 'date', 'before_or_equal:today'],
            'form.reference_no' => ['nullable', 'string', 'max:60'],
            'form.note' => ['nullable', 'string', 'max:255'],
        ], [], ['form.payment_account_id' => 'paid from', 'form.amount' => 'amount'])['form'];

        if ($this->attempt(fn () => $service->create($data), 'Expense recorded.')) {
            $this->closeModal('expense-form');
            $this->resetForm();
        }
    }

    public function delete(int $id, ExpenseService $service): void
    {
        abort_unless(auth('web')->user()->isOwner(), 403);
        $expense = Expense::query()->inBranchScope()->findOrFail($id);
        $this->attempt(fn () => $service->delete($expense), 'Expense deleted and amount returned to the account.');
    }

    public function with(): array
    {
        $query = Expense::query()->inBranchScope()
            ->when($this->category, fn ($q) => $q->where('expense_category_id', $this->category))
            ->when($this->from, fn ($q) => $q->whereDate('expense_date', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('expense_date', '<=', $this->to));

        $context = tenancy();

        return [
            'expenses' => (clone $query)->with('category:id,name', 'paymentAccount:id,account_name', 'branch:id,code', 'creator:id,name')->latest('expense_date')->latest('id')->paginate(15),
            'total' => (float) (clone $query)->sum('amount'),
            'byCategory' => (clone $query)->selectRaw('expense_category_id, SUM(amount) total')->groupBy('expense_category_id')->with('category:id,name')->orderByDesc('total')->limit(5)->get(),
            'categories' => Catalog::expenseCategories(),
            'branches' => $context->branches()->whereIn('id', $context->allowedBranchIds()),
            'accounts' => PaymentAccount::query()->active()->usableAt($this->form['branch_id'] ?? null)->get(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Expenses" :breadcrumbs="['Dashboard' => route('app.dashboard'), 'Expenses' => null]">
        <x-slot:actions>
            <a href="{{ route('app.attributes', ['tab' => 'expense']) }}" class="btn btn-light-secondary" wire:navigate><i class="ri-settings-3-line me-1"></i>Expense types</a>
            <button class="btn btn-primary" wire:click="resetForm" x-on:click="$dispatch('open-modal', { name: 'expense-form' })"><i class="ri-add-line me-1"></i>Record expense</button>
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3 mb-3">
        <div class="col-md-4"><x-stat-card label="Total (filtered)" :value="money($total)" icon="ri-wallet-3-line" color="danger" /></div>
        <div class="col-md-8">
            <div class="card card-h-100 mb-0"><div class="card-body p-3 d-flex flex-wrap gap-2 align-items-center">
                <span class="text-muted small me-2">Top categories</span>
                @forelse ($byCategory as $row)
                    <span class="badge bg-light text-body border py-2 px-3">{{ $row->category?->name ?? 'Uncategorised' }} · {{ money($row->total) }}</span>
                @empty
                    <span class="small text-muted">No expenses in this period.</span>
                @endforelse
            </div></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="row g-2 filter-bar">
                <div class="col-12 col-md-4 col-lg-3"><select wire:model.live="category" class="form-select"><option value="">All categories</option>@foreach ($categories as $c)<option value="{{ $c['id'] }}">{{ $c['name'] }}</option>@endforeach</select></div>
                <div class="col-6 col-md-4 col-lg-2"><input type="date" wire:model.live="from" class="form-control"></div>
                <div class="col-6 col-md-4 col-lg-2"><input type="date" wire:model.live="to" class="form-control"></div>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 table-stack">
                <thead class="table-light"><tr><th>Date</th><th>Category</th><th>Details</th><th>Branch</th><th>Paid from</th><th class="text-end">Amount</th><th></th></tr></thead>
                <tbody>
                    @forelse ($expenses as $e)
                        <tr wire:key="e-{{ $e->id }}">
                            <td data-label="Date">{{ $e->expense_date->format('d M Y') }}</td>
                            <td data-label="Category">{{ $e->category?->name ?? '—' }}</td>
                            <td data-label="Details" class="small">{{ $e->note }}@if ($e->reference_no)<div class="text-muted">Ref: {{ $e->reference_no }}</div>@endif</td>
                            <td data-label="Branch">{{ $e->branch->code }}</td>
                            <td data-label="Paid from" class="small">{{ $e->paymentAccount->account_name }}</td>
                            <td data-label="Amount" class="text-end fw-semibold tabular-nums">{{ money($e->amount) }}</td>
                            <td class="text-end td-actions" data-label="">
                                @if (auth('web')->user()->isOwner())
                                    <button class="btn btn-sm btn-light-danger" x-on:click="SGMS.confirm({ title: 'Delete this expense?', text: 'The amount is returned to {{ addslashes($e->paymentAccount->account_name) }}.' }).then(ok => ok && $wire.delete({{ $e->id }}))"><i class="ri-delete-bin-line"></i></button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><x-empty-state icon="ri-wallet-3-line" title="No expenses recorded" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($expenses->hasPages())<div class="card-footer">{{ $expenses->links() }}</div>@endif
    </div>

    <x-modal name="expense-form" title="Record expense">
        <form wire:submit="save" id="exp-form" class="row g-3">
            <div class="col-6">
                <label class="form-label">Branch</label>
                <select wire:model.live="form.branch_id" class="form-select">@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select>
            </div>
            <div class="col-6">
                <label class="form-label">Category</label>
                <select wire:model="form.expense_category_id" class="form-select"><option value="">—</option>@foreach ($categories as $c)<option value="{{ $c['id'] }}">{{ $c['name'] }}</option>@endforeach</select>
            </div>
            <div class="col-6"><label class="form-label">Amount</label><input wire:model="form.amount" type="number" step="0.01" class="form-control @error('form.amount') is-invalid @enderror"></div>
            <div class="col-6"><label class="form-label">Date</label><input wire:model="form.expense_date" type="date" class="form-control"></div>
            <div class="col-12">
                <label class="form-label">Paid from</label>
                <select wire:model="form.payment_account_id" class="form-select @error('form.payment_account_id') is-invalid @enderror"><option value="">Select…</option>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->account_name }} — {{ money($a->current_balance) }}</option>@endforeach</select>
                @error('form.payment_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-6"><label class="form-label">Reference</label><input wire:model="form.reference_no" class="form-control" placeholder="Bill no."></div>
            <div class="col-6"><label class="form-label">Note</label><input wire:model="form.note" class="form-control"></div>
        </form>
        <x-slot:footer>
            <button type="button" class="btn btn-light" x-on:click="$dispatch('close-modal', { name: 'expense-form' })">Cancel</button>
            <button type="submit" form="exp-form" class="btn btn-primary">Save expense</button>
        </x-slot:footer>
    </x-modal>
</div>
