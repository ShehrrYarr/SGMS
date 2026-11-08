<?php

use App\Exceptions\BusinessException;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\ProductVariant;
use App\Services\StockTransferService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Transfer Stock')] class extends Component {
    use InteractsWithUi;

    public ?int $from_branch_id = null;

    public ?int $to_branch_id = null;

    public string $transfer_date = '';

    public string $notes = '';

    public array $rows = [];

    public ?int $pick = null;

    public function mount(): void
    {
        $this->from_branch_id = tenancy()->operationalBranchId();
        $this->to_branch_id = tenancy()->branches()->firstWhere('id', '!=', $this->from_branch_id)?->id;
        $this->transfer_date = today()->toDateString();
    }

    #[Renderless]
    public function searchVariants(string $term = ''): array
    {
        return ProductVariant::query()->select('product_variants.*')->withStock($this->from_branch_id)
            ->with('product:id,name', 'color:id,name,hex_code', 'size:id,code')
            ->search($term)->limit(25)->get()
            ->map(fn ($v) => ['id' => $v->id, 'label' => $v->label, 'sub' => $v->sku.' · '.(int) $v->stock.' in stock', 'color' => $v->color?->hex_code])
            ->all();
    }

    public function updatedPick($id): void
    {
        if (! $id) {
            return;
        }

        $variant = ProductVariant::query()->select('product_variants.*')->withStock($this->from_branch_id)->with('product', 'color', 'size')->find($id);

        if ($variant && ! collect($this->rows)->contains('variant_id', $variant->id)) {
            $this->rows[] = ['variant_id' => $variant->id, 'label' => $variant->label, 'sku' => $variant->sku, 'available' => (int) $variant->stock, 'quantity' => min(1, (int) $variant->stock)];
        }

        $this->pick = null;
    }

    public function updatedFromBranchId(): void
    {
        foreach ($this->rows as $i => $row) {
            $this->rows[$i]['available'] = (int) ProductVariant::query()->select('product_variants.*')->withStock($this->from_branch_id)->find($row['variant_id'])?->stock;
        }
    }

    public function removeRow(int $i): void
    {
        unset($this->rows[$i]);
        $this->rows = array_values($this->rows);
    }

    public function save(StockTransferService $service): void
    {
        $context = tenancy();
        $this->validate([
            'from_branch_id' => ['required', Rule::in($context->allowedBranchIds())],
            'to_branch_id' => ['required', 'different:from_branch_id', Rule::in($context->branches()->pluck('id')->all())],
            'transfer_date' => ['required', 'date', 'before_or_equal:today'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.quantity' => ['required', 'integer', 'min:1'],
        ], ['rows.required' => 'Add at least one item.', 'to_branch_id.different' => 'Choose a different destination branch.']);

        try {
            $transfer = $service->create((int) $this->from_branch_id, (int) $this->to_branch_id, collect($this->rows)->map(fn ($r) => ['variant_id' => $r['variant_id'], 'quantity' => (int) $r['quantity']])->all(), $this->transfer_date, $this->notes ?: null);
        } catch (BusinessException $e) {
            $this->toast($e->getMessage(), 'error');

            return;
        }

        session()->flash('success', "Transfer {$transfer->transfer_number} completed: {$transfer->total_quantity} pieces moved.");
        $this->redirectRoute('app.transfers.index', navigate: true);
    }

    public function with(): array
    {
        $context = tenancy();

        return [
            'fromBranches' => $context->branches()->whereIn('id', $context->allowedBranchIds()),
            'toBranches' => $context->branches(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Transfer stock between branches" :breadcrumbs="['Dashboard' => route('app.dashboard'), 'Transfers' => route('app.transfers.index'), 'New' => null]" />

    <div class="card">
        <div class="card-body row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">From branch</label>
                <select wire:model.live="from_branch_id" class="form-select @error('from_branch_id') is-invalid @enderror">@foreach ($fromBranches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select>
            </div>
            <div class="col-md-1 text-center d-none d-md-block pb-2"><i class="ri-arrow-right-line fs-4 text-primary"></i></div>
            <div class="col-md-4">
                <label class="form-label">To branch</label>
                <select wire:model="to_branch_id" class="form-select @error('to_branch_id') is-invalid @enderror"><option value="">Select…</option>@foreach ($toBranches as $b)<option value="{{ $b->id }}" @disabled($b->id === (int) $from_branch_id)>{{ $b->name }}</option>@endforeach</select>
                @error('to_branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-3">
                <label class="form-label">Date</label>
                <input wire:model="transfer_date" type="date" class="form-control">
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="row g-2 align-items-center">
                <div class="col-md-8"><x-combobox wire:model.live="pick" search="searchVariants" placeholder="Search or scan an item to add…" wire:key="pick-{{ count($rows) }}-{{ $from_branch_id }}" /></div>
                <div class="col-md-4 text-md-end small text-muted">{{ count($rows) }} items · {{ collect($rows)->sum('quantity') }} pcs</div>
            </div>
            @error('rows')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
        </div>
        <div class="table-responsive">
            <table class="table mb-0 table-stack">
                <thead class="table-light"><tr><th>Item</th><th class="text-center">Available</th><th style="width: 140px">Transfer qty</th><th></th></tr></thead>
                <tbody>
                    @forelse ($rows as $i => $row)
                        <tr wire:key="tr-{{ $row['variant_id'] }}">
                            <td data-label="Item"><div class="fw-semibold">{{ $row['label'] }}</div><small class="text-muted font-monospace">{{ $row['sku'] }}</small></td>
                            <td data-label="Available" class="text-md-center">{{ $row['available'] }}</td>
                            <td data-label="Qty"><input wire:model="rows.{{ $i }}.quantity" type="number" min="1" max="{{ $row['available'] }}" class="form-control form-control-sm @error('rows.'.$i.'.quantity') is-invalid @enderror"></td>
                            <td class="text-end td-actions" data-label=""><button class="btn btn-sm btn-light-danger" wire:click="removeRow({{ $i }})"><i class="ri-delete-bin-line"></i></button></td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><x-empty-state icon="ri-arrow-left-right-line" title="No items yet" text="Search for items above to add them." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex flex-wrap gap-2 justify-content-between">
            <input wire:model="notes" class="form-control" style="max-width: 420px" placeholder="Notes (optional)">
            <button class="btn btn-primary" wire:click="save" wire:loading.attr="disabled"><i class="ri-send-plane-line me-1"></i>Transfer stock</button>
        </div>
    </div>
</div>
