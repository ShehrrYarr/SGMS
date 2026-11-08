<?php

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Inventory;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\InventoryService;
use App\Support\Catalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Stock Levels')] class extends Component {
    use InteractsWithUi, WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $filter = '';

    // Adjust stock
    public ?int $adjVariantId = null;

    public ?int $adjBranchId = null;

    public $adjCounted = '';

    public string $adjNote = '';

    // History
    public ?int $historyVariantId = null;

    public function updating($name): void
    {
        if (in_array($name, ['search', 'category', 'filter'], true)) {
            $this->resetPage();
        }
    }

    public function startAdjust(int $variantId, ?int $branchId = null): void
    {
        $this->adjVariantId = $variantId;
        $this->adjBranchId = $branchId ?? tenancy()->operationalBranchId();
        $this->adjCounted = app(InventoryService::class)->available($this->adjBranchId, $variantId);
        $this->adjNote = '';
        $this->resetValidation();
        $this->openModal('adjust-stock');
    }

    public function updatedAdjBranchId($value): void
    {
        if ($this->adjVariantId && $value) {
            $this->adjCounted = app(InventoryService::class)->available((int) $value, $this->adjVariantId);
        }
    }

    public function adjust(InventoryService $inventory): void
    {
        $this->validate([
            'adjBranchId' => ['required', Rule::in(tenancy()->allowedBranchIds())],
            'adjCounted' => ['required', 'integer', 'min:0'],
            'adjNote' => ['required', 'string', 'max:255'],
        ], ['adjNote.required' => 'Give a reason (stock count, damage, lost…).']);

        ProductVariant::query()->findOrFail($this->adjVariantId);
        $inventory->setCounted((int) $this->adjBranchId, $this->adjVariantId, (int) $this->adjCounted, $this->adjNote);

        $this->closeModal('adjust-stock');
        $this->toast('Stock updated.');
    }

    public function showHistory(int $variantId): void
    {
        $this->historyVariantId = $variantId;
        $this->openModal('stock-history');
    }

    public function with(): array
    {
        $context = tenancy();
        $branchIds = $context->branchScopeIds();
        $branches = $context->branches()->whereIn('id', $branchIds)->values();

        $stockSub = Inventory::query()->selectRaw('COALESCE(SUM(quantity), 0)')
            ->whereColumn('inventories.product_variant_id', 'product_variants.id')
            ->whereIn('inventories.branch_id', $branchIds);

        $variants = ProductVariant::query()
            ->select('product_variants.*')
            ->addSelect(['stock' => $stockSub])
            ->with(['product:id,name,category_id', 'product.category:id,name', 'color:id,name,hex_code', 'size:id,code',
                'inventories' => fn ($q) => $q->whereIn('branch_id', $branchIds)])
            ->search($this->search)
            ->when($this->category, fn ($q) => $q->whereHas('product', fn ($p) => $p->where('category_id', $this->category)))
            ->when($this->filter === 'low', fn ($q) => $q->where(clone $stockSub, '<=', DB::raw('product_variants.alert_quantity'))->where(clone $stockSub, '>', 0))
            ->when($this->filter === 'out', fn ($q) => $q->where(clone $stockSub, '<=', 0))
            ->when($this->filter === 'in', fn ($q) => $q->where(clone $stockSub, '>', 0))
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereNull('products.deleted_at')
            ->orderBy('products.name')->orderBy('product_variants.sku');

        $totals = Inventory::query()->whereIn('inventories.branch_id', $branchIds)
            ->join('product_variants', 'product_variants.id', '=', 'inventories.product_variant_id')
            ->whereNull('product_variants.deleted_at')
            ->selectRaw('COALESCE(SUM(inventories.quantity), 0) units, COALESCE(SUM(inventories.quantity * product_variants.cost_price), 0) cost_value, COALESCE(SUM(inventories.quantity * product_variants.retail_price), 0) retail_value')
            ->first();

        return [
            'variants' => $variants->paginate(25),
            'branches' => $branches,
            'allowedBranches' => $context->branches()->whereIn('id', $context->allowedBranchIds())->values(),
            'categories' => Catalog::categories(),
            'totals' => $totals,
            'adjVariant' => $this->adjVariantId ? ProductVariant::query()->with('product', 'color', 'size')->find($this->adjVariantId) : null,
            'history' => $this->historyVariantId
                ? StockMovement::query()->where('product_variant_id', $this->historyVariantId)->whereIn('branch_id', $context->allowedBranchIds())->with('branch:id,code', 'creator:id,name')->latest('id')->limit(40)->get()
                : collect(),
            'historyVariant' => $this->historyVariantId ? ProductVariant::query()->with('product', 'color', 'size')->find($this->historyVariantId) : null,
        ];
    }
}; ?>

<div>
    <x-page-header title="Stock levels" :breadcrumbs="['Dashboard' => route('app.dashboard'), 'Stock' => null]">
        <x-slot:actions>
            <a href="{{ route('app.transfers.create') }}" class="btn btn-light-primary" wire:navigate><i class="ri-arrow-left-right-line me-1"></i>Transfer stock</a>
            <a href="{{ route('app.purchases.create') }}" class="btn btn-primary" wire:navigate><i class="ri-inbox-archive-line me-1"></i>Receive lot</a>
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3 mb-3">
        <div class="col-md-4"><x-stat-card label="Units on hand" :value="number_format($totals->units)" icon="ri-stack-line" /></div>
        <div class="col-md-4"><x-stat-card label="Stock value at cost" :value="money($totals->cost_value)" icon="ri-money-dollar-box-line" color="warning" /></div>
        <div class="col-md-4"><x-stat-card label="Stock value at retail" :value="money($totals->retail_value)" icon="ri-price-tag-3-line" color="success" /></div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="row g-2 filter-bar">
                <div class="col-lg-5"><x-search-input wire:model.live.debounce.300ms="search" placeholder="Product, SKU or scan barcode…" /></div>
                <div class="col-6 col-lg-3">
                    <select wire:model.live="category" class="form-select"><option value="">All categories</option>@foreach ($categories as $c)<option value="{{ $c['id'] }}">{{ $c['name'] }}</option>@endforeach</select>
                </div>
                <div class="col-6 col-lg-4">
                    <div class="btn-group w-100" role="group">
                        @foreach (['' => 'All', 'in' => 'In stock', 'low' => 'Low', 'out' => 'Out'] as $key => $label)
                            <button type="button" class="btn btn-sm {{ $filter === $key ? 'btn-primary' : 'btn-light' }}" wire:click="$set('filter', '{{ $key }}')">{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
        <div class="table-responsive loading-overlay" wire:loading.attr="data-loading">
            <table class="table table-hover mb-0 table-stack">
                <thead class="table-light">
                    <tr>
                        <th>Product</th><th>Variant</th><th>Barcode</th>
                        @if ($branches->count() > 1)
                            @foreach ($branches as $b)<th class="text-center">{{ $b->code }}</th>@endforeach
                        @endif
                        <th class="text-center">Total</th><th class="text-end">Cost</th><th class="text-end">Retail</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($variants as $v)
                        @php $qty = (int) $v->stock; @endphp
                        <tr wire:key="inv-{{ $v->id }}">
                            <td data-label="Product"><div class="fw-semibold">{{ $v->product->name }}</div><small class="text-muted">{{ $v->product->category?->name }}</small></td>
                            <td data-label="Variant"><span class="color-dot me-1" style="background: {{ $v->color?->hex_code ?? '#e5e7eb' }}"></span>{{ $v->variant_name }}</td>
                            <td data-label="Barcode" class="font-monospace small">{{ $v->barcode }}</td>
                            @if ($branches->count() > 1)
                                @foreach ($branches as $b)
                                    <td data-label="{{ $b->code }}" class="text-md-center">{{ (int) $v->inventories->firstWhere('branch_id', $b->id)?->quantity }}</td>
                                @endforeach
                            @endif
                            <td data-label="Total" class="text-md-center">
                                <span class="badge {{ $qty <= 0 ? 'bg-danger' : ($qty <= $v->alert_quantity ? 'bg-warning-subtle text-warning' : 'bg-success-subtle text-success') }}">{{ $qty }}</span>
                            </td>
                            <td data-label="Cost" class="text-end tabular-nums small">{{ money($v->cost_price) }}</td>
                            <td data-label="Retail" class="text-end tabular-nums small">{{ money($v->retail_price) }}</td>
                            <td class="text-end td-actions text-nowrap" data-label="">
                                <button class="btn btn-sm btn-light-secondary" wire:click="showHistory({{ $v->id }})" title="Movements"><i class="ri-history-line"></i></button>
                                <button class="btn btn-sm btn-light-warning" wire:click="startAdjust({{ $v->id }})" title="Adjust / count"><i class="ri-edit-box-line"></i></button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10"><x-empty-state icon="ri-stack-line" title="No stock found" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($variants->hasPages())<div class="card-footer">{{ $variants->links() }}</div>@endif
    </div>

    <x-modal name="adjust-stock" title="Adjust stock (physical count)">
        @if ($adjVariant)
            <form wire:submit="adjust" id="adj-stock-form" class="vstack gap-3">
                <div class="fw-semibold">{{ $adjVariant->label }}<div class="small text-muted font-monospace">{{ $adjVariant->sku }}</div></div>
                <div>
                    <label class="form-label">Branch</label>
                    <select wire:model.live="adjBranchId" class="form-select">@foreach ($allowedBranches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select>
                </div>
                <div>
                    <label class="form-label">Counted quantity</label>
                    <input wire:model="adjCounted" type="number" min="0" class="form-control form-control-lg @error('adjCounted') is-invalid @enderror">
                </div>
                <div>
                    <label class="form-label">Reason</label>
                    <input wire:model="adjNote" class="form-control @error('adjNote') is-invalid @enderror" placeholder="Monthly stock count, damaged, lost…">
                    @error('adjNote')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn btn-light" x-on:click="$dispatch('close-modal', { name: 'adjust-stock' })">Cancel</button>
            <button type="submit" form="adj-stock-form" class="btn btn-warning">Save count</button>
        </x-slot:footer>
    </x-modal>

    <x-modal name="stock-history" title="Stock movements" size="lg">
        @if ($historyVariant)
            <div class="fw-semibold mb-2">{{ $historyVariant->label }}</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Date</th><th>Branch</th><th>Type</th><th class="text-end">Qty</th><th class="text-end">Balance</th><th>Note</th></tr></thead>
                    <tbody>
                        @forelse ($history as $m)
                            <tr>
                                <td class="small">{{ $m->created_at->format('d M y H:i') }}</td>
                                <td>{{ $m->branch->code }}</td>
                                <td>{{ \App\Models\StockMovement::TYPES[$m->type] ?? $m->type }}</td>
                                <td class="text-end fw-semibold {{ $m->quantity < 0 ? 'text-danger' : 'text-success' }}">{{ $m->quantity > 0 ? '+' : '' }}{{ $m->quantity }}</td>
                                <td class="text-end">{{ $m->balance_after }}</td>
                                <td class="small text-muted">{{ $m->note }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-muted">No movements yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </x-modal>
</div>
